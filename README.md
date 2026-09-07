# glpzzz/yii2-otel-log

OpenTelemetry-aligned structured logging for Yii2: a single-line JSON (NDJSON) log target for a
log shipper (Vector / Fluent Bit) to tail into OpenObserve, plus request-trace propagation so a
call chain spanning several services stays joinable.

This is the cross-service logging contract for a distributed system (Yii2 + Yii3/WordPress +
.NET). Every service emits the same key set below and forwards the same `trace.id`.

## Install

```bash
composer require glpzzz/yii2-otel-log
```

## Wire it up

### 1. Boot the tracer at the entry point

Before the Yii application is constructed, in `web/index.php` (and the console `yii` script):

```php
require __DIR__ . '/../../vendor/autoload.php';
// ...
\glpzzz\otellog\Tracker::boot();

(new yii\web\Application($config))->run();
```

`boot()` adopts an inbound `X-Request-ID` header (or the `X_REQUEST_ID` env var for
console/queue callers) when it matches `^[0-9A-Za-z._-]{8,128}$`, otherwise it mints a
32-character hex token, and freezes it into the `TRACKING_REQUEST_UUID` constant.

### 2. Add the log target

```php
'components' => [
    'log' => [
        'targets' => [
            [
                'class' => \glpzzz\otellog\StandardJsonTarget::class,
                'levels' => ['error', 'warning', 'info'],
                'logFile' => '@runtime/logs/otel.log',
                'enableRotation' => true,
                'serviceName' => getenv('SERVICE_NAME') ?: 'my-app',
                // 'accountIdResolver' => static fn () => Yii::$app->tenant->id ?? null,
                // 'userIdResolver'    => static fn () => Yii::$app->user->id ?? null,
            ],
        ],
    ],
],
```

Existing targets are unaffected — add this one alongside them.

### 3. Propagate the trace on outgoing calls

```php
$client = new \yii\httpclient\Client([
    'baseUrl' => $host,
    'as trace' => \glpzzz\otellog\httpclient\TraceHeaderBehavior::class,
]);
```

or merge `\glpzzz\otellog\Tracker::outgoingHeaders()` into a `requestConfig` you already build.

## Schema

One JSON object per line. Dot-notation keys are literal (not nested), except `context`:

| key | value |
|---|---|
| `timestamp` | ISO 8601 UTC, `Y-m-d\TH:i:s.v\Z` |
| `log.level` | `INFO` / `WARN` / `ERROR` / `DEBUG` |
| `service.name` | static identifier for this repo (`serviceName` / `SERVICE_NAME`) |
| `service.version` | running git commit — `SERVICE_VERSION` env, else `@root/VERSION` first line, else `unknown` |
| `service.environment` | `SERVICE_ENVIRONMENT`, else `YII_ENV` mapped (`prod`→`production`, `stage`→`staging`, `dev`→`develop`) |
| `account.id` | `accountIdResolver()` — tenant/client scope, nullable |
| `trace.id` | `TRACKING_REQUEST_UUID` |
| `message` | the text message (or the `message` key of an array payload) |
| `error.kind` | the log category (2nd arg to `Yii::error()` etc., usually `__METHOD__`) — always, exception or not |
| `error.message` | exception message; absent when the call carried no exception |
| `error.stack_trace` | full exception trace as one JSON string (`\n`-escaped); absent when the call carried no exception |
| `http.request.ip` | client IP for web requests, `null` on console |
| `user.id` | `userIdResolver()`, `null` for guests/console |
| `context` | nested object: developer-supplied array data, plus (web) `http.request.method/url/query/body`, `http.user_agent`, `http.referer` |

Array payloads passed to `Yii::info()/warning()/error()` are unpacked into `context`; a legacy
`serialize([...])` string payload is unpacked too. Pass exception detail as plain strings —
`error.message` and `error.stack_trace` keys are promoted onto the top-level OTel fields:

```php
Yii::error([
    'message' => 'Failed to resize image',
    'error.message' => $e->getMessage(),
    'error.stack_trace' => (string) $e,
    'image' => $path,
], __METHOD__);
```

`error.kind` is always the log category — never taken from the payload. A Throwable passed as
the whole payload (`Yii::error($e, $category)`) is also handled, and then `message` equals
`$e->getMessage()`. Keys named like secrets (`password`, `token`, `secret`, `_csrf`, …) are
masked to `***` anywhere in `context`.

## Environment variables

| var | purpose |
|---|---|
| `SERVICE_NAME` | `service.name` (or set `serviceName` in config) |
| `SERVICE_VERSION` | running commit hash; a deploy step may instead write a `VERSION` file at the project root (`@root/VERSION`) |
| `SERVICE_ENVIRONMENT` | optional override of the `YII_ENV` mapping |
| `X_REQUEST_ID` | optional inbound trace id for console / queue workers |

## Sample Vector source

```toml
[sources.otel_app]
type = "file"
include = ["/var/www/*/runtime/logs/otel.log", "/var/www/*/*/runtime/logs/otel.log"]
read_from = "end"

[transforms.otel_parse]
type = "remap"
inputs = ["otel_app"]
source = '. = parse_json!(.message)'

[sinks.openobserve]
type = "http"
inputs = ["otel_parse"]
uri = "https://openobserve.example.com/api/${OO_ORG}/${OO_STREAM}/_json"
encoding.codec = "json"
# route by account.id / service.name / service.environment as your OO org/stream layout requires
```

## Requirements

- PHP >= 8.1
- Yii 2.0.14+
- `yiisoft/yii2-httpclient` ^2.0 (only for `TraceHeaderBehavior`)
