<?php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/bot/bot_added.php';
require_once __DIR__ . '/bot/bot_removed.php';
require_once __DIR__ . '/bot/bot_started.php';
require_once __DIR__ . '/bot/message_created.php';
require_once __DIR__ . '/bot/message_edited.php';
require_once __DIR__ . '/bot/message_removed.php';

use BushlanovDev\MaxMessengerBot\Api;

// Разрешить CORS если нужно
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Подключение конфигурации
$config = require __DIR__ . '/config/config.php';
$apiToken = $config['api_token'];
$apiBaseUrl = $config['api_base_url'];
$SECRET_WEB_HOOK = $config['secret_webhook'];
$MODERATOR_CHATS = $config['moderators_chats'];
$MODERATORS_USERS = $config['moderators_users'];
$ADMINS_USERS = $config['admins_users'];

$BOT_ID = $config['bot_id'];
$BOT_USERNAME = $config['bot_username'];

// Инициализация API
$api = new Api($apiToken);
// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Получаем маршрут
$route = $_GET['route'] ?? '';

// Проверяем Secret в WebHook
$secret = $_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? null;

if ($secret === null) {
    return [
            'status' => 'error',
            'message' => 'Header X-Max-Bot-Api-Secret is missing',
            'code' => 401
    ];
}

if (empty($secret)) {
    return [
            'status' => 'error',
            'message' => 'Header X-Max-Bot-Api-Secret is empty',
            'code' => 401
    ];
}

if ($secret !== $SECRET_WEB_HOOK) {
    return [
            'status' => 'error',
            'message' => 'Header X-Max-Bot-Api-Secret is wrong',
            'code' => 401
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return [
            'status' => 'error',
            'message' => 'Method Not Allowed',
            'code' => 405
    ];
}

// Обработка маршрутов
switch ($route) {
    case 'bot/bot_added':
        handleBotAdded($api);
        break;

    case 'bot/bot_removed':
        handleBotRemoved($api);
        break;

    case 'bot/bot_started':
        handleBotStarted($api);
        break;

    case 'bot/message_created':
        handleMessageCreated($api);
        break;

    case 'bot/message_edited':
        handleMessageEdited($api);
        break;

    case 'bot/message_removed':
        handleMessageRemoved($api);
        break;

    default:
        http_response_code(404);
        echo "404 Not Found";
};