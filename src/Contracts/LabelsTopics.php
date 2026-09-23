<?php

namespace Spokospace\OpsNotify\Contracts;

/** Optional for channels with named sub-targets (Telegram forum topics). */
interface LabelsTopics
{
    /** Human label for a topic id, e.g. "Zapytania #3"; "#3" when the name is unknown. */
    public function topicLabel(string $id): string;
}
