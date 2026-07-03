<?php

require_once __DIR__ . '/session_admin.php';
requireAdminLogin();

require_once __DIR__ . '/role_helpers.php';

function myRawRoleId(): int {
  return (int)($_SESSION['userRole'] ?? 0);
}

/**
 * Effective/base role id is NOT required if you only use baseRoleName().
 * But if you want "coach behaves like manager" you can check baseRoleName().
 */
function myBaseRoleName(): string {
    $dbh = adminDbh();
    return baseRoleName($dbh, myRawRoleId());
}

function isManagerLike(): bool {
  return myBaseRoleName() === 'manager'; // manager + coach
}

function myUsername(): string {
  return trim((string)($_SESSION['admin_login'] ?? ''));
}

function myAdminId(): int {
  return (int)($_SESSION['admin_id'] ?? 0);
}


/**
 * myRoleId() = effective/base role id
 * (coach behaves like manager)
 */
function myRoleId(): int {
    $dbh = adminDbh();
    return effectiveRoleId($dbh, myRawRoleId());
}

function myAdminFriendCode(): string {
    return trim((string)($_SESSION['admin_friend_code'] ?? ''));
}


function isAdmin(): bool {
    // Admin base role means baseRoleName == 'admin'
    $dbh = adminDbh();
    return baseRoleName($dbh, myRawRoleId()) === 'admin';
}


/**
 * Internal channels allowed by EFFECTIVE/base role
 * - Admin sees all internal channels
 * - Manager (and Coach inheriting Manager) sees manager channels
 * - Staff sees staff channels
 *
 * NOTE: these channel strings must match what your internal chat uses in `feedback_admin.channel`.
 */
function allowedInternalChannelsForMe(): array {
    $dbh = adminDbh();
    $base = baseRoleName($dbh, myRawRoleId());

    if ($base === 'admin') {
        return ['admin_manager','admin_staff','admin_admin','manager_staff','manager_manager','staff_staff'];
    }
    if ($base === 'manager') {
        return ['admin_manager','manager_manager','manager_staff'];
    }
    if ($base === 'staff') {
        return ['admin_staff','staff_staff','manager_staff'];
    }
    return [];
}


/**
 * Determine chat channel from effective roles (base ids)
 * - Uses BASE role names to decide mapping
 */
function channelForAdminRoles(int $myEffectiveRoleId, int $peerEffectiveRoleId): string
{
    // We need names to decide, because ids may differ by DB.
    $dbh = adminDbh();
    $myName   = baseRoleName($dbh, $myEffectiveRoleId);
    $peerName = baseRoleName($dbh, $peerEffectiveRoleId);

    if ($myName === '' || $peerName === '') return '';

    if (($myName==='admin' && $peerName==='manager') || ($myName==='manager' && $peerName==='admin')) return 'admin_manager';
    if (($myName==='admin' && $peerName==='staff')   || ($myName==='staff'   && $peerName==='admin')) return 'admin_staff';
    if ($myName==='admin'   && $peerName==='admin')   return 'admin_admin';
    if ($myName==='manager' && $peerName==='manager') return 'manager_manager';
    if ($myName==='staff'   && $peerName==='staff')   return 'staff_staff';
    if (($myName==='manager' && $peerName==='staff') || ($myName==='staff' && $peerName==='manager')) return 'manager_staff';

    return '';
}



/**
 * Notification receiver keys (role labels) — using effective role.
 * Admin sees all; Coach inherits Manager => sees Manager only.
 */
function myNotificationReceiverKeys(): array
{
    $role = myRoleId(); // effective/base

    if ($role === 1) return ['Admin', 'Manager', 'Gospel', 'Staff'];
    if ($role === 2) return ['Manager'];
    if ($role === 3) return ['Gospel'];
    if ($role === 4) return ['Staff'];
    return [];
}

function isMyNotificationReceiver(string $receiver): bool
{
    $receiver = trim($receiver);
    if ($receiver === '') return false;
    return in_array($receiver, myNotificationReceiverKeys(), true);
}


/**
 * Friend code generator: XXXX-XXXX-XXXX
 */
function generateFriendCode(int $groups = 3): string {
    $parts = [];
    for ($i = 0; $i < $groups; $i++) {
        $parts[] = strtoupper(bin2hex(random_bytes(2))); // 4 chars
    }
    return implode('-', $parts);
}

/**
 * Ensure friend_code exists in admin table.
 * Uses admin.friend_code (as in your earlier code).
 */
function ensureAdminFriendCode(PDO $dbh): string
{
    $adminId = myAdminId();
    if ($adminId <= 0) return '';

    $st = $dbh->prepare("SELECT friend_code FROM admin WHERE idadmin = :id LIMIT 1");
    $st->execute([':id' => $adminId]);
    $code = trim((string)$st->fetchColumn());

    if ($code !== '') {
        $_SESSION['admin_friend_code'] = $code;
        return $code;
    }

    // generate unique
    for ($i = 0; $i < 30; $i++) {
        $new = generateFriendCode();

        $chk = $dbh->prepare("SELECT idadmin FROM admin WHERE friend_code = :c LIMIT 1");
        $chk->execute([':c' => $new]);

        if (!$chk->fetchColumn()) {
            $upd = $dbh->prepare("UPDATE admin SET friend_code = :c WHERE idadmin = :id LIMIT 1");
            $upd->execute([':c' => $new, ':id' => $adminId]);

            $_SESSION['admin_friend_code'] = $new;
            return $new;
        }
    }

    return '';
}