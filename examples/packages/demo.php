<?php

declare(strict_types=1);

use Survos\WorkflowAsyncBundle\Message\SubjectReference;
use Survos\WorkflowAsyncBundle\Service\TransitionDispatcher;
use Survos\WorkflowExample\ExampleKernel;
use Survos\WorkflowExample\Package;
use Survos\WorkflowExample\PackageStore;
use Survos\WorkflowExample\PackageWorkflow;
use Survos\WorkflowExample\RunQueue;
use Survos\WorkflowExtrasBundle\Service\NextTransitionResolver;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

require __DIR__.'/vendor/autoload.php';

$offline = in_array('--offline', $argv, true);
$name = $offline ? 'demo/example-bundle' : ($argv[1] ?? 'symfony/monolog-bundle');
$kernel = new ExampleKernel(__DIR__.'/var');
$kernel->boot();
$container = $kernel->getContainer();
if ($offline) {
    $container->set(HttpClientInterface::class, new MockHttpClient([
        new MockResponse(file_get_contents(__DIR__.'/fixtures/package.json')),
        new MockResponse('# Example bundle'.PHP_EOL.'Fetched in a native transition listener.'.PHP_EOL),
    ]));
}
$store = $container->get(PackageStore::class);
$reference = new SubjectReference('package', $name);
if ($store->load($reference) !== null) {
    throw new RuntimeException('This package already has saved state. Use another package or remove its demo JSON file in var/subjects to start over.');
}
$store->save($reference, new Package($name));
$dispatcher = $container->get('example.'.TransitionDispatcher::class);
$workflow = $container->get('example.state_machine.package_readme');
$bus = $container->get('example.messenger.default_bus');
$next = $container->get('example.'.NextTransitionResolver::class);

$dispatcher->dispatch('package_readme', $reference, PackageWorkflow::LOAD);
printf("Queued load on package_metadata; marking is still %s\n", $store->load($reference)->marking);
RunQueue::once('package_metadata', $container->get('example.messenger.transport.package_metadata'), $bus);
$package = $store->load($reference);
if ($package->marking !== PackageWorkflow::LOADED) {
    throw new RuntimeException('Metadata worker failed; inspect the HTTP response and retry from saved state.');
}
$transition = $next->firstEnabled($workflow, $package, [PackageWorkflow::VALIDATE, PackageWorkflow::REJECT]);
$workflow->apply($package, $transition);
$store->save($reference, $package);
printf("Validation: %s\n", $package->marking);
if (!$package->eligible) {
    echo "README skipped: selected release is not an eligible Symfony 8 bundle.\n";
    exit(0);
}

// Explicitly dispatch only after validation has been persisted. No automatic event chaining.
$dispatcher->dispatch('package_readme', $reference, PackageWorkflow::FETCH_README);
echo "Queued fetch_readme on package_readme\n";
RunQueue::once('package_readme', $container->get('example.messenger.transport.package_readme'), $bus);
$package = $store->load($reference);
if ($package->marking !== PackageWorkflow::README_CHECKED) {
    throw new RuntimeException('README worker failed; package remains valid and can be retried.');
}
printf("%s: %s; README %s (%d bytes)\n", $package->name, $package->marking, $package->readmeStatus, strlen($package->readme ?? ''));
