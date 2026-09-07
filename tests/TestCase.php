<?php

declare(strict_types=1);

namespace glpzzz\otellog\tests;

use glpzzz\otellog\Env;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Yii;
use yii\console\Application as ConsoleApplication;
use yii\web\Application as WebApplication;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        Yii::$app = null;
        Env::$versionFile = null;
        Env::reset();
        putenv('SERVICE_VERSION');
        putenv('SERVICE_ENVIRONMENT');
        putenv('SERVICE_NAME');
        putenv('X_REQUEST_ID');
        unset($_SERVER['HTTP_X_REQUEST_ID']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function mockConsoleApplication(array $config = []): ConsoleApplication
    {
        return new ConsoleApplication(array_merge($this->baseConfig(), $config));
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function mockWebApplication(array $config = []): WebApplication
    {
        $merged = array_merge($this->baseConfig(), $config);
        $merged['components']['request'] = array_merge([
            'cookieValidationKey' => 'test',
            'scriptFile' => __DIR__ . '/index.php',
            'scriptUrl' => '/index.php',
        ], $config['components']['request'] ?? []);

        return new WebApplication($merged);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseConfig(): array
    {
        return [
            'id' => 'otel-log-test',
            'basePath' => dirname(__DIR__),
            'vendorPath' => dirname(__DIR__) . '/vendor',
            'components' => [],
        ];
    }
}
