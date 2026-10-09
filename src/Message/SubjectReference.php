<?php

declare(strict_types=1);

namespace Survos\WorkflowAsyncBundle\Message;

final readonly class SubjectReference
{
    public function __construct(public string $type, public string $id)
    {
        if ($type === '' || $id === '') {
            throw new \InvalidArgumentException('Subject type and id must not be empty.');
        }
    }
}
