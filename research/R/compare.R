# The dashboard's figures (reference/dashboard.csv) against the ones recomputed here (#157 AK5).
#
# Status per figure:
#   PASS            both missing, or equal within the tolerance
#   FAIL            one missing, a difference above the tolerance, a figure here the dashboard does not show, or a
#                   figure the dashboard shows that this project should reproduce and does not
#   NOT_REPRODUCED  a figure family this version does not recompute (coverage matrix, with the reason)
#
# Tolerance: counts must be equal. Every other figure is rounded to 6 decimals on both sides; the two sum in a different
# order (R accumulates in extended precision) and round half-way cases differently (PHP rounds half away from zero
# after pre-rounding, R to the nearest representable), so one unit in the 6th decimal can differ at a rounding
# boundary: 1.5e-6. Percentages are not rounded by the dashboard: 1e-9 relative. Never more.

# Figures this version does not recompute: none since 0.7.71 (#157). Kept as the place where such a figure would be
# named, with its reason — never silently passed.
not_reproduced <- data.frame(tab = character(0), metric = character(0), reason = character(0), stringsAsFactors = FALSE)

tolerance_of <- function(stat, metric) {
  ifelse(stat %in% c("count", "n", "units", "withestimate", "itemsused", "unused", "nwithse") |
           (metric %in% c("spreadall", "spreadvalid") & stat == "n") |
           metric %in% c("verdict", "sittings", "runs", "people") |
           (metric == "localn"), 0,
         ifelse(stat == "percent", NA, 1.5e-6))
}

figure_key <- function(df) paste(df$tab, df$section, df$groupby, df$group, df$metric, df$stat, sep = "\u001f")

compare_figures <- function(reference, ours) {
  reference$key <- figure_key(reference)
  ours$key <- figure_key(ours)
  keys <- union(reference$key, ours$key)
  ref <- reference[match(keys, reference$key), ]
  our <- ours[match(keys, ours$key), ]
  out <- data.frame(
    tab = ifelse(is.na(ref$tab), our$tab, ref$tab), section = ifelse(is.na(ref$tab), our$section, ref$section),
    groupby = ifelse(is.na(ref$tab), our$groupby, ref$groupby), group = ifelse(is.na(ref$tab), our$group, ref$group),
    metric = ifelse(is.na(ref$tab), our$metric, ref$metric), stat = ifelse(is.na(ref$tab), our$stat, ref$stat),
    expected = ref$value, actual = our$value, stringsAsFactors = FALSE)
  out$inreference <- !is.na(ref$tab)
  out$inR <- !is.na(our$tab)
  out$difference <- out$actual - out$expected
  tol <- tolerance_of(out$stat, out$metric)
  rel <- out$stat == "percent"
  out$tolerance <- ifelse(rel, 1e-9 * pmax(1, abs(out$expected)), tol)
  out$status <- rep("FAIL", nrow(out))
  bothna <- out$inreference & out$inR & is.na(out$expected) & is.na(out$actual)
  within <- out$inreference & out$inR & !is.na(out$expected) & !is.na(out$actual) &
    abs(out$difference) <= out$tolerance
  out$status[bothna | within] <- "PASS"
  out$note <- rep("", nrow(out))
  out$note[out$inreference & !out$inR] <- "shown by the dashboard, not recomputed"
  out$note[!out$inreference & out$inR] <- "recomputed, not shown by the dashboard"
  out$note[out$inreference & out$inR & xor(is.na(out$expected), is.na(out$actual))] <- "missing on one side"
  for (i in seq_len(nrow(not_reproduced))) {
    hit <- out$tab == not_reproduced$tab[i] & (not_reproduced$metric[i] == "*" | out$metric == not_reproduced$metric[i]) &
      out$inreference & !out$inR
    out$status[hit] <- "NOT_REPRODUCED"
    out$note[hit] <- not_reproduced$reason[i]
  }
  out[order(out$tab, out$section, out$group, out$metric, out$stat), c("tab", "section", "groupby", "group", "metric",
    "stat", "expected", "actual", "difference", "tolerance", "status", "note")]
}

# The package itself: every table as many rows as the manifest says, with the md5 it says.
package_checks <- function(checks) {
  rows <- data.frame(tab = "package", section = "tables", groupby = "table", group = checks$table, metric = "rows",
                     stat = "count", expected = checks$rows_expected, actual = checks$rows_read,
                     difference = checks$rows_read - checks$rows_expected, tolerance = 0,
                     status = ifelse(checks$rows_read == checks$rows_expected, "PASS", "FAIL"), note = "",
                     stringsAsFactors = FALSE)
  md5 <- data.frame(tab = "package", section = "tables", groupby = "table", group = checks$table, metric = "md5",
                    stat = "match", expected = 1, actual = as.numeric(checks$md5_read == checks$md5_expected),
                    difference = as.numeric(checks$md5_read == checks$md5_expected) - 1, tolerance = 0,
                    status = ifelse(checks$md5_read == checks$md5_expected, "PASS", "FAIL"),
                    note = ifelse(checks$md5_read == checks$md5_expected, "", "the file is not the one the manifest names"),
                    stringsAsFactors = FALSE)
  rbind(rows, md5)
}
