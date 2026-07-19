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
    'social_facebook' => '',
    'social_instagram' => '',
    'social_x' => '',
    'social_linkedin' => '',
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
        $st = $dbh->prepare('SELECT * FROM user_backgrounds WHERE user_id = :uid LIMIT 1');
        $st->execute([':uid' => $meId]);
        $bg = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($bg) {
            $accountFields = ['full_name', 'username', 'email', 'mobile', 'friend_code', 'gender', 'designation'];
            foreach ($form as $k => $v) {
                if (in_array($k, $accountFields, true)) {
                    continue;
                }
                if (array_key_exists($k, $bg)) {
                    $form[$k] = trim((string)$bg[$k]);
                }
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'Could not load your background details.';
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
    foreach (array_keys($form) as $key) {
        if (in_array($key, ['family_details', 'education_history', 'work_details', 'hobbies', 'about_text'], true)) {
            $form[$key] = clean_multiline($_POST[$key] ?? '');
        } else {
            $limit = in_array($key, ['social_facebook', 'social_instagram', 'social_x', 'social_linkedin', 'email'], true) ? 255 : 150;
            if ($key === 'full_name') $limit = 120;
            if ($key === 'username') $limit = 60;
            if ($key === 'friend_code') $limit = 30;
            if ($key === 'designation') $limit = 255;
            $form[$key] = clean_text($_POST[$key] ?? '', $limit);
        }
    }

    $apiErrors = [];
    if ($form['full_name'] === '') $apiErrors[] = 'Full name is required.';
    if ($form['email'] === '') $apiErrors[] = 'Email address is required.';
    if ($form['friend_code'] === '') $apiErrors[] = 'Friend code is required.';
    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $apiErrors[] = 'Email address is not valid.';
    }
    if ($form['mobile'] !== '' && !user_phone_is_valid($form['mobile'])) {
        $apiErrors[] = 'Phone number must contain 7 to 15 digits.';
    } elseif (!publisher_is_publisher_user($dbh, $meId) && $form['mobile'] === '') {
        $apiErrors[] = 'Phone number is required for personal accounts.';
    }

    try {
        $dup = $dbh->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $dup->execute([':email' => $form['email'], ':id' => $meId]);
        if ($dup->fetchColumn()) $apiErrors[] = 'That email address is already in use.';
    } catch (Throwable $e) {}

    if ($form['username'] !== '') {
        try {
            $dup = $dbh->prepare('SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1');
            $dup->execute([':username' => $form['username'], ':id' => $meId]);
            if ($dup->fetchColumn()) $apiErrors[] = 'That username is already in use.';
        } catch (Throwable $e) {}
    }

    if ($form['friend_code'] !== '') {
        try {
            $dup = $dbh->prepare('SELECT id FROM users WHERE friend_code = :friend_code AND id <> :id LIMIT 1');
            $dup->execute([':friend_code' => $form['friend_code'], ':id' => $meId]);
            if ($dup->fetchColumn()) $apiErrors[] = 'That friend code is already in use.';
        } catch (Throwable $e) {}
    }

    if ($apiErrors) {
        json_response([
            'ok' => false,
            'error' => implode(' ', $apiErrors),
            'errors' => $apiErrors,
        ], 422);
    }

    if ($form['mobile'] !== '') {
        $form['mobile'] = user_phone_normalize($form['mobile']);
    }

    try {
        $dbh->beginTransaction();

        $userUpdates = [];
        $userParams = [':id' => $meId];

        if (isset($usersCols['fullname'])) {
            $userUpdates[] = 'fullname = :full_name';
            $userParams[':full_name'] = $form['full_name'];
        } elseif (isset($usersCols['name'])) {
            $userUpdates[] = 'name = :full_name';
            $userParams[':full_name'] = $form['full_name'];
        }
        foreach (['username', 'email', 'mobile', 'friend_code', 'gender', 'designation'] as $field) {
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
            $bgFields = [
                'pronouns','born_in','lives_in','birthday','relationship_status','languages',
                'family_details','education_history','work_details','hobbies',
                'social_facebook','social_instagram','social_x','social_linkedin','about_text'
            ];
            $insertCols = ['user_id'];
            $insertPlaceholders = [':user_id'];
            $updateCols = [];
            $bgParams = [':user_id' => $meId];
            foreach ($bgFields as $field) {
                if (!isset($bgCols[$field])) continue;
                $insertCols[] = $field;
                $insertPlaceholders[] = ':' . $field;
                $updateCols[] = "{$field} = VALUES({$field})";
                $bgParams[':' . $field] = $form[$field];
            }
            if (count($insertCols) > 1) {
                $sql = 'INSERT INTO user_backgrounds (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $insertPlaceholders) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updateCols);
                $st = $dbh->prepare($sql);
                $st->execute($bgParams);
            }
        }

        $dbh->commit();
        $_SESSION['user_name'] = $form['full_name'];
        $_SESSION['user_login'] = $form['username'];
        $_SESSION['user_email'] = $form['email'];
        $_SESSION['user_friend_code'] = $form['friend_code'];

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
    foreach (array_keys($form) as $key) {
        if (in_array($key, ['family_details', 'education_history', 'work_details', 'hobbies', 'about_text'], true)) {
            $form[$key] = clean_multiline($_POST[$key] ?? '');
        } else {
            $limit = in_array($key, ['social_facebook', 'social_instagram', 'social_x', 'social_linkedin', 'email'], true) ? 255 : 150;
            if ($key === 'full_name') $limit = 120;
            if ($key === 'username') $limit = 60;
            if ($key === 'friend_code') $limit = 30;
            if ($key === 'designation') $limit = 255;
            $form[$key] = clean_text($_POST[$key] ?? '', $limit);
        }
    }

    if ($form['full_name'] === '') $errors[] = 'Full name is required.';
    if ($form['email'] === '') $errors[] = 'Email address is required.';
    if ($form['friend_code'] === '') $errors[] = 'Friend code is required.';
    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is not valid.';
    }
    if ($form['mobile'] !== '' && !user_phone_is_valid($form['mobile'])) {
        $errors[] = 'Phone number must contain 7 to 15 digits.';
    } elseif (!publisher_is_publisher_user($dbh, $meId) && $form['mobile'] === '') {
        $errors[] = 'Phone number is required for personal accounts.';
    }

    try {
        $dup = $dbh->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $dup->execute([':email' => $form['email'], ':id' => $meId]);
        if ($dup->fetchColumn()) $errors[] = 'That email address is already in use.';
    } catch (Throwable $e) {}

    if ($form['username'] !== '') {
        try {
            $dup = $dbh->prepare('SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1');
            $dup->execute([':username' => $form['username'], ':id' => $meId]);
            if ($dup->fetchColumn()) $errors[] = 'That username is already in use.';
        } catch (Throwable $e) {}
    }

    if ($form['friend_code'] !== '') {
        try {
            $dup = $dbh->prepare('SELECT id FROM users WHERE friend_code = :friend_code AND id <> :id LIMIT 1');
            $dup->execute([':friend_code' => $form['friend_code'], ':id' => $meId]);
            if ($dup->fetchColumn()) $errors[] = 'That friend code is already in use.';
        } catch (Throwable $e) {}
    }

    if (!$errors) {
        if ($form['mobile'] !== '') {
            $form['mobile'] = user_phone_normalize($form['mobile']);
        }

        try {
            $dbh->beginTransaction();

            $userUpdates = [];
            $userParams = [':id' => $meId];

            if (isset($usersCols['fullname'])) {
                $userUpdates[] = 'fullname = :full_name';
                $userParams[':full_name'] = $form['full_name'];
            } elseif (isset($usersCols['name'])) {
                $userUpdates[] = 'name = :full_name';
                $userParams[':full_name'] = $form['full_name'];
            }
            foreach (['username', 'email', 'mobile', 'friend_code', 'gender', 'designation'] as $field) {
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
                $bgFields = [
                    'pronouns','born_in','lives_in','birthday','relationship_status','languages',
                    'family_details','education_history','work_details','hobbies',
                    'social_facebook','social_instagram','social_x','social_linkedin','about_text'
                ];
                $insertCols = ['user_id'];
                $insertPlaceholders = [':user_id'];
                $updateCols = [];
                $bgParams = [':user_id' => $meId];
                foreach ($bgFields as $field) {
                    if (!isset($bgCols[$field])) continue;
                    $insertCols[] = $field;
                    $insertPlaceholders[] = ':' . $field;
                    $updateCols[] = "{$field} = VALUES({$field})";
                    $bgParams[':' . $field] = $form[$field];
                }
                if (count($insertCols) > 1) {
                    $sql = 'INSERT INTO user_backgrounds (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $insertPlaceholders) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updateCols);
                    $st = $dbh->prepare($sql);
                    $st->execute($bgParams);
                }
            }

            $dbh->commit();
            $_SESSION['user_name'] = $form['full_name'];
            $_SESSION['user_login'] = $form['username'];
            $_SESSION['user_email'] = $form['email'];
            $_SESSION['user_friend_code'] = $form['friend_code'];

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
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link rel="stylesheet" href="./css/shamcey.css">
  <style>
    body{background:var(--msb-palette-bg,#f8fafc);color:var(--msb-palette-text,#0f172a);}
    .edit-wrap{max-width:1040px;margin:24px auto;padding:0 14px;}
    .edit-card{background:var(--msb-palette-surface-2,#fff);border:1px solid var(--msb-palette-border,rgba(15,23,42,.08));border-radius:22px;box-shadow:0 18px 48px rgba(15,23,42,.08);overflow:hidden;}
    .edit-head{padding:22px 22px 10px;border-bottom:1px solid var(--msb-palette-border,rgba(15,23,42,.08));display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
    .edit-title{font-size:24px;font-weight:800;color:var(--msb-palette-text,#0f172a);margin:0;}
    .edit-sub{font-size:14px;color:var(--msb-palette-text-muted,#64748b);}
    .edit-actions{display:flex;gap:10px;flex-wrap:wrap;}
    .btnx{display:inline-flex;align-items:center;gap:8px;justify-content:center;height:42px;padding:0 16px;border-radius:12px;border:1px solid var(--msb-palette-border,rgba(15,23,42,.12));background:var(--msb-palette-surface-2,#fff);color:var(--msb-palette-text,#0f172a);font-weight:800;text-decoration:none;cursor:pointer;}
    .btnx.primary{background:#111827;color:#fff;border-color:#111827;}
    .edit-body{padding:20px;}
    .section{margin-bottom:22px;}
    .section h3{font-size:16px;font-weight:800;color:var(--msb-palette-text,#0f172a);margin:0 0 12px;}
    .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;}
    .field{display:flex;flex-direction:column;gap:7px;}
    .field.full{grid-column:1/-1;}
    .field label{font-size:13px;font-weight:700;color:var(--msb-palette-text-muted,#334155);}
    .field input,.field textarea,.field select{width:100%;border:1px solid var(--msb-palette-border,#dbe1ea);border-radius:12px;padding:12px 14px;font-size:14px;color:var(--msb-palette-text,#0f172a);background:var(--msb-palette-bg,#fff);outline:none;}
    .field textarea{min-height:110px;resize:none;}
    .field input:focus,.field textarea:focus,.field select:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.12);}
    .muted{font-size:12px;color:#64748b;}
    .alert{border-radius:14px;padding:12px 14px;font-size:14px;margin-bottom:16px;}
    .alert.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
    .alert.note{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;}
    .avatar-line{display:flex;align-items:center;gap:12px;margin-top:8px;}
    .avatar-line img{width:54px;height:54px;border-radius:50%;object-fit:cover;border:2px solid rgba(15,23,42,.08);background:#f1f5f9;}
    @media (max-width: 800px){.grid{grid-template-columns:1fr;}.edit-head{padding:18px 16px 10px;}.edit-body{padding:16px;}.btnx{width:100%;}}
  </style>
</head>
<body>
<div class="edit-wrap">
  <div class="edit-card">
    <div class="edit-head">
      <div>
        <h1 class="edit-title">Edit background</h1>
        <div class="edit-sub">Update your About tab details, save, and return to your profile.</div>
      </div>
      <div class="edit-actions">
        <a class="btnx" href="<?php echo h($returnTo); ?>"><i class="icon ion-arrow-left-c"></i> Back</a>
        <button class="btnx primary" form="editBackgroundForm" type="submit"><i class="icon ion-checkmark"></i> Save changes</button>
      </div>
    </div>
    <div class="edit-body">
      <?php if ($errors): ?>
        <div class="alert err">
          <?php foreach ($errors as $i => $err): ?>
            <?php echo $i > 0 ? '<br>' : ''; ?><?php echo h($err); ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($storedMobileInvalid): ?>
        <div class="alert note">Your saved phone number was invalid and has been cleared. Please enter a valid phone number (digits only, 7–15 numbers) and save.</div>
      <?php endif; ?>

      <?php if (!$hasBackgroundTable): ?>
        <div class="alert note">The <b>user_backgrounds</b> table was not found. Basic user details can still save, but About background fields need your background SQL table.</div>
      <?php endif; ?>

      <form id="editBackgroundForm" method="post" action="user_edit.php">
        <input type="hidden" name="return" value="<?php echo h($returnTo); ?>">

        <div class="section">
          <h3>Basic profile</h3>
          <div class="avatar-line">
            <img src="avatar.php?email=<?php echo rawurlencode($form['email']); ?>&amp;name=<?php echo rawurlencode($form['full_name'] !== '' ? $form['full_name'] : 'User'); ?>" alt="Avatar">
            <div class="muted">Your avatar, name, contact details, and public profile basics.</div>
          </div>
          <div class="grid" style="margin-top:14px;">
            <div class="field">
              <label for="full_name">Full name</label>
              <input id="full_name" name="full_name" type="text" value="<?php echo h($form['full_name']); ?>" required>
            </div>
            <div class="field">
              <label for="username">Username</label>
              <input id="username" name="username" type="text" value="<?php echo h($form['username']); ?>">
            </div>
            <div class="field">
              <label for="email">Email address</label>
              <input id="email" name="email" type="email" value="<?php echo h($form['email']); ?>" required>
            </div>
            <div class="field">
              <label for="mobile">Phone number</label>
              <input id="mobile" name="mobile" type="tel" value="<?php echo h($form['mobile']); ?>" autocomplete="tel" inputmode="tel" pattern="[0-9+\-\s()]{7,20}" required>
            </div>
            <div class="field">
              <label for="friend_code">Friend code</label>
              <input id="friend_code" name="friend_code" type="text" value="<?php echo h($form['friend_code']); ?>" required>
            </div>
            <div class="field">
              <label for="gender">Gender</label>
              <input id="gender" name="gender" type="text" value="<?php echo h($form['gender']); ?>">
            </div>
            <div class="field full">
              <label for="designation">Work / designation</label>
              <input id="designation" name="designation" type="text" value="<?php echo h($form['designation']); ?>">
            </div>
          </div>
        </div>

        <div class="section">
          <h3>About background</h3>
          <div class="grid">
            <div class="field">
              <label for="pronouns">Pronouns</label>
              <input id="pronouns" name="pronouns" type="text" value="<?php echo h($form['pronouns']); ?>" placeholder="He / Him, She / Her, They / Them">
            </div>
            <div class="field">
              <label for="born_in">When born</label>
              <input id="born_in" name="born_in" type="text" value="<?php echo h($form['born_in']); ?>" placeholder="Dallas, Texas or 1998">
            </div>
            <div class="field">
              <label for="lives_in">Where you live</label>
              <input id="lives_in" name="lives_in" type="text" value="<?php echo h($form['lives_in']); ?>">
            </div>
            <div class="field">
              <label for="birthday">Birthday date</label>
              <input id="birthday" name="birthday" type="text" value="<?php echo h($form['birthday']); ?>" placeholder="May 14">
            </div>
            <div class="field">
              <label for="relationship_status">Relationship</label>
              <input id="relationship_status" name="relationship_status" type="text" value="<?php echo h($form['relationship_status']); ?>" placeholder="Single, Married, In a relationship">
            </div>
            <div class="field">
              <label for="languages">Languages</label>
              <input id="languages" name="languages" type="text" value="<?php echo h($form['languages']); ?>" placeholder="English, French, Spanish">
            </div>
            <div class="field full">
              <label for="family_details">Family</label>
              <textarea id="family_details" name="family_details"><?php echo h($form['family_details']); ?></textarea>
            </div>
            <div class="field full">
              <label for="education_history">Education</label>
              <textarea id="education_history" name="education_history"><?php echo h($form['education_history']); ?></textarea>
            </div>
            <div class="field full">
              <label for="work_details">Work</label>
              <textarea id="work_details" name="work_details"><?php echo h($form['work_details']); ?></textarea>
            </div>
            <div class="field full">
              <label for="hobbies">Hobby</label>
              <textarea id="hobbies" name="hobbies"><?php echo h($form['hobbies']); ?></textarea>
            </div>
          </div>
        </div>

        <div class="section">
          <h3>Social media</h3>
          <div class="grid">
            <div class="field">
              <label for="social_facebook">Facebook</label>
              <input id="social_facebook" name="social_facebook" type="text" value="<?php echo h($form['social_facebook']); ?>">
            </div>
            <div class="field">
              <label for="social_instagram">Instagram</label>
              <input id="social_instagram" name="social_instagram" type="text" value="<?php echo h($form['social_instagram']); ?>">
            </div>
            <div class="field">
              <label for="social_x">X / Twitter</label>
              <input id="social_x" name="social_x" type="text" value="<?php echo h($form['social_x']); ?>">
            </div>
            <div class="field">
              <label for="social_linkedin">LinkedIn</label>
              <input id="social_linkedin" name="social_linkedin" type="text" value="<?php echo h($form['social_linkedin']); ?>">
            </div>
            <div class="field full">
              <label for="about_text">About me</label>
              <textarea id="about_text" name="about_text" placeholder="Tell your story here..."><?php echo h($form['about_text']); ?></textarea>
            </div>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>
