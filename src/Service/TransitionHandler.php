<?php

declare(strict_types=1);

namespace Survos\WorkflowAsyncBundle\Service;

use Survos\WorkflowAsyncBundle\Contract\SubjectStoreInterface;
use Survos\WorkflowAsyncBundle\Message\TransitionRequest;
use Survos\WorkflowAsyncBundle\Message\TransitionResult;
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
        if ($request->version !== 1) {
            throw new UnrecoverableMessageHandlingException('Unsupported workflow request version.');
        }
        try {
            $workflow = $this->workflows->get($request->workflow);
            $this->router->transport($workflow, $request->transition);
        } catch (\InvalidArgumentException $e) {
            throw new UnrecoverableMessageHandlingException($e->getMessage(), previous: $e);
        }
        $subject = $this->store->load($request->subject);
        if ($subject === null) {
            return new TransitionResult($request->requestId, 'missing');
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
        $this->store->save($request->subject, $subject);

        return new TransitionResult($request->requestId, 'applied');
    }
}
