#!/usr/bin/env php
<?php
/**
 * executor.php – берет задачи в таблице jobs и выполняет отправку/изменение/удаление сообщений
 * Запуск: php executor.php
 */

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';
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
define('EXTERNAL_API_LIMIT', 20);
define('MAX_JOBS_PER_RUN', 500);

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
    $logDir = LOG_PATH . '/executor';
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
    $logDir = LOG_PATH . '/jobs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/' . $mid . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $msg\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

// Функция отправки запроса к API MAX
function callMaxApi($api, string $action, array $params): array {
    try {
        if ($action === 'create') {
            $response = $api->sendMessage(
                    chatId: $params['chat_id'],
                    text: $params['text'],
                    attachments: $params['attachments'],
                    format: MessageFormat::Markdown,
            );
            $mid = $response->body->mid ?? null;
            return [
                    'success'  => true,
                    'response' => $mid,
                    'error'    => '',
            ];
        } elseif ($action === 'edit') {
            $api->editMessage(
                    messageId: $params['mid_sended'],
                    text: $params['text'],
                    attachments: $params['attachments'] ?? [],
                    format: MessageFormat::Markdown,
            );
            return [
                    'success'  => true,
                    'response' => 'OK',
                    'error'    => '',
            ];
        } elseif ($action === 'remove') {
            $api->deleteMessage($params['mid_sended']); // удаляем по mid_sended
            return [
                    'success'  => true,
                    'response' => 'OK',
                    'error'    => '',
            ];
        } else {
            return [
                    'success'  => false,
                    'response' => '',
                    'error'    => 'Unknown action',
            ];
        }
    } catch (\Exception $e) {
        return [
                'success'  => false,
                'response' => '',
                'error'    => $e->getMessage(),
        ];
    }
}

// Инициализация классов
try {
    $posts = new Posts();
    $post_status = new PostStatus();
    $sended = new SendedPosts();
    $jobs = new Jobs();
    $error_logs = new ErrorLogs();
} catch (Exception $e) {
    logMessage("Ошибка инициализации: " . $e->getMessage());
    exit(1);
}

// Освобождаем зависшие задачи (processing > 10 минут)
$released = $jobs->releaseStale(10);
if ($released > 0) {
    logMessage("Освобождено зависших задач: $released");
}

$processed = 0;
$startTime = microtime(true);

