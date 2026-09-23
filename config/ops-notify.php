<?php

/*
| Values marked [panel] can also be edited on the Filament "Ops notifications" page (stored
| in ops_notify_settings, the bot token encrypted). A value set here or in .env wins and locks
| the field in the panel; leave it null/empty to manage it from the panel.
*/

return [

    /*
    | [panel] Master switch. When false, nothing is sent or logged. Null = enabled.
    */
    'enabled' => env('OPS_NOTIFY_ENABLED'),

    /*
    | Label that prefixes every message, so one chat can collect several services.
    */
    'service' => env('OPS_NOTIFY_SERVICE', env('APP_NAME', 'Laravel')),

    'default_channel' => env('OPS_NOTIFY_CHANNEL', 'telegram'),

    'channels' => [
        'telegram' => [
            'driver' => 'telegram',
            'bot_token' => env('OPS_NOTIFY_TELEGRAM_BOT_TOKEN'),   // [panel]
            'chat_id' => env('OPS_NOTIFY_TELEGRAM_CHAT_ID'),       // [panel]
            // [panel] Forum topic (message_thread_id) used when an event has no topic of its own.
            'topic' => env('OPS_NOTIFY_TELEGRAM_TOPIC'),
            'api_url' => env('OPS_NOTIFY_TELEGRAM_API_URL', 'https://api.telegram.org'),
            'timeout' => 10,
        ],
    ],

    /*
    | [panel] Per-event routing. Keys are patterns matched with Str::is(), first match wins.
    | Options: enabled (bool), channel (name from `channels`), topic (driver sub-target;
    | Telegram: forum topic id). Events that match nothing use the default channel and topic.
    */
    'events' => [
        // 'inquiry.*' => ['topic' => env('OPS_NOTIFY_TOPIC_INQUIRIES')],
        // 'noisy.event' => ['enabled' => false],
    ],

    /*
    | A notification sent to N users is delivered once per user. Identical messages within this
    | many seconds are sent once (Filament forwarding and the "ops" notification channel).
    | 0 disables it.
    */
    'dedupe_seconds' => 60,

    /*
    | Mirror Filament bell notifications (Notification::make()->sendToDatabase($users)).
    |
    | `map` matches the notification TITLE with Str::is(), first match wins:
    |   a string = event name (route it with `events` above), false = don't forward.
    | Unmatched titles use `default_event`; set it to null to forward only mapped titles.
    */
    'forward_database_notifications' => [
        'enabled' => env('OPS_NOTIFY_FORWARD_DATABASE'),  // [panel] null = enabled
        'default_event' => 'filament.notification',
        'map' => [                                        // [panel]
            // 'Nowe zapytanie*' => 'inquiry.created',
            // 'Export completed*' => false,
        ],
    ],

    'queue' => [
        'connection' => env('OPS_NOTIFY_QUEUE_CONNECTION'),
        'name' => env('OPS_NOTIFY_QUEUE'),
    ],

    'log' => [
        // Store every sent message in ops_notify_logs (shown on the Filament page).
        'enabled' => env('OPS_NOTIFY_LOG_ENABLED', true),
        // Rows older than this are removed by `php artisan model:prune`.
        'prune_after_days' => 30,
    ],

];
