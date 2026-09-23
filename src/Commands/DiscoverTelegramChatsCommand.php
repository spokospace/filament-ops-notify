<?php

namespace Spokospace\OpsNotify\Commands;

use Illuminate\Console\Command;
use Spokospace\OpsNotify\ChannelManager;
use Spokospace\OpsNotify\Channels\Telegram\TelegramChannel;
use Spokospace\OpsNotify\Exceptions\ChannelException;

/**
 * Finds the chat id and forum topic ids to put in .env. Telegram only reports chats the bot
 * has seen recently, so: add the bot to the group, post a command such as /ping in every
 * topic you want to use, then run this.
 */
class DiscoverTelegramChatsCommand extends Command
{
    protected $signature = 'ops-notify:telegram-chats {--channel= : Channel name from config, defaults to the default channel}';

    protected $description = 'List Telegram chats and forum topics the bot has recently seen';

    public function handle(ChannelManager $channels): int
    {
        $channel = $channels->channel($this->option('channel') ?: null);

        if (! $channel instanceof TelegramChannel) {
            $this->components->error('This command only works with a Telegram channel.');

            return self::FAILURE;
        }

        try {
            $bot = $channel->getMe();
            $updates = $channel->getUpdates();
        } catch (ChannelException $e) {
            // 409 means a webhook is set; getUpdates is unavailable until it is removed.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Bot: @'.($bot['username'] ?? '?'));

        $chats = [];
        $topics = [];

        foreach ($updates as $update) {
            $message = $update['message'] ?? $update['channel_post'] ?? $update['my_chat_member'] ?? null;
            $chat = $message['chat'] ?? null;

            if (! $chat) {
                continue;
            }

            $chats[$chat['id']] = [
                $chat['id'],
                $chat['type'] ?? '',
                $chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? '',
            ];

            if (isset($message['message_thread_id']) && ($message['is_topic_message'] ?? false)) {
                $name = $message['forum_topic_created']['name']
                    ?? $message['reply_to_message']['forum_topic_created']['name']
                    ?? $topics[$chat['id'].':'.$message['message_thread_id']][2]
                    ?? '';

                $topics[$chat['id'].':'.$message['message_thread_id']] = [$chat['id'], $message['message_thread_id'], $name];
            }
        }

        if ($chats === []) {
            $this->components->warn('No chats yet. Add the bot to your group, send /ping in each topic, then run this again.');

            return self::SUCCESS;
        }

        $this->table(['Chat id (OPS_NOTIFY_TELEGRAM_CHAT_ID)', 'Type', 'Name'], array_values($chats));

        if ($topics !== []) {
            $this->table(['Chat id', 'Topic id (OPS_NOTIFY_TELEGRAM_TOPIC / events.*.topic)', 'Topic'], array_values($topics));
        }

        return self::SUCCESS;
    }
}
