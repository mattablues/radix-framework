<?php

declare(strict_types=1);

namespace Radix\Tests\EventDispatcher;

use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\StoppableEventInterface;
use Radix\EventDispatcher\EventDispatcher;

interface TestStopCapableEvent extends StoppableEventInterface
{
    public function stop(): void;
}

final class EventDispatcherTest extends TestCase
{
    public function testDispatchCallsRegisteredListener(): void
    {
        $dispatcher = new EventDispatcher();

        $called = false;

        $event = new class {};

        $dispatcher->addListener(
            get_class($event),
            function (object $e) use (&$called, $event): void {
                if ($e === $event) {
                    $called = true;
                }
            }
        );

        $dispatcher->dispatch($event);

        $this->assertTrue($called, 'dispatch() ska anropa registrerad listener för eventet.');
    }

    public function testDispatchStopsWhenPropagationIsStopped(): void
    {
        $dispatcher = new EventDispatcher();

        $calls = 0;

        $event = new class implements TestStopCapableEvent {
            private bool $stopped = false;

            public function isPropagationStopped(): bool
            {
                return $this->stopped;
            }

            public function stop(): void
            {
                $this->stopped = true;
            }
        };

        $dispatcher->addListener(
            get_class($event),
            function (TestStopCapableEvent $e) use (&$calls): void {
                $calls++;
                $e->stop();
            }
        );

        $dispatcher->addListener(
            get_class($event),
            function () use (&$calls): void {
                $calls++;
            }
        );

        $dispatcher->dispatch($event);

        $this->assertSame(1, $calls);
    }
}
