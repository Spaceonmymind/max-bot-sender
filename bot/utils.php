<?php
/**
 * Собирает текст для ответа в чат модераторов
 */
function moderator_response(array $data) {
    $success = $data['success'] ?? 0;
    $error   = $data['error'] ?? 0;
    $action  = $data['action'] ?? '';

    $finalText = ($error ?? 0) === 0
        ? "✅ Все задачи выполнены успешно\n\n"
        : "⚠️ Выполнено с ошибками\nОшибок: **{$error}**\n\n";

    switch ($action) {
        case 'create':
            $response = $finalText . "Количество чатов, куда сообщение **ОТПРАВЛЕНО**: **{$success}**.\n\nВы можете изменить или удалить (обязательно выбрать вариант **УДАЛИТЬ ДЛЯ ВСЕХ**) свое сообщение и это действие автоматически воспроизведется во всех групповых чатах, куда исходное сообщение было отправлено.";
            break;

        case 'edit':
            $response = $finalText . "Количество чатов, где сообщение **ИЗМЕНЕНО**: **{$success}**.\n\nВы можете изменить или удалить (обязательно выбрать вариант **УДАЛИТЬ ДЛЯ ВСЕХ**) свое сообщение и это действие автоматически воспроизведется во всех групповых чатах, куда исходное сообщение было отправлено.";
            break;

        case 'remove':
            $response = $finalText . "Количество чатов, где сообщение **УДАЛЕНО**: **{$success}**.";
            break;

        default:
            $response = 'ERROR';
    };

    $info = "\n\nДля помощи в форматировании текста отправьте в этот чат:\n**/help**";
    if ($response != 'ERROR') {
        $response = $response . $info;
    };

    return $response;
};

/**
 * Собирает текст для статуса задачи
 */
function message_status(array $data) {
    $status = $data['status'] ?? 'Неизвестный статус';
    $chats_all = $data['chats_all'] ?? 0;
    $chats_bot_added = $data['chats_bot_added'] ?? 0;
    $success = $data['success'] ?? 0;
    $pending = $data['pending'] ?? 0;
    $error = $data['error'] ?? 0;
    $create = $data['create'] ?? 0;
    $edit = $data['edit'] ?? 0;
    $remove = $data['remove'] ?? 0;

    $response_status = "Это сообщение будет обновляться данными о процессе обработки. По завершению вы будете проинформированы о статусе её обработки\n\n**Статус**\n"
        . $status . "\n\n**Чаты**\n- Всего: **"
        . $chats_all . "**\n- Чат-бот добавлен: **"
        . $chats_bot_added . "**\n\n**Сообщения**\n- Завершено: **"
        . $success . "**\n- Ожидают: **"
        . $pending . "**\n- Ошибки: **"
        . $error . "**\n\n**Очередь**\n- Создание: **"
        . $create . "**\n- Изменение: **"
        . $edit . "**\n- Удаление: **"
        . $remove . "**";

    return $response_status;
};

/**
 * Возвращает список чатов, куда добавлен бот (исключает из списка чаты модераторов)
 */
function get_all_chats($api) {
    global $MODERATOR_CHATS;
    $chats = [];
    $marker = null;

    do {
        $chats_response = $api->getChats(count: 100, marker: $marker);

        foreach ($chats_response->chats as $chat) {
            if (!in_array($chat->chatId, $MODERATOR_CHATS) && ($chat->status->value === 'active')) {
                $chats[] = $chat->chatId;
            };
        };

        $marker = $chats_response->marker;
    } while ($marker !== null);
    return $chats;
};

/**
 * Возвращает список чатов, куда добавлен бот (исключает из списка чаты модераторов) из файла CSV
 */
function get_all_chats_from_csv($file_path) {
    global $MODERATOR_CHATS;
    $chats = [];
    $data = [];

    if (file_exists($file_path) && is_readable($file_path)) {
        if (($handle = fopen($file_path, 'r')) !== false) {
            $headers = fgetcsv($handle, 0, "\t");
            $data['headers'] = $headers;

            while (($row = fgetcsv($handle, 0, "\t")) !== false) {
                if ($headers) {
                    $data['rows'][] = array_combine($headers, $row);
                } else {
                    $data['rows'][] = $row;
                }
            }
            fclose($handle);
        }
    };

    foreach ($data['rows'] as $chat) {
        if (!in_array($chat['Идентификатор чата'], $MODERATOR_CHATS) && ($chat['Идентификатор чата'] !== null)) {
            $chats[] = $chat['Идентификатор чата'];
        };
    };

    return $chats;
};

// todo: DELETE test function
function get_all_chats_from_csv_TEST($count_resend=7220) {
    $test_chats = [
        '-71464674170259',
        '-71465899431315',
        '-71466392524179',
        '-71466480604563',
        '-71466486371731',
        '-71466489910675',
        '-71466493973907',
        '-71466497840531',
        '-71466502165907',
        '-71466506425747',
//            '-123456789',  // todo: нерабочий
        '-71693517755795',
        '-71693523654035',
        '-71693527913875',
        '-71693534336403',
        '-71693540431251',
        '-71693544428947',
        '-71693549802899',
        '-71693554193811',
        '-71693559698835',
        '-71693565203859',
    ];
    $chats = [];

    $id_chats_counter = 0;

    for ($i = 0; $i < $count_resend; $i++) {
        $chats[] = $test_chats[$id_chats_counter];
        $id_chats_counter++;
        if ($id_chats_counter == count($test_chats)) {
            $id_chats_counter = 0;
        };
    };

    return $chats;
};