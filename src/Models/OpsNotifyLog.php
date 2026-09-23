<?php

namespace Spokospace\OpsNotify\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Enums\Level;
use Spokospace\OpsNotify\OpsMessage;

/**
 * @property int $id
 * @property string $direction
 * @property string $channel
 * @property ?string $topic
 * @property string $event
 * @property Level $level
 * @property ?string $title
 * @property ?string $body
 * @property ?array<string, mixed> $payload
 * @property DeliveryStatus $status
 * @property ?string $external_id
 * @property ?string $error
 * @property int $attempts
 * @property ?Carbon $sent_at
 */
class OpsNotifyLog extends Model
{
    use MassPrunable;

    public const DIRECTION_OUT = 'out';

    protected $table = 'ops_notify_logs';

    protected $guarded = [];

    protected $attributes = [
        'direction' => self::DIRECTION_OUT,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'level' => Level::class,
            'status' => DeliveryStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('ops-notify.log.prune_after_days', 30)));
    }

    /** Rebuild the original message, e.g. to resend a failed one. */
    public function toMessage(): OpsMessage
    {
        // The payload column is nullable: a row without one (edited by hand) resends its event and title.
        return OpsMessage::fromArray($this->payload ?? ['event' => $this->event, 'title' => $this->title]);
    }
}
