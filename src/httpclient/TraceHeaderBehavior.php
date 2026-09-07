<?php

declare(strict_types=1);

namespace glpzzz\otellog\httpclient;

use glpzzz\otellog\Tracker;
use yii\base\Behavior;
use yii\httpclient\Client;
use yii\httpclient\RequestEvent;

/**
 * Attach to a {@see Client} so every outgoing request carries `X-Request-ID: <trace.id>`,
 * letting downstream services (the .NET / WordPress apps) log against the same trace.
 *
 * ```php
 * $client = new \yii\httpclient\Client([
 *     'as trace' => \glpzzz\otellog\httpclient\TraceHeaderBehavior::class,
 * ]);
 * ```
 *
 * An `X-Request-ID` already set on the request (per-call override) is left untouched.
 */
final class TraceHeaderBehavior extends Behavior
{
    /**
     * @return array<string, string>
     */
    public function events(): array
    {
        return [Client::EVENT_BEFORE_SEND => 'onBeforeSend'];
    }

    public function onBeforeSend(RequestEvent $event): void
    {
        $headers = $event->request->getHeaders();

        if (!$headers->has(Tracker::HEADER)) {
            $headers->set(Tracker::HEADER, Tracker::id());
        }
    }
}
