# What of "4. Results" this project recomputes, from which columns, by which function — and what it does not (#157
# DoD1). Written to coverage.csv with every run; the comparison report counts the figures per family.

coverage_matrix <- function() {
  m <- function(tab, part, figures, fn, columns, status, note = "") {
    data.frame(tab = tab, part = part, figures = figures, r_function = fn, columns = columns, status = status,
               note = note, stringsAsFactors = FALSE)
  }
  rbind(
    m("overview", "Key figures", "test length, SE, bias (n, mean, SD, SE, 95 % CI, median, quartiles, min, max, units), RMSE, correlation, stop success, concentration (Gini), runtime",
      "overview_figures", "attempts.nitems, se, error, truetheta, esttheta, runtimems, stopreached; steps.questionid; items; analysis_population",
      "compared"),
    m("overview", "Test length against precision", "points", "write_plot_data", "attempts.nitems, se", "plot data",
      "All points; the dashboard samples at most 2 000."),
    m("overview", "Strategy comparison", "runs, sittings, people, test length, SE, bias, RMSE, stop success per strategy",
      "overview_figures", "attempts", "compared"),
    m("overview", "Experimental cells", "sittings, test length, SE, bias, RMSE, correlation, stop success, runtime per cell",
      "overview_figures", "attempts.tier, strategy, model, variant, stratum, severity", "compared"),
    m("overview", "Simulated people", "true abilities of the people", "-", "twins.abilityglobal, truth_profiles",
      "data only", "The table is the data; no figure is derived from it."),
    m("global", "Estimate against truth, error against truth", "bias, RMSE, correlation; points; outside the scale range",
      "global_figures, write_plot_data", "attempts.truetheta, esttheta, error, abilitymin, abilitymax", "compared"),
    m("global", "Exposure", "Gini, HHI, largest and mean rate, share above twice the mean, items used and unused; curve",
      "global_figures, write_plot_data", "steps.questionid of counted sittings; items (pool size)", "compared"),
    m("rawdata", "Raw data", "the observations", "-", "attempts", "data only", "The tab shows the rows; attempts holds them."),
    m("subscales", "Local recovery", "n, bias, RMSE, MAE, correlation of deviations, mean SE, within 1 and 2 SE",
      "subscale_figures", "estimated_profiles.error, truedelta, estdelta, localse, within1se, within2se, valid, items",
      "compared"),
    m("subscales", "By category, by subscale", "the same per category and per subscale key", "subscale_figures",
      "estimated_profiles.category, subscale", "compared"),
    m("subscales", "Scale coverage, spread, item classes, item plots", "counting chain of scale results, spread per strategy, item count classes",
      "-", "estimated_profiles.items, itemssource, itemclass", "not reproduced", "Not in this version."),
    m("detection", "Mode-specific detection", "every mode against truth and baselines (paired, common scales, unpaired): sittings, pairs, scales, true targets, recall, precision, F1, bias, RMSE, MAE, correlation, target RMSE, Spearman, top-1, top-3, nDCG@3, mean difference",
      "detection_figures", "attempts (order, strategy, valid, twin, design), truth_profiles, estimated_profiles, runs.subscalemaps",
      "compared"),
    m("detection", "Scale results with an administered item", "scaleswithitems", "-", "-", "not reproduced",
      "Includes scale results without an estimate, which the package does not hold; NOT_REPRODUCED."),
    m("robustness", "Global outcomes and their differences to the ideal pool", "RMSE, bias, correlation, SE, test length, stop success, runtime and deltas per cell",
      "robustness_figures", "attempts.variant, strength", "compared"),
    m("robustness", "Local outcomes", "local n, RMSE, bias, within 1 and 2 SE and deltas per cell", "robustness_figures",
      "estimated_profiles", "compared"),
    m("robustness", "Ranking outcomes", "Spearman, top-3 agreement, nDCG@3 per cell (means over the sittings)",
      "sitting_ranking, robustness_figures", "estimated_profiles.truedelta, estdelta; attempts.strategy", "compared"),
    m("testflow", "Feasibility of the precision target", "reached, stopped early, budget exhausted, missed, unknown",
      "testflow_figures", "attempts.se, nitems, stopreached; runs.setarget, globalmaxitems", "compared"),
    m("testflow", "One sitting step by step", "estimate, SE, information per step", "-", "steps", "data only",
      "steps holds every step of every sitting."),
    m("testflow", "Comparison of flows", "twins' flows side by side", "-", "steps", "not reproduced", "Not in this version.")
  )
}
