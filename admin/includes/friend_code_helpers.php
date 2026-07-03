<?php
// /Business_only3/admin/includes/friend_code_helpers.php
declare(strict_types=1);

/**
 * Create friend code like XXXX-XXXX-XXXX
 */
function generateAdminFriendCode(): string {
    $parts = [];
    for ($i = 0; $i < 3; $i++) {
        $parts[] = strtoupper(bin2hex(random_bytes(2))); // 4 chars
    }
    return implode('-', $parts);
}

/**
 * Ensure admin has friend_code in admin table.
 * ✅ Can be called as ensureAdminFriendCode($dbh) (uses session admin_id)
 * ✅ Or ensureAdminFriendCode($dbh, $adminId)
 */
function ensureAdminFriendCode(PDO $dbh, ?int $adminId = null): string
{
    if ($adminId === null) {
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
    }
    if ($adminId <= 0) return '';

    $st = $dbh->prepare("SELECT friend_code FROM admin WHERE idadmin = :id LIMIT 1");
    $st->execute([':id' => $adminId]);
    $code = trim((string)$st->fetchColumn());

    if ($code !== '') {
        $_SESSION['admin_friend_code'] = $code;
        return $code;
    }

    for ($i = 0; $i < 30; $i++) {
        $new = generateAdminFriendCode();

        $dup = $dbh->prepare("SELECT idadmin FROM admin WHERE friend_code = :c LIMIT 1");
        $dup->execute([':c' => $new]);

        if (!$dup->fetchColumn()) {
            $upd = $dbh->prepare("UPDATE admin SET friend_code = :c WHERE idadmin = :id LIMIT 1");
            $upd->execute([':c' => $new, ':id' => $adminId]);

            $_SESSION['admin_friend_code'] = $new;
            return $new;
        }
    }

    return '';
}
