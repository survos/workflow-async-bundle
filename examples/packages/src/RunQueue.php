<?php

declare(strict_types=1);

namespace Survos\WorkflowExample;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

final class RunQueue
{
    /** A real worker with a bounded lifetime, for the single-process tutorial. */
    public static function once(string $name, ReceiverInterface $receiver, MessageBusInterface $bus): void
    {
        $events = new EventDispatcher();
        $events->addSubscriber(new StopWorkerOnMessageLimitListener(1));
        $events->addSubscriber(new StopWorkerOnTimeLimitListener(10));
        (new Worker([$name => $receiver], $bus, $events))->run(['sleep' => 0]);
    }
}
