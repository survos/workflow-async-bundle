<?php

declare(strict_types=1);

namespace Survos\WorkflowExample;

final class Package
{
    public string $marking = 'discovered';
    public array $metadata = [];
    public bool $eligible = false;
    public ?string $readme = null;
    public ?string $readmeStatus = null;

    public function __construct(public readonly string $name)
    {
        if (!preg_match('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}D', $name)) {
            throw new \InvalidArgumentException('Use a Composer vendor/package name.');
        }
    }
}
