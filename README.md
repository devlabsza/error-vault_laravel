# ErrorVault Laravel Package

Send PHP exceptions to your ErrorVault dashboard for centralized error monitoring.

## Installation

```bash
composer require errorvault/laravel
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=errorvault-config
```

Add these environment variables to your `.env` file:

```env
ERRORVAULT_ENABLED=true
ERRORVAULT_API_ENDPOINT=https://your-errorvault-portal.com/api/v1/errors
ERRORVAULT_API_TOKEN=your-site-api-token
```

## Usage

Once configured, exceptions are automatically reported to ErrorVault. You can also manually report errors:

```php
use ErrorVault\Laravel\Facades\ErrorVault;

// Report a custom error
ErrorVault::reportError('Something went wrong', 'warning', __FILE__, __LINE__);

// Report with additional context
ErrorVault::reportError(
    'Payment failed',
    'critical',
    __FILE__,
    __LINE__,
    ['order_id' => $orderId, 'amount' => $amount]
);

// Manually report an exception
try {
    // risky operation
} catch (\Exception $e) {
    ErrorVault::report($e, ['custom' => 'context']);
}
```

## Verify Connection

```php
use ErrorVault\Laravel\Facades\ErrorVault;

$result = ErrorVault::verify();

if ($result['success']) {
    echo "Connected to: " . $result['data']['site_name'];
} else {
    echo "Error: " . $result['error'];
}
```

## Get Statistics

```php
$stats = ErrorVault::stats();

if ($stats) {
    echo "Total errors: " . $stats['total_errors'];
    echo "New errors: " . $stats['new_errors'];
}
```

## Configuration Options

| Option | Description | Default |
|--------|-------------|---------|
| `enabled` | Enable/disable reporting | `false` |
| `api_endpoint` | Your ErrorVault API endpoint | `''` |
| `api_token` | Your site's API token | `''` |
| `send_immediately` | Send errors immediately vs batch | `true` |
| `ignore` | Exception classes to ignore | See config |
| `ignore_patterns` | Message patterns to ignore | `[]` |
| `severity_map` | Map exceptions to severities | `[]` |

## Ignoring Exceptions

Add exceptions to the `ignore` array in `config/errorvault.php`:

```php
'ignore' => [
    \App\Exceptions\IgnoredException::class,
],
```

Or use patterns to ignore by message content:

```php
'ignore_patterns' => [
    'deprecated',
    'cache miss',
],
```

## License

MIT
