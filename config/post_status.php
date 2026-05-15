<?php
// post_status.php

require_once 'database.php';

class PostStatus {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Инициализировать или обновить запись для post_id
     * @param int $postId
     * @param array $data - содержит chats_all, chats_bot_added, create_count, edit_count, remove_count, status
     */
    public function initOrUpdate(int $postId, array $data): void {
        $sql = "INSERT INTO post_status 
                (post_id, chats_all, chats_bot_added, create_count, edit_count, remove_count, pending, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                chats_all = VALUES(chats_all),
                chats_bot_added = VALUES(chats_bot_added),
                create_count = create_count + VALUES(create_count),
                edit_count = edit_count + VALUES(edit_count),
                remove_count = remove_count + VALUES(remove_count),
                pending = pending + VALUES(pending),
                status = VALUES(status)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $postId,
            $data['chats_all'],
            $data['chats_bot_added'],
            $data['create'] ?? 0,
            $data['edit'] ?? 0,
            $data['remove'] ?? 0,
            ($data['create'] ?? 0) + ($data['edit'] ?? 0) + ($data['remove'] ?? 0),
            $data['status'] ?? ''
        ]);
    }

    /**
     * Уменьшить счётчики после выполнения одной задачи
     * @param int $postId
     * @param string $action 'create'|'edit'|'remove'
     * @param bool $success
     */
    public function decrement(int $postId, string $action, bool $success): void {
        $this->pdo->beginTransaction();
        try {
            // Блокируем строку для атомарности
            $stmt = $this->pdo->prepare("SELECT pending, create_count, edit_count, remove_count FROM post_status WHERE post_id = ? FOR UPDATE");
            $stmt->execute([$postId]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$current) {
                // Если записи нет, создаём с нулями (страховка)
                $this->initOrUpdate($postId, [
                    'chats_all' => 0,
                    'chats_bot_added' => 0,
                    'create' => 0,
                    'edit' => 0,
                    'remove' => 0,
                    'status' => ''
                ]);
                $current = ['pending' => 0, 'create_count' => 0, 'edit_count' => 0, 'remove_count' => 0];
            }

            // Вычисляем новые значения (не ниже нуля)
            $pending = max(0, $current['pending'] - 1);
            $create = max(0, $current['create_count'] - ($action === 'create' ? 1 : 0));
            $edit   = max(0, $current['edit_count'] - ($action === 'edit' ? 1 : 0));
            $remove = max(0, $current['remove_count'] - ($action === 'remove' ? 1 : 0));
            $successField = $success ? 1 : 0;
            $errorField   = $success ? 0 : 1;

            $update = $this->pdo->prepare("
                UPDATE post_status
                SET pending = :pending,
                    create_count = :create,
                    edit_count = :edit,
                    remove_count = :remove,
                    success = success + :success,
                    error = error + :error
                WHERE post_id = :post_id
            ");
            $update->execute([
                ':pending' => $pending,
                ':create' => $create,
                ':edit' => $edit,
                ':remove' => $remove,
                ':success' => $successField,
                ':error' => $errorField,
                ':post_id' => $postId
            ]);

            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Получить текущую статистику для post_id
     */
    public function getStats(int $postId): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM post_status WHERE post_id = ?");
        $stmt->execute([$postId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        // Преобразуем в нужный формат
        return [
            'status'           => $row['status'],
            'chats_all'        => $row['chats_all'],
            'chats_bot_added'  => $row['chats_bot_added'],
            'success'          => $row['success'],
            'pending'          => $row['pending'],
            'error'            => $row['error'],
            'create'           => $row['create_count'],
            'edit'             => $row['edit_count'],
            'remove'           => $row['remove_count'],
        ];
    }

    /**
     * Обновить только текстовый статус (без изменения счётчиков)
     */
    public function updateStatus(int $postId, string $status): void {
        $stmt = $this->pdo->prepare("UPDATE post_status SET status = ? WHERE post_id = ?");
        $stmt->execute([$status, $postId]);
    }

    /**
     * Проверить, есть ли ещё невыполненные задачи для данного post_id
     */
    public function hasPending(int $postId): bool {
        $stmt = $this->pdo->prepare("SELECT pending FROM post_status WHERE post_id = ?");
        $stmt->execute([$postId]);
        $pending = $stmt->fetchColumn();
        return $pending > 0;
    }
}