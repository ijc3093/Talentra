<?php
// /Business_only3/public_user/user_edit.php

declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
requireUserLogin();

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/profile_access.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/user_phone.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/user_backgrounds.php';
require_once __DIR__ . '/includes/profile_people_tags.php';
$controller = new Controller();
$dbh = $controller->pdo();

error_reporting(E_ALL);
ini_set('display_errors', '0');

$meId = profile_session_owner_user_id();
if ($meId <= 0) {
    header('Location: index.php?session=reset');
    exit;
}

profile_require_edit_access($dbh, $meId, false);

if (!function_exists('h')) {
    function h(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

function clean_text($value, int $max = 255): string {
    $value = trim((string)$value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

function clean_multiline($value, int $max = 5000): string {
    $value = trim((string)$value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

function existing_columns(PDO $dbh, string $table): array {
    $cols = [];
    try {
        $st = $dbh->query("SHOW COLUMNS FROM `{$table}`");
        while ($row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false) {
            $field = (string)($row['Field'] ?? '');
            if ($field !== '') $cols[$field] = true;
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $cols;
}

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$usersCols = existing_columns($dbh, 'users');
$hasBackgroundTable = false;
try {
    $chk = $dbh->query("SHOW TABLES LIKE 'user_backgrounds'");
    $hasBackgroundTable = (bool)($chk && $chk->fetchColumn());
} catch (Throwable $e) {
    $hasBackgroundTable = false;
}
$bgCols = $hasBackgroundTable ? existing_columns($dbh, 'user_backgrounds') : [];

$returnRaw = trim((string)($_REQUEST['return'] ?? ''));
$defaultReturn = 'profile.php?tab=about&updated=1';
$returnTo = $defaultReturn;
if ($returnRaw !== '' && !preg_match('~^(?:https?:)?//~i', $returnRaw) && strpos($returnRaw, '\n') === false && strpos($returnRaw, '\r') === false) {
    $returnTo = ltrim($returnRaw, '/');
}

$flash = '';
$errors = [];
$storedMobileInvalid = false;

$form = [
    'full_name' => '',
    'username' => '',
    'email' => '',
    'mobile' => '',
    'friend_code' => '',
    'gender' => '',
    'designation' => '',
    'pronouns' => '',
    'born_in' => '',
    'lives_in' => '',
    'birthday' => '',
    'relationship_status' => '',
    'languages' => '',
    'family_details' => '',
    'education_history' => '',
    'work_details' => '',
    'hobbies' => '',
    'profile_link' => '',
    'about_text' => '',
];

try {
    $st = $dbh->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $st->execute([':id' => $meId]);
    $user = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($user) {
        if (user_phone_repair_invalid_mobile($dbh, $meId, $user)) {
            $storedMobileInvalid = true;
            $user['mobile'] = '';
        }
        $form['full_name'] = trim((string)($user['fullname'] ?? $user['name'] ?? ''));
        $form['username'] = trim((string)($user['username'] ?? ''));
        $form['email'] = trim((string)($user['email'] ?? ''));
        $form['mobile'] = user_phone_for_display(trim((string)($user['mobile'] ?? '')));
        if ($form['mobile'] === '' && !$storedMobileInvalid) {
            $rawMobile = user_phone_raw_from_user_row($user);
            if (user_phone_is_valid($rawMobile)) {
                $form['mobile'] = user_phone_normalize($rawMobile);
            }
        }
        $form['friend_code'] = trim((string)($user['friend_code'] ?? ''));
        $form['gender'] = trim((string)($user['gender'] ?? ''));
        $form['designation'] = trim((string)($user['designation'] ?? ''));
    }
} catch (Throwable $e) {
    $errors[] = 'Could not load your user details.';
}

if ($hasBackgroundTable) {
    try {
        $bg = user_background_load($dbh, $meId);
        foreach ($bg as $k => $v) {
            $form[$k] = $v;
        }
        if (trim($form['birthday']) === '' && isset($user['birthday'])) {
            $fromUser = trim((string)$user['birthday']);
            if ($fromUser !== '' && !str_starts_with($fromUser, '0000-00-00')) {
                $form['birthday'] = $fromUser;
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Could not load your background details.';
    }
}

profile_people_tags_ensure_table($dbh);
$peopleRelationship = profile_people_tags_get_relationship($dbh, $meId);
$peopleFamily = profile_people_tags_list_family($dbh, $meId);
if ($peopleRelationship) {
    $form['relationship_status'] = profile_people_tags_format_relationship($peopleRelationship, (string)$form['relationship_status']);
}
if ($peopleFamily !== []) {
    $form['family_details'] = profile_people_tags_format_family($peopleFamily, (string)$form['family_details']);
}

function user_edit_apply_about_post(array &$form, array $post): void
{
    $multiline = ['family_details', 'education_history', 'work_details', 'hobbies'];
    $userFields = ['gender' => 150, 'designation' => 255];
    $bgFields = ['pronouns', 'born_in', 'birthday', 'relationship_status', 'languages', 'family_details', 'education_history', 'work_details', 'hobbies'];
    foreach ($userFields as $key => $limit) {
        if (!array_key_exists($key, $post)) {
            continue;
        }
        $form[$key] = clean_text((string)$post[$key], $limit);
    }
    foreach ($bgFields as $key) {
        if (!array_key_exists($key, $post)) {
            continue;
        }
        $form[$key] = in_array($key, $multiline, true)
            ? clean_multiline($post[$key])
            : clean_text((string)$post[$key], 150);
    }
}

function user_edit_save_about_row(PDO $dbh, int $meId, array $form, array $usersCols, bool $hasBackgroundTable): void
{
    $userUpdates = [];
    $userParams = [':id' => $meId];
    foreach (['gender', 'designation'] as $field) {
        if (isset($usersCols[$field])) {
            $userUpdates[] = "{$field} = :{$field}";
            $userParams[":{$field}"] = $form[$field];
        }
    }
    if ($userUpdates) {
        $sql = 'UPDATE users SET ' . implode(', ', $userUpdates) . ' WHERE id = :id LIMIT 1';
        $st = $dbh->prepare($sql);
        $st->execute($userParams);
    }
    if ($hasBackgroundTable) {
        user_background_save($dbh, $meId, $form);
        user_background_sync_users_birthday($dbh, $meId, $form['birthday'], $usersCols);
    }
}

$ajaxAction = trim((string)($_REQUEST['ajax'] ?? ''));
if ($ajaxAction === 'edit_about') {
    json_response([
        'ok' => true,
        'form' => $form,
    ]);
}

if ($ajaxAction === 'save_about' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    user_edit_apply_about_post($form, $_POST);
    try {
        $dbh->beginTransaction();
        user_edit_save_about_row($dbh, $meId, $form, $usersCols, $hasBackgroundTable);
        $dbh->commit();
        json_response([
            'ok' => true,
            'message' => 'About details saved.',
            'form' => $form,
        ]);
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) $dbh->rollBack();
        json_response([
            'ok' => false,
            'error' => 'Save failed. Please check the table fields and try again.',
        ], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    user_edit_apply_about_post($form, $_POST);
    if (!$errors) {
        try {
            $dbh->beginTransaction();
            user_edit_save_about_row($dbh, $meId, $form, $usersCols, $hasBackgroundTable);
            $dbh->commit();
            header('Location: ' . $returnTo);
            exit;
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) $dbh->rollBack();
            $errors[] = 'Save failed. Please check the table fields and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Edit background</title>
  <?php theme_prefs_print_head_bootstrap($dbh, $meId); ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
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
    .acc-avatar{
      width:96px;height:96px;border-radius:50%;object-fit:cover;display:block;background:#1c2433;
    }
    .acc-fields{display:grid;gap:16px;}
    .acc-field{display:grid;gap:8px;min-width:0;}
    .acc-field.full{grid-column:1/-1;}
    .acc-field label{
      font-size:12px;line-height:1;font-weight:600;color:#8b949e;
    }
    .acc-field input,.acc-field textarea,.acc-field select{
      width:100%;box-sizing:border-box;border-radius:8px;
      border:1px solid var(--msb-palette-border-strong, var(--msb-palette-border, #d3d3d3)) !important;
      background:var(--msb-palette-input-bg, var(--msb-palette-bg, #f5f7fb)) !important;
      color:var(--msb-palette-text, #0b1220) !important;
      font-size:14px;font-weight:500;outline:none;
      box-shadow:none !important;
      -webkit-appearance:none;appearance:none;
    }
    .acc-field input,.acc-field select{height:42px;padding:0 12px;}
    .acc-field textarea{min-height:110px;padding:10px 12px;resize:none;line-height:1.5;}
    .acc-field input:focus,.acc-field textarea:focus,.acc-field select:focus{
      border-color:#6d4de8 !important;box-shadow:0 0 0 3px rgba(91,63,212,.18) !important;
    }
    .acc-split{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
    .acc-muted{font-size:12px;color:#8b949e;font-weight:500;}
    .acc-alert{border-radius:10px;padding:12px 14px;margin:0 0 18px;font-size:14px;font-weight:600;}
    .acc-alert.err{background:rgba(185,28,28,.16);color:#fecaca;border:1px solid rgba(248,113,113,.35);}
    .acc-alert.note{background:rgba(91,63,212,.14);color:#c4b5fd;border:1px solid rgba(109,77,232,.35);}
    .acc-value{font-size:14px;font-weight:600;color:#fff;margin:0 0 8px;}
    .about-people{margin-top:4px;display:flex;flex-direction:column;gap:8px;max-width:100%;}
    .about-people-row,.about-people-actions{display:flex;flex-wrap:wrap;align-items:center;gap:8px;}
    .about-people-role,.about-people-mention{
      height:42px;border-radius:8px;border:1px solid var(--msb-palette-border-strong, #d3d3d3);background:var(--msb-palette-input-bg, var(--msb-palette-bg, #f5f7fb));color:var(--msb-palette-text, #0b1220);
      font-size:13px;font-weight:600;padding:0 12px;
    }
    .about-people-mention{flex:1;min-width:160px;}
    .about-people-tag-row{position:relative;width:100%;}
    .about-people-ac{
      position:absolute;left:0;right:0;top:100%;z-index:40;margin-top:4px;
      background:var(--msb-palette-input-bg, var(--msb-palette-bg, #f5f7fb));border:1px solid var(--msb-palette-border-strong, #d3d3d3);border-radius:10px;
      box-shadow:0 12px 28px rgba(0,0,0,.35);max-height:220px;overflow:auto;padding:4px;
    }
    .about-people-ac-item{
      display:flex;flex-direction:column;align-items:flex-start;gap:2px;width:100%;
      border:0;background:transparent;cursor:pointer;text-align:left;padding:8px 10px;border-radius:8px;color:#f4f6fb;
    }
    .about-people-ac-item:hover{background:#1b2332;}
    .about-people-ac-user{font-size:13px;font-weight:700;}
    .about-people-ac-name{font-size:12px;opacity:.7;}
    .about-people-ac-empty{padding:10px 12px;font-size:12px;opacity:.7;color:#8b949e;}
    .about-people-picked{font-size:12px;font-weight:600;color:#8b949e;}
    .about-people-save{
      height:36px;padding:0 14px;border:0;border-radius:8px;cursor:pointer;
      background:#5b3fd4;color:#fff;font-size:12px;font-weight:700;
    }
    .about-people-msg{font-size:12px;font-weight:700;color:#fecaca;}
    .about-people-msg.is-ok{color:#4ade80;}
    .about-people-chips{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:6px;}
    .about-people-chips li{
      display:flex;align-items:center;justify-content:space-between;gap:8px;
      padding:8px 10px;border-radius:8px;background:var(--msb-palette-input-bg, var(--msb-palette-bg, #f5f7fb));border:1px solid var(--msb-palette-border-strong, #d3d3d3);
      font-size:13px;font-weight:600;color:#f4f6fb;
    }
    .about-people-remove{border:0;background:transparent;cursor:pointer;font-size:18px;color:#8b949e;}
    @media (max-width:720px){
      .acc-profile,.acc-split{grid-template-columns:1fr;}
      .acc-title{font-size:28px;}
      .acc-save{width:100%;}
    }
  </style>
</head>
<body class="accounts-page">
<?php $forceFeedRail = true; $skipHeaderThemeBootstrap = true; include __DIR__ . '/includes/header.php'; ?>
<div class="sh-mainpanel">
  <div class="sh-pagebody">
    <div class="acc-wrap">
      <a class="acc-back" href="<?php echo h($returnTo); ?>">‹ Back</a>
      <form id="editBackgroundForm" method="post" action="user_edit.php">
        <input type="hidden" name="return" value="<?php echo h($returnTo); ?>">
        <div class="acc-head">
          <div>
            <h1 class="acc-title">Edit background</h1>
            <p class="acc-sub">About details only. Name, username, email, phone, bio, location, and website live on <a class="acc-back" href="accounts.php?return=<?php echo rawurlencode($returnTo); ?>" style="margin:0;">Account</a>.</p>
          </div>
          <button class="acc-save" type="submit">Save changes</button>
        </div>

        <?php if ($errors): ?>
          <div class="acc-alert err">
            <?php foreach ($errors as $i => $err): ?>
              <?php echo $i > 0 ? ' ' : ''; ?><?php echo h($err); ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (!$hasBackgroundTable): ?>
          <div class="acc-alert note">The user_backgrounds table was not found. About background fields need your background SQL table.</div>
        <?php endif; ?>

        <section class="acc-card">
          <h2>About background</h2>
          <p class="acc-card-lead">Pronouns, family, work history, and other About tab details. Account identity stays on Account.</p>
          <div class="acc-fields">
            <div class="acc-split">
              <div class="acc-field">
                <label for="gender">Gender</label>
                <input id="gender" name="gender" type="text" value="<?php echo h($form['gender']); ?>">
              </div>
              <div class="acc-field">
                <label for="designation">Work / designation</label>
                <input id="designation" name="designation" type="text" value="<?php echo h($form['designation']); ?>">
              </div>
            </div>
            <div class="acc-split">
              <div class="acc-field">
                <label for="pronouns">Pronouns</label>
                <input id="pronouns" name="pronouns" type="text" value="<?php echo h($form['pronouns']); ?>" placeholder="He / Him, She / Her, They / Them">
              </div>
              <div class="acc-field">
                <label for="born_in">When born</label>
                <input id="born_in" name="born_in" type="text" value="<?php echo h($form['born_in']); ?>" placeholder="Dallas, Texas or 1998">
              </div>
            </div>
            <div class="acc-split">
              <div class="acc-field">
                <label for="birthday">Birthday date</label>
                <input id="birthday" name="birthday" type="text" value="<?php echo h($form['birthday']); ?>" placeholder="May 14">
              </div>
              <div class="acc-field">
                <label for="languages">Languages</label>
                <input id="languages" name="languages" type="text" value="<?php echo h($form['languages']); ?>" placeholder="English, French, Spanish">
              </div>
            </div>
            <div class="acc-field">
              <label>Relationship</label>
              <input type="hidden" id="relationship_status" name="relationship_status" value="<?php echo h($form['relationship_status']); ?>">
              <div class="acc-value" data-people-value><?php echo $peopleRelationship ? profile_people_tags_relationship_html($peopleRelationship, (string)$form['relationship_status']) : h((string)$form['relationship_status']); ?></div>
              <?php profile_people_tags_render_relationship_editor($peopleRelationship ?? null); ?>
              <div class="acc-muted">Type @username to tag someone. They get a notification.</div>
            </div>
            <div class="acc-field">
              <label>Family</label>
              <input type="hidden" id="family_details" name="family_details" value="<?php echo h($form['family_details']); ?>">
              <div class="acc-value" data-people-value><?php echo $peopleFamily ? profile_people_tags_family_html($peopleFamily, (string)$form['family_details']) : nl2br(h((string)$form['family_details'])); ?></div>
              <?php profile_people_tags_render_family_editor($peopleFamily ?? []); ?>
              <div class="acc-muted">Tag father, mother, brother, sister, and others with @username.</div>
            </div>
            <div class="acc-field">
              <label for="education_history">Education</label>
              <textarea id="education_history" name="education_history"><?php echo h($form['education_history']); ?></textarea>
            </div>
            <div class="acc-field">
              <label for="work_details">Work</label>
              <textarea id="work_details" name="work_details"><?php echo h($form['work_details']); ?></textarea>
            </div>
            <div class="acc-field">
              <label for="hobbies">Hobby</label>
              <textarea id="hobbies" name="hobbies"><?php echo h($form['hobbies']); ?></textarea>
            </div>
          </div>
        </section>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/mention_autocomplete.js.php'; ?>
<?php include __DIR__ . '/includes/profile_people_tags.js.php'; ?>
</body>
</html>
