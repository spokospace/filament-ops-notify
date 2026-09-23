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
    | Never post to the real chat from an app's test suite (the token may well be in .env).
    | Set to false only in a test that exercises delivery against a faked HTTP client.
    */
    'disable_in_tests' => true,

    /*
    | [panel] Label that prefixes every message, so one chat can collect several services.
    | Null = the app name (config('app.name')).
    */
    'service' => env('OPS_NOTIFY_SERVICE'),

    /*
    | [panel] Language of the text the package puts into messages (labels, buttons, "and N more
    | fields"). The chat is read by the team, so it is one language per service rather than the
    | locale of whoever triggered the message. Null = the app locale.
    */
    'locale' => env('OPS_NOTIFY_LOCALE'),

    'default_channel' => env('OPS_NOTIFY_CHANNEL', 'telegram'),

    'channels' => [
        'telegram' => [
            'driver' => 'telegram',
            'bot_token' => env('OPS_NOTIFY_TELEGRAM_BOT_TOKEN'),   // [panel]
            'chat_id' => env('OPS_NOTIFY_TELEGRAM_CHAT_ID'),       // [panel]
            // [panel] Forum topic (message_thread_id) used when an event has no topic of its own.
            'topic' => env('OPS_NOTIFY_TELEGRAM_TOPIC'),
            // [panel] Known forum topics, list of ['id' => '3', 'name' => 'Zapytania']. Labels
            // for the pickers and the log; the Bot API cannot list topics itself.
            'topics' => [],
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

    /*
    | Send rate per channel (each posts to one chat), enforced on the queue: messages over the limit
    | wait instead of being rejected. Telegram allows about 20 messages a minute in one group and
    | one a second in a chat. 0 turns a limit off. Needs a cache store shared by the workers.
    | A message still undelivered after give_up_after_minutes is marked failed.
    */
    'rate_limit' => [
        'per_second' => 1,
        'per_minute' => 20,
        'give_up_after_minutes' => 60,
    ],

    // Run the package's migrations from vendor. Turn off only after publishing them
    // (vendor:publish --tag=ops-notify-migrations) to change them.
    'run_migrations' => env('OPS_NOTIFY_RUN_MIGRATIONS', true),

    'log' => [
        // Store every sent message in ops_notify_logs (shown on the Filament page).
        'enabled' => env('OPS_NOTIFY_LOG_ENABLED', true),
        // Rows older than this are removed daily at `prune_at` (scheduled by the package;
        // needs the app's scheduler running). Set prune_at to null to schedule it yourself.
        'prune_after_days' => 30,
        'prune_at' => '02:45',
    ],

];
