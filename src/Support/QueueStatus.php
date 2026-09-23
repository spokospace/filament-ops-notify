<?php

namespace Spokospace\OpsNotify\Support;

use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Throwable;

/**
 * Where SendOpsMessage jobs go and whether anything is working them: any Laravel queue driver,
 * with extra checks when the app runs Horizon.
 */
final class QueueStatus
{
    /** Queued rows older than this are reported as stuck. */
    public const STUCK_AFTER_MINUTES = 5;

    public function connection(): string
    {
        return (string) (config('ops-notify.queue.connection') ?: config('queue.default'));
    }

    public function queue(): string
    {
        return (string) (config('ops-notify.queue.name')
            ?: config("queue.connections.{$this->connection()}.queue")
            ?: 'default');
    }

    /** The sync driver delivers inside the request that sent the message: no worker involved. */
    public function isSync(): bool
    {
        return config("queue.connections.{$this->connection()}.driver") === 'sync';
    }

    /**
     * Horizon's own verdict, as `horizon:status` gives it: running, paused or inactive. Null when
     * Horizon is not installed, the jobs go to another driver, or Redis cannot be reached.
     */
    public function horizon(): ?string
    {
        if (! $this->usesHorizon() || ! interface_exists(MasterSupervisorRepository::class)) {
            return null;
        }

        try {
            $masters = app(MasterSupervisorRepository::class)->all();
        } catch (Throwable) {
            return null;
        }

        if ($masters === []) {
            return 'inactive';
        }

        foreach ($masters as $master) {
            if (($master->status ?? null) === 'paused') {
                return 'paused';
            }
        }

        return 'running';
    }

    /**
     * Whether a Horizon supervisor for this environment works our connection and queue, merged
     * the way Horizon does it (defaults replaced by the first matching environment). Null when
     * Horizon is not configured for this driver.
     */
    public function coveredByHorizon(): ?bool
    {
        if (! $this->usesHorizon()) {
            return null;
        }

        $environments = (array) config('horizon.environments', []);
        $defaults = (array) config('horizon.defaults', []);
        $plan = collect($environments)->first(fn (mixed $_, string $name): bool => Str::is($name, app()->environment()));

        if ($plan === null) {
            return false;
        }

        return collect(array_replace_recursive($defaults, (array) $plan))->contains(
            fn (mixed $supervisor): bool => is_array($supervisor)
                && ($supervisor['connection'] ?? 'redis') === $this->connection()
                && in_array($this->queue(), (array) ($supervisor['queue'] ?? ['default']), true),
        );
    }

    /** Messages still waiting for a worker after STUCK_AFTER_MINUTES. */
    public function stuck(): int
    {
        if ($this->isSync() || ! config('ops-notify.log.enabled')) {
            return 0;
        }

        try {
            return OpsNotifyLog::query()
                ->where('status', DeliveryStatus::Queued)
                ->where('created_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES))
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /** One line for the status section, e.g. "redis · ops-notify · Horizon running". */
    public function summary(): string
    {
        if ($this->isSync()) {
            return Trans::get('page.delivery_sync');
        }

        $horizon = $this->horizon();

        return $this->connection().' · '.$this->queue().($horizon ? ' · '.Trans::get("page.horizon_{$horizon}") : '');
    }

    /** The most urgent delivery problem in words, or null when there is none. */
    public function warning(): ?string
    {
        $horizon = $this->horizon();

        return match (true) {
            $horizon === 'inactive' || $horizon === 'paused' => Trans::get("page.horizon_{$horizon}_warning"),
            $this->coveredByHorizon() === false => Trans::get('page.queue_not_in_horizon', ['queue' => $this->queue(), 'connection' => $this->connection()]),
            ($stuck = $this->stuck()) > 0 => Trans::choice('page.queue_stuck', $stuck, ['minutes' => self::STUCK_AFTER_MINUTES]),
            default => null,
        };
    }

    /** Horizon only works Redis connections, and only when the app has configured it. */
    private function usesHorizon(): bool
    {
        return config("queue.connections.{$this->connection()}.driver") === 'redis'
            && config('horizon.environments') !== null;
    }
}
