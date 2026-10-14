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

    public function batchSize(WorkflowInterface $workflow, string $name): ?int
    {
        $size = null;
        $found = false;
        foreach ($workflow->getDefinition()->getTransitions() as $transition) {
            if ($transition->getName() !== $name) {
                continue;
            }
            $metadata = $workflow->getMetadataStore()->getTransitionMetadata($transition);
            $candidate = $metadata['batch'] ?? null;
            if (null !== $candidate && (!is_int($candidate) || $candidate < 1 || !($metadata['async'] ?? false))) {
                throw new \InvalidArgumentException(sprintf('Transition "%s" needs a positive batch size and async: true.', $name));
            }
            if ($found && $size !== $candidate) {
                throw new \InvalidArgumentException(sprintf('Repeated transition "%s" has conflicting batch sizes.', $name));
            }
            $size = $candidate;
            $found = true;
        }
        if (!$found) {
            throw new \InvalidArgumentException(sprintf('Unknown transition "%s".', $name));
        }

        return $size;
    }

    public function transport(WorkflowInterface $workflow, string $name, bool $forceSync = false): ?string
    {
        $route = null;
        $found = false;
        foreach ($workflow->getDefinition()->getTransitions() as $transition) {
            if ($transition->getName() !== $name) {
                continue;
            }
            $metadata = $workflow->getMetadataStore()->getTransitionMetadata($transition);
            $transport = ($metadata['async'] ?? false) && !$forceSync ? ($metadata['transport'] ?? $this->defaultTransport) : (in_array('sync', $this->transports, true) ? 'sync' : null);
            if (($metadata['async'] ?? false) && !$forceSync && (!is_string($transport) || !in_array($transport, $this->transports, true))) {
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
