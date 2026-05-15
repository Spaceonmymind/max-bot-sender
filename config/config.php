<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

return [
    'api_base_url' => $_ENV['API_BASE_URL'],

    'api_token' => $_ENV['API_TOKEN'],
    'bot_id' => $_ENV['BOT_ID'],
    'bot_username' => $_ENV['BOT_USERNAME'],

    'admins_users' => array_filter(
        array_map('trim', explode(',', $_ENV['ADMINS_USERS']))
    ),

    'moderators_users' => array_filter(
        array_map('trim', explode(',', $_ENV['MODERATORS_USERS']))
    ),

    'moderators_chats' => array_filter(
        array_map('trim', explode(',', $_ENV['MODERATOR_CHATS']))
    ),

    'secret_webhook' => $_ENV['SECRET_WEBHOOK'],

    'db' => [
        'host' => $_ENV['DB_HOST'],
        'user' => $_ENV['DB_USER'],
        'password' => $_ENV['DB_PASSWORD'],
        'dbname' => $_ENV['DB_NAME'],
    ],
];