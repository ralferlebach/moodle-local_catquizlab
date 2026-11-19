# Mode-specific detection (the "Mode-specific detection" tab), recomputed from the raw tables (#157 AK2, AK3).
#
# Every sitting of a strategy with a role (relsubs: relevant, lowestsub: deficit, highestsub: strength, allsubs and
# classic: baselines), valid or not, of a run with subscale maps. Its true targets: subscales whose true ability lies at
# least the threshold (0.5 logit) below (deficit) or above (strength) the global one; "relevant" has no definition and
# therefore no targets. Its scale results: the valid ones with an administered item (truedelta, estdelta). A mode is
# evaluated against the truth, and against each baseline the same twin sat in the same conditions (experiment, tier,
# model, variant, strength, stratum, severity), on all its scale results and on those both sittings share.

roles <- c(relsubs = "relevant", lowestsub = "deficit", highestsub = "strength", allsubs = "allsubs", classic = "classic")
targets_all <- c("relevant", "deficit", "strength")
baselines_all <- c("allsubs", "classic")
detection_threshold <- 0.5

is_target <- function(delta, target, threshold) {
  if (target == "strength") return(delta >= abs(threshold))
  if (target == "deficit") return(delta <= -abs(threshold))
  rep(FALSE, length(delta))
}

oriented <- function(delta, target) if (target == "strength") -delta else delta

# The sittings in the order the dashboard reads them (the attempts table's order), each with what detection needs.
detection_sittings <- function(p, threshold) {
  a <- p$tables$attempts
  runs <- p$tables$runs
  a <- a[!is.na(match(a$strategy, names(roles))), , drop = FALSE]
  a <- a[runs$subscalemaps[match(a$runid, runs$runid)] > 0, , drop = FALSE]
  if (nrow(a) == 0) return(list())
  a$experimentid <- runs$experimentid[match(a$runid, runs$runid)]
  profiles <- p$tables$truth_profiles
  s <- p$tables$estimated_profiles
  s <- s[!is.na(s$items) & s$items > 0, , drop = FALSE]
  s$key <- paste(int_text(s$category), int_text(s$subscale), sep = ":")
  # Scale results with an administered item, estimated or not: from the steps (#157, 0.7.71).
  given <- administered_scales(p)$count
  byattempt <- split(seq_len(nrow(s)), s$attemptid)
  byperson <- split(seq_len(nrow(profiles)), paste(profiles$runid, profiles$personid))
  lapply(seq_len(nrow(a)), function(i) {
    row <- a[i, ]
    prof <- profiles[byperson[[paste(row$runid, row$personid)]] %||% integer(0), , drop = FALSE]
    global <- prof$theta[prof$level == "global"]
    global <- if (length(global)) global[1] else 0
    subtheta <- prof$theta[prof$level == "subscale"]
    truth <- sapply(targets_all, function(t) sum(is_target(subtheta - global, t, threshold)))
    rows <- s[byattempt[[as.character(row$attemptid)]] %||% integer(0), , drop = FALSE]
    valid <- !is.na(row$valid) && row$valid == 1
    keep <- valid & !is.na(rows$valid) & rows$valid == 1
    twin <- txt(row$twinid)
    list(role = roles[[row$strategy]], strategy = row$strategy, attemptid = row$attemptid, valid = valid,
         withitems = if (is.na(given[as.character(row$attemptid)])) 0 else unname(given[as.character(row$attemptid)]),
         truth = truth,
         pairkey = if (twin == "") "" else paste(int_text(row$experimentid), txt(row$tier), txt(row$model),
                                                 txt(row$variant), php_number_text(row$strength), txt(row$stratum),
                                                 txt(row$severity), twin, sep = "|"),
         keys = rows$key[keep], truedelta = rows$truedelta[keep], estdelta = rows$estdelta[keep])
  })
}

`%||%` <- function(a, b) if (is.null(a)) b else a

mean6 <- function(values) if (length(values) == 0) NA else r6(sum(values) / length(values))

