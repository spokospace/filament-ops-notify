<?php

namespace Spokospace\OpsNotify;

use Closure;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Spokospace\OpsNotify\Channels\Telegram\TelegramChannel;
use Spokospace\OpsNotify\Contracts\Channel;

/**
 * Resolves named channels from config('ops-notify.channels'). Every driver, built-in or not,
 * is a creator receiving the container and the channel config (plus `service`, the label
 * prefixing messages). New drivers are added without touching the sending apps:
 *
 *     app(ChannelManager::class)->extend('whatsapp', fn ($app, array $config) => new WhatsAppChannel($config));
 */
class ChannelManager
{
    /** @var array<string, Channel> */
    private array $channels = [];

    /** @var array<string, Closure(Container, array<string, mixed>): Channel> */
    private array $creators;

    public function __construct(private readonly Container $app)
    {
        $this->creators = [
            'telegram' => fn (Container $app, array $config): Channel => new TelegramChannel($config),
        ];
    }

    public function channel(?string $name = null): Channel
    {
        $name ??= $this->defaultChannel();

        return $this->channels[$name] ??= $this->resolve($name);
    }

    public function defaultChannel(): string
    {
        return (string) config('ops-notify.default_channel', 'telegram');
    }

    /** @param  Closure(Container, array<string, mixed>): Channel  $creator */
    public function extend(string $driver, Closure $creator): static
    {
        $this->creators[$driver] = $creator;

        return $this;
    }

    /** Drop resolved instances, e.g. after config changes in tests. */
    public function forgetChannels(): static
    {
        $this->channels = [];

        return $this;
    }

    private function resolve(string $name): Channel
    {
        $config = config("ops-notify.channels.{$name}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Ops notify channel [{$name}] is not defined.");
        }

        $driver = $config['driver'] ?? $name;
        $creator = $this->creators[$driver] ?? throw new InvalidArgumentException("Ops notify driver [{$driver}] is not supported.");

        return $creator($this->app, $config + ['name' => $name, 'service' => config('ops-notify.service')]);
    }
}
