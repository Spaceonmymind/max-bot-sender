<?php
// chats.php

require_once 'database.php';

class Chats
{
    private PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Создать запись о чате (если её ещё нет)
     * @param int $chatId
     * @param ?string $title
     * @return bool успешно ли создана (или уже существовала)
     */
    public function create(int $chatId, ?string $title = null): bool
    {
        // Проверяем, есть ли уже такой чат
        $stmt = $this->pdo->prepare("SELECT id FROM chats WHERE chat_id = ?");
        $stmt->execute([$chatId]);
        if ($stmt->fetch()) {
            // Если запись уже есть, можно обновить название (если передано)
            if ($title !== null) {
                $update = $this->pdo->prepare("UPDATE chats SET title = ? WHERE chat_id = ?");
                $update->execute([$title, $chatId]);
            }
            return false; // уже существует
        }

        $sql = "INSERT INTO chats (chat_id, title, is_actual, created_at) VALUES (?, ?,  0, NOW())";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$chatId, $title]);
    }

    /**
     * Изменить состояние актуальности чата
     * @param int $chatId
     * @param bool $isActual
     * @return bool
     */
    public function setActual(int $chatId, bool $isActual): bool
    {
        $sql = "UPDATE chats SET is_actual = ?, last_checked_at = NOW() WHERE chat_id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$isActual ? 1 : 0, $chatId]);
    }

    /**
     * Получить массив идентификаторов актуальных чатов
     * @return array
     */
    public function getActual(): array
    {
        $stmt = $this->pdo->query("SELECT chat_id FROM chats WHERE is_actual = 1");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Получить массив идентификаторов неактуальных чатов
     * @return array
     */
    public function getNotActual(): array
    {
        $stmt = $this->pdo->query("SELECT chat_id FROM chats WHERE is_actual = 0");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function countAll(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM chats");
        return (int) $stmt->fetchColumn();
    }

    public function countActual(): int {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM chats WHERE is_actual = 1");
        return (int) $stmt->fetchColumn();
    }

    public function getAllChatIds(): array {
        $stmt = $this->pdo->query("SELECT chat_id FROM chats");
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    public function deleteByChatId(int $chatId): void {
        $stmt = $this->pdo->prepare("DELETE FROM chats WHERE chat_id = ?");
        $stmt->execute([$chatId]);
    }

    /**
     * Получить название чата по его chat_id
     *
     * @param int $chatId
     * @return string|null
     */
    public function getTitleById(int $chatId): ?string {
        $stmt = $this->pdo->prepare("SELECT title FROM chats WHERE chat_id = ?");
        $stmt->execute([$chatId]);
        $title = $stmt->fetchColumn();
        return $title ?: '-';
    }
}