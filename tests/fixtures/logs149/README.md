# Synthetic server logs for the historical log scan (#149)

Made up, not taken from any server. Each file mimics a format the scan reads; every secret value starts with
`S3CR3T149`, so a report or a redacted copy that still holds one is caught by a plain search. `expected.json` is what
`cli/scan_logs_for_secrets.php --dir=… --all --json` must count. The release CI scans this directory and uploads the
report (job "Issue 149 — scan of existing logs").
