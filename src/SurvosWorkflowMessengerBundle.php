<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\WorkflowExtrasBundle\SurvosWorkflowExtrasBundle;
use Survos\WorkflowMessengerBundle\Compiler\TransportNamesPass;
use Survos\WorkflowMessengerBundle\Contract\SubjectStoreInterface;
use Survos\WorkflowMessengerBundle\Service\TransitionDispatcher;
use Survos\WorkflowMessengerBundle\Service\TransitionHandler;
use Survos\WorkflowMessengerBundle\Service\TransitionRouter;
use Survos\WorkflowMessengerBundle\Service\WorkflowLocator;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Lock\LockBundle;
use Symfony\Component\Messenger\MessengerBundle;
use Symfony\Component\Workflow\WorkflowBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

#[RequiredBundle(SurvosKitBundle::class)]
#[RequiredBundle(WorkflowBundle::class)]
#[RequiredBundle(SurvosWorkflowExtrasBundle::class)]
#[RequiredBundle(MessengerBundle::class)]
#[RequiredBundle(LockBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosWorkflowMessengerBundle extends AbstractSurvosBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TransportNamesPass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->scalarNode('subject_store')
                ->info('Service implementing SubjectStoreInterface; defaults to the Doctrine store when DoctrineBundle is installed.')
                ->defaultNull()
            ->end()
            ->scalarNode('default_transport')->defaultNull()->end()
            ->scalarNode('lock_factory')
                ->info('LockFactory serializing transitions per subject across workers. Use a store shared by every worker host.')
                ->defaultValue('lock.factory')
                ->cannotBeEmpty()
            ->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        foreach ([WorkflowLocator::class, TransitionRouter::class, TransitionDispatcher::class, TransitionHandler::class, Service\MessengerTransitionExecutor::class] as $class) {
            $services->set($class);
        }
        $services->get(TransitionRouter::class)->arg('$defaultTransport', $config['default_transport']);
        $services->get(TransitionHandler::class)->arg('$locks', service($config['lock_factory']));
        $services->alias(\Survos\WorkflowExtrasBundle\Contract\TransitionExecutorInterface::class, Service\MessengerTransitionExecutor::class);
        if (null === $store = $config['subject_store']) {
            if (!class_exists(\Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class)) {
                throw new \LogicException('Configure survos_workflow_messenger.subject_store, or install doctrine/doctrine-bundle to use the default Doctrine subject store.');
            }
            $services->set($store = Doctrine\DoctrineSubjectStore::class);
        }
        $services->alias(SubjectStoreInterface::class, $store);
    }
}
