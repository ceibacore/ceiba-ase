<?php

declare(strict_types=1);

namespace LemurAse\Tests\Unit\Infrastructure\Events;

use PHPUnit\Framework\TestCase;
use LemurAse\Infrastructure\Events\AseEventDispatcher;

class AseEventDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset listeners before each test
        AseEventDispatcher::forget();
    }

    public function testCanListenAndDispatchEvents(): void
    {
        $calledCount = 0;
        $receivedPayload = null;

        AseEventDispatcher::listen('test.event', function ($payload) use (&$calledCount, &$receivedPayload) {
            $calledCount++;
            $receivedPayload = $payload;
        });

        AseEventDispatcher::dispatch('test.event', ['id' => 123]);

        $this->assertEquals(1, $calledCount);
        $this->assertEquals(['id' => 123], $receivedPayload);
    }

    public function testMultipleListenersForSameEvent(): void
    {
        $listener1Called = false;
        $listener2Called = false;

        AseEventDispatcher::listen('test.event', function () use (&$listener1Called) {
            $listener1Called = true;
        });

        AseEventDispatcher::listen('test.event', function () use (&$listener2Called) {
            $listener2Called = true;
        });

        AseEventDispatcher::dispatch('test.event');

        $this->assertTrue($listener1Called);
        $this->assertTrue($listener2Called);
    }

    public function testExceptionsInListenersAreSwallowed(): void
    {
        $secondListenerCalled = false;

        AseEventDispatcher::listen('test.event', function () {
            throw new \RuntimeException('Listener failed!');
        });

        AseEventDispatcher::listen('test.event', function () use (&$secondListenerCalled) {
            $secondListenerCalled = true; // Should still be called despite first listener failing
        });

        // We temporarily suppress error_log output for this test so it doesn't pollute PHPUnit output
        $stderr = ini_get('error_log');
        ini_set('error_log', '/dev/null'); // Redirect to bit bucket or custom location

        // Should not throw an exception
        AseEventDispatcher::dispatch('test.event');

        ini_set('error_log', $stderr); // Restore

        $this->assertTrue($secondListenerCalled);
    }

    public function testCanForgetSpecificEvent(): void
    {
        $called = false;

        AseEventDispatcher::listen('test.event', function () use (&$called) {
            $called = true;
        });

        AseEventDispatcher::forget('test.event');
        AseEventDispatcher::dispatch('test.event');

        $this->assertFalse($called);
    }

    public function testForgetClearsAllEventsIfNoParameterProvided(): void
    {
        $called1 = false;
        $called2 = false;

        AseEventDispatcher::listen('event.1', function () use (&$called1) { $called1 = true; });
        AseEventDispatcher::listen('event.2', function () use (&$called2) { $called2 = true; });

        AseEventDispatcher::forget(); // Clears all

        AseEventDispatcher::dispatch('event.1');
        AseEventDispatcher::dispatch('event.2');

        $this->assertFalse($called1);
        $this->assertFalse($called2);
    }
}
