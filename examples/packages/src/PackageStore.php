<?php

declare(strict_types=1);

namespace Survos\WorkflowExample;

use Survos\WorkflowAsyncBundle\Contract\SubjectStoreInterface;
use Survos\WorkflowAsyncBundle\Message\SubjectReference;

/** Tutorial persistence only: atomic writes, but no transaction or concurrency protection. */
final readonly class PackageStore implements SubjectStoreInterface
{
    public function __construct(private string $directory)
    {
    }

    public function load(SubjectReference $reference): ?Package
    {
        $path = $this->path($reference);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $package = new Package($reference->id);
        foreach (['marking', 'metadata', 'eligible', 'readme', 'readmeStatus'] as $property) {
            $package->$property = $data[$property];
        }

        return $package;
    }

    public function save(SubjectReference $reference, object $subject, array $context = []): void
    {
        if (!$subject instanceof Package || $subject->name !== $reference->id) {
            throw new \InvalidArgumentException('Subject does not match its package reference.');
        }
        $path = $this->path($reference);
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create package storage.');
        }
        $temporary = tempnam($this->directory, 'package-');
        try {
            if (false === $temporary || false === file_put_contents($temporary, json_encode($subject, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) || !rename($temporary, $path)) {
                throw new \RuntimeException('Cannot save package.');
            }
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function path(SubjectReference $reference): string
    {
        if ('package' !== $reference->type) {
            throw new \InvalidArgumentException('Unsupported subject type.');
        }

        return $this->directory.'/'.hash('sha256', $reference->id).'.json';
    }
}
