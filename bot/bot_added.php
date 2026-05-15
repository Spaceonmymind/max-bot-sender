<?php
/**
 * Обработка POST запроса для bot_added
 */
require_once __DIR__ . '/../config/chats.php';

use BushlanovDev\MaxMessengerBot\Enums\MessageFormat;
use BushlanovDev\MaxMessengerBot\Enums\SenderAction;

function handleBotAdded($api) {
    global $MODERATOR_CHATS;

    $input = file_get_contents('php://input');
    $updateData = json_decode($input, true);

    if ($updateData === null) {
        http_response_code(400);
        echo 'Неверный JSON';
        exit;
    }

    // Проверяем тип события
    if (isset($updateData['update_type']) && $updateData['update_type'] === 'bot_added') {
        $chatId = $updateData['chat_id'] ?? null;
        $api->sendAction($chatId, SenderAction::MarkSeen);

        if ($chatId !== null) {
            $chats = new Chats();
            $error_logs = new ErrorLogs();

            try {
                $newChatData = $api->getChat($chatId);
                $chats->create($chatId, $newChatData->title);
                $chats->setActual($chatId, true);
            } catch (\Exception $bot_added_exception) {
                $id_error = $error_logs->create(null, 'bot_added_new_chat', "chatId = " . $chatId . " | " . $bot_added_exception->getMessage());

                foreach ($MODERATOR_CHATS as $chatIdModerator) {
                    $api->sendMessage(
                        chatId: $chatIdModerator,
                        text: "При добавлении нового чата в БД произошла ошибка **#" . $id_error . "**. Обратитесь к администратору",
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