# VictoriaLogs Integration

This Pterodactyl Panel installation includes VictoriaLogs integration for centralized logging.

## Environment Variables

Add these variables to your `.env` file or Railway environment:

```env
LOG_CHANNEL=stack
LOG_STACK=daily,victorialogs

VICTORIALOGS_URL=http://victorialogs.railway.internal:9482
VICTORIALOGS_USER=Ongamecloud
VICTORIALOGS_PASS=your_password
VICTORIALOGS_SERVICE=pterodactyl
VICTORIALOGS_ENVIRONMENT=production
VICTORIALOGS_BATCH_SIZE=100
VICTORIALOGS_FLUSH_INTERVAL=5
```

## Configuration

- **URL**: VictoriaLogs endpoint (automatically prefixed with `http://` if missing)
- **USER/PASS**: HTTP Basic Authentication credentials
- **SERVICE**: Service name for log filtering (default: `pterodactyl`)
- **ENVIRONMENT**: Environment tag (e.g., `production`, `staging`)
- **BATCH_SIZE**: Number of logs to buffer before sending (default: 100)
- **FLUSH_INTERVAL**: Seconds between automatic flushes (default: 5)

## Usage

Logs are automatically sent to VictoriaLogs when using Laravel's logging:

```php
Log::info('Server created', ['server_id' => $server->id]);
Log::error('Failed to create server', ['error' => $exception->getMessage()]);
```

## Disable VictoriaLogs

To disable VictoriaLogs logging, update:

```env
LOG_STACK=daily
```

Or set:

```env
LOG_CHANNEL=daily
```
