#!/usr/bin/env Rscript
# Re-analysis of a CAT experiment suite research data package (#157).
#
#   Rscript reanalysis/run_all.R [package] [--out=DIR]
#
# package: the unpacked package directory (default: the directory above this script) or the ZIP itself.
# Base R only (>= 4.1): no packages, no network, no Moodle, no database. Writes into DIR (default: <package>/reanalysis-out):
#   figures.csv      every figure of "4. Results" this project recomputes, keyed like reference/dashboard.csv
#   comparison.csv   each figure against the dashboard's: expected, actual, difference, tolerance, PASS/FAIL/NOT_REPRODUCED
#   coverage.csv     what is recomputed from which columns, and what is not
#   plotdata/*.csv   every point of the plots; plots/*.png where a PNG device exists
#   report.txt       the summary, also printed
# Exit status 0 when nothing FAILs, 1 otherwise, 2 when the package cannot be read.

args <- commandArgs(trailingOnly = TRUE)
script <- sub("^--file=", "", grep("^--file=", commandArgs(trailingOnly = FALSE), value = TRUE)[1])
here <- dirname(normalizePath(if (is.na(script)) "reanalysis/run_all.R" else script))
for (file in c("io.R", "stats.R", "figures.R", "detection.R", "compare.R", "plots.R", "coverage.R")) {
  source(file.path(here, "R", file), local = FALSE)
}

if (getRversion() < "4.1.0") {
  stop("R 4.1 or later is needed (found ", R.version.string, ").")
}
outarg <- grep("^--out=", args, value = TRUE)
positional <- setdiff(args, outarg)
package <- if (length(positional) > 0) positional[1] else dirname(here)
if (grepl("\\.zip$", package, ignore.case = TRUE)) {
  unpacked <- file.path(tempdir(), "package")
  utils::unzip(package, exdir = unpacked)
  package <- unpacked
}
out <- if (length(outarg) > 0) sub("^--out=", "", outarg[1]) else file.path(package, "reanalysis-out")
dir.create(out, showWarnings = FALSE, recursive = TRUE)

p <- tryCatch(read_package(package), error = function(e) {
  message("The package cannot be read: ", conditionMessage(e))
  quit(status = 2)
})
reference <- read_text_csv(file.path(package, "reference", "dashboard.csv"))
reference$value <- as_number(reference$value)

ours <- all_figures(p)
if (is.null(ours)) {
  ours <- data.frame(tab = character(0), section = character(0), groupby = character(0), group = character(0),
                     metric = character(0), stat = character(0), value = numeric(0))
}
utils::write.csv(ours, file.path(out, "figures.csv"), row.names = FALSE, na = "")
comparison <- rbind(package_checks(p$checks), compare_figures(reference, ours))
utils::write.csv(comparison, file.path(out, "comparison.csv"), row.names = FALSE, na = "")
coverage <- coverage_matrix()
utils::write.csv(coverage, file.path(out, "coverage.csv"), row.names = FALSE, na = "")
a <- population(p)
if (nrow(a) > 0) write_plot_data(out, a, p)

counts <- table(factor(comparison$status, levels = c("PASS", "FAIL", "NOT_REPRODUCED")))
bytab <- table(comparison$tab, factor(comparison$status, levels = c("PASS", "FAIL", "NOT_REPRODUCED")))
lines <- c(
  "CAT experiment suite: re-analysis in R",
  paste0("R: ", R.version.string),
  paste0("Package: ", normalizePath(package)),
  paste0("Counted sittings: ", nrow(a), " of ", nrow(p$tables$attempts),
         " (filter validity: ", if ("validity" %in% names(p$filter)) p$filter[["validity"]] else "valid", ")"),
  paste0("Figures compared: ", sum(counts), " - PASS ", counts[["PASS"]], ", FAIL ", counts[["FAIL"]],
         ", NOT_REPRODUCED ", counts[["NOT_REPRODUCED"]]),
  "",
  "By tab (PASS / FAIL / NOT_REPRODUCED):",
  paste0("  ", rownames(bytab), ": ", bytab[, "PASS"], " / ", bytab[, "FAIL"], " / ", bytab[, "NOT_REPRODUCED"]))
failed <- comparison[comparison$status == "FAIL", , drop = FALSE]
if (nrow(failed) > 0) {
  lines <- c(lines, "", "FAIL (first 50):", utils::capture.output(print(utils::head(failed, 50), row.names = FALSE)))
}
writeLines(lines, file.path(out, "report.txt"))
writeLines(lines)
quit(status = if (counts[["FAIL"]] > 0) 1 else 0)
