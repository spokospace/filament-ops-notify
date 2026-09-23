<?php

namespace Spokospace\OpsNotify\Channels\Telegram;

/**
 * Extracts the chats and forum topics a bot has seen from getUpdates results. The Bot API has
 * no "list topics" method, so this is how existing topics are discovered.
 */
final class UpdateParser
{
    /**
     * @param  list<array<string, mixed>>  $updates
     * @return array{
     *     chats: array<string, array{id: string, type: string, title: string, forum: bool}>,
     *     topics: array<string, array{chat_id: string, id: string, name: string}>
     * }
     */
    public static function parse(array $updates): array
    {
        $chats = [];
        $topics = [];

        foreach ($updates as $update) {
            $message = $update['message'] ?? $update['channel_post'] ?? $update['my_chat_member'] ?? null;
            $chat = $message['chat'] ?? null;

            if (! $chat) {
                continue;
            }

            $chatId = (string) $chat['id'];
            $chats[$chatId] = [
                'id' => $chatId,
                'type' => (string) ($chat['type'] ?? ''),
                'title' => (string) ($chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? ''),
                // Telegram sets is_forum on supergroups with Topics turned on.
                'forum' => (bool) ($chat['is_forum'] ?? false) || ($chats[$chatId]['forum'] ?? false),
            ];

            if (! isset($message['message_thread_id']) || ! ($message['is_topic_message'] ?? false)) {
                continue;
            }

            $key = $chatId.':'.$message['message_thread_id'];
            $name = $message['forum_topic_created']['name']
                ?? $message['reply_to_message']['forum_topic_created']['name']
                ?? null;

            $topics[$key] = [
                'chat_id' => $chatId,
                'id' => (string) $message['message_thread_id'],
                // A later message without the topic header must not erase a name already found.
                'name' => (string) ($name ?? $topics[$key]['name'] ?? ''),
            ];
        }

        return ['chats' => $chats, 'topics' => $topics];
    }
}
