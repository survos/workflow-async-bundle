<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\WorkflowMessengerBundle\Attribute\Transition as AsyncTransition;
use Survos\WorkflowMessengerBundle\Contract\SubjectStoreInterface;
use Survos\WorkflowMessengerBundle\Message\SubjectReference;
use Survos\WorkflowMessengerBundle\Message\TransitionRequest;
use Survos\WorkflowMessengerBundle\Service\TransitionHandler;
use Survos\WorkflowMessengerBundle\Service\TransitionRouter;
use Survos\WorkflowMessengerBundle\Service\WorkflowLocator;
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
        $attribute = new AsyncTransition('a', 'b', 'Fetch', guard: 'subject.ready', transport: 'downloads');
        self::assertSame('subject.ready', $attribute->guard);
        self::assertSame(['async' => true, 'transport' => 'downloads', 'guard' => 'subject.ready', 'description' => 'Fetch'], $attribute->metadata);
    }

    public function testConflictingAttributeMetadataIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AsyncTransition('a', 'b', metadata: ['async' => false]);
    }

    public function testAsyncAppendsToTheExactExtrasSignature(): void
    {
        $extras = (new \ReflectionMethod(\Survos\WorkflowExtrasBundle\Attribute\Transition::class, '__construct'))->getParameters();
        $async = (new \ReflectionMethod(AsyncTransition::class, '__construct'))->getParameters();
        foreach ($extras as $i => $parameter) {
            self::assertSame($parameter->getName(), $async[$i]->getName());
            self::assertSame((string) $parameter->getType(), (string) $async[$i]->getType());
            self::assertSame($parameter->isDefaultValueAvailable(), $async[$i]->isDefaultValueAvailable());
            if ($parameter->isDefaultValueAvailable()) {
                self::assertSame($parameter->getDefaultValue(), $async[$i]->getDefaultValue());
            }
        }
        self::assertSame(['async', 'transport'], array_map(static fn ($p) => $p->getName(), array_slice($async, count($extras))));
        $attribute = new AsyncTransition('a', 'b', 'Fetch', 'Load', 'validate', 'subject.ready', ['color' => 'blue'], 'tabler:download', 'text-primary', true, 'downloads');
        self::assertInstanceOf(\Survos\WorkflowExtrasBundle\Attribute\Transition::class, $attribute);
        self::assertSame('Load', $attribute->metadata['label']);
        self::assertSame(['validate'], $attribute->metadata['next']);
        self::assertSame('subject.ready', $attribute->guard);
        self::assertSame('downloads', $attribute->metadata['transport']);
    }

    public function testTransportRequiresAsync(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AsyncTransition('a', 'b', async: false, transport: 'downloads');
    }

    public function testNativeReaderPreservesAsyncAndExtrasMetadataWithEnumEndpoints(): void
    {
        $class = new \ReflectionClass(AsyncEnumDefinition::class);
        $attribute = $class->getAttributes(\Symfony\Component\Workflow\Attribute\AsWorkflow::class)[0]->newInstance();
        $config = (new \Symfony\Component\Workflow\DependencyInjection\AttributeReader())->read($attribute, $class)['async_enum'];
        $transition = $config['transitions'][0];
        self::assertSame([['place' => 'new']], $transition['from']);
        self::assertSame([['place' => 'done']], $transition['to']);
        self::assertSame('Finish', $transition['metadata']['label']);
        self::assertSame(['notify'], $transition['metadata']['next']);
        self::assertTrue($transition['metadata']['async']);
        self::assertSame('downloads', $transition['metadata']['transport']);
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

    public function testSynchronousTransitionUsesTheBusWithoutAQueuedTransport(): void
    {
        self::assertNull((new TransitionRouter('downloads', ['downloads']))->transport($this->workflow([[]]), 'go'));
    }

    public function testListenerFailurePropagatesAndDoesNotSave(): void
    {
        $definition = $this->workflow([['async' => true]])->getDefinition();
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('getDefinition')->willReturn($definition);
        $workflow->method('getMetadataStore')->willReturn($definition->getMetadataStore());
        $subject = new \stdClass();
        $workflow->expects(self::once())->method('apply')->with($subject, 'go', ['trace' => 123, 'workflow_extras.defer' => true])->willThrowException(new \RuntimeException('retry me'));
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
        $subject = new class {
            public string $marking = 'a';
        };
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

enum AsyncEnumPlace: string
{
    case New = 'new';
    case Done = 'done';
}

#[\Symfony\Component\Workflow\Attribute\AsWorkflow(name: 'async_enum', places: AsyncEnumPlace::class)]
final class AsyncEnumDefinition
{
    #[AsyncTransition(AsyncEnumPlace::New, AsyncEnumPlace::Done, label: 'Finish', next: 'notify', transport: 'downloads')]
    public const FINISH = 'finish';
}
