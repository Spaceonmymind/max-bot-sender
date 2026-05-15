<?php
/**
 * Обработка POST запроса для message_created
 */
require_once __DIR__ . '/../config/error_logs.php';
require_once __DIR__ . '/../config/message_state.php';
require_once __DIR__ . '/../config/posts.php';
require_once 'utils.php';

use BushlanovDev\MaxMessengerBot\Enums\MessageFormat;
use BushlanovDev\MaxMessengerBot\Enums\MessageLinkType;
use BushlanovDev\MaxMessengerBot\Enums\SenderAction;
use BushlanovDev\MaxMessengerBot\Models\MessageLink;

function handleMessageCreated($api)
{
    global $ADMINS_USERS, $BOT_ID, $MODERATOR_CHATS, $MODERATORS_USERS;
    $input = file_get_contents('php://input');
    $updateData = json_decode($input, true);

    if ($updateData === null) {
        http_response_code(400);
        echo 'Неверный JSON';
        exit;
    }

    if (isset($updateData['update_type']) && $updateData['update_type'] === 'message_created') {
        $message = $updateData['message'];
        $chatId = $message['recipient']['chat_id'];
        $api->sendAction($chatId, SenderAction::MarkSeen);

        // Не отвечать на сообщения пользователя в личку
        if ($message['recipient']['chat_type'] === 'dialog') {
            return;
        }

        $userId = $message['sender']['user_id'];
        $original_mid = $message['body']['mid'];

        if (
            in_array($chatId, $MODERATOR_CHATS)
            && ($userId !== $BOT_ID)
        ) {
            if (!(in_array($userId, $ADMINS_USERS) or in_array($userId, $MODERATORS_USERS))) {
                // Пользователь в чате модераторов, но его нет в списках - написать его id, чтобы его могли добавить
                $api->sendMessage(
                    chatId: $chatId,
                    text: "Ваш ID: **" . $userId . "**\n\n Обратитесь к администратору, чтобы Вас добавили в список модераторов.",
                    format: MessageFormat::Markdown,
                    link: new MessageLink(MessageLinkType::Reply, $original_mid),
                );
                return;
            };

            // Справочная информация по форматированию текста
            if (($message['body']['text'] === '/help') || ($message['body']['text'] === 'help')) {

                $info = "\n1) обычный текст\n2) *курсив* или _курсив_\n3) **жирный** или __жирный__\n4) ~~зачёркнутый~~\n5) ++подчёркнутый++\n6) `моноширинный`\n7) [Текст ссылки](https://dev.max.ru/)";

                // Пример символов для форматирования текста
                $api->sendMessage(
                    chatId: $chatId,
                    text: "Для форматирования текста используйте символы вокруг текста:" . $info,
                    disableLinkPreview: true,
                );

                // Пример с форматированием
                $api->sendMessage(
                    chatId: $chatId,
                    text: "Пример отформатированного текста: " . $info,
                    format: MessageFormat::Markdown,
                );
                return;
            };

            $posts = new Posts();
            $message_state  = new MessageState();
            $error_logs = new ErrorLogs();

            try {
                $repost_data_message = $api->getMessageById($original_mid);
                $message_json_data = [
                    "text" => $repost_data_message->body->text,
                    "attachments" => $repost_data_message->body->attachments ?? [],
                ];

                $postId = $posts->createFromWebhook([
                    'mid' => $original_mid,
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                    'first_name' => $message['sender']['first_name'] ?? '-',
                    'last_name' => $message['sender']['last_name'] ?? '-',
                    'username' => $message['sender']['username'] ?? '-',
                    'message_data' => $message_json_data,
                    'action' => 'create'
                ]);

                $message_state->setLastPostId($original_mid, $postId);

                try {
                    $status_data = [
                        'status' => "Задача для создания постов добавлена в очередь",
                        'chats_all' => 'Ожидает обработки',
                        'chats_bot_added' => 'Ожидает обработки',
                        'success' => 'Ожидает обработки',
                        'pending' => 'Ожидает обработки',
                        'error' => 'Ожидает обработки',
                        'create' => 'Ожидает обработки',
                        'edit' => 'Ожидает обработки',
                        'remove' => 'Ожидает обработки',
                    ];
                    $statusMessage = $api->sendMessage(
                        chatId: $chatId,
                        text: message_status($status_data),
                        format: MessageFormat::Markdown,
                        link: new MessageLink(MessageLinkType::Reply, $original_mid)
                    );
                    $statusMid = $statusMessage->body->mid ?? null;
                    if ($statusMid) {
                        $posts->updateStatusMid($postId, $statusMid);
                    }
                } catch (\Exception $send_status_exception) {
                    $id_error = $error_logs->create($original_mid, 'status_message', $send_status_exception->getMessage());
                    $api->sendMessage(
                        chatId: $chatId,
                        text: "При обработке произошла ошибка **#" . $id_error . "**. Обратитесь к администратору",
                        format: MessageFormat::Markdown,
                        link: new MessageLink(MessageLinkType::Reply, $original_mid),
                    );
                }
            }
            catch (\Exception $create_exception) {
                $id_error = $error_logs->create($original_mid, 'create', $create_exception->getMessage());
                $api->sendMessage(
                    chatId: $chatId,
                    text: "При обработке произошла ошибка **#" . $id_error . "**. Обратитесь к администратору",
                    format: MessageFormat::Markdown,
                    link: new MessageLink(MessageLinkType::Reply, $original_mid),
                );
            };
        };
    };

    // Возвращаем 200 OK
    http_response_code(200);
    echo 'OK';
};