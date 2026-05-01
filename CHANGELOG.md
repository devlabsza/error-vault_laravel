# Changelog

All notable changes to the ErrorVault Laravel package will be documented in this file.

## [1.4.1] - 2026-05-02

### Fixed
- **Memory reporting was meaningless.** Health reports were reading `memory_get_usage(true)` against `ini_get('memory_limit')`, which on a freshly-booted artisan command (the scheduler tick) is always ~3-5%. The dashboard's "Memory" tile never moved off green, so warning / critical thresholds were effectively dead.

  v1.4.1 reads system RAM directly:
  - Linux: parses `/proc/meminfo` (prefers `MemAvailable`, falls back to `MemFree + Buffers + Cached`).
  - macOS: parses `vm_stat` + `sysctl hw.memsize` (Active + Wired + Compressed = used).
  - Last-resort fallback: PHP process memory (only when both above are unavailable, e.g. exotic hosts with `shell_exec` disabled and no `/proc/meminfo`).

  PHP process memory is still in the payload as `memory.php_usage` / `memory.php_peak` / `memory.php_limit` so a runaway worker still surfaces. `memory.peak` / `memory.peak_formatted` retained as aliases so dashboards built against earlier versions don't break. New `memory.source` field reports which method was used (`proc_meminfo` / `vm_stat` / `php_fallback`).

## [1.4.0] - 2026-04-22

### Added
- **Backups.** Laravel apps can now be backed up to the ErrorVault portal via the same chunked multipart upload flow the WordPress plugin uses.
  - New config block under `errorvault.backup` (enabled, poll_interval, chunk_size_mb, tmp_path, include_storage, exclude_paths).
  - `php artisan errorvault:run-backup` polls `/api/v1/backups/pending` and, if a backup has been requested from the portal dashboard, dumps the database, zips the project, and uploads in chunks.
  - Database dump prefers `mysqldump` (fast for big DBs, uses `--defaults-extra-file` so the password never appears in `ps`) and falls back to a pure-PHP export when mysqldump isn't installed or `exec`/`shell_exec` is disabled.
  - Archive excludes `vendor`, `node_modules`, `.git`, build caches, logs, and `.env` by default. `storage/app` excluded unless `ERRORVAULT_BACKUP_INCLUDE_STORAGE=true`.
  - Chunked upload with 3-retry exponential backoff per part, sha256 checksum, fail/abort endpoints on error paths.
  - Service provider schedules the artisan command on the configured interval when both `ERRORVAULT_ENABLED=true` and `ERRORVAULT_BACKUP_ENABLED=true`. Uses `->runInBackground()` safely (it's a command, not a closure).

### Notes
- Running backups requires a writable `storage/app/errorvault-backup` (or wherever `ERRORVAULT_BACKUP_TMP_PATH` points) and the `ZipArchive` PHP extension (standard on most hosts).

## [1.3.4] - 2026-04-22

### Fixed
- **Endpoint derivation tolerates missing `/errors` suffix.** Previously the package built `/verify`, `/ping`, `/stats`, `/health/report`, `/health/alert` by string-replacing `/errors` in the configured endpoint. If the user's `ERRORVAULT_API_ENDPOINT` didn't contain `/errors` (e.g. `https://error-vault.com/api/v1`), every derived path silently collapsed to the base URL — producing 404s on health/verify/ping and silently mis-routing batch sends. The new `ErrorVault::endpointFor()` helper strips an optional trailing `/errors` and derives siblings consistently, so both `/api/v1` and `/api/v1/errors` now work.

## [1.3.3] - 2026-04-22

### Changed
- `errorvault:health-report` now prints the actual failure reason (HTTP status + response body preview, or the exception message) instead of the generic "Check your configuration and connection." `HealthMonitor::getLastError()` is public for programmatic access.

## [1.3.2] - 2026-04-22

### Fixed
- **Scheduler crashed on every heartbeat tick.** `$schedule->call(Closure)` does not support `->runInBackground()` — Laravel throws "Scheduled closures can not be run in the background." The heartbeat was calling `runInBackground()` on a closure, crashing the scheduler for every site with ErrorVault enabled. The call to the portal is already non-blocking via Guzzle, so removing `runInBackground()` has no performance impact.

## [1.3.1] - 2026-04-17

### Changed
- **Default API endpoint is now hardcoded** to `https://error-vault.com/api/v1/errors`. Users only need to set `ERRORVAULT_API_TOKEN` — no more silent "empty endpoint means reporting disabled" trap. Override with `ERRORVAULT_API_ENDPOINT` when self-hosting.

### Added
- **Query-param and context scrubbing**: any key whose name matches `config('errorvault.scrub_keys')` is replaced with `[FILTERED]` before errors are sent. Catches common secrets (`token`, `api_key`, `authorization`, `password`, `cookie`, etc.) in the request URL and in error context arrays. Extend the list in your config.
- **`errorvault:test-error` artisan command**: dispatches a sample error so you can verify the whole pipeline end-to-end, with `--severity` and `--message` options.

## [1.3.0] - 2026-01-30

### Added
- **Heartbeat/Ping System**: Automatic lightweight ping every 5 minutes to keep sites active in the portal
- **Connection Failure Tracking**: Comprehensive logging of all API connection failures
  - Tracks last 20 failures with timestamps and error messages
  - Consecutive failure counter
  - Automatic admin notification after 5 consecutive failures (once per day)
  - Failures cleared on successful connection
- **New Artisan Commands**:
  - `errorvault:test-connection` - Test connectivity to ErrorVault portal
  - `errorvault:diagnostics` - Display comprehensive diagnostics and status
  - `errorvault:diagnostics --clear-failures` - Clear connection failure logs
- **New Public Methods**:
  - `sendPing($blocking)` - Send heartbeat to portal
  - `testConnection()` - Test connection with detailed results
  - `getConnectionFailures()` - Retrieve failure history
  - `getConsecutiveFailures()` - Get consecutive failure count
  - `clearFailureLog()` - Clear all failure logs
- **Diagnostics Dashboard**: View configuration status, health monitoring settings, and connection history
- **Enhanced Health Monitoring**: Better error tracking for health report failures

### Changed
- Updated package version to 1.3.0
- Improved error handling in `send()` method to track failures
- Enhanced `sendHealthReport()` to provide better error logging

### Fixed
- Connection failures are now properly logged and tracked
- Sites no longer appear offline due to missing heartbeat

## [1.2.0] - 2025-XX-XX

### Added
- Comprehensive server health monitoring
- CPU load tracking and alerts
- Memory usage monitoring
- Request rate tracking for DDoS detection
- Traffic spike detection
- Disk space monitoring
- Automatic health reports every 5 minutes (configurable)
- Health alert system with cooldown periods

## [1.1.0] - 2025-XX-XX

### Added
- Enhanced error context with detailed server information
- Memory usage tracking
- CPU core detection
- Load average reporting
- Execution time tracking
- Database query counting

## [1.0.0] - 2025-XX-XX

### Added
- Initial release
- Automatic exception reporting
- Manual error reporting
- Batch error sending
- Exception filtering and ignoring
- Severity level mapping
- API verification
- Statistics retrieval
