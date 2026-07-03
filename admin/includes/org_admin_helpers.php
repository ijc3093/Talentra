<?php
declare(strict_types=1);

function org_admin_h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function org_admin_fmt_dt($dt): string
{
    if (!$dt) {
        return 'N/A';
    }
    $ts = strtotime((string)$dt);
    if (!$ts) {
        return (string)$dt;
    }
    return date('M j, Y g:i A', $ts);
}

function org_admin_require_admin(): void
{
    require_once __DIR__ . '/session_admin.php';
    requireAdminLogin();
    $role = (int)($_SESSION['userRole'] ?? 0);
    if ($role !== 1) {
        header('Location: dashboard.php');
        exit;
    }
}

function org_admin_db(?PDO $dbh = null): PDO
{
    if ($dbh instanceof PDO) {
        return $dbh;
    }
    require_once dirname(__DIR__) . '/controller.php';
    return (new Controller())->pdo();
}

function org_admin_table_exists(PDO $dbh, string $table): bool
{
    static $cache = [];
    $table = preg_replace('/[^a-z0-9_]/i', '', $table) ?? '';
    if ($table === '') {
        return false;
    }
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    try {
        $st = $dbh->query('SHOW TABLES LIKE ' . $dbh->quote($table));
        $cache[$table] = (bool)($st && $st->fetchColumn());
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

function org_admin_status_badge(int $status): string
{
    if ($status === 1) {
        return '<span class="pill ok">Active</span>';
    }
    return '<span class="pill bad">Disabled</span>';
}

function org_admin_set_status(PDO $dbh, string $table, int $id, int $status): bool
{
    $allowed = [
        'organizations' => 'id',
        'managers' => 'id',
        'staff_accounts' => 'id',
    ];
    if (!isset($allowed[$table]) || $id <= 0) {
        return false;
    }
    $col = $allowed[$table];
    $status = $status === 1 ? 1 : 0;
    $sql = 'UPDATE `' . $table . '` SET status = :st WHERE `' . $col . '` = :id LIMIT 1';
    $st = $dbh->prepare($sql);
    $st->execute([':st' => $status, ':id' => $id]);
    return $st->rowCount() > 0;
}

function org_admin_list_organizations(PDO $dbh, string $filter = 'all', string $search = ''): array
{
    if (!org_admin_table_exists($dbh, 'organizations')) {
        return [];
    }

    $where = ['1=1'];
    $params = [];

    if ($filter === 'active') {
        $where[] = 'o.status = 1';
    } elseif ($filter === 'disabled') {
        $where[] = 'o.status = 0';
    } elseif ($filter === 'publisher') {
        $where[] = 'o.is_publisher_org = 1';
    } elseif ($filter === 'regular') {
        $where[] = 'o.is_publisher_org = 0';
    }

    $search = trim($search);
    if ($search !== '') {
        $where[] = '(o.name LIKE :q OR o.org_code LIKE :q OR m.username LIKE :q OR m.email LIKE :q OR u.username LIKE :q OR u.email LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }

    $sql = '
        SELECT
            o.id, o.org_code, o.name, o.status, o.is_publisher_org, o.publisher_category,
            o.publisher_user_id, o.created_at,
            m.id AS manager_id, m.username AS manager_username, m.email AS manager_email,
            m.friend_code AS manager_code, m.status AS manager_status,
            u.id AS pub_user_id, u.username AS pub_username, u.friend_code AS pub_code,
            u.email AS pub_email, u.account_kind,
            (SELECT COUNT(*) FROM org_members om WHERE om.org_id = o.id AND om.member_type = \'manager\') AS manager_count,
            (SELECT COUNT(*) FROM org_members om WHERE om.org_id = o.id AND om.member_type = \'staff\') AS staff_count
        FROM organizations o
        JOIN managers m ON m.id = o.owner_manager_id
        LEFT JOIN users u ON u.id = o.publisher_user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY o.id DESC
    ';

    $st = $dbh->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function org_admin_get_organization(PDO $dbh, int $orgId): ?array
{
    if ($orgId <= 0 || !org_admin_table_exists($dbh, 'organizations')) {
        return null;
    }

    $sql = '
        SELECT
            o.*,
            m.id AS manager_id, m.username AS manager_username, m.email AS manager_email,
            m.friend_code AS manager_code, m.fullname AS manager_fullname, m.status AS manager_status,
            m.publisher_user_id AS manager_publisher_user_id,
            u.id AS pub_user_id, u.username AS pub_username, u.friend_code AS pub_code,
            u.email AS pub_email, u.account_kind, u.status AS pub_user_status,
            pno.id AS name_option_id, pno.name AS registered_publisher_name
        FROM organizations o
        JOIN managers m ON m.id = o.owner_manager_id
        LEFT JOIN users u ON u.id = o.publisher_user_id
        LEFT JOIN publisher_name_options pno ON pno.org_id = o.id
        WHERE o.id = :id
        LIMIT 1
    ';
    $st = $dbh->prepare($sql);
    $st->execute([':id' => $orgId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function org_admin_list_org_members(PDO $dbh, int $orgId): array
{
    if ($orgId <= 0 || !org_admin_table_exists($dbh, 'org_members')) {
        return [];
    }

    $sql = '
        SELECT
            om.id, om.org_id, om.member_type, om.member_id, om.status, om.joined_at,
            om.relationship_label,
            r.name AS role_name,
            CASE om.member_type
                WHEN \'manager\' THEN mgr.username
                WHEN \'staff\' THEN stf.username
                ELSE NULL
            END AS member_username,
            CASE om.member_type
                WHEN \'manager\' THEN mgr.email
                WHEN \'staff\' THEN stf.email
                ELSE NULL
            END AS member_email,
            CASE om.member_type
                WHEN \'manager\' THEN mgr.friend_code
                WHEN \'staff\' THEN stf.friend_code
                ELSE NULL
            END AS member_code,
            CASE om.member_type
                WHEN \'manager\' THEN mgr.fullname
                WHEN \'staff\' THEN stf.fullname
                ELSE NULL
            END AS member_fullname,
            CASE om.member_type
                WHEN \'manager\' THEN mgr.status
                WHEN \'staff\' THEN stf.status
                ELSE NULL
            END AS account_status
        FROM org_members om
        LEFT JOIN org_roles r ON r.id = om.role_id
        LEFT JOIN managers mgr ON om.member_type = \'manager\' AND mgr.id = om.member_id
        LEFT JOIN staff_accounts stf ON om.member_type = \'staff\' AND stf.id = om.member_id
        WHERE om.org_id = :org
        ORDER BY om.member_type ASC, om.joined_at ASC, om.id ASC
    ';
    $st = $dbh->prepare($sql);
    $st->execute([':org' => $orgId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function org_admin_list_managers(PDO $dbh, string $search = ''): array
{
    if (!org_admin_table_exists($dbh, 'managers')) {
        return [];
    }

    $where = ['1=1'];
    $params = [];
    $search = trim($search);
    if ($search !== '') {
        $where[] = '(m.username LIKE :q OR m.email LIKE :q OR m.friend_code LIKE :q OR m.fullname LIKE :q OR u.username LIKE :q OR u.email LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }

    $sql = '
        SELECT
            m.id, m.friend_code, m.username, m.email, m.fullname, m.status,
            m.publisher_user_id, m.created_at, m.last_seen,
            u.id AS pub_user_id, u.username AS pub_username, u.friend_code AS pub_code,
            (SELECT COUNT(*) FROM organizations o WHERE o.owner_manager_id = m.id) AS owned_org_count,
            (SELECT COUNT(*) FROM org_members om
                JOIN organizations o2 ON o2.id = om.org_id
                WHERE om.member_type = \'manager\' AND om.member_id = m.id) AS membership_count
        FROM managers m
        LEFT JOIN users u ON u.id = m.publisher_user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY m.id DESC
    ';
    $st = $dbh->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function org_admin_manager_orgs(PDO $dbh, int $managerId): array
{
    if ($managerId <= 0) {
        return [];
    }
    $sql = '
        SELECT o.id, o.org_code, o.name, o.status, o.is_publisher_org, o.created_at
        FROM organizations o
        WHERE o.owner_manager_id = :mid
        ORDER BY o.id DESC
    ';
    $st = $dbh->prepare($sql);
    $st->execute([':mid' => $managerId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function org_admin_list_staff(PDO $dbh, string $search = ''): array
{
    if (!org_admin_table_exists($dbh, 'staff_accounts')) {
        return [];
    }

    $where = ['1=1'];
    $params = [];
    $search = trim($search);
    if ($search !== '') {
        $where[] = '(s.username LIKE :q OR s.email LIKE :q OR s.friend_code LIKE :q OR s.fullname LIKE :q OR o.name LIKE :q OR o.org_code LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }

    $sql = '
        SELECT
            s.id, s.org_id, s.friend_code, s.username, s.email, s.fullname,
            s.status, s.created_at, s.last_seen,
            o.org_code, o.name AS org_name, o.status AS org_status, o.is_publisher_org
        FROM staff_accounts s
        JOIN organizations o ON o.id = s.org_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY s.id DESC
    ';
    $st = $dbh->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function org_admin_search_accounts(PDO $dbh, string $query): array
{
    $query = trim($query);
    if ($query === '') {
        return ['users' => [], 'managers' => [], 'organizations' => []];
    }

    $like = '%' . $query . '%';
    $out = ['users' => [], 'managers' => [], 'organizations' => []];

    if (org_admin_table_exists($dbh, 'users')) {
        $st = $dbh->prepare('
            SELECT id, name, username, email, friend_code, account_kind, status, created_at
            FROM users
            WHERE name LIKE :q OR username LIKE :q OR email LIKE :q OR friend_code LIKE :q
            ORDER BY id DESC
            LIMIT 50
        ');
        $st->execute([':q' => $like]);
        $out['users'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if (org_admin_table_exists($dbh, 'managers')) {
        $st = $dbh->prepare('
            SELECT id, username, email, friend_code, fullname, status, publisher_user_id, created_at
            FROM managers
            WHERE username LIKE :q OR email LIKE :q OR friend_code LIKE :q OR fullname LIKE :q
            ORDER BY id DESC
            LIMIT 50
        ');
        $st->execute([':q' => $like]);
        $out['managers'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if (org_admin_table_exists($dbh, 'organizations')) {
        $st = $dbh->prepare('
            SELECT id, org_code, name, status, is_publisher_org, publisher_user_id, created_at
            FROM organizations
            WHERE name LIKE :q OR org_code LIKE :q
            ORDER BY id DESC
            LIMIT 50
        ');
        $st->execute([':q' => $like]);
        $out['organizations'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    return $out;
}

function org_admin_render_head(string $title): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= org_admin_h($title) ?></title>
  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <?php require_once __DIR__ . '/admin_layout.php'; admin_layout_head_assets(); ?>
  <style>
    :root{
      --bg:#f3f4f6; --card:#fff; --border:rgba(17,24,39,.10); --muted:#64748b;
      --brand:#2563eb; --brand2:#1d4ed8; --shadow:0 10px 30px rgba(17,24,39,.08);
    }
    html, body{ height:100%; overflow:hidden; }
    body{ background:var(--bg); }
    .sh-mainpanel{
      height:100vh;
      display:flex;
      flex-direction:column;
      overflow:hidden;
    }
    .sh-pagebody{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      padding-bottom:0!important;
      display:flex;
      flex-direction:column;
      background:var(--bg);
    }
    .admin-card{
      flex:1 1 auto;
      min-height:0;
      display:flex;
      flex-direction:column;
      border:1px solid var(--border);
      box-shadow:var(--shadow);
      overflow:hidden;
      background:var(--card);
    }
    .card-header.pro{
      flex:0 0 auto;
      background:linear-gradient(135deg,var(--brand2),var(--brand));
      color:#fff;
      padding:16px 18px;
      font-weight:900;
      border-bottom:1px solid rgba(255,255,255,.18);
    }
    .card-header .sub{ font-size:12px; opacity:.92; margin-top:4px; font-weight:700; }
    .pro-tools{
      flex:0 0 auto;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      flex-wrap:wrap;
      padding:14px 18px;
      border-bottom:1px solid rgba(17,24,39,.06);
      background:rgba(248,250,252,.90);
    }
    .card-body-fixed{
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      display:flex;
      flex-direction:column;
    }
    .filter-tabs{display:flex;gap:8px;flex-wrap:wrap}
    .filter-tabs a,.btn-link-pill{
      display:inline-flex; align-items:center; gap:6px; padding:8px 12px; border-radius:999px;
      border:1px solid var(--border); background:#fff; color:#111827; font-size:12px;
      font-weight:800; text-decoration:none;
    }
    .filter-tabs a.is-active,.btn-link-pill.is-active{
      background:rgba(37,99,235,.10); border-color:rgba(37,99,235,.25); color:#1d4ed8;
    }
    .table-scroll{
      flex:1 1 auto;
      min-height:0;
      overflow-y:auto;
      overflow-x:auto;
      -webkit-overflow-scrolling:touch;
      background:#fff;
    }
    .admin-table{ width:100%; margin:0; font-size:13px; border-collapse:separate; border-spacing:0; }
    .admin-table thead th{
      position:sticky;
      top:0;
      z-index:3;
      background:#f8fafc;
      font-size:11px;
      text-transform:uppercase;
      letter-spacing:.04em;
      white-space:nowrap;
      border-bottom:1px solid rgba(17,24,39,.12);
      box-shadow:0 1px 0 rgba(17,24,39,.06);
    }
    .admin-table td{ vertical-align:middle; background:#fff; }
    .pill{
      display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px;
      font-size:11px; font-weight:900; white-space:nowrap;
    }
    .pill.ok{background:rgba(34,197,94,.12);color:#15803d}
    .pill.bad{background:rgba(239,68,68,.12);color:#b91c1c}
    .pill.info{background:rgba(37,99,235,.12);color:#1d4ed8}
    .pill.warn{background:rgba(245,158,11,.12);color:#b45309}
    .muted{ color:var(--muted); font-size:12px; }
    .detail-grid{
      display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
      gap:12px; padding:16px 18px; border-bottom:1px solid rgba(17,24,39,.06);
    }
    .detail-box{
      border:1px solid rgba(17,24,39,.08); border-radius:12px; padding:12px 14px; background:#fff;
    }
    .detail-box .label{ font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); font-weight:800; }
    .detail-box .value{ margin-top:6px; font-size:14px; font-weight:700; color:#111827; word-break:break-word; }
    .search-form{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .search-form input[type="text"]{
      min-width:220px; height:38px; border:1px solid var(--border); border-radius:10px; padding:0 12px;
    }
    .btn-mini{
      display:inline-flex; align-items:center; justify-content:center; min-height:32px;
      padding:6px 10px; border-radius:8px; border:1px solid var(--border); background:#fff;
      font-size:12px; font-weight:800; text-decoration:none; color:#111827; cursor:pointer;
    }
    .btn-mini.primary{ background:#2563eb; border-color:#2563eb; color:#fff; }
    .btn-mini.warn{ background:#f59e0b; border-color:#f59e0b; color:#fff; }
    .btn-mini.danger{ background:#ef4444; border-color:#ef4444; color:#fff; }
    .alert-lite{
      flex:0 0 auto;
      margin:12px 18px 0;
      padding:10px 12px;
      border-radius:10px;
      font-size:13px;
      font-weight:700;
    }
    .alert-lite.ok{ background:rgba(34,197,94,.12); color:#166534; }
    .alert-lite.bad{ background:rgba(239,68,68,.12); color:#991b1b; }
  </style>
</head>
<body>
<?php
}

function org_admin_render_foot(): void
{
    ?>
</body>
</html>
<?php
}

function org_admin_public_profile_url(int $userId, string $username = '', string $friendCode = ''): string
{
    if ($userId > 0) {
        return '../public_user/profile.php?id=' . $userId;
    }
    $friendCode = strtoupper(trim($friendCode));
    if ($friendCode !== '') {
        return '../public_user/profile.php?friend_code=' . rawurlencode($friendCode);
    }
    $username = trim($username);
    if ($username !== '') {
        return '../public_user/profile.php?username=' . rawurlencode($username);
    }
    return '../public_user/feed.php';
}

function org_admin_public_post_url(int $postId): string
{
    return '../public_user/post_view.php?id=' . max(0, $postId);
}

function org_admin_get_public_user(PDO $dbh, int $userId): ?array
{
    if ($userId <= 0 || !org_admin_table_exists($dbh, 'users')) {
        return null;
    }
    $st = $dbh->prepare('
        SELECT id, name, username, email, friend_code, account_kind, publisher_category,
               publisher_tagline, designation, status, created_at, last_seen
        FROM users
        WHERE id = :id
        LIMIT 1
    ');
    $st->execute([':id' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function org_admin_user_post_count(PDO $dbh, int $userId): int
{
    if ($userId <= 0 || !org_admin_table_exists($dbh, 'public_posts')) {
        return 0;
    }
    try {
        $st = $dbh->prepare('
            SELECT COUNT(*) FROM public_posts
            WHERE user_id = :uid AND (is_deleted = 0 OR is_deleted IS NULL)
        ');
        $st->execute([':uid' => $userId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function org_admin_user_follower_count(PDO $dbh, int $userId): int
{
    if ($userId <= 0 || !org_admin_table_exists($dbh, 'public_follows')) {
        return 0;
    }
    try {
        $st = $dbh->prepare('SELECT COUNT(*) FROM public_follows WHERE following_id = :uid');
        $st->execute([':uid' => $userId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function org_admin_user_friend_count(PDO $dbh, int $userId): int
{
    if ($userId <= 0 || !org_admin_table_exists($dbh, 'contact_requests')) {
        return 0;
    }
    try {
        $st = $dbh->prepare("
            SELECT COUNT(*) FROM contact_requests
            WHERE status = 'accepted'
              AND (from_user_id = :uid OR to_user_id = :uid)
        ");
        $st->execute([':uid' => $userId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function org_admin_user_recent_posts(PDO $dbh, int $userId, int $limit = 25): array
{
    if ($userId <= 0 || !org_admin_table_exists($dbh, 'public_posts')) {
        return [];
    }
    $limit = max(1, min($limit, 100));
    try {
        $st = $dbh->prepare("
            SELECT id, title, description, visibility, COALESCE(views_count, 0) AS views_count, created_at
            FROM public_posts
            WHERE user_id = :uid AND (is_deleted = 0 OR is_deleted IS NULL)
            ORDER BY created_at DESC, id DESC
            LIMIT {$limit}
        ");
        $st->execute([':uid' => $userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function org_admin_user_org_links(PDO $dbh, int $userId): array
{
    if ($userId <= 0 || !org_admin_table_exists($dbh, 'organizations')) {
        return [];
    }
    $st = $dbh->prepare('
        SELECT id, org_code, name, status, is_publisher_org, publisher_category, created_at
        FROM organizations
        WHERE publisher_user_id = :uid
        ORDER BY id DESC
    ');
    $st->execute([':uid' => $userId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function org_admin_user_publisher_approval(PDO $dbh, int $userId, string $displayName = ''): ?array
{
    if (!org_admin_table_exists($dbh, 'publisher_name_options') || !org_admin_table_exists($dbh, 'publisher_name_authority')) {
        return null;
    }
    try {
        $st = $dbh->prepare('
            SELECT pna.id, pna.publisher_name, pna.publisher_category, pna.status,
                   pna.reviewed_at, pna.review_note, pna.created_at
            FROM publisher_name_options pno
            JOIN publisher_name_authority pna ON pna.publisher_name = pno.name
            WHERE pno.registered_user_id = :uid
            ORDER BY pna.id DESC
            LIMIT 1
        ');
        $st->execute([':uid' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    } catch (Throwable $e) {
    }

    $displayName = trim($displayName);
    if ($displayName === '') {
        return null;
    }
    try {
        $st = $dbh->prepare('
            SELECT id, publisher_name, publisher_category, status, reviewed_at, review_note, created_at
            FROM publisher_name_authority
            WHERE publisher_name = :name
            ORDER BY id DESC
            LIMIT 1
        ');
        $st->execute([':name' => $displayName]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function org_admin_user_activity_link(int $userId): string
{
    return 'user_activity.php?user_id=' . max(0, $userId);
}

function org_admin_render_public_user_link(int $userId, string $label = '', string $username = '', string $friendCode = ''): string
{
    if ($userId <= 0) {
        return '';
    }
    $label = trim($label) !== '' ? trim($label) : ('User #' . $userId);
    $adminHref = org_admin_h(org_admin_user_activity_link($userId));
    $profileHref = org_admin_h(org_admin_public_profile_url($userId, $username, $friendCode));
    return '<a class="btn-mini primary" href="' . $adminHref . '" title="View public activity in Admin">'
        . org_admin_h($label)
        . '</a> '
        . '<a class="btn-mini" href="' . $profileHref . '" target="_blank" rel="noopener" title="Open public_user profile (requires public login)">Public profile</a>';
}
