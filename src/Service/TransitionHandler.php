<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Service;

use Survos\WorkflowMessengerBundle\Contract\SubjectStoreInterface;
use Survos\WorkflowMessengerBundle\Message\TransitionRequest;
use Survos\WorkflowMessengerBundle\Message\TransitionResult;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;

/**
 * Applies one queued transition. Requests for the same subject never run concurrently: the subject is loaded, applied, saved and continued under
 * a per-subject lock, so `can()` holds at apply time and no flush overwrites another.
 * A busy subject is retried shortly. The lock is re-entrant within this process, for
 * a sync continuation of the same subject inside its own handler.
 */
#[AsMessageHandler]
final class TransitionHandler
{
    /** @var array<string, int> lock resource => nesting depth held by this process */
    private array $held = [];

    public function __construct(
        private readonly SubjectStoreInterface $store,
        private readonly WorkflowLocator $workflows,
        private readonly TransitionRouter $router,
        private readonly LockFactory $locks,
        private readonly ?\Survos\WorkflowExtrasBundle\Service\NextTransitionRunner $next = null,
    ) {
    }

    public function __invoke(TransitionRequest $request): TransitionResult
    {
        if (1 !== $request->version) {
            throw new UnrecoverableMessageHandlingException('Unsupported workflow request version.');
        }
        try {
            $workflow = $this->workflows->get($request->workflow);
            $this->router->transport($workflow, $request->transition, ($request->context['cascade'] ?? null) === 'sync');
        } catch (\InvalidArgumentException $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        }
        $resource = sprintf('survos_workflow:%s:%s', $request->subject->type, $request->subject->id);
        if (isset($this->held[$resource])) {
            $this->held[$resource] = ($this->held[$resource] ?? 0) + 1;
            try {
                return $this->handle($request, $workflow);
            } finally {
                if (0 === --$this->held[$resource]) {
                    unset($this->held[$resource]);
                }
            }
        }
        // No expiring lease: slow work (HTTP fetches) must not outlive its lock.
        $lock = $this->locks->createLock($resource, ttl: null);
        if (!$lock->acquire()) {
            throw new RecoverableMessageHandlingException(sprintf('A transition of %s "%s" is already running.', $request->subject->type, $request->subject->id), retryDelay: 1000);
        }
        $this->held[$resource] = 1;
        try {
            return $this->handle($request, $workflow);
        } finally {
            unset($this->held[$resource]);
            $lock->release();
        }
    }

    private function handle(TransitionRequest $request, \Symfony\Component\Workflow\WorkflowInterface $workflow): TransitionResult
    {
        $subject = $this->store->load($request->subject);
        if (null === $subject) {
            // Fail loudly, never ack: retrying cannot make the subject appear, so the failure transport.
            throw new UnrecoverableMessageHandlingException(sprintf('Missing subject for workflow "%s" transition "%s".', $request->workflow, $request->transition));
        }
        $previous = null;
        foreach ($workflow->getEnabledTransitions($subject) as $transition) {
            if ($transition->getName() === $request->transition) {
                $previous = $transition;
                break;
            }
        }
        try {
            $workflow->apply($subject, $request->transition, array_replace($request->context, ['workflow_extras.defer' => true]));
        } catch (NotEnabledTransitionException $e) {
            $reasons = [];
            foreach ($e->getTransitionBlockerList() as $blocker) {
                $reasons[] = $blocker->getMessage();
            }

            return new TransitionResult($request->requestId, 'blocked', $reasons);
        }
        $this->store->save($request->subject, $subject, $request->context);

        $this->next?->continue($workflow, $subject, $request->context, $previous);

        return new TransitionResult($request->requestId, 'applied');
    }
}
