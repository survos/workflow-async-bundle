<?php

declare(strict_types=1);

namespace Survos\WorkflowExample;

use Survos\WorkflowAsyncBundle\Service\TransitionDispatcher;
use Survos\WorkflowAsyncBundle\SurvosWorkflowAsyncBundle;
use Survos\WorkflowExtrasBundle\Service\NextTransitionResolver;
use Survos\WorkflowExtrasBundle\SurvosWorkflowExtrasBundle;
use Symfony\Component\DependencyInjection\Kernel\AbstractKernel;
use Symfony\Component\DependencyInjection\Kernel\KernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ExampleKernel extends AbstractKernel
{
    use KernelTrait;

    public function __construct(private string $directory)
    {
        parent::__construct('dev', true);
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }

    public function getCacheDir(): string
    {
        return $this->directory.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->directory.'/log';
    }

    public function registerBundles(): iterable
    {
        yield new SurvosWorkflowAsyncBundle();
        yield new SurvosWorkflowExtrasBundle();
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('workflow', []);
        $container->extension('messenger', ['transports' => [
            'package_metadata' => ['dsn' => 'in-memory://', 'options' => ['serialize' => true]],
            'package_readme' => ['dsn' => 'in-memory://', 'options' => ['serialize' => true]],
        ]]);
        $container->extension('survos_workflow_async', ['subject_store' => PackageStore::class]);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(PackageStore::class)->arg('$directory', $this->directory.'/subjects')->public();
        $services->set(PackageWorkflow::class);
        $services->set(HttpClientInterface::class)->factory([HttpClient::class, 'create'])->public();
        foreach ([TransitionDispatcher::class, NextTransitionResolver::class, 'state_machine.package_readme', 'messenger.default_bus', 'event_dispatcher', 'messenger.transport.package_metadata', 'messenger.transport.package_readme'] as $id) {
            $services->alias('example.'.$id, $id)->public();
        }
    }
}
