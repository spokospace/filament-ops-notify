<?php

namespace Spokospace\OpsNotify\Support;

/**
 * Where a message goes: a channel name from config('ops-notify.channels') and an optional
 * topic, an opaque sub-target the driver interprets (Telegram: a forum thread id).
 * A null topic means the channel's default.
 */
final readonly class Destination
{
    public function __construct(
        public string $channel,
        public ?string $topic = null,
    ) {}
}
