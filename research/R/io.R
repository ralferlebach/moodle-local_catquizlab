# Reading a research data package (#156) with base R only.
#
# Every table is read as text, then converted by the codebook's type: numbers, integers, booleans and timestamps
# become numeric with an empty cell as NA (never 0), strings stay strings with an empty cell as "". Each table's
# row count and md5 are checked against manifest-tables.csv; a mismatch is reported, not repaired.

read_text_csv <- function(path) {
  utils::read.csv(path, colClasses = "character", na.strings = character(0), check.names = FALSE,
                  stringsAsFactors = FALSE, encoding = "UTF-8")
}

numeric_types <- c("integer", "number", "boolean", "timestamp")

as_number <- function(x) {
  out <- suppressWarnings(as.numeric(x))
  # "NaN", "Inf" and "-Inf" are written as such and read back as such; an empty cell is NA.
  out[x == ""] <- NA_real_
  out
}

read_package <- function(dir) {
  tables_csv <- read_text_csv(file.path(dir, "manifest-tables.csv"))
  codebook <- read_text_csv(file.path(dir, "codebook.csv"))
  filter <- if (file.exists(file.path(dir, "filter.csv"))) read_text_csv(file.path(dir, "filter.csv")) else
    data.frame(key = character(0), value = character(0))
  tables <- list()
  checks <- list()
  for (i in seq_len(nrow(tables_csv))) {
    name <- tables_csv$table[i]
    path <- file.path(dir, tables_csv$file[i])
    md5 <- unname(tools::md5sum(path))
    raw <- read_text_csv(path)
    types <- codebook[codebook$table == name, c("name", "type")]
    for (column in names(raw)) {
      type <- types$type[types$name == column]
      if (length(type) == 1 && type %in% numeric_types) {
        raw[[column]] <- as_number(raw[[column]])
      }
    }
    tables[[name]] <- raw
    checks[[length(checks) + 1]] <- data.frame(table = name, rows_expected = as.numeric(tables_csv$rows[i]),
                                               rows_read = nrow(raw), md5_expected = tables_csv$md5[i], md5_read = md5,
                                               stringsAsFactors = FALSE)
  }
  list(tables = tables, codebook = codebook, filter = stats::setNames(filter$value, filter$key),
       checks = do.call(rbind, checks), dir = dir)
}

# An empty string where a text cell is missing, as the dashboard keys its groups.
txt <- function(x) ifelse(is.na(x), "", as.character(x))

# Integers as the dashboard writes them into keys: no decimals, no exponent.
int_text <- function(x) ifelse(is.na(x), "0", trimws(formatC(x, format = "d", big.mark = "")))

# A number as PHP writes a float into a key ((string) $float, 14 significant digits, no trailing zeros); "" for NA.
php_number_text <- function(x) {
  ifelse(is.na(x), "", trimws(formatC(x, digits = 14, format = "g")))
}
