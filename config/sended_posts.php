<?php
require_once 'database.php';

class SendedPosts {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Добавить запись об отправленной копии
     *
     * @param string $midOriginal  Оригинальный mid сообщения
     * @param string $midSended    mid созданной копии
     * @param int    $targetChatId ID целевого чата
     * @return bool
     */
    public function add(string $midOriginal, string $midSended, int $targetChatId): bool {
        $sql = "INSERT INTO sended_posts (mid_sended, mid_original, target_chat_id, created_on, updated_on)
                VALUES (?, ?, ?, NOW(), NOW())";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$midSended, $midOriginal, $targetChatId]);
    }

    /**
     * Найти все копии по оригинальному mid
     *
     * @param string $midOriginal
     * @return array Массив записей с ключами 'mid_sended' и 'target_chat_id'
     */
    public function findByOriginal(string $midOriginal): array {
        $stmt = $this->pdo->prepare("SELECT mid_sended, target_chat_id FROM sended_posts WHERE mid_original = ?");
        $stmt->execute([$midOriginal]);
        return $stmt->fetchAll();
    }

    public function findByOriginalAndChat(string $midOriginal, int $targetChatId): array {
        $stmt = $this->pdo->prepare("SELECT mid_sended FROM sended_posts WHERE mid_original = ? AND target_chat_id = ?");
        $stmt->execute([$midOriginal, $targetChatId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    /**
     * Получить запись по mid_sended
     *
     * @param string $midSended
     * @return array|null Ассоциативный массив или null, если не найдено
     */
    public function getByMidSended(string $midSended): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM sended_posts WHERE mid_sended = ?");
        $stmt->execute([$midSended]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Удалить запись
     */
    public function delete(string $midSended): bool {
        $stmt = $this->pdo->prepare("DELETE FROM sended_posts WHERE mid_sended = ?");
        return $stmt->execute([$midSended]);
    }

    /**
     * Обновить время
     */
    public function updateTime(string $midSended): bool {
        $stmt = $this->pdo->prepare("UPDATE sended_posts SET updated_on = NOW() WHERE mid_sended = ?");
        return $stmt->execute([$midSended]);
    }

    public function getAll(): array {
        $stmt = $this->pdo->query("SELECT * FROM sended_posts ORDER BY created_on DESC");
        return $stmt->fetchAll();
    }
}