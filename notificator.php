#!/usr/bin/env php
<?php
/**
 * notificator.php – периодически обновляет статусные сообщения для активных постов
 * Запуск: php notificator.php
*/

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/error_logs.php';
require_once __DIR__ . '/config/posts.php';
require_once __DIR__ . '/config/post_status.php';
require_once __DIR__ . '/bot/utils.php';

use BushlanovDev\MaxMessengerBot\Api;
use BushlanovDev\MaxMessengerBot\Enums\MessageFormat;

define('LOG_PATH', __DIR__ . '/logs');
define('MESSENGER_API_LIMIT', 20);
define('MAX_POSTS_PER_RUN', 50);

set_time_limit(0);

$config = require __DIR__ . '/config/config.php';
$apiToken = $config['api_token'];

$api = new Api($apiToken);

/**
 * Логирование в файл и консоль
 */
function logMessage(string $msg): void {
    $date = date('d.m.Y');
    $logDir = LOG_PATH . '/notificator';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/' . $date . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $msg\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

// Инициализация классов
try {
    $posts = new Posts();
    $postStatus = new PostStatus();
    $errorLogs = new ErrorLogs();
} catch (Exception $e) {
    logMessage("Ошибка инициализации: " . $e->getMessage());
    exit(1);
}

// Получаем активные записи из post_status (где ещё есть задачи или статус не финальный)
// Мы выбираем те, где pending > 0, или статус не равен финальным значениям.
// Также ограничиваем количество, чтобы не нагружать.
$pdo = Database::getInstance()->getConnection();
$stmt = $pdo->prepare("
    SELECT ps.*, p.mid_status
    FROM post_status ps
    INNER JOIN posts p ON p.id = ps.post_id
    WHERE ps.pending > 0 
       OR (ps.status NOT IN ('Все задачи выполнены успешно', 'Выполнено с ошибками'))
    LIMIT :limit
");
$stmt->bindValue(':limit', MAX_POSTS_PER_RUN, PDO::PARAM_INT);
$stmt->execute();
$activePosts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($activePosts)) {
    logMessage("Нет активных постов для обновления статуса.");
    exit(0);
}

logMessage("Найдено активных постов: " . count($activePosts));

foreach ($activePosts as $row) {
    $postId = $row['post_id'];
    $midStatus = $row['mid_status'] ?? null;

    if (!$midStatus) {
        logMessage("Пост ID $postId не имеет статусного сообщения, пропускаем.");
        continue;
    }

    // Формируем данные для отображения (приводим к формату, ожидаемому message_status)
    $stats = [
        'status'           => $row['status'],
        'chats_all'        => $row['chats_all'],
        'chats_bot_added'  => $row['chats_bot_added'],
        'success'          => $row['success'],
        'pending'          => $row['pending'],
        'error'            => $row['error'],
        'create'           => $row['create_count'],
        'edit'             => $row['edit_count'],
        'remove'           => $row['remove_count'],
    ];

    logMessage("Обновление статуса для post_id $postId, mid_status = $midStatus");

    try {
        $api->editMessage(
            messageId: $midStatus,
            text: message_status($stats),
            format: MessageFormat::Markdown,
        );
        logMessage("Статус для post_id $postId успешно обновлён.");
    } catch (\Exception $e) {
        $errorId = $errorLogs->create('', 'notificator_update', $e->getMessage());
        logMessage("Ошибка обновления статуса для post_id $postId: #$errorId " . $e->getMessage());
    }

    // Rate limiting для API мессенджера
    usleep(1000000 / MESSENGER_API_LIMIT); // ~0.033 сек
}

logMessage("Notificator завершил работу.");