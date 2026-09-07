<?php

declare(strict_types=1);

namespace glpzzz\otellog;

use Closure;
use stdClass;
use Throwable;
use Yii;
use yii\helpers\VarDumper;
use yii\log\FileTarget;
use yii\web\Request as WebRequest;

/**
 * A Yii2 log target that writes one JSON object per line (NDJSON) using OpenTelemetry-style
 * dot-notation keys, ready for a log shipper (Vector / Fluent Bit) to tail into OpenObserve.
 *
 * Extends {@see FileTarget} for its file handling, rotation and flock-safe append; only
 * {@see formatMessage()} is replaced. Native arrays passed to `Yii::info()/warning()/error()`
 * land in a nested `context` object instead of being flattened; a legacy `serialize([...])`
 * payload is transparently unpacked too. `error.message` / `error.stack_trace` / `error.kind`
 * keys in the array are promoted onto the top-level OTel error fields (call sites pass the
 * exception detail as plain strings, e.g.
 * `['message' => 'failed', 'error.message' => $e->getMessage(), 'error.stack_trace' => (string) $e]`);
 * a Throwable passed as the payload itself is also supported.
 *
 * @see https://opentelemetry.io/docs/specs/semconv/
 */
class StandardJsonTarget extends FileTarget
{
    private const REDACTED = '***';
    private const DEFAULT_LOG_VARS = ['_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_SERVER'];

    /** `service.name` — a stable, unique identifier for this application repository. */
    public string $serviceName = '';

    /**
     * Resolver for `account.id` (tenant/client scope). `fn(): int|string|null`.
     * Defaults to the logged-in user id (or null) when not set.
     */
    public ?Closure $accountIdResolver = null;

    /**
     * Resolver for `user.id`. `fn(): int|string|null`.
     * Defaults to the logged-in user id (or null) when not set.
     */
    public ?Closure $userIdResolver = null;

    /** Merge method/url/query/body/user-agent/referer of the current web request into `context`. */
    public bool $includeRequestContext = true;

    /** Recursion cap for `context` nesting. */
    public int $maxDepth = 8;

    /** Per-array element cap for `context`. */
    public int $maxItems = 100;

    /**
     * Keys whose values are replaced with `***` anywhere in `context` (case-insensitive).
     *
     * @var list<string>
     */
    public array $maskKeys = [
        'password', 'password_repeat', 'pass', 'token', 'authkey', 'auth_key',
        'secret', 'api_key', 'access_token', 'csrf', '_csrf', 'credit_card', 'cvv',
    ];

    public function init(): void
    {
        parent::init();

        if ($this->serviceName === '') {
            $envName = getenv('SERVICE_NAME');
            $this->serviceName = is_string($envName) && $envName !== '' ? $envName : 'unknown-service';
        }

        // The curated request block below replaces logVars' purpose; keep Yii from emitting its
        // own separate $_SERVER/$_GET/$_POST dump message. An explicit override is respected.
        if ($this->logVars === self::DEFAULT_LOG_VARS) {
            $this->logVars = [];
        }
    }

