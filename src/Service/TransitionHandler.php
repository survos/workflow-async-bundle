<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Service;

use Survos\WorkflowMessengerBundle\Contract\SubjectStoreInterface;
use Survos\WorkflowMessengerBundle\Message\TransitionRequest;
use Survos\WorkflowMessengerBundle\Message\TransitionResult;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;

#[AsMessageHandler]
final readonly class TransitionHandler
{
    public function __construct(private SubjectStoreInterface $store, private WorkflowLocator $workflows, private TransitionRouter $router)
    {
    }

    public function __invoke(TransitionRequest $request): TransitionResult
    {
        if (1 !== $request->version) {
            throw new UnrecoverableMessageHandlingException('Unsupported workflow request version.');
        }
        try {
            $workflow = $this->workflows->get($request->workflow);
            $this->router->transport($workflow, $request->transition);
        } catch (\InvalidArgumentException $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        }
        $subject = $this->store->load($request->subject);
        if (null === $subject) {
            // Fail loudly, never ack: retrying cannot make the subject appear, so the failure transport.
            throw new UnrecoverableMessageHandlingException(sprintf('Missing subject for workflow "%s" transition "%s".', $request->workflow, $request->transition));
        }
        try {
            $workflow->apply($subject, $request->transition, $request->context);
        } catch (NotEnabledTransitionException $e) {
            $reasons = [];
            foreach ($e->getTransitionBlockerList() as $blocker) {
                $reasons[] = $blocker->getMessage();
            }

            return new TransitionResult($request->requestId, 'blocked', $reasons);
        }
        $this->store->save($request->subject, $subject, $request->context);

        return new TransitionResult($request->requestId, 'applied');
    }
}
