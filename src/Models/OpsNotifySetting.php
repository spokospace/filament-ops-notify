<?php

namespace Spokospace\OpsNotify\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One panel setting. Values are JSON; secrets are additionally encrypted with APP_KEY.
 * Read and write them through SettingsStore, never directly.
 *
 * @property string $key
 * @property string $value
 */
class OpsNotifySetting extends Model
{
    protected $table = 'ops_notify_settings';

    protected $guarded = [];
}