    /**
     * @param array $message the log message [text, level, category, timestamp, traces, memory]
     */
    public function formatMessage($message): string
    {
        [$text, $level, $category, $timestamp] = $message;

        $entry = [
            'timestamp' => Env::isoTimestamp((float) $timestamp),
            'log.level' => Env::mapLevel((int) $level),
            'service.name' => $this->serviceName,
            'service.version' => Env::serviceVersion(),
            'service.environment' => Env::serviceEnvironment(),
            'account.id' => $this->resolveId($this->accountIdResolver),
            'trace.id' => Tracker::id(),
            'message' => '',
            'error.kind' => (string) $category !== '' ? (string) $category : null,
            'error.message' => null,
            'error.stack_trace' => null,
            'http.request.ip' => $this->requestIp(),
            'user.id' => $this->resolveId($this->userIdResolver),
            'context' => new stdClass(),
        ];

        $context = [];

        if ($text instanceof Throwable) {
            // Yii::error($e, ...) -- derive the error.* fields from the object.
            $entry['error.kind'] = $text::class;
            $entry['error.message'] = $text->getMessage();
            $entry['error.stack_trace'] = (string) $text;
            $entry['message'] = $text->getMessage();
        } else {
            $context = $this->interpretPayload($text, $entry['message']);
            // Call sites pass the exception detail as plain strings under these keys
            // (never the live object): promote them onto the OTel error.* fields.
            $this->promoteErrorFields($context, $entry, (string) $category);
        }

        if ($this->includeRequestContext) {
            $context = array_merge($this->requestContext(), $context);
        }

        if (!empty($message[4]) && in_array($entry['log.level'], ['ERROR', 'WARN'], true)) {
            $frames = [];
            foreach ($message[4] as $frame) {
                if (isset($frame['file'], $frame['line'])) {
                    $frames[] = $frame['file'] . ':' . $frame['line'];
                }
            }
            if ($frames !== []) {
                $context['code.stacktrace'] = $frames;
            }
        }

        $context = $this->mask($this->truncate($context));
        $entry['context'] = $context === [] ? new stdClass() : $context;

        if ($entry['error.message'] === null) {
            unset($entry['error.message']);
        }
        if ($entry['error.stack_trace'] === null) {
            unset($entry['error.stack_trace']);
        }

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $json = json_encode($entry, $flags);

        if (!is_string($json)) {
            $json = json_encode([
                'timestamp' => $entry['timestamp'],
                'log.level' => 'ERROR',
                'service.name' => $entry['service.name'],
                'trace.id' => $entry['trace.id'],
                'message' => 'otel-log: entry encode failed (' . json_last_error_msg() . ')',
            ], JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return is_string($json) ? $json : '{"log.level":"ERROR","message":"otel-log encode failure"}';
    }

    /**
     * Split a non-throwable payload into a message string (by reference) and a context array.
     *
     * @return array<string, mixed>
     */
    private function interpretPayload(mixed $payload, string &$message): array
    {
        if (is_string($payload)) {
            $unserialized = $this->tryUnserializeArray($payload);
            if ($unserialized === null) {
                $message = $payload;

                return [];
            }
            $payload = $unserialized;
        }

        if (!is_array($payload)) {
            $message = VarDumper::export($payload);

            return [];
        }

        if (isset($payload['message']) && is_string($payload['message'])) {
            $message = $payload['message'];
            unset($payload['message']);
        }

        return $payload;
    }

    /**
     * Move `error.message` / `error.stack_trace` / `error.kind` out of the context array and
     * onto the top-level `error.*` fields (call sites pass these as plain strings). A stray
     * Throwable object under any key is handled too, as a safety net.
     *
     * @param array<array-key, mixed> $context
     * @param array<string, mixed> $entry
     */
    private function promoteErrorFields(array &$context, array &$entry, string $category): void
    {
        foreach (['error.message', 'error.stack_trace', 'error.kind'] as $field) {
            if (array_key_exists($field, $context)) {
                $value = $context[$field];
                unset($context[$field]);
                if (is_scalar($value) && (string) $value !== '') {
                    $entry[$field] = (string) $value;
                }
            }
        }

        foreach ($context as $key => $value) {
            if ($value instanceof Throwable) {
                unset($context[$key]);
                $entry['error.kind'] = $value::class;
                $entry['error.message'] = $value->getMessage();
                $entry['error.stack_trace'] = (string) $value;
                break;
            }
        }

        if ($entry['message'] === '' && $entry['error.message'] !== null) {
            $entry['message'] = (string) $entry['error.message'];
        }
    }

    private function resolveId(?Closure $resolver): int|string|null
    {
        try {
            $value = $resolver !== null ? $resolver() : $this->defaultUserId();
        } catch (Throwable) {
            return null;
        }

        if (is_int($value) || $value === null) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function defaultUserId(): int|string|null
    {
        $app = Yii::$app ?? null;
        if ($app === null || !$app->has('user', true)) {
            return null;
        }

        try {
            $user = $app->get('user');

            return $user->getIsGuest() ? null : $user->getId();
        } catch (Throwable) {
            return null;
        }
    }

    private function requestIp(): ?string
    {
        $app = Yii::$app ?? null;
        if ($app === null) {
            return null;
        }

        $request = $app->getRequest();

        return $request instanceof WebRequest ? $request->getUserIP() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestContext(): array
    {
        $app = Yii::$app ?? null;
        if ($app === null) {
            return [];
        }

        $request = $app->getRequest();
        if (!$request instanceof WebRequest) {
            return [];
        }

        $context = [];

        $put = static function (string $key, callable $get) use (&$context): void {
            try {
                $value = $get();
            } catch (Throwable) {
                return;
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $context[$key] = $value;
            }
        };

        $put('http.request.method', static fn () => $request->getMethod());
        $put('http.request.url', static fn () => $request->getUrl());
        $put('http.request.query', static fn () => $request->getQueryParams());
        $put('http.request.body', static fn () => $request->getBodyParams());
        $put('http.user_agent', static fn () => $request->getUserAgent());
        $put('http.referer', static fn () => $request->getReferrer());

        return $context;
    }

    private function tryUnserializeArray(string $value): ?array
    {
        if ($value === '' || $value[0] !== 'a') {
            return null;
        }

        set_error_handler(static fn (): bool => true);
        try {
            $result = unserialize($value, ['allowed_classes' => false]);
        } catch (Throwable) {
            $result = false;
        } finally {
            restore_error_handler();
        }

        return is_array($result) ? $result : null;
    }

    private function truncate(mixed $data, int $depth = 0): mixed
    {
        if ($data instanceof Throwable) {
            return $data::class . ': ' . $data->getMessage();
        }

        if (is_object($data)) {
            $data = method_exists($data, 'toArray') ? $data->toArray() : get_object_vars($data);
        }

        if (!is_array($data)) {
            return $data;
        }

        if ($depth >= $this->maxDepth) {
            return '[truncated]';
        }

        $out = [];
        $i = 0;
        foreach ($data as $key => $value) {
            if ($i++ >= $this->maxItems) {
                $out['_truncated'] = true;
                break;
            }
            $out[$key] = $this->truncate($value, $depth + 1);
        }

        return $out;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function mask(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $lowered = array_map('strtolower', $this->maskKeys);

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $lowered, true)) {
                $data[$key] = self::REDACTED;
                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->mask($value);
            }
        }

        return $data;
    }
}
