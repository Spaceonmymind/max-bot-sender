#!/usr/bin/env php
<?php
/**
 * chats_checker.php – проверяет чаты на актуальность
 * Запуск: php chats_checker.php
 */

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/chats.php';
require_once __DIR__ . '/config/error_logs.php';
require_once __DIR__ . '/bot/utils.php';

use BushlanovDev\MaxMessengerBot\Api;
use BushlanovDev\MaxMessengerBot\Enums\MessageFormat;

define('LOG_PATH', __DIR__ . '/logs');
define('CHATS_CSV', __DIR__ . '/chats.csv');

set_time_limit(0);

$config = require __DIR__ . '/config/config.php';
$apiToken = $config['api_token'];
$MODERATOR_CHATS = $config['moderators_chats'];

// Инициализация API
$api = new Api($apiToken);

/**
 * Логирование в файл и консоль
 */
function logMessage(string $msg): void {
    $date = date('d.m.Y'); // ДД.ММ.ГГГГ
    $logDir = LOG_PATH . '/chats_checker';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/' . $date . '.log';

    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $msg\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

/**
 * Получение списка целевых чатов из CSV-файла
 * @return int[]
 */
function getTargetChats(): array {
    if (!file_exists(CHATS_CSV)) {
        logMessage("Файл чатов не найден: " . CHATS_CSV);
        return [];
    }
    return get_all_chats_from_csv(CHATS_CSV);
}

/**
 * Проверка чата, что бот добавлен
 * @return array
 */
function checkTargetChats($api, $chatId): array {
    $timeout_interval = 1/30 * 1e6; // 0.03 сек (Ограничение мессенджера на 30 запросов в секунду)
    try {
        usleep($timeout_interval); // Задержка перед отправкой
        $chat = $api->getChat($chatId);
        $chat_title = $chat->title ?? null;
        return ['is_bot_added' => true, 'title' => $chat_title];
    }
    catch (\Exception $apiException) {
        return ['is_bot_added' => false, 'title' => null];
    }
}

// Инициализация классов
try {
    $chats = new Chats();
} catch (Exception $e) {
    logMessage("Ошибка инициализации: " . $e->getMessage());
    exit(1);
}

$targetChatsCSV = getTargetChats();
$targetChatsDB = $chats->getAllChatIds();
$targetChats = array_unique(array_merge($targetChatsCSV, $targetChatsDB));

if (empty($targetChats)) {
    foreach ($MODERATOR_CHATS as $chatId) {
        $api->sendMessage(
            chatId: $chatId,
            text: "**Ошибка** - Нет целевых чатов",
            format: MessageFormat::Markdown,
        );
    }
    logMessage("Нет целевых чатов, завершаем работу");
    exit(0);
}

logMessage("Проверка чатов на актуальность");

$count_checked = 0;
foreach ($targetChats as $chatId) {
    $result = checkTargetChats($api, $chatId);
    $is_bot_added = $result['is_bot_added'] ? 'ОК' : 'Чат не доступен';
    $bot_title = ($result['title'] !== null) ? $result['title'] : '-';
    $chats->create($chatId, $result['title']); // создаст или обновит название
    $chats->setActual($chatId, $result['is_bot_added']);
    $count_checked++;
    logMessage($count_checked . ") Проверено: " . $chatId . " | " . $is_bot_added . " | " . $bot_title);
}

if (empty($chats->getActual())) {
    foreach ($MODERATOR_CHATS as $chatId) {
        $api->sendMessage(
            chatId: $chatId,
            text: "**Ошибка** - Чат-бот не добавлен ни в один целевой чат, либо чаты удалены",
            format: MessageFormat::Markdown,
        );
    }
    logMessage("Чат-бот не добавлен ни в один целевой чат, либо чаты удалены, завершаем работу");
    exit(0);
}

logMessage("Проверка чатов закончена");
