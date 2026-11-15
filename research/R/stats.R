# The statistics of "4. Results", written out from their definitions (docs/dev/r-reanalyse.md), independently of the
# plugin's PHP code.

r6 <- function(x) ifelse(is.na(x), NA_real_, round(x, 6))

# Type 7 quantile of sorted values: position q * (n - 1), linear between neighbours (R's default).
quantile7 <- function(sorted, q) {
  n <- length(sorted)
  if (n == 0) return(0)
  if (n == 1) return(sorted[1])
  position <- q * (n - 1)
  lower <- floor(position)
  upper <- ceiling(position)
  if (lower == upper) return(sorted[lower + 1])
  sorted[lower + 1] + (position - lower) * (sorted[upper + 1] - sorted[lower + 1])
}

# The independent unit of a sitting: its simulated person in its replication (twin, experiment, stratum, severity,
# replication); a sitting without a twin is its own unit.
unit_key <- function(df) {
  ifelse(txt(df$twinid) == "", paste0("a", int_text(df$attemptid)),
         paste(int_text(df$experimentid), txt(df$stratum), txt(df$severity), int_text(df$replication),
               txt(df$twinid), sep = "|"))
}

# Descriptive statistics of values, and the 95 % interval of their mean over the independent units: the units'
# means where a unit contributes more than one value, a normal approximation (1.96), no interval with one unit.
describe <- function(values, clusters) {
  keep <- !is.na(values)
  values <- values[keep]
  clusters <- clusters[keep]
  n <- length(values)
  empty <- list(n = 0, mean = NA, sd = NA, se = NA, ci95lo = NA, ci95hi = NA, median = NA, q1 = NA, q3 = NA,
                min = NA, max = NA, units = 0)
  if (n == 0) return(empty)
  unitvalues <- values
  if (length(unique(clusters)) < n) {
    unitvalues <- as.numeric(tapply(values, clusters, mean))
  }
  units <- length(unitvalues)
  sorted <- sort(values)
  mean <- sum(values) / n
  sd <- if (n > 1) sqrt(sum((values - mean)^2) / (n - 1)) else NA
  se <- NA
  lo <- NA
  hi <- NA
  if (units > 1) {
    unitmean <- sum(unitvalues) / units
    se <- sqrt(sum((unitvalues - unitmean)^2) / (units - 1)) / sqrt(units)
    lo <- mean - 1.96 * se
    hi <- mean + 1.96 * se
  }
  list(n = n, mean = r6(mean), sd = r6(sd), se = r6(se), ci95lo = r6(lo), ci95hi = r6(hi),
       median = r6(quantile7(sorted, 0.5)), q1 = r6(quantile7(sorted, 0.25)), q3 = r6(quantile7(sorted, 0.75)),
       min = r6(sorted[1]), max = r6(sorted[n]), units = units)
}

# Root mean squared error and Pearson correlation of estimate and truth, over the sittings that have both.
recovery <- function(truth, estimate) {
  keep <- !is.na(truth) & !is.na(estimate)
  t <- truth[keep]
  e <- estimate[keep]
  n <- length(t)
  rmse <- if (n > 0) sqrt(sum((e - t)^2) / n) else 0
  correlation <- NA
  if (n >= 2) {
    vt <- sum((t - mean(t))^2)
    ve <- sum((e - mean(e))^2)
    if (vt > 0 && ve > 0) correlation <- r6(sum((t - mean(t)) * (e - mean(e))) / sqrt(vt * ve))
  }
  list(n = n, rmse = rmse, correlation = correlation)
}

pearson <- function(a, b) {
  n <- length(a)
  if (n < 2 || n != length(b)) return(NA)
  da <- a - sum(a) / n
  db <- b - sum(b) / n
  va <- sum(da * da)
  vb <- sum(db * db)
  if (va <= 0 || vb <= 0) return(NA)
  r6(sum(da * db) / sqrt(va * vb))
}

# How unevenly the pool was used: Gini over the exposure rates (unused items count as 0), Herfindahl index, the
# largest and mean rate, and the share of items above twice the mean.
concentration <- function(rates, poolsize) {
  values <- rates
  if (!is.na(poolsize) && poolsize > length(values)) values <- c(values, rep(0, poolsize - length(values)))
  n <- length(values)
  if (n == 0) return(list(n = 0, gini = NA, hhi = NA, max = 0, mean = 0, above = 0))
  values <- sort(values)
  total <- sum(values)
  mean <- total / n
  gini <- if (total > 0) 2 * sum(seq_len(n) * values) / (n * total) - (n + 1) / n else NA
  hhi <- if (total > 0) sum((values / total)^2) else NA
  threshold <- 2 * mean
  above <- if (threshold > 0) sum(values > threshold) else 0
  list(n = n, gini = r6(gini), hhi = r6(hhi), max = r6(max(values)), mean = r6(mean), above = r6(above / n))
}

# Rank agreement of the subscales of one sitting (the robustness tab's Spearman, top-3 and nDCG@3): values ordered
# ascending with ties kept in their order (a stable sort), average ranks for ties.
pearson_or_na <- function(x, y) {
  n <- length(x)
  if (n < 2) return(NA)
  dx <- x - sum(x) / n
  dy <- y - sum(y) / n
  sxx <- sum(dx * dx)
  syy <- sum(dy * dy)
  if (sxx <= 0 || syy <= 0) return(NA)
  sum(dx * dy) / sqrt(sxx * syy)
}

spearman <- function(truevalues, estvalues) {
  if (length(truevalues) < 2 || length(truevalues) != length(estvalues)) return(NA)
  r6(pearson_or_na(rank(truevalues, ties.method = "average"), rank(estvalues, ties.method = "average")))
}

ascending <- function(values) order(values, method = "radix")

topk_agreement <- function(truevalues, estvalues, k) {
  kk <- max(0, min(k, length(truevalues)))
  if (kk == 0) return(0)
  overlap <- length(intersect(ascending(truevalues)[seq_len(kk)], ascending(estvalues)[seq_len(kk)]))
  r6(overlap / kk)
}

ndcg_at_k <- function(truevalues, estvalues, k) {
  n <- length(truevalues)
  if (n == 0) return(0)
  kk <- max(0, min(k, n))
  trueorder <- ascending(truevalues)
  relevance <- numeric(n)
  relevance[trueorder] <- n - (seq_len(n) - 1)
  dcg <- function(indices) if (length(indices) == 0) 0 else sum(relevance[indices] / log2(seq_along(indices) + 1))
  ideal <- dcg(trueorder[seq_len(kk)])
  if (ideal > 0) r6(dcg(ascending(estvalues)[seq_len(kk)]) / ideal) else 0
}

# One sitting's ranking measures: rank 1 is the strongest deficit (the lowest deviation; the highest for a strengths
# strategy), so the values are oriented and negated before ranking.
sitting_ranking <- function(truedelta, estdelta, strategy) {
  if (length(truedelta) < 2) return(NULL)
  sign <- if (identical(strategy, "highestsub")) -1 else 1
  trueranked <- -(sign * truedelta)
  estranked <- -(sign * estdelta)
  out <- list(spearman = spearman(trueranked, estranked))
  if (3 <= length(truedelta)) {
    out$top3 <- topk_agreement(trueranked, estranked, 3)
    out$ndcg3 <- ndcg_at_k(trueranked, estranked, 3)
  }
  out
}
