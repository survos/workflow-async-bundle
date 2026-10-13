<?php

declare(strict_types=1);

namespace Survos\WorkflowAsyncBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\WorkflowAsyncBundle\Attribute\Transition as AsyncTransition;
use Survos\WorkflowAsyncBundle\Contract\SubjectStoreInterface;
use Survos\WorkflowAsyncBundle\Message\SubjectReference;
use Survos\WorkflowAsyncBundle\Message\TransitionRequest;
use Survos\WorkflowAsyncBundle\Service\TransitionHandler;
use Survos\WorkflowAsyncBundle\Service\TransitionRouter;
use Survos\WorkflowAsyncBundle\Service\WorkflowLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

final class AsyncTest extends TestCase
{
    public function testAttributePreservesNativeArgumentsAndUsesTypedRouting(): void
    {
        $attribute = new AsyncTransition('a', 'b', 'Fetch', 'subject.ready', transport: 'downloads');
        self::assertSame('subject.ready', $attribute->guard);
        self::assertSame(['async' => true, 'transport' => 'downloads', 'description' => 'Fetch'], $attribute->metadata);
    }

    public function testConflictingAttributeMetadataIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AsyncTransition('a', 'b', metadata: ['async' => false]);
    }

    public function testObjectsCannotBeSerializedAsContext(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TransitionRequest('test', new SubjectReference('test', '1'), 'go', ['nested' => [new \stdClass()]], 'id');
    }

    public function testRoutingUsesMetadataAndSupportsRepeatedTransitions(): void
    {
        $workflow = $this->workflow([['async' => true], ['async' => true, 'transport' => 'downloads']]);
        self::assertSame('downloads', (new TransitionRouter('downloads', ['downloads']))->transport($workflow, 'go'));
    }

    public function testRepeatedTransitionRoutingMustAgree(): void
    {
        $workflow = $this->workflow([['async' => true, 'transport' => 'one'], ['async' => true, 'transport' => 'two']]);
        $this->expectException(\InvalidArgumentException::class);
        (new TransitionRouter(null, ['one', 'two']))->transport($workflow, 'go');
    }

    public function testUnknownTransportDoesNotFallBackToSynchronousExecution(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TransitionRouter(null, ['downloads']))->transport($this->workflow([['async' => true, 'transport' => 'typo']]), 'go');
    }

    public function testSynchronousTransitionCannotBeDispatched(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TransitionRouter('downloads', ['downloads']))->transport($this->workflow([[]]), 'go');
    }

    public function testListenerFailurePropagatesAndDoesNotSave(): void
    {
        $definition = $this->workflow([['async' => true]])->getDefinition();
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('getDefinition')->willReturn($definition);
        $workflow->method('getMetadataStore')->willReturn($definition->getMetadataStore());
        $subject = new \stdClass();
        $workflow->expects(self::once())->method('apply')->with($subject, 'go', ['trace' => 123])->willThrowException(new \RuntimeException('retry me'));
        $store = $this->createMock(SubjectStoreInterface::class);
        $store->method('load')->willReturn($subject);
        $store->expects(self::never())->method('save');
        $locator = new WorkflowLocator(new ServiceLocator(['test' => static fn () => $workflow]));
        $handler = new TransitionHandler($store, $locator, new TransitionRouter('downloads', ['downloads']));
        $this->expectExceptionMessage('retry me');
        $handler(new TransitionRequest('test', new SubjectReference('test', '1'), 'go', ['trace' => 123], 'request-1'));
    }

    public function testSuccessfulApplyPassesContextToPersistence(): void
    {
        $workflow = $this->workflow([['async' => true]]);
        $subject = new class { public string $marking = 'a'; };
        $reference = new SubjectReference('test', '1');
        $store = $this->createMock(SubjectStoreInterface::class);
        $store->method('load')->willReturn($subject);
        $store->expects(self::once())->method('save')->with($reference, $subject, ['cascade' => 'none']);
        $handler = new TransitionHandler($store, new WorkflowLocator(new ServiceLocator(['test' => static fn () => $workflow])), new TransitionRouter('downloads', ['downloads']));
        self::assertSame('applied', $handler(new TransitionRequest('test', $reference, 'go', ['cascade' => 'none'], 'request-1'))->status);
        self::assertSame('b', $subject->marking);
    }

    public function testMissingSubjectFailsInsteadOfBeingAcknowledged(): void
    {
        $workflow = $this->workflow([['async' => true]]);
        $store = $this->createMock(SubjectStoreInterface::class);
        $store->method('load')->willReturn(null);
        $store->expects(self::never())->method('save');
        $handler = new TransitionHandler($store, new WorkflowLocator(new ServiceLocator(['test' => static fn () => $workflow])), new TransitionRouter('downloads', ['downloads']));
        $this->expectException(\Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('Missing subject for workflow "test" transition "go".');
        $handler(new TransitionRequest('test', new SubjectReference('test', 'gone'), 'go', [], 'request-1'));
    }

    private function workflow(array $metadata): StateMachine
    {
        $transitions = [];
        $store = new \SplObjectStorage();
        foreach ($metadata as $data) {
            $transition = new Transition('go', 'a', 'b');
            $transitions[] = $transition;
            $store[$transition] = $data;
        }

        return new StateMachine(new Definition(['a', 'b'], $transitions, 'a', new InMemoryMetadataStore([], [], $store)));
    }
}
