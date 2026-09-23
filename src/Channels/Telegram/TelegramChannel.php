<?php

namespace Spokospace\OpsNotify\Channels\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spokospace\OpsNotify\Contracts\Channel;
use Spokospace\OpsNotify\Contracts\ReportsStatus;
use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\Support\Button;
use Spokospace\OpsNotify\Support\Destination;

class TelegramChannel implements Channel, ReportsStatus
{
    /**
     * @param  array{bot_token?: ?string, chat_id?: int|string|null, topic?: int|string|null, api_url?: string, timeout?: int, service?: ?string}  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly TelegramFormatter $formatter = new TelegramFormatter,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->config['bot_token'] ?? null) && filled($this->config['chat_id'] ?? null);
    }

    /**
     * Asks Telegram who the bot is. Cached, errors included, because the Filament page renders
     * it on every Livewire round trip and a dead API would otherwise block each one.
     */
    public function status(): string
    {
        if (! $this->isConfigured()) {
            return 'Not configured';
        }

        $key = 'ops-notify:telegram-status:'.md5((string) $this->config['bot_token']);

        if (is_string($cached = Cache::get($key))) {
            return $cached;
        }

        try {
            $status = 'Connected as @'.($this->getMe()['username'] ?? '?');
            Cache::put($key, $status, now()->addMinutes(10));
        } catch (ChannelException $e) {
            $status = $e->getMessage();
            Cache::put($key, $status, now()->addMinute());
        }

        return $status;
    }

    public function send(OpsMessage $message, Destination $destination): string
    {
        if (! $this->isConfigured()) {
            throw new ChannelException('Telegram bot token or chat id is not configured.', permanent: true);
        }

        $payload = [
            'chat_id' => $this->config['chat_id'],
            'text' => $this->formatter->format($message, $this->config['service'] ?? null),
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];

        if (filled($topic = $destination->topic ?? $this->config['topic'] ?? null)) {
            $payload['message_thread_id'] = (int) $topic;
        }

        if ($message->buttons === []) {
            return (string) $this->call('sendMessage', $payload)['message_id'];
        }

        try {
            return (string) $this->call('sendMessage', $payload + [
                'reply_markup' => [
                    'inline_keyboard' => array_map(
                        fn (Button $button): array => [['text' => $button->label, 'url' => $button->url]],
                        $message->buttons,
                    ),
                ],
            ])['message_id'];
        } catch (ChannelException $e) {
            // One URL Telegram won't accept (localhost, a .test domain) rejects the whole message.
            // Better to deliver it with the links as plain text than to lose it.
            if (! preg_match('/BUTTON_URL_INVALID|wrong http url|button/i', $e->getMessage())) {
                throw $e;
            }

            $payload['text'] = $this->formatter->format($message, $this->config['service'] ?? null, linksAsText: true);

            return (string) $this->call('sendMessage', $payload)['message_id'];
        }
    }

    /**
     * @return array<string, mixed> The bot's User object (id, username, first_name, ...).
     */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    /**
     * Never pass allowed_updates here: Telegram stores it as the bot's filter for all later
     * getUpdates and webhook deliveries.
     *
     * @return list<array<string, mixed>>
     */
    public function getUpdates(): array
    {
        return $this->call('getUpdates');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return mixed The API's "result" field.
     */
    private function call(string $method, array $payload = []): mixed
    {
        $token = $this->config['bot_token'] ?? null;

        if (blank($token)) {
            throw new ChannelException('Telegram bot token is not configured.', permanent: true);
        }

        $url = rtrim($this->config['api_url'] ?? 'https://api.telegram.org', '/').'/bot'.$token.'/'.$method;

        try {
            $response = Http::acceptJson()
                ->timeout($this->config['timeout'] ?? 10)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            // Guzzle puts the full URL, bot token included, into the message.
            throw new ChannelException('Telegram API unreachable: '.str_replace($token, '***', $e->getMessage()));
        }

        $json = $response->json();

        if ($response->successful() && ($json['ok'] ?? false) === true) {
            return $json['result'];
        }

        $status = $response->status();

        throw new ChannelException(
            'Telegram API '.$status.': '.($json['description'] ?? 'unexpected response'),
            retryAfter: isset($json['parameters']['retry_after']) ? (int) $json['parameters']['retry_after'] : null,
            // 4xx (bad token, chat not found, bot kicked, bad markup) will fail the same way again; 429 will not.
            permanent: $status >= 400 && $status < 500 && $status !== 429,
        );
    }
}
