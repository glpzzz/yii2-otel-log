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
 * A Yii2 log target that writes one JSON object per line (NDJSON) using Elastic Common Schema
 * (ECS) field names, ready for a log shipper (Vector / Fluent Bit) to tail into OpenObserve,
 * Elasticsearch, Loki, etc.
 *
 * Extends {@see FileTarget} for its file handling, rotation and flock-safe append; only
 * {@see formatMessage()} is replaced.
 *
 * The well-known fields are promoted to the top level with ECS names -- `@timestamp`,
 * `log.level`, `log.logger` (the Yii log category), `message`, `service.*`, `trace.id`,
 * `error.message`, `error.stack_trace`, `client.ip`, `http.request.*`, `url.*`,
 * `user_agent.original`, `user.*`. Whatever the caller passed to
 * `Yii::info()/warning()/error()` as an array -- or a legacy `serialize([...])` blob -- lands
 * untouched in a nested `context` object.
 *
 * `error.*` is filled only when the call carried an exception: pass its detail as plain
 * strings, nested --
 * `['message' => 'failed', 'error' => ['message' => $e->getMessage(), 'stack_trace' => (string) $e]]`
 * -- (flat `error.message` / `error.stack_trace` keys work too), or pass the Throwable as the
 * whole payload (`Yii::error($e, $category)`), in which case `message` equals `$e->getMessage()`.
 *
 * @see https://www.elastic.co/guide/en/ecs/current/index.html
 */
class StandardJsonTarget extends FileTarget
{
    private const REDACTED = '***';
    private const DEFAULT_LOG_VARS = ['_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_SERVER'];

    /** `service.name` — a stable, unique identifier for this application repository. */
    public string $serviceName = '';

    /**
     * Resolver for the `user.*` fields. `fn(): array|null` returning any of:
     *   `['id' => int|string, 'name' => string, 'full_name' => string, 'roles' => list<string>|string]`
     * mapped to `user.id` / `user.name` / `user.full_name` / `user.roles`. Missing keys are
     * omitted; a scalar `roles` is wrapped to a single-element list. Defaults to
     * `['id' => <logged-in user id>]` (or `[]` for a guest / no `user` component).
     */
    public ?Closure $userResolver = null;

    /**
     * Merge request metadata (`client.ip`, `http.request.method`, `url.full`, `url.path`,
     * `url.query`, `user_agent.original`, `http.request.referrer`) at the top level.
     */
    public bool $includeRequestContext = true;

    /**
     * Include the request body under `http.request.body.content` (masked/truncated first).
     * Ignored when {@see $includeRequestContext} is false.
     */
    public bool $includeRequestBody = true;

    /** Recursion cap for the nested `context` payload and the request body. */
    public int $maxDepth = 8;

    /** Per-array element cap for the nested `context` payload and the request body. */
    public int $maxItems = 100;

    /**
     * Keys whose values are replaced with `***` anywhere in `context` or the request body
     * (case-insensitive).
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
            '@timestamp' => Env::isoTimestamp((float) $timestamp),
            'log.level' => Env::mapLevel((int) $level),
            'log.logger' => (string) $category !== '' ? (string) $category : null,
            'service.name' => $this->serviceName,
            'service.version' => Env::serviceVersion(),
            'service.environment' => Env::serviceEnvironment(),
            'trace.id' => Tracker::id(),
            'message' => '',
            'error.message' => null,
            'error.stack_trace' => null,
        ];

        $entry += $this->userFields();

        if ($this->includeRequestContext) {
            $entry += $this->requestFields();
        }

        $context = [];

        // error.* is filled only when the call carried an exception.
        if ($text instanceof Throwable) {
            // Yii::error($e, $category) -- the whole payload is the exception.
            $entry['message'] = $text->getMessage();
            $entry['error.message'] = $text->getMessage();
            $entry['error.stack_trace'] = (string) $text;
        } else {
            $context = $this->interpretPayload($text, $entry['message']);
            $this->promoteErrorFields($context, $entry);
        }

        if (!empty($message[4]) && in_array($entry['log.level'], ['ERROR', 'WARN'], true)) {
            $frames = [];
            foreach ($message[4] as $frame) {
                if (isset($frame['file'], $frame['line'])) {
                    $frames[] = $frame['file'] . ':' . $frame['line'];
                }
            }
            if ($frames !== []) {
                $entry['code.stacktrace'] = $frames;
            }
        }

        $context = $this->mask($this->truncate($context));
        $entry['context'] = $context === [] ? new stdClass() : $context;

        if ($entry['log.logger'] === null) {
            unset($entry['log.logger']);
        }
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
                '@timestamp' => $entry['@timestamp'],
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
     * Move the exception-specific message/stack trace out of the context array and onto the
     * top-level `error.message` / `error.stack_trace` fields. Call sites pass plain strings,
     * either nested --
     * `'error' => ['message' => $e->getMessage(), 'stack_trace' => (string) $e]` -- or as the
     * flat dotted keys `'error.message'` / `'error.stack_trace'`. `log.logger` stays the log
     * category and is never taken from the payload. A stray Throwable object under any key is
     * reduced too, as a safety net.
     *
     * @param array<array-key, mixed> $context
     * @param array<string, mixed> $entry
     */
    private function promoteErrorFields(array &$context, array &$entry): void
    {
        $set = static function (string $field, mixed $value) use (&$entry): void {
            if (is_scalar($value) && (string) $value !== '') {
                $entry[$field] = (string) $value;
            }
        };

        if (isset($context['error']) && is_array($context['error'])) {
            $error = $context['error'];
            unset($context['error']);
            $set('error.message', $error['message'] ?? null);
            $set('error.stack_trace', $error['stack_trace'] ?? null);
        }

        foreach (['error.message', 'error.stack_trace'] as $field) {
            if (array_key_exists($field, $context)) {
                $value = $context[$field];
                unset($context[$field]);
                $set($field, $value);
            }
        }

        foreach ($context as $key => $value) {
            if ($value instanceof Throwable) {
                unset($context[$key]);
                $entry['error.message'] ??= $value->getMessage();
                $entry['error.stack_trace'] ??= (string) $value;
                break;
            }
        }
    }

