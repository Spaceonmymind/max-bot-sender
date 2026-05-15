<?php
/**
 * Обработка POST запроса для bot_started
 */
use BushlanovDev\MaxMessengerBot\Enums\SenderAction;

function handleBotStarted($api) {
    $input = file_get_contents('php://input');
    $updateData = json_decode($input, true);

    if ($updateData === null) {
        http_response_code(400);
        echo 'Неверный JSON';
        exit;
    }

    // Проверяем тип события
    if (isset($updateData['update_type']) && $updateData['update_type'] === 'bot_started') {
        $chatId = $updateData['chat_id'] ?? null;

        if ($chatId !== null) {
            $api->sendAction($chatId, SenderAction::MarkSeen);
            $api->sendAction($chatId, SenderAction::TypingOn);
            $api->sendMessage(
                chatId: $chatId,
                text: "Здравствуйте! Данный бот работает только в групповых чатах.",
            );
        }
    }

    // Возвращаем 200 OK
    http_response_code(200);
    echo 'OK';
};