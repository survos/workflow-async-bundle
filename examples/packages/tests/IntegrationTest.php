<?php

declare(strict_types=1);

namespace Survos\WorkflowExample\Tests;

use PHPUnit\Framework\TestCase;
use Survos\WorkflowAsyncBundle\Message\SubjectReference;
use Survos\WorkflowAsyncBundle\Message\TransitionRequest;
use Survos\WorkflowAsyncBundle\Service\TransitionDispatcher;
use Survos\WorkflowExample\ExampleKernel;
use Survos\WorkflowExample\Package;
use Survos\WorkflowExample\PackageStore;
use Survos\WorkflowExample\PackageWorkflow;
use Survos\WorkflowExample\RunQueue;
use Survos\WorkflowExtrasBundle\Service\NextTransitionResolver;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class IntegrationTest extends TestCase
{
    private ExampleKernel $kernel;
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/workflow-example-'.bin2hex(random_bytes(6));
        $this->kernel = new ExampleKernel($this->directory);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        (new Filesystem())->remove($this->directory);
    }

    public function testTwoSerializedQueuesExecuteNativeListenersAndPersistState(): void
    {
        $container = $this->kernel->getContainer();
        $container->set(HttpClientInterface::class, new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, 'repo.packagist.org')) {
                return new MockResponse(file_get_contents(dirname(__DIR__).'/fixtures/package.json'));
            }
            self::assertStringContainsString('/repos/demo/example-bundle/readme?ref=0123456789abcdef', $url);

            return new MockResponse('# Hello Symfony');
        }));
        $store = $container->get(PackageStore::class);
        $ref = new SubjectReference('package', 'demo/example-bundle');
        $store->save($ref, new Package($ref->id));
        $dispatcher = $container->get('example.'.TransitionDispatcher::class);
        $metadata = $container->get('example.messenger.transport.package_metadata');
        $readme = $container->get('example.messenger.transport.package_readme');
        $bus = $container->get('example.messenger.default_bus');
        $workflow = $container->get('example.state_machine.package_readme');

        $envelope = $dispatcher->dispatch('package_readme', $ref, PackageWorkflow::LOAD, ['source' => 'test']);
        self::assertInstanceOf(TransitionRequest::class, $envelope->getMessage());
        self::assertSame('discovered', $store->load($ref)->marking);
        self::assertCount(1, $metadata->getSent());
        self::assertCount(0, $readme->getSent());
        RunQueue::once('package_metadata', $metadata, $bus);
        self::assertCount(1, $metadata->getAcknowledged());
        $package = $store->load($ref);
        self::assertSame('loaded', $package->marking);
        self::assertTrue($package->eligible);
        self::assertSame('Fetch metadata and README for a Symfony 8 bundle', $workflow->getMetadataStore()->getWorkflowMetadata()['description']);
        self::assertSame(['validate', 'reject'], $workflow->getMetadataStore()->getPlaceMetadata('loaded')['next']);

        $next = $container->get('example.'.NextTransitionResolver::class)->firstEnabled($workflow, $package, ['reject', 'validate']);
        self::assertSame('validate', $next);
        $workflow->apply($package, $next);
        $store->save($ref, $package);
        $dispatcher->dispatch('package_readme', $ref, PackageWorkflow::FETCH_README);
        self::assertCount(1, $readme->getSent());
        self::assertSame('valid', $store->load($ref)->marking);
        RunQueue::once('package_readme', $readme, $bus);
        self::assertSame('# Hello Symfony', $store->load($ref)->readme);
        self::assertSame('readme_checked', $store->load($ref)->marking);

        // A redelivery cannot re-run LOAD after the subject has advanced.
        $result = $bus->dispatch($envelope->getMessage(), [new ReceivedStamp('package_metadata')])->last(HandledStamp::class)->getResult();
        self::assertSame('blocked', $result->status);
        self::assertNotEmpty($result->reasons);
    }

    public function testMissingSubjectsFailWithoutRetry(): void
    {
        $bus = $this->kernel->getContainer()->get('example.messenger.default_bus');
        $request = new TransitionRequest('package_readme', new SubjectReference('package', 'missing/bundle'), 'load', requestId: 'missing-test');
        try {
            $bus->dispatch($request, [new ReceivedStamp('package_metadata')]);
            self::fail('Missing subjects must fail instead of being acknowledged.');
        } catch (\Symfony\Component\Messenger\Exception\HandlerFailedException $exception) {
            self::assertInstanceOf(\Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException::class, $exception->getPrevious());
            self::assertStringContainsString('Missing subject', $exception->getMessage());
        }
    }

    public function testMissingReadmeCompletesNormally(): void
    {
        $container = $this->kernel->getContainer();
        $container->set(HttpClientInterface::class, new MockHttpClient(new MockResponse('', ['http_code' => 404])));
        $package = new Package('demo/example-bundle');
        $package->marking = 'valid';
        $package->eligible = true;
        $package->metadata = ['source' => ['url' => 'https://github.com/demo/example-bundle.git', 'reference' => 'abc']];
        $store = $container->get(PackageStore::class);
        $ref = new SubjectReference('package', $package->name);
        $store->save($ref, $package);
        $container->get('example.'.TransitionDispatcher::class)->dispatch('package_readme', $ref, 'fetch_readme');
        RunQueue::once('package_readme', $container->get('example.messenger.transport.package_readme'), $container->get('example.messenger.default_bus'));
        self::assertSame('missing', $store->load($ref)->readmeStatus);
        self::assertSame('readme_checked', $store->load($ref)->marking);
    }

    public function testGuardIsCheckedAgainInTheWorker(): void
    {
        $container = $this->kernel->getContainer();
        $container->set(HttpClientInterface::class, new MockHttpClient(static function (): never {
            self::fail('A blocked transition must not fetch the README.');
        }));
        $store = $container->get(PackageStore::class);
        $ref = new SubjectReference('package', 'demo/example-bundle');
        $package = new Package($ref->id);
        $package->marking = 'valid';
        $package->eligible = true;
        $store->save($ref, $package);
        $container->get('example.'.TransitionDispatcher::class)->dispatch('package_readme', $ref, 'fetch_readme');
        $package->eligible = false;
        $store->save($ref, $package);
        $transport = $container->get('example.messenger.transport.package_readme');
        RunQueue::once('package_readme', $transport, $container->get('example.messenger.default_bus'));
        self::assertCount(1, $transport->getAcknowledged());
        self::assertSame('valid', $store->load($ref)->marking);
        self::assertNull($store->load($ref)->readmeStatus);
    }

    public function testNetworkFailureDoesNotBecomeMissingOrAdvanceStoredMarking(): void
    {
        $container = $this->kernel->getContainer();
        $container->set(HttpClientInterface::class, new MockHttpClient(new MockResponse('Unavailable', ['http_code' => 503])));
        $package = new Package('demo/example-bundle');
        $package->marking = 'valid';
        $package->eligible = true;
        $package->metadata = ['source' => ['url' => 'https://github.com/demo/example-bundle.git', 'reference' => 'abc']];
        $ref = new SubjectReference('package', $package->name);
        $store = $container->get(PackageStore::class);
        $store->save($ref, $package);
        $container->get('example.'.TransitionDispatcher::class)->dispatch('package_readme', $ref, 'fetch_readme');
        $transport = $container->get('example.messenger.transport.package_readme');
        RunQueue::once('package_readme', $transport, $container->get('example.messenger.default_bus'));
        self::assertCount(1, $transport->getRejected());
        self::assertSame('valid', $store->load($ref)->marking);
        self::assertNull($store->load($ref)->readmeStatus);
    }
}