# One mode or baseline on a set of sittings: recovery of the deviations, detection of the targets, ranking agreement.
evaluate_sittings <- function(sittings, target, threshold, only = NULL) {
  defined <- target %in% c("deficit", "strength")
  n <- 0
  serr <- 0; se <- 0; sae <- 0; sx <- 0; sy <- 0; sxx <- 0; syy <- 0; sxy <- 0
  tse <- 0; tn <- 0; tp <- 0; fp <- 0; truetargets <- 0; covered <- 0
  spearmans <- c()
  topk <- list()
  for (j in seq_along(sittings)) {
    st <- sittings[[j]]
    truetargets <- truetargets + st$truth[[target]]
    keep <- if (is.null(only)) rep(TRUE, length(st$keys)) else st$keys %in% only[[j]]
    t <- st$truedelta[keep]
    e <- st$estdelta[keep]
    err <- e - t
    n <- n + length(t)
    serr <- serr + sum(err); se <- se + sum(err^2); sae <- sae + sum(abs(err))
    sx <- sx + sum(t); sy <- sy + sum(e); sxx <- sxx + sum(t^2); syy <- syy + sum(e^2); sxy <- sxy + sum(t * e)
    istarget <- is_target(t, target, threshold)
    detected <- is_target(e, target, threshold)
    covered <- covered + sum(istarget); tn <- tn + sum(istarget); tse <- tse + sum(err[istarget]^2)
    tp <- tp + sum(istarget & detected); fp <- fp + sum(!istarget & detected)
    if (defined && length(t) >= 2) {
      ot <- oriented(t, target)
      oe <- oriented(e, target)
      rho <- spearman(ot, oe)
      if (!is.na(rho)) spearmans <- c(spearmans, rho)
      for (k in c(1, 3, 5)) {
        if (k > length(t)) next
        key <- as.character(k)
        topk[[key]]$agreement <- c(topk[[key]]$agreement, topk_agreement(ot, oe, k))
        topk[[key]]$ndcg <- c(topk[[key]]$ndcg, ndcg_at_k(ot, oe, k))
      }
    }
  }
  fn <- max(0, truetargets - tp)
  precision <- if ((tp + fp) > 0) tp / (tp + fp) else NA
  recall <- if (truetargets > 0) tp / truetargets else NA
  varx <- if (n > 1) sxx - sx * sx / n else 0
  vary <- if (n > 1) syy - sy * sy / n else 0
  list(sittings = length(sittings), scales = n,
       withitems = sum(vapply(sittings, function(st) st$withitems, numeric(1))),
       truetargets = if (defined) truetargets else NA, targetscovered = if (defined) covered else NA,
       precision = if (defined && !is.na(precision)) r6(precision) else NA,
       recall = if (defined && !is.na(recall)) r6(recall) else NA,
       f1 = if (defined && !is.na(precision) && !is.na(recall) && (precision + recall) > 0)
         r6(2 * precision * recall / (precision + recall)) else NA,
       bias = if (n > 0) r6(serr / n) else NA, rmse = if (n > 0) r6(sqrt(se / n)) else NA,
       mae = if (n > 0) r6(sae / n) else NA,
       correlation = if (varx > 1e-12 && vary > 1e-12) r6((sxy - sx * sy / n) / sqrt(varx * vary)) else NA,
       targetrmse = if (defined && tn > 0) r6(sqrt(tse / tn)) else NA,
       spearman = mean6(spearmans),
       top1 = mean6(topk[["1"]]$agreement), top3 = mean6(topk[["3"]]$agreement), ndcg3 = mean6(topk[["3"]]$ndcg))
}

# A mode against a baseline: the same twin in the same conditions; a person's second sitting is not a second twin.
compare_with <- function(own, baseline, target, threshold) {
  bykey <- list()
  for (st in baseline) {
    if (st$pairkey == "" || !is.null(bykey[[st$pairkey]])) next
    bykey[[st$pairkey]] <- st
  }
  seen <- character(0)
  modeside <- list(); baseside <- list(); common <- list(); differences <- c()
  pairable <- 0
  for (st in own) {
    if (st$pairkey == "" || st$pairkey %in% seen) next
    seen <- c(seen, st$pairkey)
    pairable <- pairable + 1
    twin <- bykey[[st$pairkey]]
    if (is.null(twin)) next
    modeside[[length(modeside) + 1]] <- st
    baseside[[length(baseside) + 1]] <- twin
    shared <- st$keys[st$keys %in% twin$keys]
    common[[length(common) + 1]] <- shared
    if (length(shared) > 0) {
      ownerr <- abs(st$estdelta[match(shared, st$keys)] - st$truedelta[match(shared, st$keys)])
      baseerr <- abs(twin$estdelta[match(shared, twin$keys)] - twin$truedelta[match(shared, twin$keys)])
      differences <- c(differences, sum(ownerr) / length(shared) - sum(baseerr) / length(shared))
    }
  }
  if (length(modeside) == 0) {
    return(list(paired = FALSE, pairable = pairable, unpaired = evaluate_sittings(baseline, target, threshold)))
  }
  list(paired = TRUE, pairs = length(modeside), pairable = pairable,
       mode = evaluate_sittings(modeside, target, threshold), baseline = evaluate_sittings(baseside, target, threshold),
       commonmode = evaluate_sittings(modeside, target, threshold, common),
       commonbaseline = evaluate_sittings(baseside, target, threshold, common),
       meandifference = if (length(differences)) r6(sum(differences) / length(differences)) else NA)
}

