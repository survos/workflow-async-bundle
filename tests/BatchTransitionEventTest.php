<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\WorkflowMessengerBundle\Attribute\AsBatchTransitionListener;
use Survos\WorkflowMessengerBundle\Event\BatchTransitionEvent;

final class BatchTransitionEventTest extends TestCase
{
    public function testListenerUsesTheBatchEventName(): void
    {
        $listener = new AsBatchTransitionListener('media', 'dispatch', priority: 10);
        self::assertSame(BatchTransitionEvent::name('media', 'dispatch'), $listener->event);
        self::assertSame(10, $listener->priority);
    }

    public function testSkipAndReleaseAreDistinctAndUseObjectIdentity(): void
    {
        $first = new \stdClass();
        $second = new \stdClass();
        $event = new BatchTransitionEvent('media', 'dispatch', [$first, $second]);
        $event->skip($first);
        $event->release($second);
        self::assertTrue($event->isSkipped($first));
        self::assertFalse($event->isReleased($first));
        self::assertFalse($event->isSkipped($second));
        self::assertTrue($event->isReleased($second));
    }
}
