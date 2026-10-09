<?php

declare(strict_types=1);

namespace Survos\WorkflowAsyncBundle\Compiler;

use Survos\WorkflowAsyncBundle\Service\TransitionRouter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Read registered Messenger services, never raw framework or workflow configuration. */
final class TransportNamesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $names = [];
        foreach ($container->findTaggedServiceIds('messenger.receiver') as $tags) {
            foreach ($tags as $tag) {
                if (isset($tag['alias']) && ($tag['is_consumable'] ?? true) && !($tag['is_failure_transport'] ?? false)) {
                    $names[] = $tag['alias'];
                }
            }
        }
        $container->getDefinition(TransitionRouter::class)->setArgument('$transports', array_values(array_unique($names)));
    }
}
