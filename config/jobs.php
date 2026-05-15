<?php
// jobs.php

require_once 'database.php';

class Jobs {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Добавить новую задачу
     *
     * @param int    $groupId       ID версии сообщения из posts (posts.id)
     * @param string $midOriginal   Оригинальный mid
     * @param int    $targetChatId  ID целевого чата
     * @param string $action        Действие ('create', 'edit', 'remove')
     * @param string|null $midSended Для edit/remove mid уже существующей копии
     * @return int ID созданной задачи
     */
//    public function add(int $groupId, string $midOriginal, int $targetChatId, string $action, ?string $midSended = null): int {
//        // Сначала проверяем, есть ли уже такая задача в статусах pending/processing
//        $sqlCheck = "SELECT id FROM jobs
//                 WHERE group_id = ? AND mid_original = ? AND target_chat_id = ? AND action = ?
//                 AND ((? IS NULL AND mid_sended IS NULL) OR mid_sended = ?)
//                 AND status IN ('pending', 'processing')";
//        $stmtCheck = $this->pdo->prepare($sqlCheck);
//        $stmtCheck->execute([$groupId, $midOriginal, $targetChatId, $action, $midSended, $midSended]);
//        $existingId = $stmtCheck->fetchColumn();
//
//        if ($existingId) {
//            // Если уже есть активная задача, возвращаем её ID и не создаём новую
//            return (int)$existingId;
//        }
//
//        // Иначе вставляем новую
//        $sql = "INSERT INTO jobs (group_id, mid_original, target_chat_id, action, mid_sended, status, created_at)
//            VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
//        $stmt = $this->pdo->prepare($sql);
//        $stmt->execute([$groupId, $midOriginal, $targetChatId, $action, $midSended]);
//        return (int) $this->pdo->lastInsertId();
//    }
    //todo: OLD
    public function add(int $groupId, string $midOriginal, int $targetChatId, string $action, ?string $midSended = null): int {
        $sql = "INSERT INTO jobs (group_id, mid_original, target_chat_id, action, mid_sended, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$groupId, $midOriginal, $targetChatId, $action, $midSended]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Захватить задачи для выполнения (блокировка)
     *
     * @param int $limit Максимальное количество задач
     * @return array Массив задач с полями: id, group_id, mid_original, target_chat_id, action, mid_sended
     */
    public function lock(int $limit): array {
        $this->pdo->beginTransaction();

        $stmt = $this->pdo->prepare("
            SELECT j.id, j.group_id, j.mid_original, j.target_chat_id, j.action, j.mid_sended
            FROM jobs j
            INNER JOIN posts p ON p.id = j.group_id
            WHERE j.status = 'pending' AND p.status = 'done'
            ORDER BY j.created_at ASC
            LIMIT :limit
            FOR UPDATE SKIP LOCKED
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $jobs = $stmt->fetchAll();

        if (empty($jobs)) {
            $this->pdo->rollBack();
            return [];
        }

        $ids = array_column($jobs, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $updateStmt = $this->pdo->prepare("
            UPDATE jobs
            SET status = 'processing', started_at = NOW()
            WHERE id IN ($placeholders)
        ");
        $updateStmt->execute($ids);

        $this->pdo->commit();
        return $jobs;
    }

    /**
     * Завершить задачу с результатом
     *
     * @param int         $id        ID задачи
     * @param bool        $success   Успешно ли выполнена
     * @param string|null $response  Ответ API (обрежется до 65535 символов)
     * @param string|null $error     Текст ошибки
     */
    public function finish(int $id, bool $success, ?string $response = null, ?string $error = null): void {
        $status = $success ? 'done' : 'error';
        $stmt = $this->pdo->prepare("
            UPDATE jobs
            SET status = :status,
                finished_at = NOW(),
                response = :response,
                error = :error
            WHERE id = :id
        ");
        $stmt->execute([
            ':status'    => $status,
            ':response'  => $response ? mb_substr($response, 0, 65535) : null,
            ':error'     => $error,
            ':id'        => $id,
        ]);
    }

    /**
     * Отменить все задачи для определённого group_id (например, если появилась новая версия)
     *
     * @param int $groupId ID версии из posts
     * @return int Количество отменённых задач
     */
    public function cancelByGroup(int $groupId): int {
        $stmt = $this->pdo->prepare("
            UPDATE jobs
            SET status = 'cancelled', finished_at = NOW()
            WHERE group_id = ? AND status IN ('pending', 'processing')
        ");
        $stmt->execute([$groupId]);
        return $stmt->rowCount();
    }

    /**
     * Вернуть в очередь зависшие задачи (processing > N минут)
     *
     * @param int $minutes
     * @return int Количество возвращённых задач
     */
    public function releaseStale(int $minutes = 10): int {
        $stmt = $this->pdo->prepare("
            UPDATE jobs
            SET status = 'pending'
            WHERE status = 'processing' AND started_at < NOW() - INTERVAL :minutes MINUTE
        ");
        $stmt->execute([':minutes' => $minutes]);
        return $stmt->rowCount();
    }

    /**
     * Получить статистику по задачам
     *
     * @return array Ассоциативный массив вида ['pending' => 10, 'processing' => 2, 'done' => 50, 'error' => 3, 'cancelled' => 5]
     */
    public function getStats(): array {
        $stmt = $this->pdo->query("
            SELECT status, COUNT(*) as count
            FROM jobs
            GROUP BY status
        ");
        $stats = [];
        foreach ($stmt->fetchAll() as $row) {
            $stats[$row['status']] = (int) $row['count'];
        }
        $allStatuses = ['pending', 'processing', 'done', 'error', 'cancelled'];
        foreach ($allStatuses as $status) {
            if (!isset($stats[$status])) {
                $stats[$status] = 0;
            }
        }
        return $stats;
    }

    /**
     * Получить задачи по group_id (для отладки)
     *
     * @param int $groupId
     * @return array
     */
    public function getByGroup(int $groupId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM jobs WHERE group_id = ? ORDER BY created_at");
        $stmt->execute([$groupId]);
        return $stmt->fetchAll();
    }

    public function countPendingByGroup(int $groupId): int {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM jobs WHERE group_id = ? AND status IN ('pending', 'processing')");
        $stmt->execute([$groupId]);
        return (int) $stmt->fetchColumn();
    }
}