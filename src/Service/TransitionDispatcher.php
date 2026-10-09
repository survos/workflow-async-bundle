<?php

declare(strict_types=1);

namespace Survos\WorkflowAsyncBundle\Service;

use Survos\WorkflowAsyncBundle\Message\SubjectReference;
use Survos\WorkflowAsyncBundle\Message\TransitionRequest;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final readonly class TransitionDispatcher
{
    public function __construct(private MessageBusInterface $bus, private WorkflowLocator $workflows, private TransitionRouter $router)
    {
    }

    public function dispatch(string $workflow, SubjectReference $subject, string $transition, array $context = []): Envelope
    {
        $transport = $this->router->transport($this->workflows->get($workflow), $transition);
        $request = new TransitionRequest($workflow, $subject, $transition, $context, bin2hex(random_bytes(16)));

        return $this->bus->dispatch($request, [new TransportNamesStamp([$transport])]);
    }
}