detection_figures <- function(p, threshold = detection_threshold) {
  sittings <- detection_sittings(p, threshold)
  if (length(sittings) == 0) return(NULL)
  counts <- list()
  byrole <- list()
  for (st in sittings) {
    c <- counts[[st$role]] %||% list(sittings = 0, withitems = 0)
    c$sittings <- c$sittings + 1
    c$withitems <- c$withitems + st$withitems
    counts[[st$role]] <- c
    if (st$valid) byrole[[st$role]] <- c(byrole[[st$role]] %||% list(), list(st))
  }
  columns <- c("sittings", "sittingsall", "pairs", "pairable", "scales", "scaleswithitems", "truetargets",
               "targetscovered", "correlation", "bias", "rmse", "mae", "targetrmse", "recall", "precision", "f1",
               "spearman", "top1", "top3", "ndcg3", "meandifference", "threshold")
  row <- function(mode, compared, baseline, pairing, e, extra) {
    values <- list(sittings = e$sittings, sittingsall = extra$sittingsall, pairs = extra$pairs, pairable = extra$pairable,
                   scales = e$scales, scaleswithitems = extra$withitems %||% e$withitems, truetargets = e$truetargets,
                   targetscovered = e$targetscovered, correlation = e$correlation, bias = e$bias, rmse = e$rmse,
                   mae = e$mae, targetrmse = e$targetrmse, recall = e$recall, precision = e$precision, f1 = e$f1,
                   spearman = e$spearman, top1 = e$top1, top3 = e$top3, ndcg3 = e$ndcg3,
                   meandifference = extra$meandifference, threshold = abs(threshold))
    values <- lapply(columns, function(cname) { v <- values[[cname]]; if (is.null(v)) NA else v })
    out <- data.frame(tab = "detection", section = "summary", groupby = "mode",
                      group = paste(mode, compared, baseline, pairing, sep = "|"), metric = columns, stat = "value",
                      value = as.numeric(unlist(values)), stringsAsFactors = FALSE)
    out
  }
  out <- list()
  for (target in targets_all) {
    if (is.null(counts[[target]])) next
    own <- byrole[[target]] %||% list()
    e <- evaluate_sittings(own, target, threshold)
    out[[length(out) + 1]] <- row(target, target, "", "truth", e, list(sittingsall = counts[[target]]$sittings,
                                                                      withitems = counts[[target]]$withitems))
    for (baseline in baselines_all) {
      if (is.null(counts[[baseline]])) {
        out[[length(out) + 1]] <- row(target, baseline, baseline, "notavailable", list(), list())
        next
      }
      cmp <- compare_with(own, byrole[[baseline]] %||% list(), target, threshold)
      if (!cmp$paired) {
        out[[length(out) + 1]] <- row(target, baseline, baseline, "unpaired", cmp$unpaired,
                                      list(pairs = 0, pairable = cmp$pairable, sittingsall = counts[[baseline]]$sittings))
        next
      }
      extra <- list(pairs = cmp$pairs, pairable = cmp$pairable)
      out[[length(out) + 1]] <- row(target, target, baseline, "paired", cmp$mode, extra)
      out[[length(out) + 1]] <- row(target, baseline, baseline, "paired", cmp$baseline, extra)
      out[[length(out) + 1]] <- row(target, target, baseline, "pairedcommonscales", cmp$commonmode,
                                    c(extra, list(meandifference = cmp$meandifference)))
      out[[length(out) + 1]] <- row(target, baseline, baseline, "pairedcommonscales", cmp$commonbaseline, extra)
    }
  }
  do.call(rbind, out)
}
