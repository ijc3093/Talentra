<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/profile_access.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/user_backgrounds.php';
require_once __DIR__ . '/includes/user_phone.php';
require_once __DIR__ . '/includes/publisher_accounts.php';

$controller = new Controller();
$dbh = $controller->pdo();
$meId = profile_session_owner_user_id();
if ($meId <= 0) {
    header('Location: index.php?session=reset');
    exit;
}
profile_require_edit_access($dbh, $meId, false);

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

function accounts_clean(string $value, int $max): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? trim($value));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

function accounts_country_from_location(string $location): string
{
    $location = trim($location);
    if ($location === '') {
        return 'United States';
    }
    $usStates = [
        'al','ak','az','ar','ca','co','ct','de','fl','ga','hi','id','il','in','ia','ks','ky','la','me','md',
        'ma','mi','mn','ms','mo','mt','ne','nv','nh','nj','nm','ny','nc','nd','oh','ok','or','pa','ri','sc',
        'sd','tn','tx','ut','vt','va','wa','wv','wi','wy','dc','usa','us','united states','united state',
        'alabama','alaska','arizona','arkansas','california','colorado','connecticut','delaware','florida',
        'georgia','hawaii','idaho','illinois','indiana','iowa','kansas','kentucky','louisiana','maine',
        'maryland','massachusetts','michigan','minnesota','mississippi','missouri','montana','nebraska',
        'nevada','new hampshire','new jersey','new mexico','new york','north carolina','north dakota','ohio',
        'oklahoma','oregon','pennsylvania','rhode island','south carolina','south dakota','tennessee','texas',
        'utah','vermont','virginia','washington','west virginia','wisconsin','wyoming',
    ];
    $parts = array_map('trim', explode(',', $location));
    $last = strtolower((string)end($parts));
    $last = preg_replace('/\s+/', ' ', $last) ?? $last;
    if (in_array($last, $usStates, true) || preg_match('/\b(usa|united states)\b/i', $location)) {
        return 'United States';
    }
    if (count($parts) > 1 && $last !== '') {
        return (string)end($parts);
    }
    return 'United States';
}

function accounts_columns(PDO $dbh): array
{
    $cols = [];
    try {
        $st = $dbh->query('SHOW COLUMNS FROM users');
        while ($row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false) {
            $field = (string)($row['Field'] ?? '');
            if ($field !== '') {
                $cols[$field] = true;
            }
        }
    } catch (Throwable $e) {
    }
    return $cols;
}

$usersCols = accounts_columns($dbh);
$errors = [];
$saved = false;
$storedMobileInvalid = false;

$form = [
    'full_name' => '',
    'username' => '',
    'email' => '',
    'mobile' => '',
    'bio' => '',
    'location' => '',
    'website' => '',
    'friend_code' => '',
    'status' => 1,
    'created_at' => '',
];

try {
    $st = $dbh->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $st->execute([':id' => $meId]);
    $user = $st->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $user = [];
}

$form['full_name'] = trim((string)($user['fullname'] ?? $user['name'] ?? ''));
$form['username'] = trim((string)($user['username'] ?? ''));
$form['email'] = trim((string)($user['email'] ?? ''));
$form['friend_code'] = trim((string)($user['friend_code'] ?? ''));
$form['status'] = (int)($user['status'] ?? 1);
$form['created_at'] = trim((string)($user['created_at'] ?? ''));
if (user_phone_repair_invalid_mobile($dbh, $meId, $user)) {
    $storedMobileInvalid = true;
    $user['mobile'] = '';
}
$form['mobile'] = user_phone_for_display(trim((string)($user['mobile'] ?? '')));
if ($form['mobile'] === '' && !$storedMobileInvalid) {
    $rawMobile = user_phone_raw_from_user_row($user);
    if (user_phone_is_valid($rawMobile)) {
        $form['mobile'] = user_phone_normalize($rawMobile);
    }
}

$bg = user_background_load($dbh, $meId);
$form['bio'] = trim((string)($bg['about_text'] ?? ''));
$form['location'] = trim((string)($bg['lives_in'] ?? ''));
$form['website'] = trim((string)($bg['profile_link'] ?? ''));

