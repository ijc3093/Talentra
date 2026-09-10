<?php
declare(strict_types=1);

function ensure_private_video_call_tables(PDO $dbh): bool
{
    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS user_video_calls (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                call_mode VARCHAR(16) NOT NULL DEFAULT 'video',
                caller_user_id INT NOT NULL,
                caller_code VARCHAR(60) NOT NULL DEFAULT '',
                callee_user_id INT NOT NULL,
                callee_code VARCHAR(60) NOT NULL DEFAULT '',
                status VARCHAR(20) NOT NULL DEFAULT 'initiated',
                started_at DATETIME NULL DEFAULT NULL,
                ended_at DATETIME NULL DEFAULT NULL,
                ended_by_user_id INT NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_user_video_calls_pair (caller_user_id, callee_user_id, id),
                KEY idx_user_video_calls_callee (callee_user_id, status, id),
                KEY idx_user_video_calls_status (status, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $dbh->exec("
            CREATE TABLE IF NOT EXISTS user_video_call_signals (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                call_id BIGINT UNSIGNED NOT NULL,
                from_user_id INT NOT NULL,
                to_user_id INT NOT NULL,
                signal_type VARCHAR(20) NOT NULL,
                payload LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                consumed_at DATETIME NULL DEFAULT NULL,
                KEY idx_user_video_call_signals_target (to_user_id, call_id, id),
                KEY idx_user_video_call_signals_call (call_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $requiredCallColumns = [
            'call_mode' => "ALTER TABLE user_video_calls ADD COLUMN call_mode VARCHAR(16) NOT NULL DEFAULT 'video' AFTER id",
            'caller_user_id' => "ALTER TABLE user_video_calls ADD COLUMN caller_user_id INT NOT NULL DEFAULT 0 AFTER call_mode",
            'caller_code' => "ALTER TABLE user_video_calls ADD COLUMN caller_code VARCHAR(60) NOT NULL DEFAULT '' AFTER caller_user_id",
            'callee_user_id' => "ALTER TABLE user_video_calls ADD COLUMN callee_user_id INT NOT NULL DEFAULT 0 AFTER caller_code",
            'callee_code' => "ALTER TABLE user_video_calls ADD COLUMN callee_code VARCHAR(60) NOT NULL DEFAULT '' AFTER callee_user_id",
            'status' => "ALTER TABLE user_video_calls ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'initiated' AFTER callee_code",
            'started_at' => "ALTER TABLE user_video_calls ADD COLUMN started_at DATETIME NULL DEFAULT NULL AFTER status",
            'ended_at' => "ALTER TABLE user_video_calls ADD COLUMN ended_at DATETIME NULL DEFAULT NULL AFTER started_at",
            'ended_by_user_id' => "ALTER TABLE user_video_calls ADD COLUMN ended_by_user_id INT NULL DEFAULT NULL AFTER ended_at",
            'created_at' => "ALTER TABLE user_video_calls ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER ended_by_user_id",
            'updated_at' => "ALTER TABLE user_video_calls ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at",
        ];

        $requiredSignalColumns = [
            'call_id' => "ALTER TABLE user_video_call_signals ADD COLUMN call_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id",
            'from_user_id' => "ALTER TABLE user_video_call_signals ADD COLUMN from_user_id INT NOT NULL DEFAULT 0 AFTER call_id",
            'to_user_id' => "ALTER TABLE user_video_call_signals ADD COLUMN to_user_id INT NOT NULL DEFAULT 0 AFTER from_user_id",
            'signal_type' => "ALTER TABLE user_video_call_signals ADD COLUMN signal_type VARCHAR(20) NOT NULL DEFAULT '' AFTER to_user_id",
            'payload' => "ALTER TABLE user_video_call_signals ADD COLUMN payload LONGTEXT NULL AFTER signal_type",
            'created_at' => "ALTER TABLE user_video_call_signals ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER payload",
            'consumed_at' => "ALTER TABLE user_video_call_signals ADD COLUMN consumed_at DATETIME NULL DEFAULT NULL AFTER created_at",
        ];

        foreach ($requiredCallColumns as $column => $sql) {
            if (!private_video_call_column_exists($dbh, 'user_video_calls', $column)) {
                $dbh->exec($sql);
            }
        }

        foreach ($requiredSignalColumns as $column => $sql) {
            if (!private_video_call_column_exists($dbh, 'user_video_call_signals', $column)) {
                $dbh->exec($sql);
            }
        }

        $payloadMeta = private_video_call_column_meta($dbh, 'user_video_call_signals', 'payload');
        if ($payloadMeta) {
            $columnType = strtolower((string)($payloadMeta['COLUMN_TYPE'] ?? ''));
            $isNullable = strtoupper((string)($payloadMeta['IS_NULLABLE'] ?? 'YES'));
            $collation = trim((string)($payloadMeta['COLLATION_NAME'] ?? ''));
            if ($columnType !== 'longtext' || $isNullable !== 'YES' || ($collation !== '' && $collation !== 'utf8mb4_unicode_ci')) {
                $dbh->exec("
                    ALTER TABLE user_video_call_signals
                    MODIFY COLUMN payload LONGTEXT NULL
                    CHARACTER SET utf8mb4
                    COLLATE utf8mb4_unicode_ci
                ");
            }
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function private_video_call_column_exists(PDO $dbh, string $table, string $column): bool
{
    try {
        $st = $dbh->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
            LIMIT 1
        ");
        $st->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function private_video_call_column_meta(PDO $dbh, string $table, string $column): ?array
{
    try {
        $st = $dbh->prepare("
            SELECT COLUMN_TYPE, IS_NULLABLE, COLLATION_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
            LIMIT 1
        ");
        $st->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}
