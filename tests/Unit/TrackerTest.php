<?php

declare(strict_types=1);

namespace glpzzz\otellog\tests\Unit;

use glpzzz\otellog\tests\TestCase;
use glpzzz\otellog\Tracker;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The trace id lands in the TRACKING_REQUEST_UUID constant, so each case runs isolated.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TrackerTest extends TestCase
{
    public function testGeneratesThirtyTwoHexWhenNoUpstreamId(): void
    {
        $id = Tracker::boot();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        self::assertSame($id, Tracker::id());
        self::assertSame($id, Tracker::boot(), 'boot() is idempotent');
    }

    public function testAdoptsValidUpstreamHeader(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = '0af7651916cd43dd8448eb211c80319c';

        self::assertSame('0af7651916cd43dd8448eb211c80319c', Tracker::boot());
    }

    public function testIgnoresGarbageUpstreamHeader(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'no spaces allowed';

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', Tracker::boot());
    }

    public function testOutgoingHeaders(): void
    {
        $id = Tracker::boot();

        self::assertSame(['X-Request-ID' => $id], Tracker::outgoingHeaders());
    }
}
