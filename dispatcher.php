#!/usr/bin/env php
<?php
/**
 * dispatcher.php – создаёт задачи в таблице jobs на основе новых записей в posts
 * Запуск: php dispatcher.php
 */

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/chats.php';
require_once __DIR__ . '/config/error_logs.php';
require_once __DIR__ . '/config/jobs.php';
require_once __DIR__ . '/config/posts.php';
require_once __DIR__ . '/config/post_status.php';
require_once __DIR__ . '/config/sended_posts.php';
require_once __DIR__ . '/bot/utils.php';

use BushlanovDev\MaxMessengerBot\Api;
use BushlanovDev\MaxMessengerBot\Enums\MessageFormat;
use BushlanovDev\MaxMessengerBot\Enums\MessageLinkType;
use BushlanovDev\MaxMessengerBot\Models\MessageLink;

define('LOG_PATH', __DIR__ . '/logs');

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
    $logDir = LOG_PATH . '/dispatcher';
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
 * Логирование в файл и консоль по mid сообщения
 */
function logMessageByMID(string $mid, string $msg): void {
    $logDir = LOG_PATH . '/mid';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/' . $mid . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $msg\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

// Инициализация классов
try {
    $posts = new Posts();
    $post_status = new PostStatus();
    $chats = new Chats();
    $sended = new SendedPosts();
    $jobs = new Jobs();
    $error_logs = new ErrorLogs();
} catch (Exception $e) {
    logMessage("Ошибка инициализации: " . $e->getMessage());
    exit(1);
}

// Освобождаем зависшие записи в posts (processing > 10 минут)
$released = $posts->releaseStale(10);
if ($released > 0) {
    logMessage("Освобождено зависших записей posts: $released");
}

// Проверка актуальных чатов
$clearTargetChats = $chats->getActual();  // todo: боевой
//$clearTargetChats = get_all_chats_from_csv_TEST(20);  // todo: TEST

if (empty($clearTargetChats)) {
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

// Получаем записи для обработки (блокируем)
$pendingPosts = $posts->lockPending(20); // берём по 20 за раз
logMessage("Заблокировано записей: " . count($pendingPosts));

foreach ($pendingPosts as $post) {
    $postId = $post['id'];
    $mid = $post['mid'];
    $mid_status = $post['mid_status'];
    $action = $post['action'];

    $status_data = [
            'status' => 'Создание очереди для отправки сообщений',
            'chats_all' => $chats->countAll(),
            'chats_bot_added' => count($clearTargetChats),
            'success' => 0,
            'pending' => 0,
            'error' => 0,
            'create' => 0,
            'edit' => 0,
            'remove' => 0,
    ];

    $post_status->initOrUpdate($postId, $status_data);

    if ($mid_status) {
        $api->editMessage(
                messageId: $mid_status,
                text: message_status($status_data),
                format: MessageFormat::Markdown,
        );
    }
    else {
        try {
            $original_message = $api->getMessageById($mid);
            $chatId = $original_message->recipient->chatId;
            $statusMessage = $api->sendMessage(
                    chatId: $chatId,
                    text: message_status($status_data),
                    format: MessageFormat::Markdown,
                    link: new MessageLink(MessageLinkType::Reply, $mid)
            );
            $mid_status = $statusMessage->body->mid ?? null;
            if ($mid_status) {
                $posts->updateStatusMid($postId, $mid_status);
            }
        }
        catch (\Exception $notificate_exception) {
            $posts->markError($postId);
            $id_error = $error_logs->create($mid, 'notificate new status (dispatcher)', $notificate_exception->getMessage());
            foreach ($MODERATOR_CHATS as $chatId) {
                $api->sendMessage(
                        chatId: $chatId,
                        text: "При обработке произошла ошибка **#" . $id_error . "**. Обратитесь к администратору",
                        format: MessageFormat::Markdown,
                );
            }
            logMessage("Не получается отправить данные об обработке для сообщения " . $mid);
            continue;
        };
    }

    logMessage("Обработка записи ID $postId, mid=$mid, action=$action");

    $count_to_process = 0;
    $count_to_create = 0;
    $count_to_edit = 0;
    $count_to_remove = 0;
    $count_errors = 0;

    // Для каждого целевого чата решаем, какую задачу создать
    foreach ($clearTargetChats as $chatId) {
        try {
            // Получаем все существующие копии для этого mid и чата
            $existingMids = $sended->findByOriginalAndChat($mid, $chatId);

            if ($action === 'create') {
                $count_to_process++;
                $count_to_create++;
                // Если копия уже есть – пропускаем (не создаём дубль)
                if (!empty($existingMids)) {
                    logMessageByMID($mid,"  Чат $chatId: уже есть копия, пропускаем create");
                    continue;
                }
                // Иначе создаём задачу create
                $jobId = $jobs->add($postId, $mid, $chatId, 'create');
                logMessageByMID($mid,"  Чат $chatId: создана задача create (job ID $jobId)");

            } elseif ($action === 'edit') {
                $count_to_process++;
                if (!empty($existingMids)) {
                    // Для каждой копии создаём задачу edit
                    foreach ($existingMids as $midSended) {
                        $jobId = $jobs->add($postId, $mid, $chatId, 'edit', $midSended);
                        $count_to_edit++;
                        logMessageByMID($mid, "  Чат $chatId: создана задача edit (job ID $jobId) для копии $midSended");
                    }
                } else {
                    // Копии нет – создаём задачу create, чтобы создать новое сообщение с актуальными данными
                    $jobId = $jobs->add($postId, $mid, $chatId, 'create');
                    $count_to_create++;
                    logMessageByMID($mid,"  Чат $chatId: копии нет, создана задача create (job ID $jobId) вместо edit");
                }

            } elseif ($action === 'remove') {
                if (!empty($existingMids)) {
                    // Для каждой копии создаём задачу remove
                    foreach ($existingMids as $midSended) {
                        $jobId = $jobs->add($postId, $mid, $chatId, 'remove', $midSended);
                        $count_to_remove++;
                        logMessageByMID($mid, "  Чат $chatId: создана задача remove (job ID $jobId) для копии $midSended");
                    }
                } else {
                    // Копии нет – ничего не делаем
                    logMessageByMID($mid,"  Чат $chatId: копии нет, remove не требуется");
                }
            }
        }
        catch (\Exception $jobs_exception) {
            $count_errors++;
            logMessageByMID($mid,"  ERROR: " . $jobs_exception->getMessage());
        }
    }

    // Помечаем запись как выполненную
    $posts->markDone($postId);
    logMessage("Запись ID $postId помечена как done");

    $status_data['status'] = 'Очередь задач создана. Ожидается обработка очереди';
    $status_data['pending'] = $count_to_process;
    $status_data['error'] = $count_errors;
    $status_data['create'] = $count_to_create;
    $status_data['edit'] = $count_to_edit;
    $status_data['remove'] = $count_to_remove;

    $post_status->initOrUpdate($postId, $status_data);

    if ($mid_status) {
        $api->editMessage(
                messageId: $mid_status,
                text: message_status($status_data),
                format: MessageFormat::Markdown,
        );
    }
    else {
        try {
            $original_message = $api->getMessageById($mid);
            $chatId = $original_message->recipient->chatId;
            $statusMessage = $api->sendMessage(
                    chatId: $chatId,
                    text: message_status($status_data),
                    format: MessageFormat::Markdown,
                    link: new MessageLink(MessageLinkType::Reply, $mid)
            );
            $mid_status = $statusMessage->body->mid ?? null;
            if ($mid_status) {
                $posts->updateStatusMid($postId, $mid_status);
            }
        }
        catch (\Exception $notificate_exception) {
            $posts->markError($postId);
            $id_error = $error_logs->create($mid, 'notificate new status (dispatcher)', $notificate_exception->getMessage());
            foreach ($MODERATOR_CHATS as $chatId) {
                $api->sendMessage(
                        chatId: $chatId,
                        text: "При обработке произошла ошибка **#" . $id_error . "**. Обратитесь к администратору",
                        format: MessageFormat::Markdown,
                );
            }
            logMessage("Не получается отправить данные об обработке для сообщения " . $mid);
        };
    }
}

logMessage("Диспетчер завершил работу");