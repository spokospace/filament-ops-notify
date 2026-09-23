<?php

namespace Spokospace\OpsNotify\Channels\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spokospace\OpsNotify\Contracts\Channel;
use Spokospace\OpsNotify\Contracts\LabelsTopics;
use Spokospace\OpsNotify\Contracts\ReportsStatus;
use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\Support\Button;
use Spokospace\OpsNotify\Support\Destination;
use Spokospace\OpsNotify\Support\Trans;

class TelegramChannel implements Channel, LabelsTopics, ReportsStatus
{
    /** Telegram only accepts these six forum icon colours. */
    public const TOPIC_COLORS = [
        7322096 => 'blue',
        16766590 => 'yellow',
        13338331 => 'violet',
        9367192 => 'green',
        16749490 => 'pink',
        16478047 => 'red',
    ];

    /** getUpdates returns at most this many per call. */
    private const UPDATES_PER_PAGE = 100;

    /** Upper bound on getUpdates calls in one discovery, so a flooded group cannot loop for long. */
    private const UPDATE_PAGES = 10;

    /**
     * @param  array{bot_token?: ?string, chat_id?: int|string|null, topic?: int|string|null, api_url?: string, timeout?: int, service?: ?string}  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly TelegramFormatter $formatter = new TelegramFormatter,
    ) {}

    /** @var array<string, string>|null id => name, from config('ops-notify.channels.telegram.topics') */
    private ?array $topicNames = null;

    public function isConfigured(): bool
    {
        return filled($this->config['bot_token'] ?? null) && filled($this->config['chat_id'] ?? null);
    }

    public function topicLabel(string $id): string
    {
        $this->topicNames ??= collect($this->config['topics'] ?? [])->pluck('name', 'id')->map(fn ($name): string => (string) $name)->all();

        return self::formatTopic($id, $this->topicNames[$id] ?? null);
    }

    /** The one topic label format, shared by the log table and the settings pickers. */
    public static function formatTopic(string $id, ?string $name): string
    {
        return filled($name) ? "{$name} #{$id}" : "#{$id}";
    }

    /**
     * Asks Telegram who the bot is. Cached, errors included, because the Filament page renders
     * it on every Livewire round trip and a dead API would otherwise block each one.
     */
    public function status(): string
    {
        if (! $this->isConfigured()) {
            return Trans::get('page.not_configured');
        }

        // Cache the raw result, not the sentence, so it follows the viewer's locale.
        $key = 'ops-notify:telegram-status:'.md5((string) $this->config['bot_token']);
        $result = Cache::get($key);

        if (! is_array($result)) {
            try {
                $result = ['username' => (string) ($this->getMe()['username'] ?? '?')];
                Cache::put($key, $result, now()->addMinutes(10));
            } catch (ChannelException $e) {
                $result = ['error' => $e->getMessage()];
                Cache::put($key, $result, now()->addMinute());
            }
        }

        return isset($result['username'])
            ? Trans::get('page.connected_as', ['username' => $result['username']])
            : (string) $result['error'];
    }

