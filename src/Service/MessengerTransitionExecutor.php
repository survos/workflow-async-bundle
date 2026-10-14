<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Service;

use Survos\WorkflowExtrasBundle\Contract\TransitionExecutorInterface;
use Survos\WorkflowMessengerBundle\Contract\SubjectStoreInterface;
use Symfony\Component\Workflow\WorkflowInterface;

final readonly class MessengerTransitionExecutor implements TransitionExecutorInterface
{
    public function __construct(private TransitionDispatcher $dispatcher, private SubjectStoreInterface $store)
    {
    }

    public function requiresSavedSubject(): bool
    {
        return true;
    }

    public function execute(WorkflowInterface $workflow, object $subject, string $transition, array $context = []): void
    {
        $this->dispatcher->dispatch($workflow->getName(), $this->store->reference($subject), $transition, $context);
    }
}
