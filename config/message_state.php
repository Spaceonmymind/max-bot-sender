<?php
require_once 'database.php';

class MessageState {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Получить последний post_id для указанного mid
     */
    public function getLastPostId(string $mid): ?int {
        $stmt = $this->pdo->prepare("SELECT last_post_id FROM message_state WHERE mid = ?");
        $stmt->execute([$mid]);
        $result = $stmt->fetch();
        return $result ? (int)$result['last_post_id'] : null;
    }

    /**
     * Обновить или вставить запись о последнем post_id для mid
     */
    public function setLastPostId(string $mid, int $postId): bool {
        // Используем INSERT ... ON DUPLICATE KEY UPDATE
        $sql = "INSERT INTO message_state (mid, last_post_id) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE last_post_id = VALUES(last_post_id)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$mid, $postId]);
    }

    /**
     * Получить все записи (mid => last_post_id)
     */
    public function getAll(): array {
        $stmt = $this->pdo->query("SELECT mid, last_post_id FROM message_state");
        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['mid']] = (int)$row['last_post_id'];
        }
        return $result;
    }

    /**
     * Удалить запись для mid
     */
    public function delete(string $mid): bool {
        $stmt = $this->pdo->prepare("DELETE FROM message_state WHERE mid = ?");
        return $stmt->execute([$mid]);
    }
}