    /** @return array<int, string> Telegram colour => translated name, for pickers. */
    public static function topicColorOptions(): array
    {
        return array_map(fn (string $key): string => Trans::get("colors.{$key}"), self::TOPIC_COLORS);
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
     * Pending updates, oldest first. Telegram returns at most 100 per call and keeps them for
     * 24 hours, so in a busy group a /ping can sit behind 100 other messages; full pages are
     * therefore followed with an offset. Fetching with an offset confirms (deletes) the pages
     * before it, which is why seen() remembers what it found.
     *
     * Never pass allowed_updates here: Telegram stores it as the bot's filter for all later
     * getUpdates and webhook deliveries.
     *
     * @return list<array<string, mixed>>
     */
    public function getUpdates(): array
    {
        $updates = [];
        $payload = [];

        for ($page = 0; $page < self::UPDATE_PAGES; $page++) {
            $batch = (array) $this->call('getUpdates', $payload);
            $updates = [...$updates, ...$batch];

            if (count($batch) < self::UPDATES_PER_PAGE) {
                break;
            }

            $payload = ['offset' => (int) end($batch)['update_id'] + 1];
        }

        return $updates;
    }

    /**
     * Chats and forum topics the bot has seen, merged with what earlier calls found: paging
     * through getUpdates confirms older updates, so they would not come back on the next call.
     *
     * @return array{
     *     chats: array<string, array{id: string, type: string, title: string, forum: bool}>,
     *     topics: array<string, array{chat_id: string, id: string, name: string}>
     * }
     */
    public function seen(): array
    {
        $key = 'ops-notify:telegram-seen:'.hash('xxh128', (string) ($this->config['bot_token'] ?? ''));
        $found = UpdateParser::parse($this->getUpdates());
        $known = (array) Cache::get($key, []);

        // array_replace, not spread: chat ids such as "-1001234" are integer keys and would be renumbered.
        $seen = [
            'chats' => array_replace($known['chats'] ?? [], $found['chats']),
            'topics' => array_replace($known['topics'] ?? [], array_map(
                // A topic seen again without its header keeps the name found earlier.
                fn (array $topic): array => $topic['name'] === ''
                    ? [...$topic, 'name' => $known['topics'][$topic['chat_id'].':'.$topic['id']]['name'] ?? '']
                    : $topic,
                $found['topics'],
            )),
        ];

        Cache::put($key, $seen, now()->addDays(30));

        return $seen;
    }

    /**
     * Creates a forum topic in the configured chat. The bot needs the "Manage topics" admin right.
     *
     * @return string The new topic's id (message_thread_id).
     */
    public function createForumTopic(string $name, ?int $iconColor = null): string
    {
        if (! $this->isConfigured()) {
            throw new ChannelException('Save the bot token and chat id first.', permanent: true);
        }

        $payload = ['chat_id' => $this->config['chat_id'], 'name' => $name];

        // Null as an array key is deprecated in PHP 8.5, so check it first.
        if ($iconColor !== null && isset(self::TOPIC_COLORS[$iconColor])) {
            $payload['icon_color'] = $iconColor;
        }

        return (string) $this->call('createForumTopic', $payload)['message_thread_id'];
    }

    /**
     * Topics of the configured chat that the bot has seen recently (the API cannot list them).
     *
     * @return array<string, string> id => name
     */
    public function seenTopics(): array
    {
        $chatId = (string) ($this->config['chat_id'] ?? '');

        return collect($this->seen()['topics'])
            ->where('chat_id', $chatId)
            ->mapWithKeys(fn (array $topic): array => [$topic['id'] => $topic['name']])
            ->all();
    }

    /**
     * The bot's own profile: display name, short description (profile page) and description
     * (the empty-chat screen). Bot API: getMy* / setMy*.
     *
     * @return array{name: string, short_description: string, description: string}
     */
    public function getProfile(): array
    {
        return [
            'name' => (string) ($this->call('getMyName')['name'] ?? ''),
            'short_description' => (string) ($this->call('getMyShortDescription')['short_description'] ?? ''),
            'description' => (string) ($this->call('getMyDescription')['description'] ?? ''),
        ];
    }

    /**
     * Sends only the fields that differ from Telegram's current values: setMyName in particular
     * is rate-limited, so re-sending unchanged values would waste the quota.
     *
     * Pass $current when the caller already has Telegram's values (the form loaded them when it
     * opened); otherwise they are fetched, which costs three extra calls.
     *
     * @param  array{name?: ?string, short_description?: ?string, description?: ?string}  $profile
     * @param  array{name: string, short_description: string, description: string}|null  $current
     * @return list<string> The fields that were changed.
     */
    public function updateProfile(array $profile, ?array $current = null): array
    {
        $methods = ['name' => 'setMyName', 'short_description' => 'setMyShortDescription', 'description' => 'setMyDescription'];
        $current ??= $this->getProfile();
        $changed = [];

        foreach ($methods as $field => $method) {
            $value = trim((string) ($profile[$field] ?? ''));

            if (array_key_exists($field, $profile) && $value !== $current[$field]) {
                $this->call($method, [$field => $value]);
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /** Sets the bot's profile photo (Bot API 9.4). Telegram accepts only JPEG for static photos. */
    public function setProfilePhoto(string $jpeg): void
    {
        $this->call(
            'setMyProfilePhoto',
            ['photo' => json_encode(['type' => 'static', 'photo' => 'attach://avatar'])],
            ['avatar' => $jpeg],
        );
    }

    public function removeProfilePhoto(): void
    {
        $this->call('removeMyProfilePhoto');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $files  Multipart attachments: name => raw contents.
     * @return mixed The API's "result" field.
     */
    private function call(string $method, array $payload = [], array $files = []): mixed
    {
        $token = $this->config['bot_token'] ?? null;

        if (blank($token)) {
            throw new ChannelException('Telegram bot token is not configured.', permanent: true);
        }

        $url = rtrim($this->config['api_url'] ?? 'https://api.telegram.org', '/').'/bot'.$token.'/'.$method;

        try {
            $request = Http::acceptJson()->timeout($this->config['timeout'] ?? 10);

            foreach ($files as $name => $contents) {
                $request = $request->attach($name, $contents, "{$name}.jpg");
            }

            $response = $request->post($url, $payload);
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
