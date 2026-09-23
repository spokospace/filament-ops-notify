<?php

return [

    'page' => [
        'title' => 'Notificações ops',
        'status' => 'Status',
        'service' => 'Serviço',
        'enabled' => 'Ativadas',
        'disabled' => 'Desativadas',
        'channel' => 'Canal',
        'connection' => 'Conexão',
        'configured' => 'Configurado',
        'not_configured' => 'Não configurado',
        'connected_as' => 'Conectado como @:username',
    ],

    'actions' => [
        'settings' => 'Configurações',
        'settings_saved' => 'Configurações salvas',
        'save' => 'Salvar',
        'send_test' => 'Enviar teste',
        'send' => 'Enviar',
        'message' => 'Mensagem',
        'test_default_text' => 'Se você consegue ler isto, as notificações funcionam.',
        'resend' => 'Reenviar',
        'sent' => 'Enviado',
        'not_sent' => 'Não enviado',
    ],

    'table' => [
        'when' => 'Quando',
        'event' => 'Evento',
        'level' => 'Nível',
        'title' => 'Título',
        'status' => 'Status',
        'channel_topic' => 'Canal / tópico',
        'attempts' => 'Tentativas',
        'empty' => 'Nenhuma notificação enviada ainda',
        'resent_as' => 'Reenviado como #:id',
    ],

    'level' => [
        'info' => 'Info',
        'success' => 'Sucesso',
        'warning' => 'Aviso',
        'error' => 'Erro',
        'critical' => 'Crítico',
    ],

    'status' => [
        'queued' => 'Na fila',
        'sent' => 'Enviado',
        'failed' => 'Falhou',
        'resent' => 'Reenviado',
    ],

    'settings' => [
        'locked' => 'Definido no .env ou em config/ops-notify.php; altere lá.',

        'telegram' => 'Telegram',
        'service' => 'Nome do serviço',
        'service_help' => 'Prefixo de cada mensagem, ex.: [shop.example]. Vazio = nome do app.',
        'locale' => 'Idioma das mensagens',
        'locale_help' => 'Idioma dos textos que o pacote adiciona às mensagens do Telegram. O chat é lido pela equipe toda, então é o mesmo para todos.',
        'locale_default' => 'Idioma do app (:locale)',
        'bot_token' => 'Token do bot',
        'bot_token_saved' => 'Salvo; deixe vazio para mantê-lo',
        'bot_token_unreadable' => 'Não foi possível descriptografar o token salvo (a APP_KEY mudou). Digite-o novamente.',
        'chat_id' => 'ID do chat',
        'chat_id_help' => 'php artisan ops-notify:telegram-chats mostra o ID.',
        'default_topic' => 'Tópico padrão',
        'default_topic_help' => 'Para eventos sem tópico próprio. Vazio = General.',
        'general' => 'General',
        'enabled' => 'Notificações ativadas',

        'topics' => 'Tópicos',
        'topics_description' => 'Tópicos do fórum do chat. "Criar tópico" cria no Telegram (o bot precisa da permissão de administrador "Gerenciar tópicos"; salve antes o token e o ID do chat). Remover um tópico aqui não o exclui no Telegram.',
        'topic_name' => 'Nome',
        'topic_id' => 'ID do tópico',
        'topic' => 'Tópico',
        'add_existing_topic' => 'Adicionar tópico existente',
        'topic_name_placeholder' => 'Comentários',
        'create_topic' => 'Criar tópico',
        'icon_colour' => 'Cor do ícone',
        'create_in_telegram' => 'Criar no Telegram',
        'topic_created' => 'Tópico ":name" criado (#:id)',
        'topic_not_created' => 'Tópico não criado',
        'save_to_keep_it' => 'Salve as configurações para mantê-lo na lista.',
        'import_topics' => 'Importar do Telegram',
        'imported_topics' => ':count tópico importado|:count tópicos importados',
        'save_to_keep_them' => 'Salve as configurações para mantê-los.',
        'import_failed' => 'Falha na importação',
        'no_new_topics' => 'Nenhum tópico novo encontrado',
        'no_new_topics_help' => 'Envie /ping@seu_bot em cada tópico e importe novamente.',

        'routing' => 'Roteamento de eventos',
        'routing_description' => 'Vence o primeiro padrão correspondente, ex.: inquiry.* ou build.failed.',
        'pattern' => 'Padrão',
        'add_rule' => 'Adicionar regra',

        'forwarding' => 'Notificações do Filament',
        'forwarding_description' => 'Notificações do sino encaminhadas ao canal ops. As regras comparam o título; vence a primeira correspondente.',
        'forward_enabled' => 'Encaminhar notificações do sino',
        'forward_title_placeholder' => 'Nova consulta*',
        'forward' => 'Encaminhar',
    ],

    'colors' => [
        'blue' => 'Azul',
        'yellow' => 'Amarelo',
        'violet' => 'Violeta',
        'green' => 'Verde',
        'pink' => 'Rosa',
        'red' => 'Vermelho',
    ],

    'message' => [
        'test_title' => 'Notificação de teste',
        'sent_by' => 'Enviado por',
        'environment' => 'Ambiente',
        'host' => 'Host',
        'open' => 'Abrir',
        'more_fields' => 'e mais :count campo|e mais :count campos',
    ],

];
