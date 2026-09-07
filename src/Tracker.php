<?php

declare(strict_types=1);

namespace glpzzz\otellog;

/**
 * Holds the current request's distributed-trace id.
 *
 * {@see boot()} runs from the entry point *before* the Yii application is built, so this class
 * deliberately depends on nothing from Yii. It adopts an inbound `X-Request-ID` (web header or
 * `X_REQUEST_ID` env for console/queue callers) when it looks sane, otherwise it mints a
 * 32-character hex token. The value is frozen into the `TRACKING_REQUEST_UUID` constant.
 */
final class Tracker
{
    /** Global constant that carries the trace id for the lifetime of the process. */
    public const CONSTANT = 'TRACKING_REQUEST_UUID';

    /** Header used both to adopt an upstream id and to propagate ours downstream. */
    public const HEADER = 'X-Request-ID';

    private const PATTERN = '/^[0-9A-Za-z._-]{8,128}$/';

    /**
     * Resolve the trace id once and define {@see CONSTANT}. Safe to call repeatedly and from
     * every entry point; the first call wins.
     */
    public static function boot(): string
    {
        if (defined(self::CONSTANT)) {
            return (string) constant(self::CONSTANT);
        }

        $id = self::candidate() ?? bin2hex(random_bytes(16));
        define(self::CONSTANT, $id);

        return $id;
    }

    /** The trace id, booting one if the entry point did not. */
    public static function id(): string
    {
        return defined(self::CONSTANT) ? (string) constant(self::CONSTANT) : self::boot();
    }

    public static function headerName(): string
    {
        return self::HEADER;
    }

    /**
     * Header pair to merge into an outgoing request when not attaching {@see httpclient\TraceHeaderBehavior}.
     *
     * @return array<string, string>
     */
    public static function outgoingHeaders(): array
    {
        return [self::HEADER => self::id()];
    }

    private static function candidate(): ?string
    {
        $envValue = getenv('X_REQUEST_ID');

        foreach ([$_SERVER['HTTP_X_REQUEST_ID'] ?? null, $envValue === false ? null : $envValue] as $value) {
            if (is_string($value) && preg_match(self::PATTERN, $value) === 1) {
                return $value;
            }
        }

        return null;
    }
}
