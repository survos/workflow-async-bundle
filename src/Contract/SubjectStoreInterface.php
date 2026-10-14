<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Contract;

use Survos\WorkflowMessengerBundle\Message\SubjectReference;

/** Applications own persistence, subject type validation and transaction/locking policy. */
interface SubjectStoreInterface
{
    public function reference(object $subject): SubjectReference;

    public function load(SubjectReference $reference): ?object;

    /** Context from apply(), for application-owned continuation/persistence policy. */
    public function save(SubjectReference $reference, object $subject, array $context = []): void;
}
