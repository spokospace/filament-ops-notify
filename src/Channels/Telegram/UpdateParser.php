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
     *     topics: array<string, array{chat_id: string, id: string, name: string}>,
     *     migrations: array<string, string>
     * }
     */
    public static function parse(array $updates): array
    {
        $chats = [];
        $topics = [];
        // old group id => supergroup id. Turning on Topics upgrades a group to a supergroup with a
        // new id, and Telegram announces it in both chats.
        $migrations = [];

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

            if (isset($message['migrate_to_chat_id'])) {
                $migrations[$chatId] = (string) $message['migrate_to_chat_id'];
            }

            if (isset($message['migrate_from_chat_id'])) {
                $migrations[(string) $message['migrate_from_chat_id']] = $chatId;
            }

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

        return ['chats' => $chats, 'topics' => $topics, 'migrations' => $migrations];
    }

    /**
     * Adds a newer parse() result to an older one: newer values win, but a chat keeps its
     * forum flag and a topic keeps a name the newer updates did not repeat.
     *
     * @param  array{chats?: array<string, array<string, mixed>>, topics?: array<string, array<string, mixed>>, migrations?: array<string, string>}  $known
     * @param  array{chats: array<string, array<string, mixed>>, topics: array<string, array<string, mixed>>, migrations: array<string, string>}  $found
     * @return array{chats: array<string, array<string, mixed>>, topics: array<string, array<string, mixed>>, migrations: array<string, string>}
     */
    public static function merge(array $known, array $found): array
    {
        $chats = $known['chats'] ?? [];
        foreach ($found['chats'] as $id => $chat) {
            $chats[$id] = [...$chat, 'forum' => $chat['forum'] || ($chats[$id]['forum'] ?? false)];
        }

        $topics = $known['topics'] ?? [];
        foreach ($found['topics'] as $key => $topic) {
            $topics[$key] = [...$topic, 'name' => $topic['name'] !== '' ? $topic['name'] : ($topics[$key]['name'] ?? '')];
        }

        return [
            'chats' => $chats,
            'topics' => $topics,
            'migrations' => array_replace($known['migrations'] ?? [], $found['migrations']),
        ];
    }
}
