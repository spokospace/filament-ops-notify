<?php

namespace Spokospace\OpsNotify\Commands;

use Illuminate\Console\Command;
use Spokospace\OpsNotify\ChannelManager;
use Spokospace\OpsNotify\Channels\Telegram\UpdateParser;
use Spokospace\OpsNotify\Exceptions\ChannelException;

/**
 * Finds the chat id and forum topic ids. Telegram only reports chats the bot has seen recently,
 * so: add the bot to the group, post a command such as /ping in every topic you want to use,
 * then run this.
 */
class DiscoverTelegramChatsCommand extends Command
{
    protected $signature = 'ops-notify:telegram-chats {--channel= : Channel name from config, defaults to the default channel}';

    protected $description = 'List Telegram chats and forum topics the bot has recently seen';

    public function handle(ChannelManager $channels): int
    {
        try {
            $channel = $channels->telegram($this->option('channel') ?: null);
            $bot = $channel->getMe();
            $updates = $channel->getUpdates();
        } catch (ChannelException $e) {
            // Also 409 when a webhook is set; getUpdates is unavailable until it is removed.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Bot: @'.($bot['username'] ?? '?'));

        ['chats' => $chats, 'topics' => $topics] = UpdateParser::parse($updates);

        if ($chats === []) {
            $this->components->warn('No chats yet. Add the bot to your group, send /ping in each topic, then run this again.');

            return self::SUCCESS;
        }

        $rows = fn (array $records): array => array_map('array_values', array_values($records));

        $this->table(['Chat id', 'Type', 'Name'], $rows($chats));

        if ($topics !== []) {
            $this->table(['Chat id', 'Topic id', 'Topic'], $rows($topics));
        }

        return self::SUCCESS;
    }
}
