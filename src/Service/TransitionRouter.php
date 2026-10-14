<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Service;

use Symfony\Component\Workflow\WorkflowInterface;

final readonly class TransitionRouter
{
    /** @param list<string> $transports Consumable Messenger transport aliases. */
    public function __construct(private ?string $defaultTransport = null, private array $transports = [])
    {
    }

    public function transport(WorkflowInterface $workflow, string $name): string
    {
        $route = null;
        $found = false;
        foreach ($workflow->getDefinition()->getTransitions() as $transition) {
            if ($transition->getName() !== $name) {
                continue;
            }
            $metadata = $workflow->getMetadataStore()->getTransitionMetadata($transition);
            if (($metadata['async'] ?? false) !== true) {
                throw new \InvalidArgumentException(sprintf('Transition "%s" must declare async metadata as true.', $name));
            }
            $transport = $metadata['transport'] ?? $this->defaultTransport;
            if (!is_string($transport) || !in_array($transport, $this->transports, true)) {
                throw new \InvalidArgumentException(sprintf('Transition "%s" needs a configured consumable Messenger transport.', $name));
            }
            if ($found && $route !== $transport) {
                throw new \InvalidArgumentException(sprintf('Repeated transition "%s" has conflicting transport metadata.', $name));
            }
            $found = true;
            $route = $transport;
        }
        if (!$found) {
            throw new \InvalidArgumentException(sprintf('Unknown transition "%s".', $name));
        }

        return $route;
    }
}
