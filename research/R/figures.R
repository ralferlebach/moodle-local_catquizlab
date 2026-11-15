# The figures of "4. Results", recomputed from the package's raw tables (#157 AK2–AK4).
#
# Every function returns rows of (tab, section, groupby, group, metric, stat, value), keyed as reference/dashboard.csv
# keys the dashboard's own figures, so that the two can be put side by side. The sources are the raw tables only:
# attempts (true and estimated ability, length, SE, how a sitting ended), steps (the questions really played),
# items (the pool), estimated_profiles (subscale estimates), runs (each run's design and CAT parameters),
# analysis_population (which sittings the dashboard counts under the package's filter).

figure_rows <- function(tab, section, groupby, group, metric, values) {
  if (length(values) == 0) return(NULL)
  data.frame(tab = tab, section = section, groupby = groupby, group = group, metric = metric,
             stat = names(values), value = as.numeric(unlist(lapply(values, function(v) if (is.null(v)) NA else v))),
             stringsAsFactors = FALSE)
}

# The sittings the dashboard counts, with what they need: experiment, unit, cell.
population <- function(p) {
  a <- p$tables$attempts
  pop <- p$tables$analysis_population
  a <- a[a$attemptid %in% pop$attemptid[pop$inpopulation == 1], , drop = FALSE]
  # The error from the raw abilities, not from the exported error column: estimate minus truth, NA without an estimate.
  a$error <- a$esttheta - a$truetheta
  runs <- p$tables$runs
  a$experimentid <- runs$experimentid[match(a$runid, runs$runid)]
  a$unit <- unit_key(a)
  a$cell <- paste(txt(a$tier), txt(a$strategy), txt(a$model), txt(a$variant), txt(a$stratum), txt(a$severity), sep = "|")
  a
}

# A group of sittings as the overview summarises it: sittings, runs, people; described fields; RMSE (and correlation).
summary_rows <- function(tab, section, groupby, group, a, fields, withcorrelation = FALSE) {
  out <- list(figure_rows(tab, section, groupby, group, "sittings", list(count = nrow(a))),
              figure_rows(tab, section, groupby, group, "runs", list(count = length(unique(a$runid)))),
              figure_rows(tab, section, groupby, group, "people", list(count = length(unique(a$unit)))))
  for (field in fields) {
    out[[length(out) + 1]] <- figure_rows(tab, section, groupby, group, field, describe(a[[field]], a$unit))
  }
  rec <- recovery(a$truetheta, a$esttheta)
  out[[length(out) + 1]] <- figure_rows(tab, section, groupby, group, "rmse", list(value = r6(rec$rmse)))
  if (withcorrelation) {
    out[[length(out) + 1]] <- figure_rows(tab, section, groupby, group, "correlation", list(value = rec$correlation))
  }
  do.call(rbind, out)
}

# Stop success: the share of ALL sittings (valid or not) that ended on a stop rule of the design, in percent.
stop_success <- function(all) {
  if (nrow(all) == 0) return(NA)
  100 * sum(all$stopreached == 1, na.rm = TRUE) / nrow(all)
}

# How often each question was played by the counted sittings, as rates, against the whole pool of the selection.
exposure <- function(p, a) {
  steps <- p$tables$steps
  played <- steps$questionid[steps$attemptid %in% a$attemptid]
  counts <- table(played)
  n <- nrow(a)
  rates <- if (n > 0) round(as.numeric(counts) / n, 6) else numeric(0)
  poolsize <- nrow(p$tables$items)
  list(rates = rates, used = length(counts), poolsize = if (poolsize > 0) poolsize else NA,
       concentration = concentration(rates, if (poolsize > 0) poolsize else NA))
}

overview_figures <- function(p, a) {
  all <- p$tables$attempts
  all$cell <- paste(txt(all$tier), txt(all$strategy), txt(all$model), txt(all$variant), txt(all$stratum),
                    txt(all$severity), sep = "|")
  out <- list(summary_rows("overview", "kpi", "", "", a, c("nitems", "se", "error", "runtimems")))
  rec <- recovery(a$truetheta, a$esttheta)
  out[[length(out) + 1]] <- figure_rows("overview", "kpi", "", "", "correlation", list(value = rec$correlation))
  out[[length(out) + 1]] <- figure_rows("overview", "kpi", "", "", "stopsuccess", list(percent = stop_success(all)))
  ex <- exposure(p, a)
  out[[length(out) + 1]] <- figure_rows("overview", "kpi", "", "", "concentration", list(gini = ex$concentration$gini))
  for (strategy in unique(a$strategy)) {
    s <- a[a$strategy == strategy, , drop = FALSE]
    out[[length(out) + 1]] <- summary_rows("overview", "strategy", "strategy", strategy, s, c("nitems", "se", "error"))
    out[[length(out) + 1]] <- figure_rows("overview", "strategy", "strategy", strategy, "stopsuccess",
                                          list(percent = stop_success(all[all$strategy == strategy, , drop = FALSE])))
  }
  for (cell in unique(a$cell)) {
    s <- a[a$cell == cell, , drop = FALSE]
    out[[length(out) + 1]] <- summary_rows("overview", "cell", "cell", cell, s, c("nitems", "se", "error", "runtimems"),
                                           withcorrelation = TRUE)
    out[[length(out) + 1]] <- figure_rows("overview", "cell", "cell", cell, "stopsuccess",
                                          list(percent = stop_success(all[all$cell == cell, , drop = FALSE])))
  }
  do.call(rbind, out)
}

