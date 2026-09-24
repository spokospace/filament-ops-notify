<?php

namespace Spokospace\OpsNotify\Enums;

use Filament\Support\Contracts\HasColor;
use Spokospace\OpsNotify\Support\Trans;

/** What the routing rules do with an event seen in the log (SeenEvents::status()), for its tag in Settings. */
enum SeenEventStatus implements HasColor
{
    /** A rule gives the event a topic. */
    case Routed;
    /** No rule matches the event, or its rule has no topic: the message's own or the default topic applies. */
    case NoTopic;
    /** A disabled rule drops the event. */
    case Disabled;
    /** The log cut the name short and no rule matches it: an exact rule for the full name cannot be checked. */
    case Unknown;

    public function getColor(): string
    {
        return $this === self::NoTopic ? 'warning' : 'gray';
    }

    /** Shown in the tag itself, so the state does not rest on colour or a tooltip alone. */
    public function note(): ?string
    {
        return match ($this) {
            self::Routed => null,
            self::NoTopic => Trans::get('settings.seen_default'),
            self::Disabled => Trans::get('page.disabled'),
            self::Unknown => Trans::get('settings.seen_unknown'),
        };
    }
}
