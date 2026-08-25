<?php
declare(strict_types=1);

/**
 * Execute an idempotent SQL migration file (PREPARE/EXECUTE blocks, CREATE IF NOT EXISTS).
 */
function msb_run_sql_migration_file(PDO $dbh, string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $sql = (string)file_get_contents($path);
    if (trim($sql) === '') {
        return;
    }

    try {
        if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
            $dbh->setAttribute(PDO::MYSQL_ATTR_MULTI_STATEMENTS, true);
        }
        $dbh->exec($sql);
        do {
            // drain additional result sets from multi-statement scripts
        } while (@$dbh->nextRowset());
    } catch (Throwable $e) {
        // columns/tables may already exist on partially migrated databases
    }
}