while ($processed < MAX_JOBS_PER_RUN) {
    // Захватываем задачи (до 20 за раз)
    $tasks = $jobs->lock(20);
    if (empty($tasks)) {
        logMessage("Нет задач, завершаем работу");
        break;
    }

    // Группируем задачи по group_id
    $tasksByGroup = [];
    foreach ($tasks as $task) {
        $tasksByGroup[$task['group_id']][] = $task;
    }

    foreach ($tasksByGroup as $groupId => $groupTasks) {
        // Получаем данные исходного сообщения
        $originalPost = $posts->getById($groupId);
        if (!$originalPost) {
            logMessage("Ошибка: исходное сообщение для group_id $groupId не найдено");
            // Отменяем все задачи этой группы
            foreach ($groupTasks as $task) {
                $jobs->finish($task['id'], false, null, 'Original post not found');
                $processed++;
            }
            continue;
        }

        $mid = $originalPost['mid'];
        $midStatus = $originalPost['mid_status'] ?? null;
        $messageData = $originalPost['message_data']; // уже декодирован

        $status_data = $post_status->getStats($groupId);
        if (!$status_data) {
            // Если записи нет (маловероятно, но на всякий случай), создаём
            $post_status->initOrUpdate($groupId, [
                    'status' => 'Обработка очереди',
                    'chats_all' => 0,
                    'chats_bot_added' => 0,
                    'success' => 0,
                    'pending' => count($groupTasks),
                    'error' => 0,
                    'create' => 0,
                    'edit' => 0,
                    'remove' => 0,
            ]);
            $status_data = $post_status->getStats($groupId);
        }

        logMessageByMID($mid, "Начало обработки группы задач (group_id $groupId), всего задач: " . count($groupTasks));

        $status_data['status'] = "Обработка задач";
        $post_status->updateStatus($groupId, "Обработка задач");

        foreach ($groupTasks as $task) {
            $taskId = $task['id'];
            $action = $task['action'];
            $targetChatId = $task['target_chat_id'];
            $midSended = $task['mid_sended'] ?? null;

            logMessageByMID($mid, "  Выполнение задачи $taskId: $action в чат $targetChatId");

            // Формируем параметры для внешнего API
            $apiParams = [
                    'chat_id' => $targetChatId,
                    'mid_original' => $mid,
                    'mid_sended' => $midSended,
                    'text' => $messageData['text'] ?? '',
                    'attachments' => $messageData['attachments'] ?? [],
            ];

            try {
                usleep(1000000 / EXTERNAL_API_LIMIT); // 50 000 мкс = 0.05 сек
                $result = callMaxApi($api, $action, $apiParams);

                if ($result['success'] && $action === 'create') {
                    $newMid = $result['response'];
                    if ($newMid) {
                        $sended->add($mid, $newMid, $targetChatId);
                    } else {
                        // Если mid не получен, считаем ошибкой
                        $result['success'] = false;
                        $result['error'] = 'No mid in response';
                    }
                } elseif ($result['success'] && $action === 'edit' && $midSended) {
                    $sended->updateTime($midSended);
                } elseif ($result['success'] && $action === 'remove' && $midSended) {
                    $sended->delete($midSended);
                }

                // Уменьшаем счётчики в post_status
                $post_status->decrement($groupId, $action, $result['success']);

                $jobs->finish($taskId, $result['success'], $result['response'], $result['error']);

                logMessageByMID($mid, sprintf(
                        "    Задача %d завершена. Успех: %s",
                        $taskId,
                        $result['success'] ? 'да' : 'нет'
                ));

            } catch (\Exception $e) {
                // В случае исключения записываем как ошибку
                $post_status->decrement($groupId, $action, false);
                $errorId = $error_logs->create($mid, $action, $e->getMessage());
                $jobs->finish($taskId, false, null, $e->getMessage());
                logMessageByMID($mid, "    Исключение при выполнении задачи $taskId: " . $e->getMessage());
            }

            $processed++;
        }

        // После обработки всех задач группы проверяем, остались ли ещё задачи для этого group_id
        $remaining = $jobs->countPendingByGroup($groupId);
        if ($remaining === 0) {
            // Все задачи выполнены – обновляем финальный статус
            $finalStats = $post_status->getStats($groupId);

            $finalStatus = ($finalStats['error'] ?? 0) === 0 ? 'Все задачи выполнены успешно' : 'Выполнено с ошибками';
            $post_status->updateStatus($groupId, $finalStatus);

            $moderatorMsg = moderator_response([
                    'success' => $finalStats['success'],
                    'error'   => $finalStats['error'],
                    'action'  => $originalPost['action']
            ]);

            // Получаем актуальную статистику для обновления статусного сообщения
            $currentStats = $post_status->getStats($groupId);
            // Обновляем статусное сообщение после каждой задачи
            if ($currentStats  && $midStatus) {
                usleep(1000000 / EXTERNAL_API_LIMIT); // 50 000 мкс = 0.05 сек
                $api->editMessage(
                        messageId: $midStatus,
                        text: message_status($currentStats),
                        format: MessageFormat::Markdown,
                );
            }
            elseif (!$midStatus) {
                try {
                    usleep(1000000 / EXTERNAL_API_LIMIT); // 50 000 мкс = 0.05 сек
                    $statusMessage = $api->sendMessage(
                            chatId: $originalPost['chat_id'],
                            text: message_status($currentStats),
                            format: MessageFormat::Markdown,
                            link: new MessageLink(MessageLinkType::Reply, $mid)
                    );
                    $newMidStatus = $statusMessage->body->mid ?? null;
                    if ($newMidStatus) {
                        $posts->updateStatusMid($groupId, $newMidStatus);
                        $midStatus = $newMidStatus;
                    }
                }
                catch (\Exception $notificate_exception) {
                    $posts->markError($groupId);
                    $id_error = $error_logs->create($mid, 'notificate new status (executor)', $notificate_exception->getMessage());
                    foreach ($MODERATOR_CHATS as $chatId) {
                        usleep(1000000 / EXTERNAL_API_LIMIT); // 50 000 мкс = 0.05 сек
                        $api->sendMessage(
                                chatId: $chatId,
                                text: "При обработке произошла ошибка **#" . $id_error . "**. Обратитесь к администратору",
                                format: MessageFormat::Markdown,
                        );
                    }
                    logMessage("Не получается отправить данные об обработке для сообщения " . $mid);
                };
            }

            if ($midStatus) {
                usleep(1000000 / EXTERNAL_API_LIMIT); // 50 000 мкс = 0.05 сек
                $api->sendMessage(
                        chatId: $originalPost['chat_id'],
                        text: $moderatorMsg,
                        format: MessageFormat::Markdown,
                        link: new MessageLink(MessageLinkType::Reply, $mid)
                );
            }
        }

        logMessageByMID($mid, "Группа задач завершена. Осталось задач в очереди: $remaining");
    }
}

$totalTime = microtime(true) - $startTime;
logMessage("Исполнитель завершил работу. Обработано задач: $processed за " . round($totalTime, 2) . " сек.");