global_figures <- function(p, a) {
  both <- !is.na(a$truetheta) & !is.na(a$esttheta)
  outside <- sum(both & (a$truetheta < a$abilitymin | a$truetheta > a$abilitymax), na.rm = TRUE)
  ex <- exposure(p, a)
  c <- ex$concentration
  rbind(
    figure_rows("global", "recovery", "", "", "sittings", list(withestimate = sum(both))),
    figure_rows("global", "recovery", "", "", "outsiderange", list(count = outside)),
    figure_rows("global", "exposure", "", "", "exposure", list(gini = c$gini, hhi = c$hhi, max = c$max, mean = c$mean,
                                                                 above = c$above, n = c$n, itemsused = ex$used,
                                                                 unused = if (is.na(ex$poolsize)) NA else
                                                                   max(0, ex$poolsize - ex$used),
                                                                 maxrate = if (length(ex$rates)) max(ex$rates) else 0))
  )
}

# The subscale results the dashboard counts: valid ones of counted sittings (filter "valid"), or, with "all", every
# one with an administered item or an unknown count.
subscale_rows <- function(p, a) {
  s <- p$tables$estimated_profiles
  s <- s[s$attemptid %in% a$attemptid, , drop = FALSE]
  mode <- if ("validity" %in% names(p$filter)) p$filter[["validity"]] else "valid"
  if (mode == "valid") {
    s <- s[!is.na(s$valid) & s$valid == 1, , drop = FALSE]
  } else if (mode == "all") {
    s <- s[is.na(s$items) | s$items > 0, , drop = FALSE]
  } else {
    s <- s[0, , drop = FALSE]
    attr(s, "unsupported") <- mode
  }
  s$key <- paste(int_text(s$category), int_text(s$subscale), sep = ":")
  s
}

local_summary <- function(s) {
  n <- nrow(s)
  if (n == 0) {
    return(list(n = 0, bias = NA, rmse = NA, mae = NA, correlation = NA, meanse = NA, within1se = NA, within2se = NA,
                nwithse = 0))
  }
  withse <- !is.na(s$localse)
  list(n = n, bias = r6(sum(s$error) / n), rmse = r6(sqrt(sum(s$error^2) / n)), mae = r6(sum(abs(s$error)) / n),
       correlation = pearson(s$truedelta, s$estdelta),
       meanse = if (any(withse)) r6(sum(s$localse[withse]) / sum(withse)) else NA,
       within1se = if (any(withse)) r6(sum(s$within1se[withse] == 1) / sum(withse)) else NA,
       within2se = if (any(withse)) r6(sum(s$within2se[withse] == 1) / sum(withse)) else NA,
       nwithse = sum(withse))
}

subscale_figures <- function(p, a) {
  s <- subscale_rows(p, a)
  out <- list(figure_rows("subscales", "summary", "", "", "local", local_summary(s)))
  for (groupby in c("category", "key")) {
    for (group in unique(as.character(if (groupby == "category") int_text(s$category) else s$key))) {
      rows <- s[(if (groupby == "category") int_text(s$category) else s$key) == group, , drop = FALSE]
      out[[length(out) + 1]] <- figure_rows("subscales", groupby, groupby, group, "local", local_summary(rows))
    }
  }
  do.call(rbind, out)
}

