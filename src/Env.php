<?php

declare(strict_types=1);

namespace glpzzz\otellog;

use DateTimeImmutable;
use DateTimeZone;
use Yii;
use yii\log\Logger;

/**
 * Pure, statically-cached resolvers for the `service.*` schema fields plus timestamp/level
 * formatting. No shell commands: the running commit hash comes from the `SERVICE_VERSION`
 * environment variable or a `VERSION` file written at the project root at deploy time.
 */
final class Env
{
    /** Explicit path to the version file; overrides the resolved candidates. Mainly for tests. */
    public static ?string $versionFile = null;

    private static ?string $_version = null;
    private static ?string $_environment = null;

    /** Drop the memoised values (tests). */
    public static function reset(): void
    {
        self::$_version = null;
        self::$_environment = null;
    }

    /** Running git commit hash, or `'unknown'`. */
    public static function serviceVersion(): string
    {
        if (self::$_version !== null) {
            return self::$_version;
        }

        $env = getenv('SERVICE_VERSION');
        if (is_string($env) && trim($env) !== '') {
            return self::$_version = trim($env);
        }

        foreach (self::versionFiles() as $file) {
            if (is_file($file) && is_readable($file)) {
                $contents = @file_get_contents($file);
                $first = is_string($contents) ? strtok($contents, "\n") : false;
                $line = is_string($first) ? trim($first) : '';
                if ($line !== '') {
                    return self::$_version = $line;
                }
            }
        }

        return self::$_version = 'unknown';
    }

    /** Normalised runtime environment: `production` / `staging` / `develop` (or an override). */
    public static function serviceEnvironment(): string
    {
        if (self::$_environment !== null) {
            return self::$_environment;
        }

        $env = getenv('SERVICE_ENVIRONMENT');
        if (is_string($env) && trim($env) !== '') {
            return self::$_environment = trim($env);
        }

        $yiiEnv = defined('YII_ENV')
            ? (string) constant('YII_ENV')
            : (string) (getenv('YII_ENV') ?: 'prod');
        $map = ['prod' => 'production', 'stage' => 'staging', 'dev' => 'develop'];

        return self::$_environment = $map[$yiiEnv] ?? $yiiEnv;
    }

    /** Yii log level int to upper-case OTel-style name. */
    public static function mapLevel(int $level): string
    {
        return match (Logger::getLevelName($level)) {
            'error' => 'ERROR',
            'warning' => 'WARN',
            'info' => 'INFO',
            default => 'DEBUG',
        };
    }

    /** ISO 8601 UTC with milliseconds, e.g. `2026-09-07T13:45:12.482Z`. */
    public static function isoTimestamp(float $epoch): string
    {
        $utc = new DateTimeZone('UTC');
        $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $epoch), $utc);
        if ($dt === false) {
            $dt = new DateTimeImmutable('now', $utc);
        }

        return $dt->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');
    }

    /** @return list<string> */
    private static function versionFiles(): array
    {
        $files = [];

        if (self::$versionFile !== null) {
            $files[] = self::$versionFile;
        }

        try {
            $alias = Yii::getAlias('@root/VERSION', false);
            if (is_string($alias)) {
                $files[] = $alias;
            }
        } catch (\Throwable) {
            // no @root alias registered; fall through to the path guess
        }

        $files[] = dirname(__DIR__, 4) . '/VERSION';

        return $files;
    }
}
