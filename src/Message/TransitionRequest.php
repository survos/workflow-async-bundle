<?php

declare(strict_types=1);

namespace Survos\WorkflowAsyncBundle\Message;

/** Versioned data only; never put entities or events in a queue. */
final readonly class TransitionRequest
{
    public function __construct(
        public string $workflow,
        public SubjectReference $subject,
        public string $transition,
        public array $context = [],
        public string $requestId = '',
        public int $version = 1,
    ) {
        if ('' === $workflow || '' === $transition || '' === $requestId || 1 !== $version) {
            throw new \InvalidArgumentException('A version-1 transition request needs workflow, transition and request ID.');
        }
        self::validateContext($context);
    }

    private static function validateContext(array $context): void
    {
        // Also detects recursive arrays and non-finite floats before traversing.
        json_encode($context, JSON_THROW_ON_ERROR);
        foreach ($context as $value) {
            if (is_array($value)) {
                self::validateContext($value);
            } elseif (null !== $value && !is_scalar($value)) {
                throw new \InvalidArgumentException('Context must contain only arrays, scalar values and null.');
            }
        }
    }
}
