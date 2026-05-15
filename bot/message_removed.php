<?php
/**
 * Обработка POST запроса для message_removed
 */
require_once __DIR__ . '/../config/error_logs.php';
require_once __DIR__ . '/../config/message_state.php';
require_once __DIR__ . '/../config/posts.php';
require_once 'utils.php';

use BushlanovDev\MaxMessengerBot\Enums\MessageFormat;
use BushlanovDev\MaxMessengerBot\Enums\MessageLinkType;
use BushlanovDev\MaxMessengerBot\Enums\SenderAction;
use BushlanovDev\MaxMessengerBot\Models\MessageLink;

function handleMessageRemoved($api) {
    global $ADMINS_USERS, $BOT_ID, $MODERATOR_CHATS, $MODERATORS_USERS;
    $input = file_get_contents('php://input');
    $updateData = json_decode($input, true);

    if ($updateData === null) {
        http_response_code(400);
        echo 'Неверный JSON';
        exit;
    }

    if (isset($updateData['update_type']) && $updateData['update_type'] === 'message_removed') {
        $original_mid = $updateData['message_id'];
        $chatId = $updateData['chat_id'];
        $userId = $updateData['user_id'];

        $api->sendAction($chatId, SenderAction::MarkSeen);

        if (
            in_array($chatId, $MODERATOR_CHATS)
            && ($userId !== $BOT_ID)
            && (in_array($userId, $ADMINS_USERS) or in_array($userId, $MODERATORS_USERS))
        ) {
            $posts = new Posts();
            $message_state  = new MessageState();
            $error_logs = new ErrorLogs();

            try {
                $postId = $posts->createFromWebhook([
                    'mid' => $original_mid,
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                    'first_name' => '-',
                    'last_name' => '-',
                    'username' => '-',
                    'message_data' => null,
                    'action' => 'remove'
                ]);

                $message_state->setLastPostId($original_mid, $postId);

                try {
                    $status_data = [
                        'status' => "Задача для удаления постов добавлена в очередь",
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
                $id_error = $error_logs->create($original_mid, 'remove', $create_exception->getMessage());
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