function accounts_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$ajaxAction = trim((string)($_REQUEST['ajax'] ?? ''));
if ($ajaxAction === 'save_display_name' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = accounts_clean((string)($_POST['full_name'] ?? ''), 120);
    if ($name === '') {
        accounts_json(['ok' => false, 'error' => 'Full name is required.'], 400);
    }
    try {
        $updates = [];
        $params = [':id' => $meId, ':full_name' => $name];
        if (isset($usersCols['fullname'])) {
            $updates[] = 'fullname = :full_name';
        } elseif (isset($usersCols['name'])) {
            $updates[] = 'name = :full_name';
        }
        if ($updates !== []) {
            $st = $dbh->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = :id LIMIT 1');
            $st->execute($params);
        }
        $_SESSION['user_name'] = $name;
        if (isset($_SESSION['name'])) {
            $_SESSION['name'] = $name;
        }
        require_once __DIR__ . '/includes/account_display_helpers.php';
        $parts = account_display_name_parts($name, publisher_is_publisher_user($dbh, $meId), $dbh);
        accounts_json([
            'ok' => true,
            'message' => 'Display name saved.',
            'full_name' => $name,
            'display_name' => (string)$parts['display_name'],
            'badge' => (string)$parts['badge'],
        ]);
    } catch (Throwable $e) {
        accounts_json(['ok' => false, 'error' => 'Could not save your display name.'], 500);
    }
}

if ($ajaxAction === 'save_username' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = accounts_clean((string)($_POST['username'] ?? ''), 60);
    if ($username === '') {
        accounts_json(['ok' => false, 'error' => 'Username is required.'], 400);
    }
    try {
        $dup = $dbh->prepare('SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1');
        $dup->execute([':username' => $username, ':id' => $meId]);
        if ($dup->fetchColumn()) {
            accounts_json(['ok' => false, 'error' => 'That username is already in use.'], 400);
        }
        if (isset($usersCols['username'])) {
            $st = $dbh->prepare('UPDATE users SET username = :username WHERE id = :id LIMIT 1');
            $st->execute([':username' => $username, ':id' => $meId]);
        }
        $_SESSION['user_login'] = $username;
        accounts_json([
            'ok' => true,
            'message' => 'Username saved.',
            'username' => $username,
            'handle' => '@' . $username,
        ]);
    } catch (Throwable $e) {
        accounts_json(['ok' => false, 'error' => 'Could not save your username.'], 500);
    }
}

