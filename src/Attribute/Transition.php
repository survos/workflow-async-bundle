<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Attribute;

use Survos\WorkflowExtrasBundle\Attribute\Transition as ExtrasTransition;
use Symfony\Component\Workflow\Arc;

/**
 * Extras Transition arguments unchanged, followed by `async`, `transport` and `batch`.
 */
#[\Attribute(\Attribute::TARGET_CLASS_CONSTANT | \Attribute::IS_REPEATABLE)]
class Transition extends ExtrasTransition
{
    public function __construct(
        \BackedEnum|Arc|string|array $from,
        \BackedEnum|Arc|string|array $to,
        ?string $description = null,
        ?string $label = null,
        string|array|null $next = null,
        ?string $guard = null,
        array $metadata = [],
        ?string $icon = null,
        ?string $iconClass = null,
        bool $async = true,
        ?string $transport = null,
        ?int $batch = null,
    ) {
        if (null !== $batch && ($batch < 1 || !$async)) {
            throw new \InvalidArgumentException('A positive batch size requires async: true.');
        }
        if (array_key_exists('batch', $metadata) && $metadata['batch'] !== $batch) {
            throw new \InvalidArgumentException('Use the "batch" argument instead of conflicting metadata.');
        }
        $routing = ['async' => $async, 'transport' => $transport];
        if (null !== $batch) {
            $routing['batch'] = $batch;
        }
        foreach ($routing as $key => $value) {
            if (array_key_exists($key, $metadata) && $metadata[$key] !== $value) {
                throw new \InvalidArgumentException(sprintf('Use the "%s" argument instead of conflicting metadata.', $key));
            }
        }
        if (!$async && null !== $transport) {
            throw new \InvalidArgumentException('A transport requires async: true.');
        }
        parent::__construct($from, $to, description: $description, label: $label, next: $next, guard: $guard, icon: $icon, iconClass: $iconClass, metadata: array_replace($metadata, $routing));
    }
}
