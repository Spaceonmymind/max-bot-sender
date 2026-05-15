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
                text: "Я электронный помощник Государственной жилищной инспекции Курганской области для информирования пользователей чатов многоквартирных домов.\n\n"
                    . "Этот бот предназначен для размещения официальных информационных сообщений в домовых чатах МАХ.\n\n"
                    . "Если вы отправите сообщение в этот диалог, я покажу ваш ID.",
            );
        }
    }

    // Возвращаем 200 OK
    http_response_code(200);
    echo 'OK';
};