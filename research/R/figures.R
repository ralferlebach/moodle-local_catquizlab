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

# The subscale results the dashboard counts: valid ones of counted sittings (filter "valid"); with "all", every one
# with an administered item or an unknown count; with "invalid" or "unknown", the scale results in that state —
# of every sitting, valid or not (an invalid scale result of a valid sitting is invalid), never one estimated
# without an item of its own (#157, 0.7.71).
subscale_rows <- function(p, a) {
  s <- p$tables$estimated_profiles
  mode <- if ("validity" %in% names(p$filter)) p$filter[["validity"]] else "valid"
  if (mode == "valid") {
    s <- s[s$attemptid %in% a$attemptid & !is.na(s$valid) & s$valid == 1, , drop = FALSE]
  } else if (mode == "all") {
    s <- s[s$attemptid %in% a$attemptid & (is.na(s$items) | s$items > 0), , drop = FALSE]
  } else if (mode %in% c("invalid", "unknown")) {
    mapped <- mapped_attempts(p)
    s <- s[s$attemptid %in% mapped & txt(s$validitystate) == mode & (is.na(s$items) | s$items > 0), , drop = FALSE]
  } else {
    s <- s[0, , drop = FALSE]
    attr(s, "unsupported") <- mode
  }
  s$key <- paste(int_text(s$category), int_text(s$subscale), sep = ":")
  s
}

# Every sitting of the package whose run maps its subscales: what the subscale analysis reads, valid or not.
mapped_attempts <- function(p) {
  a <- p$tables$attempts
  runs <- p$tables$runs
  a$attemptid[runs$subscalemaps[match(a$runid, runs$runid)] > 0]
}

# Per sitting, the subscales of the design that were given at least one item: from the steps (the questions played),
# the items (the scale each was assigned to) and the scale maps (which engine scale is which subscale) — raw tables
# only, also for subscales the engine gave no estimate (#157: "scale results with an administered item").
administered_scales <- function(p) {
  steps <- p$tables$steps
  items <- p$tables$items
  maps <- p$tables$scale_maps
  if (is.null(maps) || nrow(steps) == 0) return(list(count = integer(0), keys = list()))
  maps <- maps[maps$level == "subscale" & !is.na(maps$category) & !is.na(maps$subscale), , drop = FALSE]
  scale <- items$assignedcatscaleid[match(paste(steps$runid, steps$questionid), paste(items$runid, items$questionid))]
  key <- ifelse(is.na(scale), NA_character_, paste(int_text(maps$category), int_text(maps$subscale), sep = ":")[
    match(paste(steps$runid, scale), paste(maps$runid, maps$catscaleid))])
  keep <- !is.na(key)
  keys <- tapply(key[keep], steps$attemptid[keep], function(k) sort(unique(k)), simplify = FALSE)
  list(count = vapply(keys, length, integer(1)), keys = keys)
}

# The item count class of a scale result (#113): 0, 1, 2, 3-4, 5+, or unknown.
item_class <- function(items) {
  ifelse(is.na(items), "unknown", ifelse(items <= 0, "0", ifelse(items == 1, "1", ifelse(items == 2, "2",
         ifelse(items <= 4, "3-4", "5+")))))
}

# The spread of a set of scale results as the dashboard sums it (#112): SDs of the true and the estimated deviation,
# their ratio and correlation, RMSE and bias of the error.
spread_of <- function(x, y) {
  n <- length(x)
  if (n < 2) {
    e <- y - x
    return(list(n = n, sdtrue = NA, sdest = NA, ratio = NA, correlation = NA,
                rmse = if (n > 0) r6(sqrt(sum(e^2) / n)) else NA, bias = if (n > 0) r6(sum(e) / n) else NA))
  }
  varx <- max(0, (sum(x * x) - sum(x)^2 / n) / (n - 1))
  vary <- max(0, (sum(y * y) - sum(y)^2 / n) / (n - 1))
  cov <- (sum(x * y) - sum(x) * sum(y) / n) / (n - 1)
  e <- y - x
  list(n = n, sdtrue = r6(sqrt(varx)), sdest = r6(sqrt(vary)), ratio = if (varx > 1e-12) r6(sqrt(vary / varx)) else NA,
       correlation = if (varx > 1e-12 && vary > 1e-12) r6(cov / sqrt(varx * vary)) else NA,
       rmse = r6(sqrt(sum(e^2) / n)), bias = r6(sum(e) / n))
}

