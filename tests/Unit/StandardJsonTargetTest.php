<?php

declare(strict_types=1);

namespace glpzzz\otellog\tests\Unit;

use glpzzz\otellog\StandardJsonTarget;
use glpzzz\otellog\tests\TestCase;
use glpzzz\otellog\Tracker;
use RuntimeException;
use yii\log\Logger;

final class StandardJsonTargetTest extends TestCase
{
    private function target(array $config = []): StandardJsonTarget
    {
        return new StandardJsonTarget(array_merge([
            'serviceName' => 'wtis-tdj2',
            'logFile' => sys_get_temp_dir() . '/otel-test.log',
        ], $config));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(StandardJsonTarget $target, array $message): array
    {
        $line = $target->formatMessage($message);

        self::assertStringNotContainsString("\n", $line, 'output must be a single line');
        $decoded = json_decode($line, true);
        self::assertIsArray($decoded, 'output must be valid JSON');

        return $decoded;
    }

    public function testPlainStringMessage(): void
    {
        $entry = $this->decode($this->target(), ['hello world', Logger::LEVEL_INFO, 'app\\Foo::bar', 1757252712.5]);

        self::assertSame('hello world', $entry['message']);
        self::assertSame('INFO', $entry['log.level']);
        self::assertSame('wtis-tdj2', $entry['service.name']);
        self::assertSame('app\\Foo::bar', $entry['error.kind']);
        self::assertSame(Tracker::id(), $entry['trace.id']);
        self::assertNull($entry['user.id']);
        self::assertNull($entry['account.id']);
        self::assertNull($entry['http.request.ip']);
        self::assertArrayNotHasKey('error.stack_trace', $entry);
    }

    public function testArrayWithMessageKeySplitsIntoContext(): void
    {
        $entry = $this->decode($this->target(), [
            ['message' => 'login blocked', 'country' => 'RU', 'ip' => '1.2.3.4'],
            Logger::LEVEL_WARNING,
            'FormEvent::beforeLogin',
            1757252712.5,
        ]);

        self::assertSame('login blocked', $entry['message']);
        self::assertSame('WARN', $entry['log.level']);
        self::assertSame('RU', $entry['context']['country']);
        self::assertSame('1.2.3.4', $entry['context']['ip']);
        self::assertArrayNotHasKey('message', $entry['context']);
    }

    public function testRawArrayWithoutMessageKeyBecomesContext(): void
    {
        $entry = $this->decode($this->target(), [
            ['foo' => ['bar' => 1]],
            Logger::LEVEL_INFO,
            'app\\X',
            1757252712.5,
        ]);

        self::assertSame('', $entry['message']);
        self::assertSame(1, $entry['context']['foo']['bar']);
    }

    public function testSerializedArrayPayloadIsUnpacked(): void
    {
        $payload = serialize(['message' => 'sent', 'to' => 'a@b.c']);
        $entry = $this->decode($this->target(), [$payload, Logger::LEVEL_INFO, 'common\\helpers\\Mailer', 1757252712.5]);

        self::assertSame('sent', $entry['message']);
        self::assertSame('a@b.c', $entry['context']['to']);
    }

    public function testThrowablePayload(): void
    {
        $exception = new RuntimeException('boom');
        $entry = $this->decode($this->target(), [$exception, Logger::LEVEL_ERROR, 'app', 1757252712.5, [
            ['file' => '/app/x.php', 'line' => 10],
        ]]);

        self::assertSame('boom', $entry['message']);
        self::assertSame('boom', $entry['error.message']);
        self::assertSame('app', $entry['error.kind'], 'error.kind is always the category');
        self::assertStringContainsString('RuntimeException', $entry['error.stack_trace']);
        self::assertStringContainsString('\n', json_encode($entry['error.stack_trace']));
        self::assertSame(['/app/x.php:10'], $entry['context']['code.stacktrace']);
    }

    public function testErrorFieldsPromotedFromStringKeys(): void
    {
        $exception = new RuntimeException('inner failure');
        $entry = $this->decode($this->target(), [
            [
                'message' => 'Failed to do the thing',
                'user' => 7,
                'error.message' => $exception->getMessage(),
                'error.stack_trace' => (string) $exception,
            ],
            Logger::LEVEL_ERROR,
            'common\\jobs\\DoThing::execute',
            1757252712.5,
        ]);

        self::assertSame('Failed to do the thing', $entry['message']);
        self::assertSame('inner failure', $entry['error.message']);
        self::assertStringContainsString('RuntimeException', $entry['error.stack_trace']);
        self::assertSame('common\\jobs\\DoThing::execute', $entry['error.kind'], 'error.kind = category');
        self::assertSame(7, $entry['context']['user']);
        self::assertArrayNotHasKey('error.message', $entry['context']);
        self::assertArrayNotHasKey('error.stack_trace', $entry['context']);
    }

    public function testStrayExceptionObjectIsStillHandled(): void
    {
        $entry = $this->decode($this->target(), [
            ['message' => 'oops', 'boom' => new RuntimeException('leaked object')],
            Logger::LEVEL_ERROR,
            'app\\X',
            1757252712.5,
        ]);

        self::assertSame('leaked object', $entry['error.message']);
        self::assertSame('app\\X', $entry['error.kind'], 'error.kind stays the category');
        self::assertArrayNotHasKey('boom', $entry['context']);
    }

    public function testNoExceptionKeysWhenNoException(): void
    {
        $entry = $this->decode($this->target(), ['plain', Logger::LEVEL_INFO, 'app\\X', 1757252712.5]);

        self::assertArrayNotHasKey('error.message', $entry);
        self::assertArrayNotHasKey('error.stack_trace', $entry);
        self::assertSame('app\\X', $entry['error.kind']);
    }

    public function testRequestContextAndMaskingUnderWebApp(): void
    {
        $app = $this->mockWebApplication();
        $app->getRequest()->setQueryParams(['q' => 'x']);
        $app->getRequest()->setBodyParams(['username' => 'joe', 'password' => 'hunter2']);

        $entry = $this->decode($this->target(), ['did a thing', Logger::LEVEL_INFO, 'app', 1757252712.5]);

        self::assertSame('x', $entry['context']['http.request.query']['q']);
        self::assertSame('joe', $entry['context']['http.request.body']['username']);
        self::assertSame('***', $entry['context']['http.request.body']['password']);
        self::assertArrayHasKey('http.request.method', $entry['context']);
    }

    public function testRequestContextDisabled(): void
    {
        $this->mockWebApplication();
        $entry = $this->decode($this->target(['includeRequestContext' => false]), [
            'x', Logger::LEVEL_INFO, 'app', 1757252712.5,
        ]);

        self::assertArrayNotHasKey('http.request.method', $entry['context']);
    }

    public function testResolversAreUsed(): void
    {
        $entry = $this->decode($this->target([
            'accountIdResolver' => static fn (): int => 42,
            'userIdResolver' => static fn (): string => 'u-7',
        ]), ['x', Logger::LEVEL_INFO, 'app', 1757252712.5]);

        self::assertSame(42, $entry['account.id']);
        self::assertSame('u-7', $entry['user.id']);
    }

    public function testThrowingResolverIsSwallowed(): void
    {
        $entry = $this->decode($this->target([
            'userIdResolver' => static fn () => throw new RuntimeException('nope'),
        ]), ['x', Logger::LEVEL_INFO, 'app', 1757252712.5]);

        self::assertNull($entry['user.id']);
    }
}
