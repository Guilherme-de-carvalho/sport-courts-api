<?php
declare(strict_types=1);

// Script CLI para remover reservas cuja data de início já passou há mais de 5 dias.
// Uso: php scripts/cleanup_reservations.php

require __DIR__ . '/../vendor/autoload.php';

use App\\Database;

// config
$logFile = __DIR__ . '/../logs/cleanup_reservations.log';

try {
    $db = new Database();
    $pdo = $db->getConnection();

    $sql = "DELETE FROM reservations WHERE start_datetime < DATE_SUB(NOW(), INTERVAL 5 DAY)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $deleted = $stmt->rowCount();

    $msg = sprintf("[%s] Deleted %d old reservations\n", date('c'), $deleted);
    echo $msg;
    // ensure logs dir exists
    @mkdir(dirname($logFile), 0755, true);
    @file_put_contents($logFile, $msg, FILE_APPEND | LOCK_EX);
    exit(0);
} catch (Throwable $e) {
    $msg = sprintf("[%s] Cleanup failed: %s\n", date('c'), $e->getMessage());
    echo $msg;
    @mkdir(dirname($logFile), 0755, true);
    @file_put_contents($logFile, $msg, FILE_APPEND | LOCK_EX);
    exit(1);
}