    /**
     * Resolve the `user.*` fields from {@see $userResolver} (or the default: the logged-in id).
     *
     * @return array<string, mixed>
     */
    private function userFields(): array
    {
        try {
            $data = $this->userResolver !== null ? ($this->userResolver)() : $this->defaultUser();
        } catch (Throwable) {
            return [];
        }

        if (!is_array($data)) {
            return [];
        }

        $out = [];

        if (isset($data['id']) && is_scalar($data['id'])) {
            $out['user.id'] = is_int($data['id']) ? $data['id'] : (string) $data['id'];
        }

        foreach (['name' => 'user.name', 'full_name' => 'user.full_name'] as $key => $field) {
            if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] !== '') {
                $out[$field] = (string) $data[$key];
            }
        }

        if (isset($data['roles'])) {
            $roles = is_array($data['roles']) ? $data['roles'] : [$data['roles']];
            $roles = array_values(array_filter(
                array_map(static fn ($role) => is_scalar($role) ? (string) $role : null, $roles),
                static fn (?string $role) => $role !== null && $role !== '',
            ));
            if ($roles !== []) {
                $out['user.roles'] = $roles;
            }
        }

        return $out;
    }

    private function defaultUser(): array
    {
        $app = Yii::$app ?? null;
        if ($app === null || !$app->has('user', true)) {
            return [];
        }

        try {
            $user = $app->get('user');
            if ($user->getIsGuest()) {
                return [];
            }
            $id = $user->getId();

            return $id === null ? [] : ['id' => $id];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * ECS request metadata for the current web request, for the top level of the entry.
     *
     * @return array<string, mixed>
     */
    private function requestFields(): array
    {
        $app = Yii::$app ?? null;
        if ($app === null) {
            return [];
        }

        $request = $app->getRequest();
        if (!$request instanceof WebRequest) {
            return [];
        }

        $out = [];

        $put = static function (string $key, callable $get) use (&$out): void {
            try {
                $value = $get();
            } catch (Throwable) {
                return;
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $out[$key] = $value;
            }
        };

        $put('client.ip', static fn () => $request->getUserIP());
        $put('http.request.method', static fn () => $request->getMethod());
        $put('url.full', static fn () => $request->getAbsoluteUrl());
        $put('url.path', static fn () => $request->getPathInfo());
        $put('url.query', static fn () => $request->getQueryString());
        $put('user_agent.original', static fn () => $request->getUserAgent());
        $put('http.request.referrer', static fn () => $request->getReferrer());

        if ($this->includeRequestBody) {
            $put('http.request.body.content', fn () => $this->mask($this->truncate($request->getBodyParams())));
        }

        return $out;
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

        if ($data instanceof \__PHP_Incomplete_Class) {
            // A legacy serialize([...]) payload is unpacked with allowed_classes=false
            // (see tryUnserializeArray()), so every object in it arrives as an incomplete
            // class. method_exists()/property_exists()/any method call *throw* on those,
            // so it must be reduced here, before the generic is_object() branch below.
            $vars = get_object_vars($data);
            $class = $vars['__PHP_Incomplete_Class_Name'] ?? null;
            unset($vars['__PHP_Incomplete_Class_Name']);
            $data = ['__class' => is_string($class) ? $class : 'unknown'] + $vars;
        } elseif (is_object($data)) {
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
