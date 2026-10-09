<?php

declare(strict_types=1);

namespace Survos\WorkflowAsyncBundle\Contract;

use Survos\WorkflowAsyncBundle\Message\SubjectReference;

/** Applications own persistence, subject type validation and transaction/locking policy. */
interface SubjectStoreInterface
{
    public function load(SubjectReference $reference): ?object;

    /** Context from apply(), for application-owned continuation/persistence policy. */
    public function save(SubjectReference $reference, object $subject, array $context = []): void;
}
