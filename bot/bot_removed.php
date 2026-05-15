<?php
/**
 * Обработка POST запроса для bot_removed
 */
require_once __DIR__ . '/../config/chats.php';

use BushlanovDev\MaxMessengerBot\Enums\MessageFormat;
use BushlanovDev\MaxMessengerBot\Enums\SenderAction;

function handleBotRemoved($api) {
    global $MODERATOR_CHATS;

    $input = file_get_contents('php://input');
    $updateData = json_decode($input, true);

    $api->sendMessage(
        userId: 49172371,
        text: print_r($updateData, true),
    );

    if ($updateData === null) {
        http_response_code(400);
        echo 'Неверный JSON';
        exit;
    }

    // Проверяем тип события
    if (isset($updateData['update_type']) && $updateData['update_type'] === 'bot_removed') {
        $chatId = $updateData['chat_id'] ?? null;
        $api->sendAction($chatId, SenderAction::MarkSeen);

        if ($chatId !== null) {
            $chats = new Chats();
            $error_logs = new ErrorLogs();

            try {
                $chat_title = $chats->getTitleById($chatId);

                $user_id = $updateData['user']['user_id'] ?? '-';
                $first_name = $updateData['user']['first_name'] ?? '-';
                $last_name = $updateData['user']['last_name'] ?? '-';
                $username = $updateData['user']['username'] ?? '-';
                $is_bot = $updateData['user']['is_bot'] ? 'Да' : 'Нет';

                $chats->deleteByChatId($chatId);

                $delete_data = "**Бот был удалён из чата**\n\n"
                    . "Название чата:\n**" . $chat_title
                    . "**\nid чата:\n**" . $chatId
                    . "**\n\n**Пользователь удаливший бота**"
                    . "\nid пользователя:\n**" . $user_id
                    . "**\nusername:\n**" . $username
                    . "**\nимя:\n**" . $first_name
                    . "**\nфамилия:\n**" . $last_name
                    . "**\nэто бот:\n**" . $is_bot . "**";

                foreach ($MODERATOR_CHATS as $chatIdModerator) {
                    $api->sendMessage(
                        chatId: $chatIdModerator,
                        text: $delete_data,
                        format: MessageFormat::Markdown,
                    );
                }
            } catch (\Exception $bot_added_exception) {
                $id_error = $error_logs->create(null, 'bot_added_new_chat', "chatId = " . $chatId . " | " . $bot_added_exception->getMessage());

                foreach ($MODERATOR_CHATS as $chatIdModerator) {
                    $api->sendMessage(
                        chatId: $chatIdModerator,
                        text: "При удалении чата из БД произошла ошибка **#" . $id_error . "**. Обратитесь к администратору",
                        format: MessageFormat::Markdown,
                    );
                }
            }
        }
    }

    // Возвращаем 200 OK
    http_response_code(200);
    echo 'OK';
};