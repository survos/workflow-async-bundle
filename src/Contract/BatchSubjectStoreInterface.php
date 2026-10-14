<?php

declare(strict_types=1);

namespace Survos\WorkflowMessengerBundle\Contract;

use Survos\WorkflowMessengerBundle\Message\SubjectReference;

/** Optional group persistence; stores without it save every subject before continuation. */
interface BatchSubjectStoreInterface extends SubjectStoreInterface
{
    /** @param list<array{SubjectReference, object, array}> $changes */
    public function saveBatch(array $changes): void;

    /**
     * Discard unsaved in-memory changes after a failed group, so another group cannot flush them.
     *
     * @param list<SubjectReference> $references
     */
    public function discardBatch(array $references): void;
}
