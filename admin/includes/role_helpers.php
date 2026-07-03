<?php
// /Business_only3/admin/includes/role_helpers.php
declare(strict_types=1);

require_once __DIR__ . '/../controller.php';

if (!function_exists('adminDbh')) {
  function adminDbh(): PDO {
    static $dbh = null;
    if ($dbh === null) {
      $controller = new Controller();
      $dbh = $controller->pdo();
    }
    return $dbh;
  }
}

if (!function_exists('getRoleRow')) {
  function getRoleRow(PDO $dbh, int $roleId): ?array {
    if ($roleId <= 0) return null;

    $st = $dbh->prepare("
      SELECT
        idrole,
        name,
        COALESCE(inherits_from, NULL) AS inherits_from,
        COALESCE(status, 1) AS status
      FROM role
      WHERE idrole = :id
      LIMIT 1
    ");
    $st->execute([':id' => $roleId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}

if (!function_exists('resolveBaseRoleId')) {
  function resolveBaseRoleId(PDO $dbh, int $roleId): int {
    $seen = [];
    $cur = $roleId;

    for ($i = 0; $i < 30; $i++) {
      if ($cur <= 0) return 0;
      if (isset($seen[$cur])) return $roleId;
      $seen[$cur] = true;

      $row = getRoleRow($dbh, $cur);
      if (!$row) return 0;
      if ((int)($row['status'] ?? 1) !== 1) return 0;

      $parent = (int)($row['inherits_from'] ?? 0);
      if ($parent <= 0) return $cur;
      $cur = $parent;
    }

    return $roleId;
  }
}

if (!function_exists('baseRoleName')) {
  function baseRoleName(PDO $dbh, int $roleId): string {
    $seen = [];
    $cur = $roleId;

    for ($i = 0; $i < 30; $i++) {
      if ($cur <= 0) return '';
      if (isset($seen[$cur])) break;
      $seen[$cur] = true;

      $row = getRoleRow($dbh, $cur);
      if (!$row) return '';
      if ((int)($row['status'] ?? 1) !== 1) return '';

      $parent = (int)($row['inherits_from'] ?? 0);
      if ($parent <= 0) {
        return strtolower(trim((string)($row['name'] ?? '')));
      }
      $cur = $parent;
    }

    $row = getRoleRow($dbh, $roleId);
    return strtolower(trim((string)($row['name'] ?? '')));
  }
}

if (!function_exists('roleNameRaw')) {
  function roleNameRaw(PDO $dbh, int $roleId): string {
    $row = getRoleRow($dbh, $roleId);
    return strtolower(trim((string)($row['name'] ?? '')));
  }
}

if (!function_exists('isActiveRole')) {
  function isActiveRole(PDO $dbh, int $roleId): bool {
    return baseRoleName($dbh, $roleId) !== '';
  }
}

if (!function_exists('isBaseAdmin')) {
  function isBaseAdmin(PDO $dbh, int $roleId): bool {
    return baseRoleName($dbh, $roleId) === 'admin';
  }
}

if (!function_exists('dashboardForRole')) {
  function dashboardForRole(PDO $dbh, int $roleId): string {
    $base = baseRoleName($dbh, $roleId);
    if ($base === '') return 'index.php?inactiveRole=1';
    return 'dashboard.php';
  }
}

if (!function_exists('effectiveRoleId')) {
  function effectiveRoleId(PDO $dbh, int $roleId): int {
    return resolveBaseRoleId($dbh, $roleId);
  }
}

if (!function_exists('currentRoleName')) {
  function currentRoleName(PDO $dbh, int $roleId): string {
    $row = getRoleRow($dbh, $roleId);
    return trim((string)($row['name'] ?? ''));
  }
}

if (!function_exists('roleDisplayName')) {
  function roleDisplayName(PDO $dbh, int $roleId): string {
    $row = getRoleRow($dbh, $roleId);
    return trim((string)($row['name'] ?? ''));
  }
}
