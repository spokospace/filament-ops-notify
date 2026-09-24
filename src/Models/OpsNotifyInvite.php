<?php

namespace Spokospace\OpsNotify\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An invite link to the ops chat, created from the panel.
 *
 * @property int $id
 * @property string $channel
 * @property string $name
 * @property string $invite_link
 * @property ?int $member_limit
 * @property ?Carbon $expires_at
 * @property ?Carbon $revoked_at
 * @property ?string $created_by
 * @property Carbon $created_at
 */
class OpsNotifyInvite extends Model
{
    public const STATE_ACTIVE = 'active';

    public const STATE_EXPIRED = 'expired';

    public const STATE_REVOKED = 'revoked';

    protected $table = 'ops_notify_invites';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'invite_link' => 'encrypted',
            'member_limit' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Revoked or expired from what the panel knows. Telegram does not report whether a
     * single-use link has been used without a bot-wide update filter, so "used" is not shown.
     */
    public function state(): string
    {
        return match (true) {
            $this->revoked_at !== null => self::STATE_REVOKED,
            $this->expires_at?->isPast() ?? false => self::STATE_EXPIRED,
            default => self::STATE_ACTIVE,
        };
    }
}