$joinedLabel = '—';
if ($form['created_at'] !== '' && strtotime($form['created_at'])) {
    $joinedLabel = date('F j, Y', strtotime($form['created_at']));
}
$countryLabel = accounts_country_from_location($form['location']);
$userIdLabel = $form['friend_code'] !== '' ? $form['friend_code'] : ('ID-' . $meId);
$isActive = $form['status'] === 1;
$avatarUrl = 'avatar.php?u=' . $meId . '&name=' . rawurlencode($form['full_name'] !== '' ? $form['full_name'] : $form['username']) . '&v=' . time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['full_name'] = accounts_clean((string)($_POST['full_name'] ?? ''), 120);
    $form['username'] = accounts_clean((string)($_POST['username'] ?? ''), 60);
    $form['email'] = accounts_clean((string)($_POST['email'] ?? $form['email']), 255);
    $form['mobile'] = accounts_clean((string)($_POST['mobile'] ?? ''), 20);
    $form['friend_code'] = accounts_clean((string)($_POST['friend_code'] ?? ''), 30);
    $form['bio'] = accounts_clean((string)($_POST['bio'] ?? ''), 160);
    $form['location'] = accounts_clean((string)($_POST['location'] ?? ''), 150);
    $form['website'] = user_background_normalize_link(accounts_clean((string)($_POST['website'] ?? ''), 255));

    if ($form['full_name'] === '') {
        $errors[] = 'Full name is required.';
    }
    if ($form['username'] === '') {
        $errors[] = 'Username is required.';
    }
    if ($form['email'] === '' || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($form['friend_code'] === '') {
        $errors[] = 'Friend code is required.';
    }
    if ($form['mobile'] !== '' && !user_phone_is_valid($form['mobile'])) {
        $errors[] = 'Phone number must contain 7 to 15 digits.';
    } elseif (!publisher_is_publisher_user($dbh, $meId) && $form['mobile'] === '') {
        $errors[] = 'Phone number is required for personal accounts.';
    }

    if ($form['username'] !== '') {
        try {
            $dup = $dbh->prepare('SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1');
            $dup->execute([':username' => $form['username'], ':id' => $meId]);
            if ($dup->fetchColumn()) {
                $errors[] = 'That username is already in use.';
            }
        } catch (Throwable $e) {
        }
    }
    if ($form['email'] !== '') {
        try {
            $dup = $dbh->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
            $dup->execute([':email' => $form['email'], ':id' => $meId]);
            if ($dup->fetchColumn()) {
                $errors[] = 'That email address is already in use.';
            }
        } catch (Throwable $e) {
        }
    }
    if ($form['friend_code'] !== '') {
        try {
            $dup = $dbh->prepare('SELECT id FROM users WHERE friend_code = :friend_code AND id <> :id LIMIT 1');
            $dup->execute([':friend_code' => $form['friend_code'], ':id' => $meId]);
            if ($dup->fetchColumn()) {
                $errors[] = 'That friend code is already in use.';
            }
        } catch (Throwable $e) {
        }
    }

    if ($errors === []) {
        if ($form['mobile'] !== '') {
            $form['mobile'] = user_phone_normalize($form['mobile']);
        }
        try {
            $updates = [];
            $params = [':id' => $meId];
            if (isset($usersCols['fullname'])) {
                $updates[] = 'fullname = :full_name';
                $params[':full_name'] = $form['full_name'];
            } elseif (isset($usersCols['name'])) {
                $updates[] = 'name = :full_name';
                $params[':full_name'] = $form['full_name'];
            }
            if (isset($usersCols['username'])) {
                $updates[] = 'username = :username';
                $params[':username'] = $form['username'];
            }
            if (isset($usersCols['email'])) {
                $updates[] = 'email = :email';
                $params[':email'] = $form['email'];
            }
            if (isset($usersCols['mobile'])) {
                $updates[] = 'mobile = :mobile';
                $params[':mobile'] = $form['mobile'];
            }
            if (isset($usersCols['friend_code'])) {
                $updates[] = 'friend_code = :friend_code';
                $params[':friend_code'] = $form['friend_code'];
            }
            if ($updates !== []) {
                $st = $dbh->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = :id LIMIT 1');
                $st->execute($params);
            }
            $bgSave = $bg;
            $bgSave['about_text'] = $form['bio'];
            $bgSave['lives_in'] = $form['location'];
            $bgSave['profile_link'] = $form['website'];
            user_background_save($dbh, $meId, $bgSave);
            $_SESSION['user_name'] = $form['full_name'];
            $_SESSION['user_login'] = $form['username'];
            $_SESSION['user_email'] = $form['email'];
            $_SESSION['user_friend_code'] = $form['friend_code'];
            $saved = true;
            $avatarUrl = 'avatar.php?u=' . $meId . '&name=' . rawurlencode($form['full_name']) . '&v=' . time();
            $countryLabel = accounts_country_from_location($form['location']);
            $userIdLabel = $form['friend_code'] !== '' ? $form['friend_code'] : ('ID-' . $meId);
        } catch (Throwable $e) {
            $errors[] = 'Could not save your account details.';
        }
    }
}

$ajaxAction = trim((string)($_REQUEST['ajax'] ?? $ajaxAction ?? ''));
if ($ajaxAction === 'save_account') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        accounts_json(['ok' => false, 'error' => 'Not allowed'], 400);
    }
    if ($errors !== []) {
        accounts_json(['ok' => false, 'error' => implode(' ', $errors)], 400);
    }
    if (!$saved) {
        accounts_json(['ok' => false, 'error' => 'Could not save your account details.'], 400);
    }
    require_once __DIR__ . '/includes/account_display_helpers.php';
    $parts = account_display_name_parts($form['full_name'], publisher_is_publisher_user($dbh, $meId), $dbh);
    accounts_json([
        'ok' => true,
        'message' => 'Account details saved.',
        'form' => $form,
        'display_name' => (string)$parts['display_name'],
        'badge' => (string)$parts['badge'],
        'handle' => $form['username'] !== '' ? ('@' . $form['username']) : '',
    ]);
}

