<?php

namespace Spokospace\OpsNotify\Commands;

use Illuminate\Console\Command;
use Spokospace\OpsNotify\ChannelManager;
use Spokospace\OpsNotify\Exceptions\ChannelException;

/**
 * Finds the chat id and forum topic ids. Telegram only reports chats the bot has seen, so: add
 * the bot to the group, post /ping@your_bot in every topic you want to use, then run this.
 */
class DiscoverTelegramChatsCommand extends Command
{
    protected $signature = 'ops-notify:telegram-chats {--channel= : Channel name from config, defaults to the default channel}';

    protected $description = 'List Telegram chats and forum topics the bot has seen';

    public function handle(ChannelManager $channels): int
    {
        try {
            $channel = $channels->telegram($this->option('channel') ?: null);
            $bot = $channel->getMe();
            ['chats' => $chats, 'topics' => $topics] = $channel->seen();
        } catch (ChannelException $e) {
            // Also 409 when a webhook is set; getUpdates is unavailable until it is removed.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $ping = '/ping@'.($bot['username'] ?? 'your_bot');
        $this->components->info('Bot: @'.($bot['username'] ?? '?'));

        if ($chats === []) {
            $this->components->warn("No chats yet. Add the bot to your group, send {$ping} in each topic (in a group the bot only sees commands addressed to it), then run this again.");

            return self::SUCCESS;
        }

        $this->table(
            ['Chat id', 'Type', 'Name', 'Topics on'],
            array_map(fn (array $chat): array => [$chat['id'], $chat['type'], $chat['title'], $chat['forum'] ? 'yes' : 'no'], array_values($chats)),
        );

        $types = array_column($chats, 'type');

        // Turning on Topics upgrades a group to a supergroup with a new id; the old one stays listed.
        if (in_array('group', $types, true) && in_array('supergroup', $types, true)) {
            $this->components->warn('Use the supergroup id (starts with -100). A "group" row is the id from before Topics was turned on.');
        }

        if ($topics === []) {
            $this->components->warn("No topics yet. Send {$ping} in each topic you want to use, then run this again.");

            return self::SUCCESS;
        }

        $this->table(['Chat id', 'Topic id', 'Topic'], array_map('array_values', array_values($topics)));

        return self::SUCCESS;
    }
}
