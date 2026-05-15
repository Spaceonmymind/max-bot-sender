# Бот для рассылки постов в групповые чаты мессенджера Max.

## Функционал

Берет сообщение от модератора или админа в чатах модераторов и отправляет по всем групповым 
чатам, куда бот добавлен в участники чата.

При изменении или удалении сообщения в чате модераторов дублирует это действие на все репосты 
этого сообщения в групповых чатах.


## Вебхуки

## Создание вебхуков

Отправить http **POST-запрос** на ```https://platform-api.max.ru/subscriptions```

Указать в Headers ```Authorization: ~~token бота~~```

Указать в Body JSON:
```
{
    "url": "ссылка куда Max будет посылать данные вебхука",
    "update_types": ["update_type"],
    "secret": "Секрет от 5 до 256 символов. Разрешены только символы A-Z, a-z, 0-9, и дефис."
}
```

### Добавление бота в групповой чат

Update_type - ```bot_added``` 

Url - ```~/bot/bot_added.php```

Запишет в БД в таблицу chats название и id чата для дальнейших рассылок.

### Удаление бота из группового чата

Update_type - ```bot_removed```

Url - ```~/bot/bot_removed.php```

Удалит из БД в таблице chats. Сообщит в чат модераторов об удалении бота из данного чата.

### Запуск бота пользователем

Update_type - ```bot_started```

Url - ```~/bot/bot_started.php```

Бот отправит пользователю сообщение, что работает только в групповых чатах и будет игнорировать любые дальнейшие 
действия пользователя.

### Получение нового сообщения

Update_type - ```message_created```

Url - ```~/bot/message_created.php```

Если это сообщение `/help` (или `help` на случай опечатки пользователя) - отправит информацию с подсказками как 
форматировать текст в сообщениях.

Если это чат модераторов, но пользователь не в списке модераторов, то напишет его id, чтобы его могли добавить. 

Иначе отправляет сообщение пользователя во все групповые чаты, куда бот добавлен в участники. Записывает в БД 
идентификаторы исходного и пересланных сообщений для дальнейшей возможности изменить или удалить сообщение.

### Изменение сообщения в чате

Update_type - ```message_edited```

Url - ```~/bot/message_edited.php```

При изменении сообщения берет по связи идентификаторы в БД куда отправлял копии и дублирует изменения во всех 
пересланных копиях.

### Удаление сообщения в чате

Update_type - ```message_removed```

Url - ```~/bot/message_removed.php```

При удалении сообщения берет по связи идентификаторы в БД куда отправлял копии и удаляет все пересланные копии. 

## База данных

### Таблица posts

```
CREATE TABLE posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mid VARCHAR(200) NOT NULL,
    chat_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    first_name VARCHAR(200),
    last_name VARCHAR(200),
    username VARCHAR(200),
    message_data TEXT,
    action ENUM('create','edit','remove') NOT NULL,
    status ENUM('pending','processing','done','error') DEFAULT 'pending',
    mid_status VARCHAR(200) NULL,
    created_on TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_on TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (status)
);
```

### Таблица sended_posts

```
CREATE TABLE sended_posts (
    mid_sended VARCHAR(200) PRIMARY KEY,
    mid_original VARCHAR(200) NOT NULL,
    target_chat_id BIGINT NOT NULL,
    created_on TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_on TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (mid_original)
);
```

### Таблица jobs

```
CREATE TABLE jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NOT NULL,           -- ссылка на posts.id
    mid_original VARCHAR(200) NOT NULL,
    target_chat_id BIGINT NOT NULL,
    action ENUM('create','edit','remove') NOT NULL,
    mid_sended VARCHAR(200) NULL,              -- для edit/remove, если уже есть копия
    status ENUM('pending','processing','done','error','cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    finished_at TIMESTAMP NULL,
    response TEXT NULL,
    error TEXT NULL,
    INDEX (group_id),
    INDEX (mid_original, status),
    INDEX (status, created_at)
);
```

### Таблица message_state

```
CREATE TABLE message_state (
    mid VARCHAR(200) PRIMARY KEY,
    last_post_id INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### Таблица error_logs

```
CREATE TABLE error_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mid VARCHAR(200) NULL,
    action VARCHAR(500) NOT NULL,
    error_data TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Таблица post_status

```
CREATE TABLE post_status (
    post_id INT UNSIGNED PRIMARY KEY,
    chats_all INT UNSIGNED NOT NULL DEFAULT 0,
    chats_bot_added INT UNSIGNED NOT NULL DEFAULT 0,
    success INT UNSIGNED NOT NULL DEFAULT 0,
    pending INT UNSIGNED NOT NULL DEFAULT 0,
    error INT UNSIGNED NOT NULL DEFAULT 0,
    create_count INT UNSIGNED NOT NULL DEFAULT 0,
    edit_count INT UNSIGNED NOT NULL DEFAULT 0,
    remove_count INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(500) NOT NULL DEFAULT '',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### Таблица chats

```
CREATE TABLE chats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NOT NULL UNIQUE,
    title VARCHAR(500) NULL,
    is_actual TINYINT(1) DEFAULT 0,
    last_checked_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

## Задания в crontab
```
0 0 * * * php8.3 /home/users/j/j88612214/domains/announcemax.brevis.pro/chats_checker.php > /dev/null
* * * * * php8.3 /home/users/j/j88612214/domains/announcemax.brevis.pro/dispatcher.php > /dev/null
* * * * * php8.3 /home/users/j/j88612214/domains/announcemax.brevis.pro/executor.php > /dev/null
* * * * * php8.3 /home/users/j/j88612214/domains/announcemax.brevis.pro/notificator.php > /dev/null
```

### chats_checker.php

Запуск каждый день в 00:00

Проверяет список chat_id из файла CSV и в БД на доступность чата ботом и ставит флаг is_actual.
Отправка/изменение/удаление репостов будет делаться в чаты с is_actual=true

### dispatcher.php

Запуск каждую минуту

Проверяет в БД таблицу пост на наличие новых сообщений (или удаление сообщения) от модераторов. 
Если есть новое действие, то создает задачи в таблицу jobs для всех чатов, где ```chats.is_actual == true```:
1) create (рассылка новых сообщений в групповые чаты)
2) edit (изменение уже отправленного сообщения в групповых чатах)
3) remove (удаление сообщения из групповых чатов)

### executor.php

Запуск каждую минуту

1) Проверяет наличие задач в таблице jobs
2) Если есть задачи, которые висят больше 10 минут, то переводит их в статус ожидания выполнения
3) Выполняет порциями задачи на отправку/изменение/удаление сообщения в групповой чат

### notificator.php

Запуск каждую минуту

Проверяет не завершенные задачи и обновляет в чате модераторов статистику по отправке/изменению/удалению исходного 
сообщения в групповых чатах 
