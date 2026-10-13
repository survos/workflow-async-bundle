<?php

declare(strict_types=1);

namespace Survos\WorkflowAsyncBundle\Attribute;

use Symfony\Component\Workflow\Arc;
use Symfony\Component\Workflow\Attribute\Transition as NativeTransition;

/**
 * Native Transition (from, to, description, guard, metadata) plus `async` and `transport`.
 */
#[\Attribute(\Attribute::TARGET_CLASS_CONSTANT | \Attribute::IS_REPEATABLE)]
class Transition extends NativeTransition
{
    public function __construct(
        \BackedEnum|Arc|string|array $from,
        \BackedEnum|Arc|string|array $to,
        ?string $description = null,
        ?string $guard = null,
        array $metadata = [],
        bool $async = true,
        ?string $transport = null,
    ) {
        foreach (['async' => $async, 'transport' => $transport] as $key => $value) {
            if (array_key_exists($key, $metadata) && $metadata[$key] !== $value) {
                throw new \InvalidArgumentException(sprintf('Use the "%s" argument instead of conflicting metadata.', $key));
            }
        }
        if (!$async && null !== $transport) {
            throw new \InvalidArgumentException('A transport requires async: true.');
        }
        parent::__construct($from, $to, description: $description, guard: $guard, metadata: array_replace($metadata, ['async' => $async, 'transport' => $transport]));
    }
}