# What went in of what there was (#112, #157): the counting chain of the scale results, the reasons a scale result of
# a valid sitting was left out, and the spread per strategy — every sitting of the selection, valid or not.
coverage_figures <- function(p) {
  a <- p$tables$attempts
  a <- a[a$attemptid %in% mapped_attempts(p), , drop = FALSE]
  e <- p$tables$estimated_profiles
  e <- e[e$attemptid %in% a$attemptid, , drop = FALSE]
  sittingvalid <- !is.na(a$valid[match(e$attemptid, a$attemptid)]) & a$valid[match(e$attemptid, a$attemptid)] == 1
  strategy <- a$strategy[match(e$attemptid, a$attemptid)]
  unknown <- is.na(e$items)
  zero <- !unknown & e$items <= 0
  measured <- !unknown & e$items > 0
  scalevalid <- !is.na(e$scalevalid) & e$scalevalid == 1
  valid <- measured & sittingvalid & scalevalid
  # With an item but no estimate: design subscales given an item, without a row here.
  given <- administered_scales(p)
  estimated <- tapply(paste(int_text(e$category), int_text(e$subscale), sep = ":"), e$attemptid,
                      function(k) unique(k), simplify = FALSE)
  unestimated <- 0
  unestimatedvalid <- 0
  for (id in names(given$keys)) {
    if (!(as.numeric(id) %in% a$attemptid)) next
    missing <- length(setdiff(given$keys[[id]], estimated[[id]] %||% character(0)))
    unestimated <- unestimated + missing
    if (isTRUE(a$valid[match(as.numeric(id), a$attemptid)] == 1)) unestimatedvalid <- unestimatedvalid + missing
  }
  mode <- if ("validity" %in% names(p$filter)) p$filter[["validity"]] else "valid"
  state <- txt(e$validitystate)
  included <- sum(valid & (mode == "all" | state == mode))
  out <- list(figure_rows("subscales", "coverage", "", "", "sittings", list(count = nrow(a))),
              figure_rows("subscales", "coverage", "", "", "measured", list(count = sum(measured) + unestimated)),
              figure_rows("subscales", "coverage", "", "", "included", list(count = included)),
              figure_rows("subscales", "coverage", "", "", "frominvalid", list(count = sum(measured & !sittingvalid))),
              figure_rows("subscales", "coverage", "", "", "unestimated", list(count = unestimated)),
              figure_rows("subscales", "coverage", "", "", "unknownitems", list(count = sum(unknown))),
              figure_rows("subscales", "coverage", "", "", "withoutitems", list(count = sum(zero))))
  chain <- list(fromvalid = sum(measured & sittingvalid) + unestimatedvalid, paired = sum(measured & sittingvalid),
                valid = sum(valid), withse = sum(valid & !is.na(e$localse)))
  for (k in names(chain)) {
    out[[length(out) + 1]] <- figure_rows("subscales", "coverage", "chain", k, "chain", list(count = chain[[k]]))
  }
  left <- measured & sittingvalid & !scalevalid
  reasons <- unlist(lapply(txt(e$scalereasons[left]), function(r) if (r == "") "engine" else strsplit(r, ",")[[1]]))
  for (reason in sort(unique(reasons))) {
    out[[length(out) + 1]] <- figure_rows("subscales", "coverage", "reason", reason, "reasons",
                                          list(count = sum(reasons == reason)))
  }
  for (st in sort(unique(strategy))) {
    mine <- strategy == st
    out[[length(out) + 1]] <- figure_rows("subscales", "spread", "strategy", st, "spreadall",
                                          spread_of(e$truedelta[mine], e$estdelta[mine]))
    out[[length(out) + 1]] <- figure_rows("subscales", "spread", "strategy", st, "spreadvalid",
                                          spread_of(e$truedelta[mine & valid], e$estdelta[mine & valid]))
  }
  do.call(rbind, out)
}

# The flows of "Comparison of flows": every counted sitting step by step — the estimate and its standard error after
# each step, from the steps table (#157).
flow_figures <- function(p, a) {
  steps <- p$tables$steps
  out <- list()
  bya <- split(seq_len(nrow(steps)), steps$attemptid)
  for (id in a$attemptid) {
    i <- bya[[as.character(id)]] %||% integer(0)
    i <- i[order(steps$step[i])]
    group <- int_text(id)
    out[[length(out) + 1]] <- figure_rows("testflow", "flow", "attempt", group, "steps", list(count = length(i)))
    if (length(i)) {
      out[[length(out) + 1]] <- data.frame(tab = "testflow", section = "flow", groupby = "attempt", group = group,
                                           metric = "ability", stat = paste0("step", int_text(steps$step[i])),
                                           value = r6(steps$esttheta[i]), stringsAsFactors = FALSE)
      out[[length(out) + 1]] <- data.frame(tab = "testflow", section = "flow", groupby = "attempt", group = group,
                                           metric = "se", stat = paste0("step", int_text(steps$step[i])),
                                           value = r6(steps$se[i]), stringsAsFactors = FALSE)
    }
  }
  do.call(rbind, out)
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
  # By item count class, from the administered count (#113): the item classes table.
  classes <- item_class(s$items)
  for (class in c("0", "1", "2", "3-4", "5+", "unknown")) {
    rows <- s[classes == class, , drop = FALSE]
    if (nrow(rows) > 0) {
      out[[length(out) + 1]] <- figure_rows("subscales", "itemclass", "itemclass", class, "local", local_summary(rows))
    }
  }
  out[[length(out) + 1]] <- coverage_figures(p)
  do.call(rbind, out)
}

# Robustness: per cell (design coordinates, pool variant and strength) the global and local outcomes, and their
# difference to the cell with the ideal pool and the same tier, strategy, model, stratum and severity.
robustness_figures <- function(p, a) {
  a$rcell <- paste(txt(a$tier), txt(a$strategy), txt(a$model), txt(a$stratum), txt(a$severity), txt(a$variant),
                   php_number_text(a$strength), sep = "|")
  a$reference <- paste(txt(a$tier), txt(a$strategy), txt(a$model), txt(a$stratum), txt(a$severity), sep = "|")
  # Valid scale results of valid sittings only, whatever the validity filter (robustness_analysis, #112).
  s <- p$tables$estimated_profiles
  s <- s[s$attemptid %in% a$attemptid & !is.na(s$valid) & s$valid == 1, , drop = FALSE]
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
        testflow_figures(p, a), flow_figures(p, a), detection_figures(p))
}
