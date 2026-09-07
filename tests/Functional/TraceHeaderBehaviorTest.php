<?php

declare(strict_types=1);

namespace glpzzz\otellog\tests\Functional;

use glpzzz\otellog\httpclient\TraceHeaderBehavior;
use glpzzz\otellog\tests\TestCase;
use glpzzz\otellog\Tracker;
use yii\httpclient\Client;

final class TraceHeaderBehaviorTest extends TestCase
{
    public function testAddsTraceHeaderWhenAbsent(): void
    {
        $client = new Client(['as trace' => TraceHeaderBehavior::class]);
        $request = $client->createRequest()->setMethod('GET')->setUrl('http://example.test');

        $client->trigger(Client::EVENT_BEFORE_SEND, new \yii\httpclient\RequestEvent(['request' => $request]));

        self::assertSame(Tracker::id(), $request->getHeaders()->get('X-Request-ID'));
    }

    public function testDoesNotOverrideExistingHeader(): void
    {
        $client = new Client(['as trace' => TraceHeaderBehavior::class]);
        $request = $client->createRequest()
            ->setMethod('GET')
            ->setUrl('http://example.test')
            ->addHeaders(['X-Request-ID' => 'caller-supplied-id']);

        $client->trigger(Client::EVENT_BEFORE_SEND, new \yii\httpclient\RequestEvent(['request' => $request]));

        self::assertSame('caller-supplied-id', $request->getHeaders()->get('X-Request-ID'));
    }
}
