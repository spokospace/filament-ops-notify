<?php

namespace Spokospace\OpsNotify\Settings;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Spokospace\OpsNotify\Models\OpsNotifySetting;
use Throwable;

/**
 * Settings edited on the Filament page, overlaid onto config('ops-notify.*').
 *
 * - A value set in config/.env wins: the field is "locked" and the stored value is ignored.
 * - Secrets (the bot token) are encrypted with APP_KEY, in the database and in the cache.
 * - Rows are cached forever (cleared on save), so a database outage does not silence the
 *   alert about that outage. A small version key tells long-running queue workers when to
 *   reload, so they pick up changes without a restart.
 */
class SettingsStore
{
    public const CACHE_KEY = 'ops-notify:settings';

    public const VERSION_KEY = 'ops-notify:settings:version';

    /** Setting key => config path it overrides. */
    public const FIELDS = [
        'service' => 'ops-notify.service',
        'locale' => 'ops-notify.locale',
        'enabled' => 'ops-notify.enabled',
        'telegram_bot_token' => 'ops-notify.channels.telegram.bot_token',
        'telegram_chat_id' => 'ops-notify.channels.telegram.chat_id',
        'telegram_topic' => 'ops-notify.channels.telegram.topic',
        'telegram_topics' => 'ops-notify.channels.telegram.topics',
        'events' => 'ops-notify.events',
        'forward_enabled' => 'ops-notify.forward_database_notifications.enabled',
        'forward_map' => 'ops-notify.forward_database_notifications.map',
        'forward_other_enabled' => 'ops-notify.forward_notifications.enabled',
        'forward_other_channels' => 'ops-notify.forward_notifications.channels',
    ];

    /** Static defaults; see defaultFor() for the dynamic ones. */
    public const DEFAULTS = [
        'enabled' => true,
        'forward_enabled' => true,
        'forward_other_enabled' => false,
        'forward_other_channels' => ['mail'],
    ];

    public const SECRETS = ['telegram_bot_token'];

    /** Seconds to stop retrying a failed settings load (database down, cold cache). */
    private const FAILURE_BACKOFF = 30;

    /** @var array<string, mixed>|null Config as loaded from files and .env, before any overlay. */
    private ?array $baseline = null;

    private ?string $version = null;

    /** @var array<string, string> */
    private array $rows = [];

    private int $failedAt = 0;

    /** @var list<string> Secrets that could not be decrypted (APP_KEY changed since they were saved). */
    private array $unreadable = [];

    /**
     * Overlay stored settings onto config. Cheap enough to call before every send: one small
     * cache read, and config is only rewritten when the settings version changed.
     */
    public function apply(): void
    {
        $version = $this->currentVersion();

        if ($version !== null && $version === $this->version) {
            return;
        }

        $this->rows = $this->loadRows();
        // A cold cache gets its version key during loadRows(); re-read it so the next call is a hit.
        $this->version = $version ?? $this->currentVersion();
        $this->unreadable = [];

        foreach (self::FIELDS as $key => $path) {
            if ($this->isLocked($key)) {
                continue;
            }

            $value = array_key_exists($key, $this->rows) ? $this->decode($key, $this->rows[$key]) : null;

            config()->set($path, $value ?? $this->baseline()[$key] ?? $this->defaultFor($key));
        }
    }

    /** Changes whenever the applied settings change; lets callers drop objects built from old config. */
    public function version(): ?string
    {
        $this->apply();

        return $this->version;
    }

    public function isLocked(string $key): bool
    {
        return filled($this->baseline()[$key] ?? null);
    }

    public function hasStored(string $key): bool
    {
        $this->apply();

        return array_key_exists($key, $this->rows);
    }

    /** @return list<string> */
    public function unreadableSecrets(): array
    {
        $this->apply();

        return $this->unreadable;
    }

    /** @return array<string, mixed> Effective panel values for the form. Secrets are never returned. */
    public function formValues(): array
    {
        $this->apply();
        $values = [];

        foreach (array_keys(self::FIELDS) as $key) {
            if ($this->isSecret($key)) {
                $values[$key] = null;

                continue;
            }

            // A locked field shows what is really in effect: the .env/config value.
            $stored = ! $this->isLocked($key) && array_key_exists($key, $this->rows)
                ? $this->decode($key, $this->rows[$key])
                : null;

            $values[$key] = $this->isLocked($key)
                ? $this->baseline()[$key]
                : $stored ?? $this->defaultFor($key);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values  Keys from FIELDS. Blank values remove the setting,
     *                                        except a blank secret, which keeps the stored one.
     */
    public function save(array $values): void
    {
        $upserts = [];
        $deletes = [];

        foreach (array_intersect_key($values, self::FIELDS) as $key => $value) {
            if ($this->isLocked($key) || ($this->isSecret($key) && blank($value))) {
                continue;
            }

            if (blank($value)) {
                $deletes[] = $key;

                continue;
            }

            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $upserts[] = ['key' => $key, 'value' => $this->isSecret($key) ? Crypt::encryptString($encoded) : $encoded];
        }

        if ($upserts !== []) {
            OpsNotifySetting::query()->upsert($upserts, ['key'], ['value']);
        }

        if ($deletes !== []) {
            OpsNotifySetting::query()->whereIn('key', $deletes)->delete();
        }

        Cache::forget(self::CACHE_KEY);
        Cache::forever(self::VERSION_KEY, Str::random(16));
        $this->failedAt = 0;
        $this->apply();
    }

    private function currentVersion(): ?string
    {
        try {
            return Cache::get(self::VERSION_KEY);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, string> Raw rows: key => stored value. */
    private function loadRows(): array
    {
        if ($this->failedAt > time() - self::FAILURE_BACKOFF) {
            return $this->rows;
        }

        try {
            $rows = Cache::rememberForever(
                self::CACHE_KEY,
                fn (): array => OpsNotifySetting::query()->pluck('value', 'key')->all(),
            );
            Cache::add(self::VERSION_KEY, Str::random(16));

            return $rows;
        } catch (Throwable) {
            // No table yet (migration pending) or database and cache both down: config/.env only.
            $this->failedAt = time();

            return $this->rows;
        }
    }

    /** @return array<string, mixed> */
    private function baseline(): array
    {
        return $this->baseline ??= array_map(fn (string $path): mixed => config($path), self::FIELDS);
    }

    /** Used when neither config/.env nor the panel sets a value. */
    private function defaultFor(string $key): mixed
    {
        return $key === 'service' ? config('app.name') : (self::DEFAULTS[$key] ?? null);
    }

    private function isSecret(string $key): bool
    {
        return in_array($key, self::SECRETS, true);
    }

    private function decode(string $key, string $value): mixed
    {
        if ($this->isSecret($key)) {
            try {
                $value = Crypt::decryptString($value);
            } catch (DecryptException) {
                $this->unreadable[] = $key;

                return null;
            }
        }

        return json_decode($value, true);
    }
}
