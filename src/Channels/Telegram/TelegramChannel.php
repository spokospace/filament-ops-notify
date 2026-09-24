<?php

namespace Spokospace\OpsNotify\Channels\Telegram;

use Illuminate\Contracts\Cache\LockTimeoutException;
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
use Spokospace\OpsNotify\Support\MessageTemplate;
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
     * @param  array{bot_token?: ?string, chat_id?: int|string|null, topic?: int|string|null, topics?: list<array{id: int|string, name: string}>, api_url?: string, timeout?: int, service?: ?string}  $config
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

    /** Callers check isConfigured() first; this makes a missing value an explicit error. */
    private function botToken(): string
    {
        return (string) ($this->config['bot_token'] ?? throw new ChannelException('Telegram bot token is not configured.', permanent: true));
    }

    private function chatId(): int|string
    {
        return $this->config['chat_id'] ?? throw new ChannelException('Telegram chat id is not configured.', permanent: true);
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

    /** Admin rights the package uses: Create topic in Settings, and Invites. */
    public const RIGHTS = ['can_manage_topics' => 'manage_topics', 'can_invite_users' => 'invite_users'];

    /**
     * The bot's membership and admin rights in the configured chat (getChatMember), cached for
     * a minute so the page stays fast but shows a newly granted right soon after. Status is
     * creator, administrator, member, restricted, left or kicked. Null when not configured.
     *
     * @return array{status: string, rights: array<string, bool>}|array{error: string}|null
     */
    public function rights(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        return Cache::remember($this->rightsKey(), now()->addMinute(), function (): array {
            try {
                // A bot's user id is the number before the colon in its token.
                $member = (array) $this->call('getChatMember', [
                    'chat_id' => $this->chatId(),
                    'user_id' => (int) strtok($this->botToken(), ':'),
                ]);
            } catch (ChannelException $e) {
                return ['error' => $e->getMessage()];
            }

            $status = (string) ($member['status'] ?? '');
            $rights = [];

            foreach (self::RIGHTS as $field => $name) {
                // The group's creator has every right; a plain member has none.
                $rights[$name] = $status === 'creator' || ($status === 'administrator' && ($member[$field] ?? false) === true);
            }

            return ['status' => $status, 'rights' => $rights];
        });
    }

    /** Forgets the cached rights, e.g. after the admin changed them in Telegram. */
    public function forgetRights(): void
    {
        if ($this->isConfigured()) {
            Cache::forget($this->rightsKey());
        }
    }

    /**
     * A Telegram refusal in words: for a missing admin right, which one and where to turn it on
     * (and the cached rights are refreshed); anything else as Telegram said it.
     *
     * @param  string  $right  A value of RIGHTS, e.g. "invite_users".
     */
    public function explain(ChannelException $e, string $right): string
    {
        if (! str_contains(strtolower($e->getMessage()), 'not enough rights')) {
            return $e->getMessage();
        }

        $this->forgetRights();

        return Trans::get('rights.how_to', ['right' => Trans::get("rights.{$right}")]);
    }

    /**
     * One line for the status section, and whether every right the package uses is there.
     *
     * @return array{text: string, ok: bool}|null
     */
    public function rightsSummary(): ?array
    {
        $result = $this->rights();

        if ($result === null) {
            return null;
        }

        if (isset($result['error'])) {
            return ['text' => Trans::get('rights.unknown', ['error' => $result['error']]), 'ok' => false];
        }

        return match ($result['status']) {
            'left', 'kicked' => ['text' => Trans::get('rights.not_member'), 'ok' => false],
            'member', 'restricted' => ['text' => Trans::get('rights.not_admin'), 'ok' => false],
            default => ($missing = array_keys(array_filter($result['rights'], fn (bool $has): bool => ! $has))) === []
                ? ['text' => Trans::get('rights.all'), 'ok' => true]
                : ['text' => Trans::get('rights.missing', ['rights' => implode(', ', array_map(fn (string $right): string => Trans::get("rights.{$right}"), $missing))]), 'ok' => false],
        };
    }

    private function rightsKey(): string
    {
        return 'ops-notify:telegram-rights:'.hash('sha256', $this->botToken().'|'.$this->chatId());
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
        // Derived from a secret, so a cryptographic hash: the token must not be guessable from the key.
        $key = 'ops-notify:telegram-status:'.hash('sha256', $this->botToken());
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

        $template = MessageTemplate::for((array) config('ops-notify.templates', []), $message);

        $payload = [
            'chat_id' => $this->chatId(),
            'text' => $this->formatter->format($message, $this->config['service'] ?? null, template: $template),
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

            $payload['text'] = $this->formatter->format($message, $this->config['service'] ?? null, linksAsText: true, template: $template);

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
     * One page of pending updates, oldest first: at most 100, kept by Telegram for 24 hours.
     * Passing an offset confirms (deletes) every update before it. Use seen() to discover chats.
     *
     * Never pass allowed_updates here: Telegram stores it as the bot's filter for all later
     * getUpdates and webhook deliveries.
     *
     * @return list<array<string, mixed>>
     */
    public function getUpdates(?int $offset = null): array
    {
        $result = $this->call('getUpdates', $offset === null ? [] : ['offset' => $offset]);

        // Telegram returns a list of Update objects; anything else is not an update.
        return array_values(array_filter(is_array($result) ? $result : [], 'is_array'));
    }

    /**
     * Chats, forum topics and group-to-supergroup migrations the bot has seen, including what
     * earlier calls found. In a busy group a /ping can sit behind 100 other messages, so full
     * pages are followed with an offset; that confirms the page before it on Telegram's side,
     * so every page is saved before the next one is requested. A lock per bot keeps two
     * discoveries from overwriting each other's findings.
     *
     * @return array{
     *     chats: array<string, array{id: string, type: string, title: string, forum: bool}>,
     *     topics: array<string, array{chat_id: string, id: string, name: string}>,
     *     migrations: array<string, string>
     * }
     *
     * @throws ChannelException
     */
    /**
     * How long to hold the chat-discovery lock: the whole worst-case scan (every page timing out),
     * never less than a minute. Long enough that the lock cannot lapse before the run finishes.
     */
    public static function discoveryLockSeconds(int $timeout): int
    {
        return max(60, self::UPDATE_PAGES * max(0, $timeout) + 15);
    }

    public function seen(): array
    {
        $key = 'ops-notify:telegram-seen:'.hash('sha256', (string) ($this->config['bot_token'] ?? ''));

        // The scan makes up to UPDATE_PAGES getUpdates calls, each bounded by the HTTP timeout, so
        // it can run for ~100 s. A 60 s lock would expire mid-run and let a second discovery start
        // and clobber our Cache::put($key) — the exact race the lock exists to stop. Hold it for
        // the whole worst case instead.
        $lockSeconds = self::discoveryLockSeconds((int) ($this->config['timeout'] ?? 10));

        try {
            return Cache::lock($key.':lock', $lockSeconds)->block(15, function () use ($key): array {
                $seen = UpdateParser::merge((array) Cache::get($key, []), ['chats' => [], 'topics' => [], 'migrations' => []]);
                $offset = null;

                for ($page = 0; $page < self::UPDATE_PAGES; $page++) {
                    $batch = $this->getUpdates($offset);
                    $seen = UpdateParser::merge($seen, UpdateParser::parse($batch));
                    Cache::put($key, $seen, now()->addDays(30));

                    if (count($batch) < self::UPDATES_PER_PAGE) {
                        break;
                    }

                    $offset = (int) end($batch)['update_id'] + 1;
                }

                return $seen;
            });
        } catch (LockTimeoutException) {
            throw new ChannelException('Another chat discovery for this bot is still running. Try again in a moment.');
        }
    }

    /**
     * An invite link to the configured chat. The bot needs the "Invite users via link" admin right.
     * Telegram limits the link's name to 32 characters.
     *
     * @return string The invite link, e.g. https://t.me/+AbCd…
     */
    public function createInviteLink(string $name, ?\DateTimeInterface $expiresAt = null, ?int $memberLimit = null): string
    {
        $payload = ['chat_id' => $this->chatId(), 'name' => mb_substr($name, 0, 32)];

        if ($expiresAt !== null) {
            $payload['expire_date'] = $expiresAt->getTimestamp();
        }

        if ($memberLimit !== null) {
            $payload['member_limit'] = max(1, min(99999, $memberLimit));
        }

        return (string) $this->call('createChatInviteLink', $payload)['invite_link'];
    }

    /** Stops an invite link from working. */
    public function revokeInviteLink(string $link): void
    {
        $this->call('revokeChatInviteLink', ['chat_id' => $this->chatId(), 'invite_link' => $link]);
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

        $payload = ['chat_id' => $this->chatId(), 'name' => $name];

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
