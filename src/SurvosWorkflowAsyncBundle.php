<?php

declare(strict_types=1);

namespace Survos\WorkflowAsyncBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\WorkflowAsyncBundle\Compiler\TransportNamesPass;
use Survos\WorkflowAsyncBundle\Contract\SubjectStoreInterface;
use Survos\WorkflowAsyncBundle\Service\TransitionDispatcher;
use Survos\WorkflowAsyncBundle\Service\TransitionHandler;
use Survos\WorkflowAsyncBundle\Service\TransitionRouter;
use Survos\WorkflowAsyncBundle\Service\WorkflowLocator;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Messenger\MessengerBundle;
use Symfony\Component\Workflow\WorkflowBundle;

#[RequiredBundle(SurvosKitBundle::class)]
#[RequiredBundle(WorkflowBundle::class)]
#[RequiredBundle(MessengerBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosWorkflowAsyncBundle extends AbstractSurvosBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TransportNamesPass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->scalarNode('subject_store')->isRequired()->cannotBeEmpty()->end()
            ->scalarNode('default_transport')->defaultNull()->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        foreach ([WorkflowLocator::class, TransitionRouter::class, TransitionDispatcher::class, TransitionHandler::class] as $class) {
            $services->set($class);
        }
        $services->get(TransitionRouter::class)->arg('$defaultTransport', $config['default_transport']);
        $services->alias(SubjectStoreInterface::class, $config['subject_store']);
    }
}
