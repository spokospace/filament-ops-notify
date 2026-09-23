<?php

return [

    'page' => [
        'title' => 'Ops-сповіщення',
        'status' => 'Стан',
        'service' => 'Сервіс',
        'enabled' => 'Увімкнено',
        'disabled' => 'Вимкнено',
        'channel' => 'Канал',
        'connection' => "З'єднання",
        'configured' => 'Налаштовано',
        'not_configured' => 'Не налаштовано',
        'connected_as' => 'Підключено як @:username',
    ],

    'actions' => [
        'settings' => 'Налаштування',
        'settings_saved' => 'Налаштування збережено',
        'save' => 'Зберегти',
        'send_test' => 'Надіслати тест',
        'send' => 'Надіслати',
        'message' => 'Повідомлення',
        'test_default_text' => 'Якщо ви бачите це, сповіщення працюють.',
        'resend' => 'Надіслати знову',
        'sent' => 'Надіслано',
        'not_sent' => 'Не надіслано',
    ],

    'table' => [
        'when' => 'Коли',
        'event' => 'Подія',
        'level' => 'Рівень',
        'title' => 'Заголовок',
        'status' => 'Стан',
        'channel_topic' => 'Канал / тема',
        'attempts' => 'Спроби',
        'empty' => 'Сповіщень ще не надсилали',
        'resent_as' => 'Надіслано знову як #:id',
    ],

    'level' => [
        'info' => 'Інформація',
        'success' => 'Успіх',
        'warning' => 'Попередження',
        'error' => 'Помилка',
        'critical' => 'Критично',
    ],

    'status' => [
        'queued' => 'У черзі',
        'sent' => 'Надіслано',
        'failed' => 'Помилка',
        'resent' => 'Надіслано знову',
    ],

    'settings' => [
        'locked' => 'Задано в .env або config/ops-notify.php — змініть там.',

        'telegram' => 'Telegram',
        'service' => 'Назва сервісу',
        'service_help' => 'Префікс кожного повідомлення, напр. [shop.example]. Порожньо = назва застосунку.',
        'locale' => 'Мова повідомлень',
        'locale_help' => 'Мова текстів, які пакет додає до повідомлень у Telegram. Чат читає вся команда, тож вона однакова для всіх.',
        'locale_default' => 'Мова застосунку (:locale)',
        'bot_token' => 'Токен бота',
        'bot_token_saved' => 'Збережено; залиште порожнім, щоб не змінювати',
        'bot_token_unreadable' => 'Не вдається розшифрувати збережений токен (змінився APP_KEY). Введіть його знову.',
        'chat_id' => 'ID чату',
        'chat_id_help' => 'Його покаже php artisan ops-notify:telegram-chats.',
        'default_topic' => 'Тема за замовчуванням',
        'default_topic_help' => 'Для подій без власної теми. Порожньо = General.',
        'general' => 'General',
        'enabled' => 'Сповіщення увімкнено',

        'topics' => 'Теми',
        'topics_description' => 'Теми форуму в чаті. «Створити тему» створює її в Telegram (боту потрібне право адміністратора «Керування темами»; спершу збережіть токен і ID чату). Видалення теми тут не видаляє її в Telegram.',
        'topic_name' => 'Назва',
        'topic_id' => 'ID теми',
        'topic' => 'Тема',
        'add_existing_topic' => 'Додати наявну тему',
        'topic_name_placeholder' => 'Коментарі',
        'create_topic' => 'Створити тему',
        'icon_colour' => 'Колір значка',
        'create_in_telegram' => 'Створити в Telegram',
        'topic_created' => 'Тему «:name» створено (#:id)',
        'topic_not_created' => 'Тему не створено',
        'save_to_keep_it' => 'Збережіть налаштування, щоб залишити її у списку.',
        'import_topics' => 'Імпортувати з Telegram',
        'imported_topics' => 'Імпортовано :count тему|Імпортовано :count теми|Імпортовано :count тем',
        'save_to_keep_them' => 'Збережіть налаштування, щоб їх залишити.',
        'import_failed' => 'Не вдалося імпортувати',
        'no_new_topics' => 'Нових тем не знайдено',
        'no_new_topics_help' => 'Надішліть /ping@ваш_бот у кожній темі й імпортуйте знову.',

        'routing' => 'Маршрутизація подій',
        'routing_description' => 'Перемагає перший відповідний шаблон, напр. inquiry.* або build.failed.',
        'pattern' => 'Шаблон',
        'add_rule' => 'Додати правило',

        'forwarding' => 'Сповіщення Filament',
        'forwarding_description' => 'Сповіщення з дзвіночка, що пересилаються в ops-канал. Правила порівнюють заголовок; перемагає перше відповідне.',
        'forward_enabled' => 'Пересилати сповіщення з дзвіночка',
        'forward_title_placeholder' => 'Новий запит*',
        'forward' => 'Пересилати',
    ],

    'colors' => [
        'blue' => 'Синій',
        'yellow' => 'Жовтий',
        'violet' => 'Фіолетовий',
        'green' => 'Зелений',
        'pink' => 'Рожевий',
        'red' => 'Червоний',
    ],

    'message' => [
        'test_title' => 'Тестове сповіщення',
        'sent_by' => 'Надіслав',
        'environment' => 'Середовище',
        'host' => 'Хост',
        'open' => 'Відкрити',
        'more_fields' => 'і ще :count поле|і ще :count поля|і ще :count полів',
    ],

];
