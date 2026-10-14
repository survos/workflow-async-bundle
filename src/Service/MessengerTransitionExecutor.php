<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Service;

use Survos\WorkflowExtrasBundle\Contract\TransitionExecutorInterface;
use Survos\WorkflowMessengerBundle\Contract\SubjectStoreInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\Service\ResetInterface;

final class MessengerTransitionExecutor implements TransitionExecutorInterface, ResetInterface
{
    private bool $sync = false;

    public function __construct(private readonly TransitionDispatcher $dispatcher, private readonly SubjectStoreInterface $store)
    {
    }

    public function reset(): void
    {
        $this->sync = false;
    }

    public function synchronously(callable $callback): mixed
    {
        $previous = $this->sync;
        $this->sync = true;
        try {
            return $callback();
        } finally {
            $this->sync = $previous;
        }
    }

    public function requiresSavedSubject(): bool
    {
        return true;
    }

    public function execute(WorkflowInterface $workflow, object $subject, string $transition, array $context = []): void
    {
        if ($this->sync) {
            $context['cascade'] ??= 'sync';
        }
        $this->dispatcher->dispatch($workflow->getName(), $this->store->reference($subject), $transition, $context);
    }
}