# Robustness: per cell (design coordinates, pool variant and strength) the global and local outcomes, and their
# difference to the cell with the ideal pool and the same tier, strategy, model, stratum and severity.
robustness_figures <- function(p, a) {
  a$rcell <- paste(txt(a$tier), txt(a$strategy), txt(a$model), txt(a$stratum), txt(a$severity), txt(a$variant),
                   php_number_text(a$strength), sep = "|")
  a$reference <- paste(txt(a$tier), txt(a$strategy), txt(a$model), txt(a$stratum), txt(a$severity), sep = "|")
  s <- subscale_rows(p, a)
  s$rcell <- a$rcell[match(s$attemptid, a$attemptid)]
  outcomes <- list()
  for (cell in unique(a$rcell)) {
    m <- a[a$rcell == cell, , drop = FALSE]
    errors <- m$error[!is.na(m$error)]
    rec <- recovery(m$truetheta, m$esttheta)
    cellrows <- s[s$rcell == cell, , drop = FALSE]
    local <- local_summary(cellrows)
    # The ranking measures of each sitting with two or more valid subscale results, then their means.
    rankings <- lapply(split(seq_len(nrow(cellrows)), factor(cellrows$attemptid, levels = unique(cellrows$attemptid))),
                       function(i) sitting_ranking(cellrows$truedelta[i], cellrows$estdelta[i],
                                                   m$strategy[match(cellrows$attemptid[i[1]], m$attemptid)]))
    rankings <- Filter(Negate(is.null), rankings)
    meanof <- function(name) {
      values <- unlist(lapply(rankings, function(r) r[[name]]))
      values <- values[!is.na(values)]
      if (length(values)) r6(sum(values) / length(values)) else NA
    }
    outcomes[[cell]] <- list(
      variant = m$variant[1], reference = m$reference[1], n = nrow(m),
      values = list(rmse = if (length(errors)) r6(sqrt(sum(errors^2) / length(errors))) else NA,
                    bias = describe(m$error, m$unit)$mean, correlation = rec$correlation,
                    se = describe(m$se, m$unit)$mean, nitems = describe(m$nitems, m$unit)$mean,
                    stopsuccess = r6(sum(m$stopreached == 1, na.rm = TRUE) / max(1, nrow(m))),
                    runtimems = describe(m$runtimems, m$unit)$mean,
                    localn = if (local$n > 0) local$n else 0,
                    localrmse = if (local$n > 0) local$rmse else NA, localbias = if (local$n > 0) local$bias else NA,
                    within1se = if (local$n > 0) local$within1se else NA,
                    within2se = if (local$n > 0) local$within2se else NA,
                    spearman = if (local$n > 0) meanof("spearman") else NA,
                    top3 = if (local$n > 0) meanof("top3") else NA,
                    ndcg3 = if (local$n > 0) meanof("ndcg3") else NA))
  }
  out <- list()
  for (cell in names(outcomes)) {
    o <- outcomes[[cell]]
    out[[length(out) + 1]] <- figure_rows("robustness", "cell", "cell", cell, "sittings", list(count = o$n))
    ref <- NULL
    if (!identical(o$variant, "ideal")) {
      for (other in outcomes) {
        if (identical(other$variant, "ideal") && identical(other$reference, o$reference)) ref <- other
      }
    }
    for (metric in names(o$values)) {
      value <- o$values[[metric]]
      out[[length(out) + 1]] <- figure_rows("robustness", "cell", "cell", cell, metric, list(value = value))
      if (!is.null(ref)) {
        base <- ref$values[[metric]]
        delta <- if (is.na(value) || is.na(base)) NA else r6(value - base)
        out[[length(out) + 1]] <- figure_rows("robustness", "cell", "cell", cell, metric, list(delta = delta))
      }
    }
  }
  do.call(rbind, out)
}

# Test flow: was the precision target reachable? Required information 1/SE_target^2 against achieved 1/SE^2.
testflow_figures <- function(p, a) {
  runs <- p$tables$runs
  target <- runs$setarget[match(a$runid, runs$runid)]
  maxitems <- runs$globalmaxitems[match(a$runid, runs$runid)]
  required <- ifelse(!is.na(target) & target > 0, round(1 / target^2, 6), NA)
  achieved <- ifelse(!is.na(a$se) & a$se > 0, round(1 / a$se^2, 6), NA)
  verdict <- ifelse(is.na(required) | is.na(achieved), "unknown",
             ifelse(achieved >= required, "reached",
             ifelse(!is.na(a$stopreached) & a$stopreached == 1, "stoppedearly",
             ifelse(!is.na(maxitems) & a$nitems >= maxitems, "budgetexhausted", "missed"))))
  counts <- sapply(c("reached", "stoppedearly", "budgetexhausted", "missed", "unknown"), function(k) sum(verdict == k))
  figure_rows("testflow", "feasibility", "", "", "verdict", as.list(c(counts, n = length(verdict))))
}

all_figures <- function(p) {
  a <- population(p)
  if (nrow(a) == 0) return(NULL)
  rbind(overview_figures(p, a), global_figures(p, a), subscale_figures(p, a), robustness_figures(p, a),
        testflow_figures(p, a), detection_figures(p))
}
