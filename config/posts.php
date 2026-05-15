<?php
require_once 'database.php';

class Posts {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Создаёт запись из данных вебхука и возвращает ID новой записи
     */
    public function createFromWebhook(array $data): int {
        // Сериализуем и кодируем message_data (может содержать объекты)
        $serialized = base64_encode(serialize($data['message_data']));

        $sql = "INSERT INTO posts 
                (mid, chat_id, user_id, first_name, last_name, username, message_data, action, status, created_on, updated_on)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $data['mid'],
            $data['chat_id'],
            $data['user_id'],
            $data['first_name'] ?? '-',
            $data['last_name'] ?? '-',
            $data['username'] ?? '-',
            $serialized,
            $data['action']
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Получить запись по её ID (с десериализацией message_data)
     */
    public function getById(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM posts WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['message_data'] = unserialize(base64_decode($row['message_data']));
        }
        return $row ?: null;
    }

    /**
     * Захватить записи со статусом pending для обработки (блокировка)
     * Возвращает массив записей с полями id, mid, mid_status, action
     */
    public function lockPending(int $limit): array {
        $this->pdo->beginTransaction();

        $stmt = $this->pdo->prepare("
            SELECT id, mid, mid_status, action
            FROM posts
            WHERE status = 'pending'
            ORDER BY created_on ASC
            LIMIT :limit
            FOR UPDATE SKIP LOCKED
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $posts = $stmt->fetchAll();

        if (empty($posts)) {
            $this->pdo->rollBack();
            return [];
        }

        $ids = array_column($posts, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $updateStmt = $this->pdo->prepare("
            UPDATE posts SET status = 'processing' WHERE id IN ($placeholders)
        ");
        $updateStmt->execute($ids);

        $this->pdo->commit();
        return $posts;
    }

    /**
     * Пометить запись с ошибкой (по id)
     */
    public function markError(int $id): void {
        $stmt = $this->pdo->prepare("UPDATE posts SET status = 'error' WHERE id = ?");
        $stmt->execute([$id]);
    }

    /**
     * Пометить запись как выполненную (по id)
     */
    public function markDone(int $id): void {
        $stmt = $this->pdo->prepare("UPDATE posts SET status = 'done' WHERE id = ?");
        $stmt->execute([$id]);
    }

    /**
     * Вернуть в очередь зависшие записи (processing > N минут)
     */
    public function releaseStale(int $minutes = 10): int {
        $stmt = $this->pdo->prepare("
            UPDATE posts
            SET status = 'pending'
            WHERE status = 'processing' AND updated_on < NOW() - INTERVAL :minutes MINUTE
        ");
        $stmt->bindValue(':minutes', $minutes, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Удалить запись по id
     */
    public function delete(int $id): bool {
        $stmt = $this->pdo->prepare("DELETE FROM posts WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Обновить mid статусного сообщения для записи
     */
    public function updateStatusMid(int $id, string $midStatus): void {
        $stmt = $this->pdo->prepare("UPDATE posts SET mid_status = ? WHERE id = ?");
        $stmt->execute([$midStatus, $id]);
    }

    /**
     * Получить mid статусного сообщения по ID записи
     */
    public function getStatusMid(int $id): ?string {
        $stmt = $this->pdo->prepare("SELECT mid_status FROM posts WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetchColumn();
        return $result ?: null;
    }
}