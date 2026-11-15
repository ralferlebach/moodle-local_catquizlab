# The plots of "4. Results" from the raw tables (#157 AK6): every point written as a CSV a reader can plot with any
# tool (ggplot2-friendly, one row per point, no jitter), and drawn with base R graphics where a PNG device exists.
#
# The dashboard draws at most 2 000 points per plot, sampled; these are all of them. Test length is an integer and
# stays one: no jitter, no offset.

write_plot_data <- function(out, a, p) {
  dir.create(file.path(out, "plotdata"), showWarnings = FALSE, recursive = TRUE)
  both <- !is.na(a$truetheta) & !is.na(a$esttheta)
  recovery <- data.frame(attemptid = a$attemptid[both], runid = a$runid[both], strategy = a$strategy[both],
                         truetheta = a$truetheta[both], esttheta = a$esttheta[both], error = a$error[both])
  utils::write.csv(recovery, file.path(out, "plotdata", "global_recovery.csv"), row.names = FALSE)
  withse <- !is.na(a$se)
  lengthse <- data.frame(attemptid = a$attemptid[withse], strategy = a$strategy[withse], nitems = a$nitems[withse],
                         se = a$se[withse])
  utils::write.csv(lengthse, file.path(out, "plotdata", "overview_length_se.csv"), row.names = FALSE)
  ex <- exposure(p, a)
  rates <- sort(c(ex$rates, rep(0, max(0, (if (is.na(ex$poolsize)) 0 else ex$poolsize) - length(ex$rates)))),
                decreasing = TRUE)
  curve <- data.frame(rank = seq_along(rates), rate = rates)
  utils::write.csv(curve, file.path(out, "plotdata", "global_exposure_curve.csv"), row.names = FALSE)
  s <- subscale_rows(p, a)
  utils::write.csv(s[, intersect(c("attemptid", "runid", "strategy", "category", "subscale", "truedelta", "estdelta",
                                   "error", "localse"), names(s))],
                   file.path(out, "plotdata", "subscales_deltas.csv"), row.names = FALSE)

  if (!isTRUE(capabilities("png"))) {
    message("No PNG device: point data written, plots not drawn.")
    return(invisible(FALSE))
  }
  draw <- function(name, expr) {
    grDevices::png(file.path(out, "plots", paste0(name, ".png")), width = 1000, height = 750, res = 120)
    on.exit(grDevices::dev.off())
    expr
  }
  dir.create(file.path(out, "plots"), showWarnings = FALSE, recursive = TRUE)
  draw("global_recovery", {
    plot(recovery$truetheta, recovery$esttheta, pch = 16, cex = 0.5, xlab = "True ability (logit)",
         ylab = "Estimated ability (logit)", main = "Estimate against truth")
    abline(0, 1, lty = 2)
  })
  draw("global_error", {
    plot(recovery$truetheta, recovery$error, pch = 16, cex = 0.5, xlab = "True ability (logit)",
         ylab = "Estimate − truth (logit)", main = "Error against truth")
    abline(h = 0, lty = 2)
  })
  draw("overview_length_se", {
    plot(lengthse$nitems, lengthse$se, pch = 16, cex = 0.5, xlab = "Test length (questions)", ylab = "Final SE",
         main = "Test length against precision", ylim = c(0, max(c(lengthse$se, 0.1), na.rm = TRUE)))
  })
  draw("global_exposure_curve", {
    plot(curve$rank, curve$rate, type = "s", xlab = "Item rank", ylab = "Exposure rate", main = "Exposure curve",
         ylim = c(0, max(c(curve$rate, 0.01))))
  })
  invisible(TRUE)
}