$returnUrl = trim((string)($_GET['return'] ?? 'settings.php#gear-account-tools'));
if (strpos($returnUrl, '://') !== false || str_starts_with($returnUrl, '//')) {
    $returnUrl = 'settings.php#gear-account-tools';
}
$bioLen = function_exists('mb_strlen') ? mb_strlen($form['bio']) : strlen($form['bio']);
$phoneRequired = !publisher_is_publisher_user($dbh, $meId);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Account</title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link rel="stylesheet" href="./css/shamcey.css">
  <style>
    html:has(body.accounts-page),
    body.accounts-page,
    html[data-theme] body.accounts-page,
    html[data-msb-appearance] body.accounts-page,
    body.accounts-page.feed-insta-ui{
      background:#0b0f19 !important;
      color:#f4f6fb !important;
    }
    body.accounts-page .sh-mainpanel,
    body.accounts-page .sh-pagebody,
    body.accounts-page.feed-insta-ui .sh-pagebody{
      background:transparent !important;
      color:#f4f6fb !important;
    }
    body.accounts-page .sh-pagebody{padding:36px 24px 56px !important;}
    .acc-wrap{
      max-width:860px;margin:0 auto;
      font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;
    }
    .acc-back{
      display:inline-flex;margin:0 0 18px;font-size:13px;font-weight:600;
      color:#8b949e;text-decoration:none;
    }
    .acc-back:hover{color:#fff;text-decoration:none;}
    .acc-head{
      display:flex;align-items:flex-start;justify-content:space-between;gap:16px;
      margin:0 0 28px;
    }
    .acc-title{
      margin:0;font-size:32px;line-height:1.15;font-weight:700;letter-spacing:-.03em;color:#fff;
    }
    .acc-sub{
      margin:8px 0 0;font-size:14px;line-height:1.45;color:#8b949e;font-weight:500;
    }
    .acc-save{
      display:inline-flex;align-items:center;justify-content:center;
      height:40px;padding:0 18px;border:0;border-radius:8px;
      background:#5b3fd4;color:#fff;font-size:14px;font-weight:600;cursor:pointer;
      box-shadow:none;
    }
    .acc-save:hover{background:#5136c4;color:#fff;}
    .acc-card{
      background:transparent !important;
      border:1px solid #2a3344 !important;
      border-radius:14px;padding:24px 26px;margin:0 0 20px;
      box-shadow:none !important;
    }
    .acc-card h2{
      margin:0 0 18px;font-size:16px;line-height:1.3;font-weight:700;color:#fff;
    }
    .acc-card-lead{
      margin:-10px 0 18px;font-size:13px;line-height:1.45;color:#8b949e;font-weight:500;
    }
    .acc-profile{display:grid;grid-template-columns:96px minmax(0,1fr);gap:28px;align-items:start;}
    .acc-avatar-wrap{position:relative;width:96px;height:96px;flex:0 0 96px;}
    .acc-avatar{
      width:96px;height:96px;border-radius:50%;object-fit:cover;display:block;background:#1c2433;
    }
    .acc-cam{
      position:absolute;right:-2px;bottom:-2px;width:28px;height:28px;border-radius:50%;
      border:2px solid #0b0f19;background:#1b2332;color:#fff;
      display:inline-flex;align-items:center;justify-content:center;cursor:pointer;font-size:13px;
    }
    .acc-cam input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
    .acc-fields{display:grid;gap:16px;}
    .acc-field{display:grid;gap:8px;min-width:0;}
    .acc-field label{
      font-size:12px;line-height:1;font-weight:600;color:#8b949e;
    }
    .acc-field input,.acc-field textarea{
      width:100%;box-sizing:border-box;border-radius:8px;
      border:1px solid var(--msb-palette-border-strong, var(--msb-palette-border, #d3d3d3)) !important;
      background:var(--msb-palette-input-bg, var(--msb-palette-bg, #f5f7fb)) !important;
      color:var(--msb-palette-text, #0b1220) !important;
      font-size:14px;font-weight:500;outline:none;
      box-shadow:none !important;
      -webkit-appearance:none;appearance:none;
    }
    .acc-field input{height:42px;padding:0 12px;}
    .acc-field textarea{min-height:88px;padding:10px 12px 22px;resize:none;line-height:1.5;}
    .acc-field input:focus,.acc-field textarea:focus{
      border-color:#6d4de8 !important;box-shadow:0 0 0 3px rgba(91,63,212,.18) !important;
    }
    .acc-bio{position:relative;}
    .acc-count{
      position:absolute;right:10px;bottom:8px;font-size:11px;color:#6b7385;font-weight:600;pointer-events:none;
    }
    .acc-split{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
    .acc-email-row{
      display:flex;align-items:center;justify-content:space-between;gap:16px;
    }
    .acc-email-copy{min-width:0;}
    .acc-email-copy > .acc-k{
      display:block;font-size:12px;font-weight:600;color:#8b949e;margin:0 0 8px;
    }
    .acc-email-value{
      display:flex;align-items:center;gap:10px;flex-wrap:wrap;
      font-size:15px;font-weight:600;color:#fff;
    }
    .acc-verified{
      display:inline-flex;align-items:center;height:22px;padding:0 8px;border-radius:6px;
      border:1px solid #22c55e;background:transparent;color:#4ade80;font-size:11px;font-weight:700;
    }
    .acc-ghost{
      display:inline-flex;align-items:center;justify-content:center;
      height:38px;padding:0 14px;border-radius:8px;
      border:1px solid #3a4456 !important;background:transparent !important;
      color:#e8edf5 !important;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;
    }
    .acc-ghost:hover{background:#161d2b !important;color:#fff !important;text-decoration:none;}
    .acc-email-edit{display:none;margin-top:14px;}
    .acc-email-edit.is-on{display:block;}
    .acc-meta{display:grid;gap:0;}
    .acc-meta-row{
      display:flex;align-items:center;justify-content:space-between;gap:16px;
      padding:15px 0;border-bottom:1px solid #232b3a;font-size:14px;
    }
    .acc-meta-row:first-child{padding-top:2px;}
    .acc-meta-row:last-child{border-bottom:0;padding-bottom:2px;}
    .acc-meta-row span{color:#8b949e;font-weight:500;}
    .acc-meta-row b a.acc-ghost{height:32px;padding:0 10px;font-size:12px;}
    .acc-dot{
      display:inline-block;width:8px;height:8px;border-radius:50%;background:#22c55e;
      margin-right:8px;vertical-align:middle;
    }
    .acc-dot.is-off{background:#6b7385;}
    .acc-alert{border-radius:10px;padding:12px 14px;margin:0 0 18px;font-size:14px;font-weight:600;}
    .acc-alert.err{background:rgba(185,28,28,.16);color:#fecaca;border:1px solid rgba(248,113,113,.35);}
    .acc-alert.ok{background:rgba(22,163,74,.14);color:#bbf7d0;border:1px solid rgba(74,222,128,.28);}
    @media (max-width:720px){
      .acc-profile,.acc-split{grid-template-columns:1fr;}
      .acc-title{font-size:28px;}
      .acc-save{width:100%;}
      .acc-email-row{align-items:flex-start;flex-direction:column;}
    }
  </style>
</head>
<body class="accounts-page">
<?php $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>
<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="acc-wrap">
      <a class="acc-back" href="<?php echo h($returnUrl); ?>">‹ Back to Gear</a>
      <form method="post" id="accountsForm">
        <div class="acc-head">
          <div>
            <h1 class="acc-title">Account</h1>
            <p class="acc-sub">Manage your personal information and account details.</p>
          </div>
          <button class="acc-save" type="submit">Save changes</button>
        </div>

        <?php if ($errors): ?>
          <div class="acc-alert err"><?php echo h(implode(' ', $errors)); ?></div>
        <?php elseif ($saved): ?>
          <div class="acc-alert ok">Account details saved.</div>
        <?php endif; ?>
        <?php if ($storedMobileInvalid): ?>
          <div class="acc-alert err">Your saved phone number was invalid and has been cleared. Enter a valid phone number (7–15 digits) and save.</div>
        <?php endif; ?>

        <section class="acc-card">
          <h2>Profile Information</h2>
          <div class="acc-profile">
            <div class="acc-avatar-wrap">
              <img class="acc-avatar" id="accAvatar" src="<?php echo h($avatarUrl); ?>" alt="Profile photo" data-live-avatar="1" data-avatar-base="<?php echo h('avatar.php?u=' . $meId . '&name=' . rawurlencode($form['full_name'] !== '' ? $form['full_name'] : $form['username'])); ?>">
              <label class="acc-cam" title="Change photo">
                <i class="icon ion-camera"></i>
                <input type="file" id="accAvatarFile" accept="image/*">
              </label>
            </div>
            <div class="acc-fields">
              <div class="acc-field">
                <label for="full_name">Full name</label>
                <input id="full_name" name="full_name" type="text" value="<?php echo h($form['full_name']); ?>" required>
              </div>
              <div class="acc-field">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" value="<?php echo h($form['username']); ?>" required>
              </div>
              <div class="acc-field acc-bio">
                <label for="bio">Bio</label>
                <textarea id="bio" name="bio" maxlength="160"><?php echo h($form['bio']); ?></textarea>
                <span class="acc-count" id="accBioCount"><?php echo (int)$bioLen; ?>/160</span>
              </div>
              <div class="acc-split">
                <div class="acc-field">
                  <label for="location">Location</label>
                  <input id="location" name="location" type="text" value="<?php echo h($form['location']); ?>" placeholder="City, Country">
                </div>
                <div class="acc-field">
                  <label for="website">Website</label>
                  <input id="website" name="website" type="text" inputmode="url" value="<?php echo h($form['website']); ?>" placeholder="https://">
                </div>
              </div>
              <div class="acc-split">
                <div class="acc-field">
                  <label for="mobile">Phone number</label>
                  <input id="mobile" name="mobile" type="tel" value="<?php echo h($form['mobile']); ?>" autocomplete="tel" inputmode="tel" pattern="[0-9+\-\s()]{7,20}"<?php echo $phoneRequired ? ' required' : ''; ?>>
                </div>
                <div class="acc-field">
                  <label for="friend_code">Friend code</label>
                  <input id="friend_code" name="friend_code" type="text" value="<?php echo h($form['friend_code']); ?>" required>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section class="acc-card">
          <h2>Email address</h2>
          <p class="acc-card-lead">This is the email associated with your account.</p>
          <div class="acc-email-row">
            <div class="acc-email-copy">
              <span class="acc-k">Email</span>
              <div class="acc-email-value">
                <span id="accEmailText"><?php echo h($form['email']); ?></span>
                <?php if ($form['email'] !== ''): ?>
                  <span class="acc-verified">Verified</span>
                <?php endif; ?>
              </div>
            </div>
            <button class="acc-ghost" type="button" id="accChangeEmail">Change email</button>
          </div>
          <div class="acc-email-edit" id="accEmailEdit">
            <div class="acc-field">
              <label for="email">New email</label>
              <input id="email" name="email" type="email" value="<?php echo h($form['email']); ?>">
            </div>
          </div>
        </section>

        <section class="acc-card">
          <h2>Account information</h2>
          <div class="acc-meta">
            <div class="acc-meta-row"><span>Date joined</span><b><?php echo h($joinedLabel); ?></b></div>
            <div class="acc-meta-row"><span>Account status</span><b><i class="acc-dot<?php echo $isActive ? '' : ' is-off'; ?>"></i><?php echo $isActive ? 'Active' : 'Inactive'; ?></b></div>
            <div class="acc-meta-row"><span>Country</span><b><?php echo h($countryLabel); ?></b></div>
            <div class="acc-meta-row"><span>User ID</span><b><?php echo h($userIdLabel); ?></b></div>
            <div class="acc-meta-row"><span>Password</span><b><a class="acc-ghost" href="change-password.php?return=<?php echo rawurlencode('accounts.php'); ?>">Change in Security</a></b></div>
            <div class="acc-meta-row"><span>About background</span><b><a class="acc-ghost" href="user_edit.php?return=<?php echo rawurlencode('accounts.php'); ?>">Edit background</a></b></div>
          </div>
        </section>
      </form>
    </div>
  </div>
</div>
<script>
(function(){
  var bio = document.getElementById('bio');
  var count = document.getElementById('accBioCount');
  if (bio && count) {
    function sync(){ count.textContent = String((bio.value || '').length) + '/160'; }
    bio.addEventListener('input', sync);
  }
  var changeBtn = document.getElementById('accChangeEmail');
  var edit = document.getElementById('accEmailEdit');
  if (changeBtn && edit) {
    changeBtn.addEventListener('click', function(){
      edit.classList.add('is-on');
      var input = document.getElementById('email');
      if (input) input.focus();
    });
  }
  var file = document.getElementById('accAvatarFile');
  var img = document.getElementById('accAvatar');
  if (!file || !img) return;
  file.addEventListener('change', function(){
    if (!file.files || !file.files[0]) return;
    var fd = new FormData();
    fd.append('kind', 'avatar');
    fd.append('media', file.files[0]);
    fetch('save_gear_media.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(res){ return res.json(); }).then(function(data){
      if (!data || !data.ok) throw new Error('upload failed');
      var base = img.getAttribute('data-avatar-base') || img.getAttribute('src') || '';
      base = base.replace(/([?&])v=\d+/g, '$1').replace(/[?&]$/, '');
      img.setAttribute('src', base + (base.indexOf('?') >= 0 ? '&' : '?') + 'v=' + Date.now());
    }).catch(function(){}).finally(function(){ file.value = ''; });
  });
})();
</script>
</body>
</html>
