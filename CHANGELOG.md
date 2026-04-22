# Changelog

All notable changes to the ErrorVault Laravel package will be documented in this file.

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
