<?php
// error_logs.php

require_once 'database.php';

class ErrorLogs {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Сохранить ошибку в лог
     */
    public function create(string $mid, string $action, string $error): int {
        $sql = "INSERT INTO error_logs (mid, action, error_data) VALUES (?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$mid, $action, $error]);
        return (int) $this->pdo->lastInsertId();
    }
}