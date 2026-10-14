<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Service;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Workflow\WorkflowInterface;

final readonly class WorkflowLocator
{
    public function __construct(
        #[AutowireLocator('workflow', indexAttribute: 'name')]
        private ContainerInterface $workflows,
    ) {
    }

    public function get(string $name): WorkflowInterface
    {
        if (!$this->workflows->has($name)) {
            throw new \InvalidArgumentException(sprintf('Unknown workflow "%s".', $name));
        }

        return $this->workflows->get($name);
    }
}
