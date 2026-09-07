<?php

declare(strict_types=1);

namespace glpzzz\otellog\tests\Unit;

use glpzzz\otellog\Env;
use glpzzz\otellog\tests\TestCase;
use yii\log\Logger;

final class EnvTest extends TestCase
{
    public function testVersionFromEnvWins(): void
    {
        putenv('SERVICE_VERSION=abc1234');
        Env::reset();

        self::assertSame('abc1234', Env::serviceVersion());
    }

    public function testVersionFallsBackToFileFirstLine(): void
    {
        $file = sys_get_temp_dir() . '/otel-version-' . uniqid();
        file_put_contents($file, "deadbeef\nsecond line\n");
        Env::$versionFile = $file;
        Env::reset();

        self::assertSame('deadbeef', Env::serviceVersion());

        unlink($file);
    }

    public function testVersionUnknownWhenNothingResolves(): void
    {
        Env::$versionFile = '/no/such/file';
        Env::reset();

        self::assertSame('unknown', Env::serviceVersion());
    }

    public function testEnvironmentMapping(): void
    {
        putenv('SERVICE_ENVIRONMENT=develop');
        Env::reset();

        self::assertSame('develop', Env::serviceEnvironment());
    }

    public function testLevelMapping(): void
    {
        self::assertSame('ERROR', Env::mapLevel(Logger::LEVEL_ERROR));
        self::assertSame('WARN', Env::mapLevel(Logger::LEVEL_WARNING));
        self::assertSame('INFO', Env::mapLevel(Logger::LEVEL_INFO));
        self::assertSame('DEBUG', Env::mapLevel(Logger::LEVEL_TRACE));
    }

    public function testIsoTimestampFormat(): void
    {
        $epoch = 1757252712.482;
        $expected = gmdate('Y-m-d\TH:i:s', (int) $epoch) . '.482Z';

        self::assertSame($expected, Env::isoTimestamp($epoch));
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            Env::isoTimestamp(microtime(true)),
        );
    }
}
