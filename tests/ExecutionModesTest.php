<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\WorkflowExtrasBundle\EventListener\NextTransitionListener;
use Survos\WorkflowExtrasBundle\Service\DirectTransitionExecutor;
use Survos\WorkflowExtrasBundle\Service\NextTransitionResolver;
use Survos\WorkflowExtrasBundle\Service\NextTransitionRunner;
use Survos\WorkflowExtrasBundle\Service\WorkflowCatalog;
use Survos\WorkflowMessengerBundle\Contract\SubjectStoreInterface;
use Survos\WorkflowMessengerBundle\Message\SubjectReference;
use Survos\WorkflowMessengerBundle\Message\TransitionRequest;
use Survos\WorkflowMessengerBundle\Service\MessengerTransitionExecutor;
use Survos\WorkflowMessengerBundle\Service\TransitionDispatcher;
use Survos\WorkflowMessengerBundle\Service\TransitionHandler;
use Survos\WorkflowMessengerBundle\Service\TransitionRouter;
use Survos\WorkflowMessengerBundle\Service\WorkflowLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

final class ExecutionModesTest extends TestCase
{
    public static function modes(): iterable
    {
        yield 'native apply' => ['direct'];
        yield 'Messenger sync transport' => ['sync'];
        yield 'serialized queue' => ['queue'];
    }

    #[DataProvider('modes')]
    public function testSameChainAcrossExecutors(string $mode): void
    {
        $subject = new class {
            public string $marking = 'new';
        };
        $store = new class($subject) implements SubjectStoreInterface {
            public array $saved = [];

            public function __construct(public object $subject)
            {
            }

            public function reference(object $subject): SubjectReference
            {
                return new SubjectReference('example', '1');
            }

            public function load(SubjectReference $reference): ?object
            {
                return clone $this->subject;
            }

            public function save(SubjectReference $reference, object $subject, array $context = []): void
            {
                $this->subject = clone $subject;
                $this->saved[] = $subject->marking;
            }
        };
        $first = new Transition('start', 'new', 'loaded');
        $last = new Transition('finish', 'loaded', 'done');
        $transitions = new \SplObjectStorage();
        foreach ([$first, $last] as $transition) {
            $transitions[$transition] = ['async' => true, 'transport' => 'queue'];
        }
        $events = new EventDispatcher();
        $workflow = new StateMachine(new Definition(['new', 'loaded', 'done'], [$first, $last], 'new', new InMemoryMetadataStore([], ['new' => ['next' => ['start']], 'loaded' => ['next' => ['finish']]], $transitions)), new MethodMarkingStore(true, 'marking'), $events, 'example');
        $locator = new ServiceLocator(['example' => static fn () => $workflow]);
        $queue = new InMemoryTransport(new PhpSerializer());
        $sync = null;
        $handler = null;
        $bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator([], new ServiceLocator(['queue' => static fn () => $queue, 'sync' => static function () use (&$sync) { return $sync; }]))),
            new HandleMessageMiddleware(new HandlersLocator([TransitionRequest::class => [static function ($message) use (&$handler) { return $handler($message); }]])),
        ]);
        $sync = new SyncTransport($bus);
        $router = new TransitionRouter(null, ['queue', 'sync']);
        $dispatcher = new TransitionDispatcher($bus, new WorkflowLocator($locator), $router);
        $executor = 'direct' === $mode ? new DirectTransitionExecutor() : new MessengerTransitionExecutor($dispatcher, $store);
        $runner = new NextTransitionRunner(new NextTransitionResolver(), $executor);
        $events->addListener('workflow.completed', (new NextTransitionListener($runner, new WorkflowCatalog($locator, ['example' => []]), $executor))->completed(...));
        // Sync mode re-enters the handler for the same subject under the outer lock.
        $locks = new LockFactory(new InMemoryStore());
        $handler = new TransitionHandler($store, new WorkflowLocator($locator), $router, $locks, $runner);
        $runner->continue($workflow, $subject, 'sync' === $mode ? ['cascade' => 'sync'] : []);
        if ('queue' === $mode) {
            self::assertSame('new', $store->subject->marking, 'No work before a worker receives the message.');
            for ($i = 0; $i < 2; ++$i) {
                foreach ($queue->get() as $envelope) {
                    $bus->dispatch($envelope->with(new ReceivedStamp('queue')));
                    $queue->ack($envelope);
                    break;
                }
            }
            self::assertCount(2, $queue->getAcknowledged());
        } else {
            self::assertCount(0, $queue->getSent());
        }
        self::assertSame('done', 'direct' === $mode ? $subject->marking : $store->subject->marking);
        if ('direct' !== $mode) {
            self::assertSame(['loaded', 'done'], $store->saved, 'Persist each state before continuing.');
        }
        self::assertTrue($locks->createLock('survos_workflow:example:1')->acquire(), 'The subject lock is released.');
    }

    public function testBusySubjectIsRetriedNotRunConcurrently(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $competitor = $locks->createLock('survos_workflow:example:1');
        $competitor->acquire();
        $store = $this->createMock(SubjectStoreInterface::class);
        $store->expects(self::never())->method('load');
        $workflow = new StateMachine(new Definition(['new', 'done'], [new Transition('start', 'new', 'done')], 'new'), new MethodMarkingStore(true, 'marking'), null, 'example');
        $handler = new TransitionHandler($store, new WorkflowLocator(new ServiceLocator(['example' => static fn () => $workflow])), new TransitionRouter('queue', ['queue']), $locks);

        $this->expectException(RecoverableMessageHandlingException::class);
        $handler(new TransitionRequest('example', new SubjectReference('example', '1'), 'start', [], 'test'));
    }
}
