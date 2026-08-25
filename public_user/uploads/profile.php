<?php
// /Business_only3/public_user/profile.php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/includes/admin_profile_preview.php';

$__profileReqId = (int)($_GET['id'] ?? 0);
$__adminProfilePreview = ($__profileReqId > 0
    && isset($_GET['from_admin'])
    && admin_profile_preview_validate($__profileReqId));

if ($__adminProfilePreview) {
    admin_profile_preview_mark_active();
} else {
    requireUserLogin();
}

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/friend_system.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/post_categories.php';
require_once __DIR__ . '/includes/post_layout.php';
require_once __DIR__ . '/includes/post_media_stage.css.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/profile_access.php';
require_once __DIR__ . '/includes/theme_prefs.php';
require_once __DIR__ . '/includes/user_phone.php';
require_once __DIR__ . '/includes/publisher_authority.php';
require_once __DIR__ . '/includes/post_card_actions_menu.php';
require_once __DIR__ . '/includes/post_tags.php';
require_once __DIR__ . '/includes/appearance_palettes.php';
require_once __DIR__ . '/includes/post_action_thin_icons.php';
$controller = new Controller();
$dbh = $controller->pdo();
ensurePostCategorySchema($dbh);
publisher_ensure_schema($dbh);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$sessionOwnerId = profile_session_owner_user_id();
$meId = $sessionOwnerId > 0 ? $sessionOwnerId : (int)($_SESSION['user_id'] ?? 0);
$viewId = $meId;
$profileAlertPostId = (int)($_GET['open_post'] ?? $_GET['post'] ?? 0);
$profileAlertCommentId = (int)($_GET['open_comment'] ?? 0);

$reqId = (int)($_GET['id'] ?? 0);
$reqUsername = trim((string)($_GET['username'] ?? $_GET['u'] ?? ''));
$reqFriendCode = strtoupper(trim((string)($_GET['friend_code'] ?? '')));
$requestedPeer = ($reqFriendCode !== '' || $reqUsername !== '' || $reqId > 0);
$peerFound = false;

// -------- fetch viewed user ----------
$me = [
  'avatar_image_path' => '',
  'cover_image_path' => '',
  'avatar_image_path' => '',
  'cover_image_path' => '',
  'id' => $meId,
  'name' => '',
  'username' => '',
  'email' => '',
  'gender' => '',
  'mobile' => '',
  'designation' => '',
  'role' => '',
  'status' => '',
  'created_at' => '',
  'friend_code' => '',
  'account_kind' => 'personal',
];

if (!function_exists('profileNormalizeUserRow')) {
  function profileNormalizeUserRow(array $row): array {
    $accountKind = strtolower(trim((string)($row['account_kind'] ?? 'personal')));
    if ($accountKind !== 'publisher') {
      $accountKind = 'personal';
    }
    return [
      'id' => (string)($row['id'] ?? '0'),
      'name' => trim((string)($row['fullname'] ?? '')) !== '' ? trim((string)($row['fullname'] ?? '')) : trim((string)($row['name'] ?? '')),
      'username' => trim((string)($row['username'] ?? '')),
      'email' => trim((string)($row['email'] ?? '')),
      'gender' => trim((string)($row['gender'] ?? '')),
      'mobile' => user_phone_raw_from_user_row($row),
      'designation' => trim((string)($row['designation'] ?? '')),
      'role' => trim((string)($row['role'] ?? '')),
      'status' => trim((string)($row['status'] ?? '')),
      'created_at' => trim((string)($row['created_at'] ?? '')),
      'friend_code' => trim((string)($row['friend_code'] ?? '')),
      'account_kind' => $accountKind,
    ];
  }
}

if (!function_exists('profileFetchUserRow')) {
  function profileFetchUserRow(PDO $dbh, string $whereSql, array $params): array {
    $sql = "SELECT * FROM users WHERE {$whereSql} LIMIT 1";
    $st = $dbh->prepare($sql);
    $st->execute($params);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('profile_ensure_user_registration_columns')) {
  function profile_ensure_user_registration_columns(PDO $dbh): void
  {
    static $done = false;
    if ($done) {
      return;
    }
    $done = true;

    $definitions = [
      'birthday' => 'ALTER TABLE users ADD COLUMN birthday DATE NULL DEFAULT NULL',
      'policy_agreed' => 'ALTER TABLE users ADD COLUMN policy_agreed TINYINT(1) NOT NULL DEFAULT 0',
      'policy_agreed_at' => 'ALTER TABLE users ADD COLUMN policy_agreed_at DATETIME NULL DEFAULT NULL',
      'age_confirmed' => 'ALTER TABLE users ADD COLUMN age_confirmed TINYINT(1) NOT NULL DEFAULT 0',
      'age_confirmed_at' => 'ALTER TABLE users ADD COLUMN age_confirmed_at DATETIME NULL DEFAULT NULL',
    ];

    foreach ($definitions as $column => $sql) {
      if (!publisher_db_column_exists($dbh, 'users', $column)) {
        try {
          $dbh->exec($sql);
        } catch (Throwable $e) {
          // Non-fatal.
        }
      }
    }
  }
}

if (!function_exists('profile_format_registration_birthday')) {
  function profile_format_registration_birthday(string $birthdayIso): string
  {
    $birthdayIso = trim($birthdayIso);
    if ($birthdayIso === '') {
      return '';
    }

    try {
      $dt = new DateTimeImmutable($birthdayIso);
      return $dt->format('F j, Y');
    } catch (Throwable $e) {
      return $birthdayIso;
    }
  }
}

if (!function_exists('profile_format_consent_status')) {
  function profile_format_consent_status(bool $confirmed, string $confirmedAt): string
  {
    if (!$confirmed) {
      return 'Not recorded';
    }

    $confirmedAt = trim($confirmedAt);
    if ($confirmedAt !== '') {
      $timestamp = strtotime($confirmedAt);
      if ($timestamp) {
        return 'Yes — ' . date('F j, Y', $timestamp);
      }
    }

    return 'Yes';
  }
}

if (!function_exists('profile_load_registration_fields')) {
  function profile_load_registration_fields(PDO $dbh, int $userId): array
  {
    $fields = [
      'birthday' => '',
      'mobile' => '',
      'policy_label' => '',
      'age_label' => '',
      'policy_agreed' => false,
      'age_confirmed' => false,
    ];
    if ($userId <= 0) {
      return $fields;
    }

    profile_ensure_user_registration_columns($dbh);

    $columns = ['mobile'];
    foreach (['birthday', 'age', 'policy_agreed', 'policy_agreed_at', 'age_confirmed', 'age_confirmed_at'] as $column) {
      if (publisher_db_column_exists($dbh, 'users', $column)) {
        $columns[] = $column;
      }
    }

    try {
      $st = $dbh->prepare('SELECT ' . implode(', ', $columns) . ' FROM users WHERE id = :id LIMIT 1');
      $st->execute([':id' => $userId]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      if ($row === []) {
        return $fields;
      }

      $fields['mobile'] = user_phone_for_display(trim((string)($row['mobile'] ?? '')));

      $birthdayRaw = trim((string)($row['birthday'] ?? ''));
      if ($birthdayRaw === '') {
        $ageRaw = trim((string)($row['age'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ageRaw) === 1) {
          $birthdayRaw = $ageRaw;
        }
      }
      $fields['birthday'] = profile_format_registration_birthday($birthdayRaw);

      $fields['policy_agreed'] = (int)($row['policy_agreed'] ?? 0) === 1;
      $fields['policy_label'] = profile_format_consent_status(
        $fields['policy_agreed'],
        trim((string)($row['policy_agreed_at'] ?? ''))
      );

      $fields['age_confirmed'] = (int)($row['age_confirmed'] ?? 0) === 1;
      $fields['age_label'] = profile_format_consent_status(
        $fields['age_confirmed'],
        trim((string)($row['age_confirmed_at'] ?? ''))
      );
    } catch (Throwable $e) {
      // Non-fatal — About tab falls back to background fields.
    }

    return $fields;
  }
}

/** @deprecated Use profile_load_registration_fields() */
if (!function_exists('profile_load_personal_registration_fields')) {
  function profile_load_personal_registration_fields(PDO $dbh, int $userId): array
  {
    return profile_load_registration_fields($dbh, $userId);
  }
}

if (!function_exists('profile_format_publisher_approval_label')) {
  function profile_format_publisher_approval_label(?array $request): string
  {
    if (!$request) {
      return '';
    }

    $status = strtolower(trim((string)($request['status'] ?? '')));
    $reviewedAt = trim((string)($request['reviewed_at'] ?? ''));
    $dateLabel = '';
    if ($reviewedAt !== '') {
      $timestamp = strtotime($reviewedAt);
      if ($timestamp) {
        $dateLabel = date('F j, Y', $timestamp);
      }
    }

    if ($status === 'approved') {
      return $dateLabel !== '' ? 'Approved — ' . $dateLabel : 'Approved by admin';
    }
    if ($status === 'pending') {
      return 'Pending admin approval';
    }
    if ($status === 'rejected') {
      return $dateLabel !== '' ? 'Rejected — ' . $dateLabel : 'Rejected by admin';
    }

    return '';
  }
}

if (!function_exists('profile_load_publisher_approval_fields')) {
  function profile_load_publisher_approval_fields(PDO $dbh, string $publisherName): array
  {
    $fields = [
      'publisher_name' => publisher_registry_normalize_name($publisherName),
      'status' => 'none',
      'label' => '',
      'review_note' => '',
    ];

    if ($fields['publisher_name'] === '') {
      return $fields;
    }

    $request = publisher_authority_fetch_latest_for_name($dbh, $fields['publisher_name']);
    if (!$request) {
      return $fields;
    }

    $fields['status'] = strtolower(trim((string)($request['status'] ?? 'none')));
    $fields['label'] = profile_format_publisher_approval_label($request);
    $fields['review_note'] = trim((string)($request['review_note'] ?? ''));

    return $fields;
  }
}

if (!function_exists('profile_build_registration_about_cards')) {
  function profile_build_registration_about_cards(array $registration, bool $includeAgeFields, ?array $publisherApproval = null): array
  {
    $cards = [
      [
        'icon' => 'ion-ios-paper',
        'label' => 'Terms & Policy',
        'value' => trim((string)($registration['policy_label'] ?? '')),
        'empty_text' => 'Not recorded at registration',
      ],
    ];

    if ($includeAgeFields) {
      $cards[] = [
        'icon' => 'ion-ios-checkmark',
        'label' => 'Age confirmation',
        'value' => trim((string)($registration['age_label'] ?? '')),
        'empty_text' => 'Not recorded at registration',
      ];
    }

    if ($publisherApproval !== null) {
      $approvalLabel = trim((string)($publisherApproval['label'] ?? ''));
      $reviewNote = trim((string)($publisherApproval['review_note'] ?? ''));
      if ($reviewNote !== '' && $approvalLabel !== '') {
        $approvalLabel .= ' — ' . $reviewNote;
      }

      $cards[] = [
        'icon' => 'ion-ios-checkmark-circle',
        'label' => 'Publisher name approval',
        'value' => $approvalLabel,
        'empty_text' => 'No admin approval recorded yet',
      ];
    }

    return $cards;
  }
}

if (!function_exists('profile_gear_group_slug')) {
  function profile_gear_group_slug(string $title): string
  {
    return 'gear-' . strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
  }
}

if (!function_exists('profile_gear_row_value')) {
  function profile_gear_row_value(array $row, array $profileSettings, string $themeAutoDefault): string
  {
    $field = trim((string)($row['field'] ?? ''));
    $fieldLocal = trim((string)($row['field_local'] ?? ''));
    if (array_key_exists('default_value', $row)) {
      return (string)$row['default_value'];
    }
    if ($fieldLocal === 'theme_auto_enabled') {
      return $themeAutoDefault;
    }
    if ($field !== '' && array_key_exists($field, $profileSettings)) {
      return (string)$profileSettings[$field];
    }
    return '';
  }
}

if (!function_exists('profile_gear_row_value_label')) {
  function profile_gear_row_value_label(array $row, array $profileSettings, string $themeAutoDefault): string
  {
    $val = profile_gear_row_value($row, $profileSettings, $themeAutoDefault);
    $controlType = strtolower(trim((string)($row['control'] ?? 'select')));
    if ($controlType === 'color') {
      $hex = appearance_palette_parse_custom_hex($val);
      if ($hex !== null) {
        return strtoupper($hex);
      }
      if ($val !== '' && $val !== 'system' && appearance_palette_is_valid_slug($val)) {
        return strtoupper(appearance_palette_hex_for_slug($val));
      }
      return strtoupper((string)($row['default_value'] ?? '#8D514F'));
    }
    foreach ((array)($row['options'] ?? []) as $ov => $ol) {
      if ((string)$ov === $val) {
        return (string)$ol;
      }
    }
    foreach ((array)($row['option_groups'] ?? []) as $group) {
      foreach ((array)($group['options'] ?? []) as $ov => $ol) {
        if ((string)$ov === $val) {
          return (string)$ol;
        }
      }
    }
    $custom = appearance_palette_parse_custom_hex($val);
    if ($custom !== null) {
      return 'Progress color';
    }
    return '';
  }
}

if (!function_exists('profile_gear_render_detail_action')) {
  function profile_gear_render_detail_action(array $row, array $profileSettings, string $themeAutoDefault): void
  {
    $href = trim((string)($row['href'] ?? ''));
    $isLink = $href !== '';
    $field = trim((string)($row['field'] ?? ''));
    $fieldLocal = trim((string)($row['field_local'] ?? ''));
    $options = (array)($row['options'] ?? []);
    $optionGroups = (array)($row['option_groups'] ?? []);
    $controlType = strtolower(trim((string)($row['control'] ?? 'select')));
    $mediaKind = trim((string)($row['media_kind'] ?? ''));
    $hasColor = (!$isLink && $field !== '' && $controlType === 'color');
    $hasControl = (!$isLink && ($field !== '' || $fieldLocal !== '') && (!$hasColor) && (!empty($options) || !empty($optionGroups)));
    $hasUpload = (!$isLink && in_array($mediaKind, ['avatar', 'cover'], true));
    $currentValue = profile_gear_row_value($row, $profileSettings, $themeAutoDefault);
    $tag = trim((string)($row['tag'] ?? ''));

    if ($hasColor):
      $colorValue = appearance_palette_parse_custom_hex($currentValue)
        ?? (appearance_palette_is_valid_slug($currentValue) && !in_array($currentValue, ['system', 'light', 'dark'], true)
          ? strtolower(appearance_palette_hex_for_slug($currentValue))
          : '#8d514f');
      if ($colorValue !== '' && $colorValue[0] !== '#') {
        $colorValue = '#' . $colorValue;
      }
      ?>
      <div class="gear-detail-control">
        <label class="gear-detail-control-label" for="<?php echo h('gear-ctrl-progress-color'); ?>">Pick background color</label>
        <div class="gear-progress-picker" id="gearProgressPicker" aria-label="Color picker">
          <div
            class="gear-progress-sv"
            id="gearProgressSv"
            role="slider"
            tabindex="0"
            aria-label="Saturation and brightness"
            aria-valuetext="<?php echo h(strtoupper($colorValue)); ?>"
          >
            <span class="gear-progress-sv-thumb" id="gearProgressSvThumb" aria-hidden="true"></span>
          </div>
          <div class="gear-progress-hue-row">
            <span class="gear-progress-swatch" id="gearProgressSwatch" aria-hidden="true"></span>
            <input
              type="range"
              class="gear-progress-hue"
              id="gearProgressHue"
              min="0"
              max="360"
              step="1"
              value="0"
              aria-label="Hue"
            >
          </div>
        </div>
        <div class="gear-control-wrap gear-control-wrap--detail gear-control-wrap--color">
          <input
            type="color"
            id="gear-ctrl-progress-color"
            class="gear-control gear-color-control"
            data-field="<?php echo h($field); ?>"
            data-progress-color="1"
            data-autosave="0"
            value="<?php echo h($colorValue); ?>"
            title="Progress color"
            aria-label="Progress color"
          >
          <span class="gear-color-hex" id="gearProgressColorHex"><?php echo h(strtoupper($colorValue)); ?></span>
          <button type="button" class="gear-progress-save-btn" id="gearProgressColorSave" disabled>Save</button>
          <span class="gear-save-state" id="gearProgressColorState" aria-live="polite"></span>
        </div>
        <p class="gear-progress-save-hint">Move the compass on the color screen, or slide hue. Press Save to apply across the app.</p>
      </div>
    <?php elseif ($hasControl): ?>
      <div class="gear-detail-control">
        <label class="gear-detail-control-label" for="<?php echo h('gear-ctrl-' . ($field !== '' ? $field : $fieldLocal)); ?>">Choose setting</label>
        <div class="gear-control-wrap gear-control-wrap--detail">
          <select
            id="<?php echo h('gear-ctrl-' . ($field !== '' ? $field : $fieldLocal)); ?>"
            class="gear-control<?php echo $fieldLocal !== '' ? ' js-theme-local-control' : ''; ?><?php echo $field === 'appearance_mode' ? ' gear-appearance-select' : ''; ?>"
            <?php if ($field !== ''): ?>data-field="<?php echo h($field); ?>"<?php endif; ?>
            <?php if ($fieldLocal !== ''): ?>data-local-field="<?php echo h($fieldLocal); ?>"<?php endif; ?>
          >
            <?php if (!empty($optionGroups)): ?>
              <?php foreach ($optionGroups as $group): ?>
                <optgroup label="<?php echo h((string)($group['label'] ?? '')); ?>">
                  <?php foreach ((array)($group['options'] ?? []) as $ov => $ol): ?>
                    <option value="<?php echo h((string)$ov); ?>" <?php echo $currentValue === (string)$ov ? 'selected' : ''; ?>><?php echo h((string)$ol); ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            <?php else: ?>
              <?php foreach ($options as $ov => $ol): ?>
                <option value="<?php echo h((string)$ov); ?>" <?php echo $currentValue === (string)$ov ? 'selected' : ''; ?>><?php echo h((string)$ol); ?></option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
          <span class="gear-save-state" aria-live="polite"></span>
        </div>
      </div>
    <?php elseif ($hasUpload): ?>
      <form class="gear-upload-form gear-upload-form--detail" data-kind="<?php echo h($mediaKind); ?>" enctype="multipart/form-data">
        <input type="file" name="media" accept="image/*" class="gear-upload-input" id="gear-upload-<?php echo h($mediaKind); ?>">
        <label class="gear-upload-btn" for="gear-upload-<?php echo h($mediaKind); ?>"><?php echo $mediaKind === 'avatar' ? 'Choose photo' : 'Choose cover'; ?></label>
        <span class="gear-upload-hint"><?php echo $mediaKind === 'avatar' ? 'JPG or PNG recommended.' : 'Wide images work best for cover.'; ?></span>
        <span class="gear-save-state" aria-live="polite"></span>
      </form>
    <?php elseif ($isLink): ?>
      <a class="gear-detail-open-btn" href="<?php echo h($href); ?>">
        <i class="icon <?php echo h((string)($row['icon'] ?? 'ion-ios-arrow-forward')); ?>"></i>
        <?php echo h($tag !== '' ? $tag : 'Open'); ?>
      </a>
    <?php elseif ($tag !== ''): ?>
      <span class="gear-tag"><?php echo h($tag); ?></span>
    <?php endif;
  }
}

if (!function_exists('profile_load_user_mobile')) {
  function profile_load_user_mobile(PDO $dbh, int $userId): string
  {
    if ($userId <= 0) {
      return '';
    }

    try {
      $st = $dbh->prepare('SELECT mobile FROM users WHERE id = :id LIMIT 1');
      $st->execute([':id' => $userId]);
      return user_phone_for_display(trim((string)($st->fetchColumn() ?: '')));
    } catch (Throwable $e) {
      return '';
    }
  }
}

$profileUserRow = [];
try {
  $row = [];
  if ($reqFriendCode !== '') {
    $row = profileFetchUserRow($dbh, "UPPER(TRIM(COALESCE(friend_code, ''))) = :friend_code", [':friend_code' => $reqFriendCode]);
  }
  if (!$row && $reqUsername !== '') {
    $row = profileFetchUserRow($dbh, "TRIM(COALESCE(username, '')) = :username", [':username' => $reqUsername]);
  }
  if (!$row && $reqId > 0) {
    $row = profileFetchUserRow($dbh, "id = :id", [':id' => $reqId]);
  }
  if (!$row && !$requestedPeer && $meId > 0) {
    $row = profileFetchUserRow($dbh, "id = :id", [':id' => $meId]);
  }

  if ($row) {
    $profileUserRow = $row;
    $peerFound = true;
    $norm = profileNormalizeUserRow($row);
    $viewId = (int)($norm['id'] !== '' ? $norm['id'] : $meId);
    foreach ($me as $k => $v) {
      if (array_key_exists($k, $norm)) $me[$k] = (string)$norm[$k];
    }
  } elseif (!$requestedPeer && $meId > 0) {
    $viewId = $meId;
  }
} catch (Throwable $e) {}

if ($meId > 0 && $viewId > 0 && !publisher_profile_can_view_user($dbh, $meId, $viewId)) {
  header('Location: feed.php');
  exit;
}

// -------- stats ----------
$statPosts = 0;
try {
  $st = $dbh->prepare("SELECT COUNT(*) FROM public_posts WHERE user_id = :me AND (is_deleted = 0 OR is_deleted IS NULL)");
  $st->execute([':me' => $viewId]);
  $statPosts = (int)$st->fetchColumn();
} catch (Throwable $e) {}

$statFriends = 0;
try {
  $statFriends = fs_friend_count($dbh, $viewId);
} catch (Throwable $e) {}

$statFollowing = 0;
try {
  $st = $dbh->prepare("SELECT COUNT(*) FROM public_follows WHERE follower_id = :id");
  $st->execute([':id' => $viewId]);
  $statFollowing = (int)$st->fetchColumn();
} catch (Throwable $e) {}

$friendStatus = 'self';
$incomingRequestId = 0;
if ($meId > 0 && $viewId > 0 && $meId !== $viewId) {
  $friendStatus = fs_friend_status($dbh, $meId, $viewId);
  if ($friendStatus === 'incoming_pending') {
    $incomingRequestId = fs_pending_request_id($dbh, $viewId, $meId);
  }
}

$isViewedPublisher = ($viewId > 0 && $meId !== $viewId && publisher_is_publisher_user($dbh, $viewId));
$isFollowingPublisher = $isViewedPublisher && publisher_user_is_followed($dbh, $meId, $viewId);
$canFollowPublishers = publisher_can_follow_as_viewer($dbh, $meId);
$profileIsPublisher = publisher_is_publisher_user($dbh, $viewId);
if ($profileIsPublisher) {
  $me['account_kind'] = 'publisher';
}
$statSocialCount = $profileIsPublisher ? publisher_follower_count($dbh, $viewId) : $statFriends;
$statSocialLabel = $profileIsPublisher ? publisher_social_stat_label($statSocialCount) : 'friends';

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

// ---- UTF-8 safe helpers ----
if (!function_exists('u_len')) {
  function u_len(string $s): int { return function_exists('mb_strlen') ? (int)mb_strlen($s) : (int)strlen($s); }
}
if (!function_exists('u_sub')) {
  function u_sub(string $s, int $start, int $len): string {
    if (function_exists('mb_substr')) return (string)mb_substr($s, $start, $len);
    return (string)substr($s, $start, $len);
  }
}
if (!function_exists('sentence_snippet')) {
  function sentence_snippet(string $text, int $maxSentences = 3, int $maxChars = 170): string {
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($text === '') return '';
    $parts = preg_split('/(?<=[\.\!\?])\s+/', $text) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts)));
    if (!$parts) return u_len($text) > $maxChars ? (u_sub($text, 0, $maxChars - 1) . '…') : $text;
    $take = array_slice($parts, 0, $maxSentences);
    $out  = trim(implode(' ', $take));
    if (u_len($out) > $maxChars) $out = u_sub($out, 0, $maxChars - 1) . '…';
    if (count($parts) > $maxSentences) $out .= '…';
    return $out;
  }
}
if (!function_exists('is_video_path')) {
  function is_video_path(string $p): bool {
    $p = strtolower(trim($p));
    return (bool)preg_match('/\.(mp4|webm|ogg|mov|m4v)(\?.*)?$/', $p);
  }
}

// detect mobile/tablet
$isMobile = false;
$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua !== '') $isMobile = (bool)preg_match('/(iphone|ipod|ipad|android|mobile|tablet|silk|kindle)/i', $ua);

$isOwnProfile = profile_is_own_account($viewId);
$canManageProfilePrivate = $isOwnProfile && profile_may_edit_account($dbh, $viewId);
// Publisher email, phone, and friend code are visible only to the account owner (not staff or other viewers).
$canViewProfilePrivateContact = !$profileIsPublisher || $canManageProfilePrivate;
$fromLivePublic = (string)($_GET['from_live_public'] ?? '') === '1';
$restrictedLiveView = (string)($_GET['restricted_live_view'] ?? '') === '1';
$liveVisitorMode = (!$isOwnProfile && $fromLivePublic && $restrictedLiveView && $friendStatus !== 'friends');
$selectedTab = strtolower(trim((string)($_GET['tab'] ?? 'posts')));
if ($selectedTab === 'tagged') {
  $selectedTab = 'tags';
}
$galleryVisParam = strtolower(trim((string)($_GET['gallery_vis'] ?? '')));
if (!in_array($galleryVisParam, ['private', 'friends', 'public'], true)) {
  $galleryVisParam = '';
}
if ($galleryVisParam !== '' && $selectedTab !== 'gallery') {
  $selectedTab = 'gallery';
}
$profileContentTabs = ['gallery', 'posts', 'tags', 'about', 'preserve', 'gear'];
if (!in_array($selectedTab, $profileContentTabs, true)) {
  $selectedTab = 'posts';
}
if ($liveVisitorMode && !in_array($selectedTab, ['gallery', 'posts', 'tags', 'about'], true)) {
  $selectedTab = 'posts';
}
if (!$canManageProfilePrivate && in_array($selectedTab, ['gear', 'preserve'], true)) {
  $selectedTab = 'posts';
}
$showUpdated = isset($_GET['updated']) && (string)$_GET['updated'] === '1';
$showPeerNotFound = ($requestedPeer && !$peerFound);
$displayName = trim($me['name']) !== '' ? $me['name'] : ($me['username'] !== '' ? $me['username'] : ($isOwnProfile ? 'My Profile' : 'Profile'));
require_once __DIR__ . '/includes/account_display_helpers.php';
$profileNameParts = account_display_name_parts($displayName, $profileIsPublisher, $dbh);
$profileDisplayName = (string)$profileNameParts['display_name'];
$profileAccountBadge = (string)$profileNameParts['badge'];
$username    = trim($me['username']);
$avatarUrl = 'avatar.php?u=' . (int)$viewId . '&name=' . rawurlencode($displayName);
if ($canViewProfilePrivateContact) {
  $avatarUrl .= '&email=' . rawurlencode($me['email']) . '&friend_code=' . rawurlencode($me['friend_code']);
}
$profileHandleLabel = $username !== ''
  ? ('@' . $username)
  : ($canViewProfilePrivateContact && trim($me['friend_code']) !== '' ? trim($me['friend_code']) : 'Profile');

$joinedLabel = '—';
if (trim($me['created_at']) !== '') {
  $t = strtotime($me['created_at']);
  if ($t) $joinedLabel = date('F Y', $t);
}

$about = [
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
$hasBackgroundTable = false;
try {
  $chk = $dbh->query("SHOW TABLES LIKE 'user_backgrounds'");
  $hasBackgroundTable = (bool)($chk && $chk->fetchColumn());
} catch (Throwable $e) {
  $hasBackgroundTable = false;
}
if ($hasBackgroundTable && $viewId > 0) {
  try {
    $st = $dbh->prepare("SELECT pronouns, born_in, lives_in, birthday, relationship_status, languages, family_details, education_history, work_details, hobbies, social_facebook, social_instagram, social_x, social_linkedin, about_text FROM user_backgrounds WHERE user_id = :uid LIMIT 1");
    $st->execute([':uid' => $viewId]);
    $bg = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($about as $k => $v) {
      if (array_key_exists($k, $bg)) $about[$k] = trim((string)$bg[$k]);
    }
  } catch (Throwable $e) {}
}

$profileRegistration = ($viewId > 0)
  ? profile_load_registration_fields($dbh, $viewId)
  : ['birthday' => '', 'mobile' => '', 'policy_label' => '', 'age_label' => '', 'policy_agreed' => false, 'age_confirmed' => false];
$profileBirthdayValue = !$profileIsPublisher && trim((string)$profileRegistration['birthday']) !== ''
  ? trim((string)$profileRegistration['birthday'])
  : trim((string)$about['birthday']);
$profileShowRegistrationAbout = $canManageProfilePrivate && $viewId > 0;
$profilePublisherApproval = ($profileIsPublisher && $viewId > 0)
  ? profile_load_publisher_approval_fields($dbh, $displayName)
  : null;
$profileRegistrationAboutCards = $profileShowRegistrationAbout
  ? profile_build_registration_about_cards($profileRegistration, !$profileIsPublisher, $profilePublisherApproval)
  : [];
$profilePhoneNeedsFix = false;
if ($canManageProfilePrivate && !$profileIsPublisher && $viewId > 0 && user_phone_repair_invalid_mobile($dbh, $viewId, $profileUserRow)) {
  $profileUserRow['mobile'] = '';
  $me['mobile'] = '';
  $profileRegistration = profile_load_registration_fields($dbh, $viewId);
  $profilePublisherApproval = $profileIsPublisher
    ? profile_load_publisher_approval_fields($dbh, $displayName)
    : null;
  $profileRegistrationAboutCards = profile_build_registration_about_cards($profileRegistration, !$profileIsPublisher, $profilePublisherApproval);
  $profilePhoneNeedsFix = true;
}
$profilePhoneValue = trim((string)($profileRegistration['mobile'] ?? ''));
if ($profilePhoneValue === '') {
  $profilePhoneValue = profile_load_user_mobile($dbh, $viewId);
}
$profilePhoneEmptyText = $profilePhoneNeedsFix
  ? 'Add a valid phone number in Edit background'
  : 'No phone number added yet';

$aboutCards = [
  [
    'icon' => 'ion-person',
    'label' => 'Full name',
    'value' => $displayName,
  ],
  [
    'icon' => 'ion-ios-telephone',
    'label' => 'Phone number',
    'value' => $profilePhoneValue,
    'empty_text' => $profilePhoneEmptyText,
  ],
  [
    'icon' => 'ion-android-mail',
    'label' => 'Email address',
    'value' => trim($me['email']),
  ],
  [
    'icon' => 'ion-ios-people',
    'label' => 'Friend code',
    'value' => trim($me['friend_code']),
  ],
  [
    'icon' => 'ion-transgender',
    'label' => 'Pronouns',
    'value' => $about['pronouns'],
  ],
  [
    'icon' => 'ion-android-calendar',
    'label' => 'When born',
    'value' => $about['born_in'],
  ],
  [
    'icon' => 'ion-location',
    'label' => 'Where you live',
    'value' => $about['lives_in'],
  ],
  [
    'icon' => 'ion-ios-calendar-outline',
    'label' => 'Birthday date',
    'value' => $profileBirthdayValue,
  ],
  [
    'icon' => 'ion-heart',
    'label' => 'Relationship',
    'value' => $about['relationship_status'],
  ],
  [
    'icon' => 'ion-chatbubbles',
    'label' => 'Language',
    'value' => $about['languages'],
  ],
  [
    'icon' => 'ion-male',
    'label' => 'Gender',
    'value' => trim($me['gender']),
  ],
  [
    'icon' => 'ion-home',
    'label' => 'Family',
    'value' => $about['family_details'],
  ],
  [
    'icon' => 'ion-university',
    'label' => 'Education',
    'value' => $about['education_history'],
  ],
  [
    'icon' => 'ion-briefcase',
    'label' => 'Work',
    'value' => $about['work_details'] !== '' ? $about['work_details'] : trim($me['designation']),
  ],
  [
    'icon' => 'ion-happy',
    'label' => 'Hobby',
    'value' => $about['hobbies'],
  ],
  [
    'icon' => 'ion-social-facebook',
    'label' => 'Facebook',
    'value' => $about['social_facebook'],
  ],
  [
    'icon' => 'ion-social-instagram',
    'label' => 'Instagram',
    'value' => $about['social_instagram'],
  ],
  [
    'icon' => 'ion-at',
    'label' => 'X / Twitter',
    'value' => $about['social_x'],
  ],
  [
    'icon' => 'ion-social-linkedin',
    'label' => 'LinkedIn',
    'value' => $about['social_linkedin'],
  ],
];
if ($profileShowRegistrationAbout && $profileRegistrationAboutCards !== []) {
  $insertAt = null;
  foreach ($aboutCards as $index => $card) {
    if (trim((string)($card['label'] ?? '')) === 'Birthday date') {
      $insertAt = $index + 1;
      break;
    }
  }
  if ($insertAt === null) {
    foreach ($aboutCards as $index => $card) {
      if (trim((string)($card['label'] ?? '')) === 'Friend code') {
        $insertAt = $index + 1;
        break;
      }
    }
  }
  if ($insertAt === null) {
    $insertAt = count($aboutCards);
  }

  array_splice($aboutCards, $insertAt, 0, $profileRegistrationAboutCards);
}
if (!$canViewProfilePrivateContact) {
  $aboutCards = array_values(array_filter($aboutCards, static function (array $card): bool {
    $label = trim((string)($card['label'] ?? ''));
    return !in_array($label, ['Phone number', 'Email address', 'Friend code'], true);
  }));
}

if (isset($_GET['ajax']) && (string)$_GET['ajax'] === 'about') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  $items = [];
  foreach ($aboutCards as $card) {
    $label = trim((string)($card['label'] ?? ''));
    if ($label === '') continue;
    $items[] = [
      'icon' => trim((string)($card['icon'] ?? '')),
      'label' => $label,
      'value' => trim((string)($card['value'] ?? '')),
      'empty_text' => trim((string)($card['empty_text'] ?? 'No background added yet')),
    ];
  }

  echo json_encode([
    'ok' => true,
    'tab' => 'about',
    'user' => [
      'id' => $viewId,
      'display_name' => $displayName,
      'username' => $username,
      'handle' => $profileHandleLabel,
      'friend_code' => $canViewProfilePrivateContact ? trim((string)($me['friend_code'] ?? '')) : '',
      'avatar_url' => $avatarUrl,
      'joined_label' => $joinedLabel,
    ],
    'items' => $items,
  ], JSON_UNESCAPED_SLASHES);
  exit;
}



$profileSettings = [
  'avatar_image_path' => '',
  'cover_image_path' => '',
  'profile_visibility' => 'public',
  'about_visibility' => 'friends',
  'gallery_visibility' => 'friends',
  'comment_permission' => 'friends',
  'friend_request_permission' => 'public',
  'message_permission' => 'friends',
  'timeline_visit_approval' => 1,
  'auto_show_timeline' => 1,
  'resurface_old_memories' => 1,
  'show_timeline_reactions' => 1,
  'show_timeline_comments' => 1,
  'archive_memory_enabled' => 0,
  'pin_memory_enabled' => 0,
  'email_notifications' => 1,
  'friend_request_notifications' => 1,
  'comment_notifications' => 1,
  'reaction_notifications' => 1,
  'share_notifications' => 1,
  'blocked_users_enabled' => 1,
  'hidden_users_enabled' => 1,
  'mute_users_enabled' => 1,
  'report_history_enabled' => 1,
  'allow_download_data' => 1,
  'allow_deactivate_account' => 1,
  'allow_delete_account' => 1,
  'allow_logout_all_devices' => 1,
  'appearance_mode' => 'system',
  'theme_auto_enabled' => 1,
  'gallery_grid_size' => 'medium',
  'autoplay_videos' => 1,
  'sound_enabled' => 1,
  'app_language' => 'English',
  'date_format' => 'F j, Y',
  'theme_color' => 'indigo',
];
$hasProfileSettingsTable = false;
try {
  $chk = $dbh->query("SHOW TABLES LIKE 'user_profile_settings'");
  $hasProfileSettingsTable = (bool)($chk && $chk->fetchColumn());
} catch (Throwable $e) {
  $hasProfileSettingsTable = false;
}
if ($hasProfileSettingsTable && $viewId > 0) {
  $settingsUserId = $canManageProfilePrivate ? $sessionOwnerId : $viewId;
  try {
    $st = $dbh->prepare("SELECT * FROM user_profile_settings WHERE user_id = :uid LIMIT 1");
    $st->execute([':uid' => $settingsUserId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($profileSettings as $k => $v) {
      if (array_key_exists($k, $row) && $row[$k] !== null) {
        $profileSettings[$k] = is_numeric($v) ? (int)$row[$k] : trim((string)$row[$k]);
      }
    }
  } catch (Throwable $e) {}
}

$coverUrl = trim((string)($profileSettings['cover_image_path'] ?? ''));
if ($coverUrl !== '') {
  $coverUrl = ltrim(str_replace('\\', '/', $coverUrl), '/');
}

$privacyOptions = [
  'public' => 'Public',
  'friends' => 'Friends',
  'only_me' => 'Only me',
  'approved_visitors' => 'Approved visitors',
];
$yesNoOptions = [
  '1' => 'Yes',
  '0' => 'No',
];
$themeAutoOptions = [
  '1' => 'On',
  '0' => 'Off',
];
$appearanceModeOptionGroups = appearance_palette_groups_for_select();
$storedAppearanceMode = appearance_palette_normalize_mode((string)($profileSettings['appearance_mode'] ?? 'system'));
$progressColorDefault = appearance_palette_parse_custom_hex($storedAppearanceMode)
  ?? (
    appearance_bridge_is_named_palette($storedAppearanceMode)
      ? strtolower(appearance_palette_hex_for_slug($storedAppearanceMode))
      : '#8d514f'
  );
$appearanceModeOptions = [
  'light' => 'Light',
  'dark' => 'Dark',
];
$gridSizeOptions = [
  'small' => 'Small',
  'medium' => 'Medium',
  'large' => 'Large',
];
$languageOptions = [
  'English' => 'English',
  'French' => 'French',
  'Spanish' => 'Spanish',
  'German' => 'German',
  'Portuguese' => 'Portuguese',
  'Arabic' => 'Arabic',
];
$dateFormatOptions = [
  'F j, Y' => 'March 8, 2026',
  'm/d/Y' => '03/08/2026',
  'd/m/Y' => '08/03/2026',
  'Y-m-d' => '2026-03-08',
  'M j, Y' => 'Mar 8, 2026',
];
$themeColorOptions = [
  'indigo' => 'Indigo',
  'blue' => 'Blue',
  'emerald' => 'Emerald',
  'rose' => 'Rose',
  'amber' => 'Amber',
];
$themeAutoDefault = appearance_bridge_theme_auto_enabled(
  $dbh,
  ($canManageProfilePrivate && $sessionOwnerId > 0) ? $sessionOwnerId : max(0, (int)$viewId)
) ? '1' : '0';
$manualAppearanceDefault = $storedAppearanceMode;

$gearGroups = [
  [
    'title' => 'Profile settings',
    'nav_label' => 'Edit Profile',
    'icon' => 'ion-ios-person',
    'desc' => 'Manage the profile identity and look of your page.',
    'rows' => [
      [
        'label' => 'Edit Profile',
        'meta' => 'Open user_edit.php for name, username, email, phone, friend code, gender, work and about details.',
        'href' => 'user_edit.php?return=' . rawurlencode('profile.php?tab=gear'),
        'icon' => 'ion-edit',
        'tag'  => 'Open',
      ],
      [
        'label' => 'Change avatar',
        'meta' => 'Upload a real photo so avatar initials become your photo across header, profile, timeline, messages, contacts, and more.',
        'icon' => 'ion-camera',
        'tag'  => 'Upload',
        'media_kind' => 'avatar',
      ],
      [
        'label' => 'Change cover / background image',
        'meta' => 'Upload a story cover that appears at the top of your profile.',
        'icon' => 'ion-image',
        'tag'  => 'Upload',
        'media_kind' => 'cover',
      ],
      [
        'label' => 'Change display name',
        'meta' => 'Use Edit Profile to change the name shown at the top of the profile.',
        'href' => 'user_edit.php?return=' . rawurlencode('profile.php?tab=gear'),
        'icon' => 'ion-person',
        'tag'  => 'Edit',
      ],
      [
        'label' => 'Change username',
        'meta' => 'Use Edit Profile to update your username.',
        'href' => 'user_edit.php?return=' . rawurlencode('profile.php?tab=gear'),
        'icon' => 'ion-at',
        'tag'  => 'Edit',
      ],
      [
        'label' => 'Change theme color',
        'meta' => 'Pick a theme color for your profile control center.',
        'icon' => 'ion-paintbrush',
        'tag'  => 'Live',
        'field' => 'theme_color',
        'options' => $themeColorOptions,
      ],
    ],
  ],
  [
    'title' => 'Privacy controls',
    'nav_label' => 'Privacy',
    'icon' => 'ion-locked',
    'desc' => 'Control who can view your profile, About, Gallery, comments, friend requests, messages, and timeline visits.',
    'chips' => ['Public', 'Friends', 'Only me', 'Approved visitors'],
    'rows' => [
      ['label' => 'Who can view profile', 'meta' => 'Choose Public, Friends, Only me, or Approved visitors.', 'icon' => 'ion-person-stalker', 'tag' => 'Live', 'field' => 'profile_visibility', 'options' => $privacyOptions],
      ['label' => 'Who can view About', 'meta' => 'Protect your life details and contact information.', 'icon' => 'ion-ios-person', 'tag' => 'Live', 'field' => 'about_visibility', 'options' => $privacyOptions],
      ['label' => 'Who can view Gallery', 'meta' => 'Control who can see your photo and video grids.', 'icon' => 'ion-images', 'tag' => 'Live', 'field' => 'gallery_visibility', 'options' => $privacyOptions],
      ['label' => 'Who can comment on posts', 'meta' => 'Limit comments to friends or approved visitors when you are ready.', 'icon' => 'ion-chatbubbles', 'tag' => 'Live', 'field' => 'comment_permission', 'options' => $privacyOptions],
      ['label' => 'Who can send friend request', 'meta' => 'Choose who is allowed to connect with you.', 'icon' => 'ion-person-add', 'tag' => 'Live', 'field' => 'friend_request_permission', 'options' => $privacyOptions],
      ['label' => 'Who can message me', 'meta' => 'Control DM access before private chat opens.', 'icon' => 'ion-email', 'tag' => 'Live', 'field' => 'message_permission', 'options' => $privacyOptions],
      ['label' => 'Allow timeline visit by approval only', 'meta' => 'Strong Talentra feature for consent-based timeline access.', 'icon' => 'ion-clock', 'tag' => 'Live', 'field' => 'timeline_visit_approval', 'options' => $yesNoOptions],
    ],
  ],
  [
    'title' => 'Timeline / memory controls',
    'nav_label' => 'Timeline Settings',
    'icon' => 'ion-ios-book',
    'desc' => 'Shape how memories appear, resurface, and stay meaningful on your life timeline.',
    'rows' => [
      ['label' => 'Archived posts', 'meta' => 'Open the private list of posts you hid from feeds.', 'href' => 'archive.php', 'icon' => 'ion-ios-box', 'tag' => 'Open'],
      ['label' => 'Bookmarks', 'meta' => 'Open posts and stories you saved from For You, Discover, Reels, or Profile.', 'href' => 'bookmark.php', 'icon' => 'ion-ios-bookmarks', 'tag' => 'Open'],
      ['label' => 'Auto-show posts in timeline', 'meta' => 'Yes / No control for moving Dashboard posts into the life timeline automatically.', 'icon' => 'ion-ios-albums', 'tag' => 'Live', 'field' => 'auto_show_timeline', 'options' => $yesNoOptions],
      ['label' => 'Allow old memories to resurface', 'meta' => 'Bring older moments back later as meaningful memories.', 'icon' => 'ion-refresh', 'tag' => 'Live', 'field' => 'resurface_old_memories', 'options' => $yesNoOptions],
      ['label' => 'Show reactions in timeline', 'meta' => 'Decide whether likes and love appear on your life timeline.', 'icon' => 'ion-heart', 'tag' => 'Live', 'field' => 'show_timeline_reactions', 'options' => $yesNoOptions],
      ['label' => 'Show comments in timeline', 'meta' => 'Choose whether comments travel with the timeline story.', 'icon' => 'ion-chatbubble-working', 'tag' => 'Live', 'field' => 'show_timeline_comments', 'options' => $yesNoOptions],
      ['label' => 'Archive memory', 'meta' => 'Hide older moments without deleting them.', 'icon' => 'ion-filing', 'tag' => 'Live', 'field' => 'archive_memory_enabled', 'options' => $yesNoOptions],
      ['label' => 'Pin important memory', 'meta' => 'Keep your most meaningful story near the top.', 'icon' => 'ion-pin', 'tag' => 'Live', 'field' => 'pin_memory_enabled', 'options' => $yesNoOptions],
    ],
  ],
  [
    'title' => 'Archived posts',
    'nav_label' => 'Archived posts',
    'icon' => 'ion-ios-box',
    'desc' => 'Posts you hid from feeds stay here. Only you can open this archive.',
    'rows' => [
      ['label' => 'Open archived posts', 'meta' => 'Review and unarchive posts that are hidden from everyone else.', 'href' => 'archive.php', 'icon' => 'ion-ios-box', 'tag' => 'Open'],
    ],
  ],
  [
    'title' => 'Bookmarks',
    'nav_label' => 'Bookmarks',
    'icon' => 'ion-ios-bookmarks',
    'desc' => 'Posts and stories you saved from For You, Discover, Reels, or Profile. Only you can open this list.',
    'rows' => [
      ['label' => 'Open bookmarks', 'meta' => 'Review and remove saved posts and stories.', 'href' => 'bookmark.php', 'icon' => 'ion-ios-bookmarks', 'tag' => 'Open'],
    ],
  ],
  [
    'title' => 'Notifications',
    'nav_label' => 'Notifications',
    'icon' => 'ion-android-notifications',
    'desc' => 'Keep one place for alerts from friends, reactions, comments, shares, and email.',
    'rows' => [
      ['label' => 'Email notifications', 'meta' => 'Updates from profile, timeline, and activity by email.', 'icon' => 'ion-android-mail', 'tag' => 'Live', 'field' => 'email_notifications', 'options' => $yesNoOptions],
      ['label' => 'Friend request notifications', 'meta' => 'Know when somebody wants to connect.', 'icon' => 'ion-person-add', 'tag' => 'Live', 'field' => 'friend_request_notifications', 'options' => $yesNoOptions],
      ['label' => 'Comment notifications', 'meta' => 'Get notified when someone comments on your story.', 'icon' => 'ion-chatbox', 'tag' => 'Live', 'field' => 'comment_notifications', 'options' => $yesNoOptions],
      ['label' => 'Reaction notifications', 'meta' => 'See likes and love on your posts.', 'icon' => 'ion-heart', 'tag' => 'Live', 'field' => 'reaction_notifications', 'options' => $yesNoOptions],
      ['label' => 'Share notifications', 'meta' => 'Track when your posts are shared.', 'icon' => 'ion-forward', 'tag' => 'Live', 'field' => 'share_notifications', 'options' => $yesNoOptions],
    ],
  ],
  [
    'title' => 'Security and safety',
    'nav_label' => 'Security',
    'icon' => 'ion-shield',
    'desc' => 'Protect your account, manage who you block or mute, and keep security tools close.',
    'rows' => [
      ['label' => 'Change password', 'meta' => 'Open the password page for your account.', 'href' => 'change-password.php?return=' . rawurlencode('profile.php?tab=gear'), 'icon' => 'ion-key', 'tag' => 'Open'],
      ['label' => 'Blocked users system', 'meta' => 'Turn blocked-user tools on or off for your profile.', 'icon' => 'ion-close-circled', 'tag' => 'Live', 'field' => 'blocked_users_enabled', 'options' => $yesNoOptions],
      ['label' => 'Hidden users system', 'meta' => 'Keep hidden-user controls ready when you want a quieter profile.', 'icon' => 'ion-eye-disabled', 'tag' => 'Live', 'field' => 'hidden_users_enabled', 'options' => $yesNoOptions],
      ['label' => 'Mute user system', 'meta' => 'Allow mute controls for noisy accounts and story activity.', 'icon' => 'ion-volume-mute', 'tag' => 'Live', 'field' => 'mute_users_enabled', 'options' => $yesNoOptions],
      ['label' => 'Report history system', 'meta' => 'Save report history tools in one place for later moderation work.', 'icon' => 'ion-flag', 'tag' => 'Live', 'field' => 'report_history_enabled', 'options' => $yesNoOptions],
      ['label' => 'Open Safety Center', 'meta' => 'See the current state of blocked, hidden, muted, report history, and login safety.', 'href' => 'security_tools.php', 'icon' => 'ion-shield', 'tag' => 'Open'],
      ['label' => 'Login / security section', 'meta' => 'Open your security center for session and account protection notes.', 'href' => 'security_tools.php#login-security', 'icon' => 'ion-locked', 'tag' => 'Open'],
      ['label' => 'Manage devices', 'meta' => 'See your active devices, last active time, IP address, and revoke one device at a time.', 'href' => 'manage_devices.php', 'icon' => 'ion-iphone', 'tag' => 'Open'],
    ],
  ],
  [
    'title' => 'Appearance and app preferences',
    'nav_label' => 'Appearance',
    'icon' => 'ion-android-color-palette',
    'desc' => 'Fine-tune how the profile feels on desktop, laptop, tablet, and mobile.',
    'rows' => [
      ['label' => 'Dark auto', 'meta' => 'Turn automatic day/night theme switching on or off. When On, Appearance color is set to Off.', 'icon' => 'ion-ios-moon', 'tag' => 'Live', 'field_local' => 'theme_auto_enabled', 'options' => $themeAutoOptions, 'default_value' => $themeAutoDefault],
      ['label' => 'Appearance color', 'meta' => 'Pick Off, Light, Dark, or any HTML color. Choosing a color turns Dark auto Off to avoid conflicts.', 'icon' => 'ion-contrast', 'tag' => 'Live', 'field' => 'appearance_mode', 'option_groups' => $appearanceModeOptionGroups, 'default_value' => $manualAppearanceDefault],
      ['label' => 'Progress color', 'meta' => 'Pick any background color, then press Save to apply it across the app. Turns Dark auto Off.', 'icon' => 'ion-android-color-palette', 'tag' => 'Live', 'field' => 'appearance_mode', 'control' => 'color', 'default_value' => $progressColorDefault],
      ['label' => 'Grid size for gallery', 'meta' => 'Control how many columns or tile sizes appear in your gallery.', 'icon' => 'ion-grid', 'tag' => 'Live', 'field' => 'gallery_grid_size', 'options' => $gridSizeOptions],
      ['label' => 'Autoplay videos on / off', 'meta' => 'Choose whether videos start automatically.', 'icon' => 'ion-videocamera', 'tag' => 'Live', 'field' => 'autoplay_videos', 'options' => $yesNoOptions],
      ['label' => 'Sound on / off', 'meta' => 'Control sound for video posts and reels.', 'icon' => 'ion-volume-high', 'tag' => 'Live', 'field' => 'sound_enabled', 'options' => $yesNoOptions],
      ['label' => 'Language', 'meta' => 'Set your app language in one place.', 'icon' => 'ion-chatbubbles', 'tag' => 'Live', 'field' => 'app_language', 'options' => $languageOptions],
      ['label' => 'Date format', 'meta' => 'Choose how profile and timeline dates appear.', 'icon' => 'ion-calendar', 'tag' => 'Live', 'field' => 'date_format', 'options' => $dateFormatOptions],
    ],
  ],
  [
    'title' => 'Account tools',
    'nav_label' => 'Account',
    'icon' => 'ion-android-settings',
    'desc' => 'Big account actions should stay visible, but separate from your About details.',
    'rows' => [
      ['label' => 'Archived posts', 'meta' => 'Open the private archive of posts you hid from feeds.', 'href' => 'archive.php', 'icon' => 'ion-ios-box', 'tag' => 'Open'],
      ['label' => 'Bookmarks', 'meta' => 'Open posts and stories you saved across For You, Discover, Reels, and Profile.', 'href' => 'bookmark.php', 'icon' => 'ion-ios-bookmarks', 'tag' => 'Open'],
      ['label' => 'Allow download my data', 'meta' => 'Keep export tools available for your account.', 'icon' => 'ion-archive', 'tag' => 'Live', 'field' => 'allow_download_data', 'options' => $yesNoOptions],
      ['label' => 'Allow deactivate account', 'meta' => 'Control whether deactivation tools are available in your account center.', 'icon' => 'ion-pause', 'tag' => 'Live', 'field' => 'allow_deactivate_account', 'options' => $yesNoOptions],
      ['label' => 'Allow delete account', 'meta' => 'Show or hide the protected delete-account flow.', 'icon' => 'ion-trash-a', 'tag' => 'Live', 'field' => 'allow_delete_account', 'options' => $yesNoOptions],
      ['label' => 'Allow logout all devices', 'meta' => 'Keep the multi-device sign-out tool visible in your account center.', 'icon' => 'ion-log-out', 'tag' => 'Live', 'field' => 'allow_logout_all_devices', 'options' => $yesNoOptions],
      ['label' => 'Download my data', 'meta' => 'Export your profile, about details, settings, and post summary as a JSON file.', 'href' => 'account_tools.php?action=download', 'icon' => 'ion-archive', 'tag' => 'Open'],
      ['label' => 'Deactivate account', 'meta' => 'Pause the account by switching your status off until you log in again.', 'href' => 'account_tools.php?action=deactivate', 'icon' => 'ion-pause', 'tag' => 'Open'],
      ['label' => 'Delete account', 'meta' => 'Protected page for permanent account removal planning.', 'href' => 'account_tools.php?action=delete', 'icon' => 'ion-trash-a', 'tag' => 'Open'],
      ['label' => 'Logout all devices', 'meta' => 'Sign out every other active browser session while this current device stays signed in.', 'href' => 'account_tools.php?action=logout_all', 'icon' => 'ion-log-out', 'tag' => 'Open'],
      ['label' => 'Manage devices', 'meta' => 'Review current and recent devices, then revoke one session at a time.', 'href' => 'manage_devices.php', 'icon' => 'ion-iphone', 'tag' => 'Open'],
      ['label' => 'Logout now', 'meta' => 'Sign out of the current session immediately.', 'href' => 'logout.php', 'icon' => 'ion-power', 'tag' => 'Open'],
    ],
  ],
];

$gearQuickLinks = [
  ['label' => 'Edit Profile', 'icon' => 'ion-edit', 'href' => 'user_edit.php?return=' . rawurlencode('profile.php?tab=gear')],
  ['label' => 'Privacy', 'icon' => 'ion-locked', 'href' => '#gear-privacy-controls'],
  ['label' => 'Timeline Settings', 'icon' => 'ion-ios-book', 'href' => '#gear-timeline-memory-controls'],
  ['label' => 'Archived posts', 'icon' => 'ion-ios-box', 'href' => 'archive.php'],
  ['label' => 'Bookmarks', 'icon' => 'ion-ios-bookmarks', 'href' => 'bookmark.php'],
  ['label' => 'Notifications', 'icon' => 'ion-android-notifications', 'href' => '#gear-notifications'],
  ['label' => 'Security', 'icon' => 'ion-shield', 'href' => '#gear-security-and-safety'],
  ['label' => 'Blocked Users', 'icon' => 'ion-close-circled', 'href' => '#gear-security-and-safety'],
  ['label' => 'Manage Devices', 'icon' => 'ion-iphone', 'href' => 'manage_devices.php'],
  ['label' => 'Appearance', 'icon' => 'ion-android-color-palette', 'href' => '#gear-appearance-and-app-preferences'],
  ['label' => 'Account', 'icon' => 'ion-android-settings', 'href' => '#gear-account-tools'],
];

if (isset($_GET['ajax']) && (string)$_GET['ajax'] === 'gear') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  profile_require_edit_access($dbh, $sessionOwnerId);

  if (!$canManageProfilePrivate) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You cannot modify Gear settings on this profile.']);
    exit;
  }

  $gearGroupByTitle = [];
  foreach ($gearGroups as $group) {
    $title = trim((string)($group['title'] ?? ''));
    if ($title === '') continue;

    $rows = [];
    foreach (($group['rows'] ?? []) as $row) {
      $field = trim((string)($row['field'] ?? ''));
      $localField = trim((string)($row['field_local'] ?? ''));
      $defaultValue = array_key_exists('default_value', $row)
        ? (string)$row['default_value']
        : ($field !== '' ? (string)($profileSettings[$field] ?? '') : '');
      if ($localField === 'theme_auto_enabled') {
        $defaultValue = $themeAutoDefault;
      }

      $options = [];
      foreach (($row['options'] ?? []) as $optionValue => $optionLabel) {
        $options[] = [
          'value' => (string)$optionValue,
          'label' => (string)$optionLabel,
        ];
      }

      $rows[] = [
        'label' => trim((string)($row['label'] ?? '')),
        'meta' => trim((string)($row['meta'] ?? '')),
        'icon' => trim((string)($row['icon'] ?? '')),
        'tag' => trim((string)($row['tag'] ?? '')),
        'href' => trim((string)($row['href'] ?? '')),
        'field' => $field,
        'field_local' => $localField,
        'value' => $defaultValue,
        'options' => $options,
      ];
    }

    $gearGroupByTitle[$title] = [
      'title' => $title,
      'icon' => trim((string)($group['icon'] ?? '')),
      'desc' => trim((string)($group['desc'] ?? '')),
      'rows' => $rows,
    ];
  }

  $mobileItems = [
    ['key' => 'privacy', 'label' => 'Privacy', 'icon' => 'ion-locked', 'group' => 'Privacy controls'],
    ['key' => 'timeline', 'label' => 'Timeline Settings', 'icon' => 'ion-ios-book', 'group' => 'Timeline / memory controls'],
    ['key' => 'archived_posts', 'label' => 'Archived posts', 'icon' => 'ion-ios-box', 'group' => 'Archived posts'],
    ['key' => 'bookmarks', 'label' => 'Bookmarks', 'icon' => 'ion-ios-bookmarks', 'group' => 'Bookmarks'],
    ['key' => 'security', 'label' => 'Security', 'icon' => 'ion-shield', 'group' => 'Security and safety'],
    ['key' => 'devices', 'label' => 'Manage Devices', 'icon' => 'ion-iphone', 'group' => 'Security and safety'],
    ['key' => 'account_settings', 'label' => 'Account Settings', 'icon' => 'ion-ios-person', 'group' => 'Profile settings'],
    ['key' => 'notifications', 'label' => 'Notification', 'icon' => 'ion-android-notifications', 'group' => 'Notifications'],
    ['key' => 'blocked_users', 'label' => 'Blocked Users', 'icon' => 'ion-close-circled', 'group' => 'Security and safety'],
    ['key' => 'appearance', 'label' => 'Appearance', 'icon' => 'ion-android-color-palette', 'group' => 'Appearance and app preferences'],
    ['key' => 'account', 'label' => 'Account', 'icon' => 'ion-android-settings', 'group' => 'Account tools'],
  ];

  foreach ($mobileItems as $idx => $item) {
    $group = $gearGroupByTitle[$item['group']] ?? ['title' => $item['label'], 'icon' => $item['icon'], 'desc' => '', 'rows' => []];
    if ($item['key'] === 'devices') {
      $group['rows'] = array_values(array_filter($group['rows'], static function ($row) {
        return stripos((string)($row['label'] ?? ''), 'device') !== false;
      }));
    } elseif ($item['key'] === 'blocked_users') {
      $group['rows'] = array_values(array_filter($group['rows'], static function ($row) {
        $label = (string)($row['label'] ?? '');
        $meta = (string)($row['meta'] ?? '');
        return stripos($label, 'block') !== false || stripos($meta, 'block') !== false;
      }));
    }
    $mobileItems[$idx]['detail'] = $group;
  }

  echo json_encode([
    'ok' => true,
    'tab' => 'gear',
    'items' => $mobileItems,
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

$galleryCategories = fetchUserPostCategories($dbh, $viewId);
$selectedGalleryCategoryId = (int)($_GET['gallery_category'] ?? 0);
$gallerySearch = trim((string)($_GET['gallery_search'] ?? ''));
$validGalleryCategoryIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $galleryCategories);
if ($selectedGalleryCategoryId > 0 && !in_array($selectedGalleryCategoryId, $validGalleryCategoryIds, true)) {
  $selectedGalleryCategoryId = 0;
}

/**
 * Grid data
 */
$grid = [];
$gridLayoutSelect = post_layout_select_sql($dbh);
try {
  $gridWhere = "
      p.user_id = :me
      AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
      AND COALESCE(p.is_archived,0) = 0
  ";
  $gridParams = [
    ':me' => $viewId,
    ':viewer_id' => $meId,
    ':viewer_share_id' => $meId,
    ':viewer_save_id' => $meId,
  ];
  // Visitors (including personal → publisher) see the same posts as the Posts tab.
  if ($meId > 0 && $viewId > 0 && $meId !== $viewId) {
    $gridWhere .= ' AND ' . publisher_profile_author_posts_scope_sql($dbh, $meId, $viewId);
    $gridParams = array_merge($gridParams, publisher_profile_author_posts_scope_params($dbh, $meId, $viewId));
  }
  if ($selectedGalleryCategoryId > 0) {
    $gridWhere .= " AND p.category_id = :gallery_category_id";
    $gridParams[':gallery_category_id'] = $selectedGalleryCategoryId;
  }
  if ($gallerySearch !== '') {
    $gridWhere .= "
      AND (
        COALESCE(p.title,'') LIKE :gallery_search
        OR COALESCE(p.description,'') LIKE :gallery_search
        OR COALESCE(p.body,'') LIKE :gallery_search
        OR COALESCE(pc.name,'') LIKE :gallery_search
        OR COALESCE(a.type,'') LIKE :gallery_search
        OR DATE_FORMAT(p.created_at, '%Y') LIKE :gallery_search
        OR DATE_FORMAT(p.created_at, '%M') LIKE :gallery_search
        OR DATE_FORMAT(p.created_at, '%b') LIKE :gallery_search
        OR DATE_FORMAT(p.created_at, '%e') LIKE :gallery_search
        OR DATE_FORMAT(p.created_at, '%d') LIKE :gallery_search
        OR DATE_FORMAT(p.created_at, '%M %e, %Y') LIKE :gallery_search
      )
    ";
    $gridParams[':gallery_search'] = '%' . $gallerySearch . '%';
  }

  $st = $dbh->prepare("
    SELECT
      p.id AS post_id,
      COALESCE(NULLIF(p.title,''), '') AS title,
      COALESCE(NULLIF(p.description,''), '') AS descr,
      COALESCE(NULLIF(p.body,''), '') AS body,
      {$gridLayoutSelect}
      COALESCE(p.category_id, 0) AS category_id,
      COALESCE(NULLIF(pc.name,''), '') AS category_name,
      COALESCE(NULLIF(pc.category_type,''), '') AS category_type,
      LOWER(COALESCE(NULLIF(TRIM(p.visibility), ''), 'public')) AS visibility,
      COALESCE(NULLIF(a.type,''), '') AS atype,
      COALESCE(NULLIF(a.thumb_path,''), '') AS thumb,
      COALESCE(NULLIF(a.file_path,''), '') AS file_path,
      p.created_at,
      COALESCE(p.updated_at, p.created_at) AS updated_at,
      (SELECT reaction FROM public_post_reactions r WHERE r.post_id = p.id AND r.user_id = :viewer_id LIMIT 1) AS my_reaction,
      COALESCE(p.views_count, 0) AS views_count,
      (SELECT COUNT(*) FROM public_post_comments c WHERE c.post_id = p.id AND c.is_deleted = 0) AS comment_count,
      (SELECT COUNT(*) FROM public_post_reactions r WHERE r.post_id = p.id AND r.reaction = 'love') AS love_count,
      (SELECT COUNT(*) FROM public_post_reactions r WHERE r.post_id = p.id AND r.reaction <> 'love') AS like_count,
      (SELECT COUNT(*) FROM public_post_shares s WHERE s.post_id = p.id) AS share_count,
      (SELECT COUNT(*) FROM public_post_saves sv WHERE sv.post_id = p.id) AS save_count,
      EXISTS(SELECT 1 FROM public_post_shares s WHERE s.post_id = p.id AND s.user_id = :viewer_share_id) AS my_shared,
      EXISTS(SELECT 1 FROM public_post_saves sv WHERE sv.post_id = p.id AND sv.user_id = :viewer_save_id) AS my_saved,
      (SELECT COUNT(*) FROM public_post_attachments ac WHERE ac.post_id = p.id) AS attachment_count
    FROM public_posts p
    LEFT JOIN public_post_attachments a
      ON a.id = (
        SELECT aa.id
        FROM public_post_attachments aa
        WHERE aa.post_id = p.id
        ORDER BY aa.id DESC
        LIMIT 1
      )
    LEFT JOIN user_post_categories pc
      ON pc.id = p.category_id
    WHERE {$gridWhere}
    ORDER BY p.created_at DESC, p.id DESC
    LIMIT 30
  ");
  $st->execute($gridParams);
  $grid = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  // fallback without views_count column
  try {
    $st = $dbh->prepare("
      SELECT
        p.id AS post_id,
        COALESCE(NULLIF(p.title,''), '') AS title,
        COALESCE(NULLIF(p.description,''), '') AS descr,
        COALESCE(NULLIF(p.body,''), '') AS body,
        {$gridLayoutSelect}
        COALESCE(p.category_id, 0) AS category_id,
        COALESCE(NULLIF(pc.name,''), '') AS category_name,
        COALESCE(NULLIF(pc.category_type,''), '') AS category_type,
        LOWER(COALESCE(NULLIF(TRIM(p.visibility), ''), 'public')) AS visibility,
        COALESCE(NULLIF(a.type,''), '') AS atype,
        COALESCE(NULLIF(a.thumb_path,''), '') AS thumb,
        COALESCE(NULLIF(a.file_path,''), '') AS file_path,
        p.created_at,
        COALESCE(p.updated_at, p.created_at) AS updated_at,
        (SELECT reaction FROM public_post_reactions r WHERE r.post_id = p.id AND r.user_id = :viewer_id LIMIT 1) AS my_reaction,
        0 AS views_count,
        (SELECT COUNT(*) FROM public_post_comments c WHERE c.post_id = p.id AND c.is_deleted = 0) AS comment_count,
        (SELECT COUNT(*) FROM public_post_reactions r WHERE r.post_id = p.id AND r.reaction = 'love') AS love_count,
        (SELECT COUNT(*) FROM public_post_reactions r WHERE r.post_id = p.id AND r.reaction <> 'love') AS like_count,
        (SELECT COUNT(*) FROM public_post_shares s WHERE s.post_id = p.id) AS share_count,
        (SELECT COUNT(*) FROM public_post_saves sv WHERE sv.post_id = p.id) AS save_count,
        EXISTS(SELECT 1 FROM public_post_shares s WHERE s.post_id = p.id AND s.user_id = :viewer_share_id) AS my_shared,
        EXISTS(SELECT 1 FROM public_post_saves sv WHERE sv.post_id = p.id AND sv.user_id = :viewer_save_id) AS my_saved,
        (SELECT COUNT(*) FROM public_post_attachments ac WHERE ac.post_id = p.id) AS attachment_count
      FROM public_posts p
      LEFT JOIN public_post_attachments a
        ON a.id = (
          SELECT aa.id
          FROM public_post_attachments aa
          WHERE aa.post_id = p.id
          ORDER BY aa.id DESC
          LIMIT 1
        )
      LEFT JOIN user_post_categories pc
        ON pc.id = p.category_id
      WHERE {$gridWhere}
      ORDER BY p.created_at DESC, p.id DESC
      LIMIT 30
    ");
    $st->execute($gridParams);
    $grid = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e2) {
    $grid = [];
  }
}

foreach ($grid as &$gridRow) {
  if (trim((string)($gridRow['declared_layout'] ?? '')) === '') {
    $gridRow['declared_layout'] = post_declared_layout(array_merge($gridRow, [
      'description' => (string)($gridRow['descr'] ?? ''),
      'descr' => (string)($gridRow['descr'] ?? ''),
    ]));
  }
}
unset($gridRow);

$gridStorySource = array_values(array_filter($grid, static fn(array $it): bool => post_is_story_only($it)));
$gridFeedSource = array_values(array_filter($grid, static fn(array $it): bool => !post_is_story_only($it)));

// Dedicated story rail query so stories are not crowded out of the LIMIT 30 feed grid.
try {
  $storyWhere = "
      p.user_id = :me
      AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
      AND COALESCE(p.is_archived,0) = 0
  ";
  $layoutCol = post_layout_column($dbh);
  if ($layoutCol) {
    $safeCol = preg_replace('/[^a-z0-9_]/i', '', (string)$layoutCol);
    if ($safeCol !== '') {
      $storyWhere .= "
        AND (
          LOWER(TRIM(COALESCE(p.`{$safeCol}`,''))) = 'story'
          OR COALESCE(p.description,'') LIKE '%[[layout:story]]%'
          OR COALESCE(p.body,'') LIKE '%[[layout:story]]%'
          OR COALESCE(p.title,'') LIKE '%[[layout:story]]%'
        )
      ";
    }
  } else {
    $storyWhere .= "
      AND (
        COALESCE(p.description,'') LIKE '%[[layout:story]]%'
        OR COALESCE(p.body,'') LIKE '%[[layout:story]]%'
        OR COALESCE(p.title,'') LIKE '%[[layout:story]]%'
      )
    ";
  }
  $storyParams = [':me' => $viewId];
  if ($meId > 0 && $viewId > 0 && $meId !== $viewId) {
    $storyWhere .= ' AND ' . publisher_profile_author_posts_scope_sql($dbh, $meId, $viewId);
    $storyParams = array_merge($storyParams, publisher_profile_author_posts_scope_params($dbh, $meId, $viewId));
  }
  $storyLayoutSelect = post_layout_select_sql($dbh);
  $stStories = $dbh->prepare("
    SELECT
      p.id AS post_id,
      COALESCE(NULLIF(p.title,''), '') AS title,
      COALESCE(NULLIF(p.description,''), '') AS descr,
      COALESCE(NULLIF(p.body,''), '') AS body,
      {$storyLayoutSelect}
      COALESCE(NULLIF(a.type,''), '') AS atype,
      COALESCE(NULLIF(a.thumb_path,''), '') AS thumb,
      COALESCE(NULLIF(a.file_path,''), '') AS file_path,
      p.created_at,
      COALESCE(p.updated_at, p.created_at) AS updated_at,
      (SELECT reaction FROM public_post_reactions r WHERE r.post_id = p.id AND r.user_id = :viewer_id LIMIT 1) AS my_reaction
    FROM public_posts p
    LEFT JOIN public_post_attachments a
      ON a.id = (
        SELECT aa.id
        FROM public_post_attachments aa
        WHERE aa.post_id = p.id
        ORDER BY aa.id DESC
        LIMIT 1
      )
    WHERE {$storyWhere}
    ORDER BY p.created_at DESC, p.id DESC
    LIMIT 60
  ");
  $storyParams[':viewer_id'] = $meId;
  $stStories->execute($storyParams);
  $storyRows = $stStories->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $gridStorySource = array_values(array_filter($storyRows, static fn(array $it): bool => post_is_story_only($it)));
} catch (Throwable $e) {
  // keep $gridStorySource from the feed grid filter
}

if (!function_exists('profile_story_time_ago')) {
  function profile_story_time_ago(string $dt): string {
    $dt = trim($dt);
    if ($dt === '') {
      return '';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
      return '';
    }
    $sec = time() - $ts;
    if ($sec < 60) {
      return 'now';
    }
    $mins = (int)floor($sec / 60);
    if ($mins < 60) {
      return $mins . 'm';
    }
    $hrs = (int)floor($sec / 3600);
    if ($hrs < 24) {
      return $hrs . 'h';
    }
    $days = (int)floor($sec / 86400);
    if ($days < 7) {
      return $days . 'd';
    }
    $weeks = (int)floor($days / 7);
    if ($weeks < 5) {
      return $weeks . 'w';
    }
    return date('M j', $ts);
  }
}

// One catalog row / circle per story (new create → next circle on the rail).
$profileStoryCatalog = [];
foreach ($gridStorySource as $it) {
  $postId = (int)($it['post_id'] ?? 0);
  if ($postId <= 0) {
    continue;
  }
  $atype = strtolower(trim((string)($it['atype'] ?? '')));
  $thumb = trim((string)($it['thumb'] ?? ''));
  $filePath = trim((string)($it['file_path'] ?? ''));
  $src = '';
  $type = $atype !== '' ? $atype : 'image';
  if ($atype === 'video' && $filePath !== '' && is_video_path($filePath)) {
    $src = $filePath;
    $type = 'video';
  } elseif ($thumb !== '') {
    $src = $thumb;
  } elseif ($filePath !== '') {
    $src = $filePath;
  }
  $caption = post_story_caption($it);
  if ($src === '' && $caption === '') {
    continue;
  }
  $srcNorm = $src !== '' ? ltrim(preg_replace('~^\./~', '', $src), '/') : '';
  $storyWhen = trim((string)($it['updated_at'] ?? $it['created_at'] ?? ''));
  $whenLabel = profile_story_time_ago($storyWhen);
  $slide = [
    'src' => $srcNorm,
    'type' => $srcNorm !== '' ? $type : 'text',
    'title' => trim((string)($it['title'] ?? '')),
    'caption' => $caption,
    'timeLabel' => $whenLabel,
    'timeAgo' => $whenLabel,
    'createdAt' => $storyWhen,
    'postId' => $postId,
    'myReaction' => trim((string)($it['my_reaction'] ?? '')),
    'friendCode' => $canViewProfilePrivateContact ? strtoupper(trim((string)($me['friend_code'] ?? ''))) : '',
  ];
  $ringSrc = $srcNorm !== '' ? $srcNorm : $avatarUrl;
  $profileStoryCatalog[] = [
    'key' => 's' . $postId,
    'userId' => $viewId,
    'name' => $displayName,
    'username' => $username,
    'friendCode' => $canViewProfilePrivateContact ? strtoupper(trim((string)($me['friend_code'] ?? ''))) : '',
    'verified' => $profileIsPublisher,
    'isPublisher' => $profileIsPublisher,
    'avatarUrl' => $ringSrc,
    'ringSrc' => $ringSrc,
    'ringType' => $slide['type'],
    'subtitle' => $whenLabel,
    'slides' => [$slide],
  ];
}
$profileStoryPostId = (int)($_GET['story_post'] ?? 0);

$gridIds = [];
foreach ($gridFeedSource as $it) {
  $pid = (int)($it['post_id'] ?? 0);
  if ($pid > 0) {
    $gridIds[] = $pid;
  }
}

if (!function_exists('profile_item_has_media')) {
  function profile_item_has_media(array $it): bool {
    $atype = strtolower(trim((string)($it['atype'] ?? '')));
    $thumb = trim((string)($it['thumb'] ?? ''));
    $filePath = trim((string)($it['file_path'] ?? ''));
    if ($atype === 'video' && $filePath !== '' && is_video_path($filePath)) {
      return true;
    }
    if ($thumb !== '') {
      return true;
    }
    return $filePath !== '';
  }
}

if (!function_exists('profile_item_has_gallery_content')) {
  /** Gallery tiles: media posts, or title/description-only text posts. */
  function profile_item_has_gallery_content(array $it): bool {
    if (profile_item_has_media($it)) {
      return true;
    }
    $title = trim((string)($it['title'] ?? ''));
    $descr = trim((string)($it['descr'] ?? ''));
    $body = trim((string)($it['body'] ?? ''));
    return ($title !== '' || $descr !== '' || $body !== '');
  }
}

$postsGrid = $gridFeedSource;
$galleryGrid = array_values(array_filter($gridFeedSource, static function (array $it): bool {
  return profile_item_has_gallery_content($it);
}));

// Tags tab = posts where this profile user was @tagged / people-tagged (Instagram-style).
$tagsGrid = [];
try {
  msb_post_tags_ensure_schema($dbh);
  $tagWhere = "
      t.tagged_user_id = :tagged_me
      AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
      AND COALESCE(p.is_archived,0) = 0
  ";
  $tagParams = [
    ':tagged_me' => $viewId,
    ':viewer_id' => $meId,
    ':viewer_share_id' => $meId,
    ':viewer_save_id' => $meId,
  ];
  if ($meId !== $viewId) {
    // Visitors only see tagged posts they are allowed to open.
    $tagWhere .= " AND (
      LOWER(COALESCE(NULLIF(TRIM(p.visibility), ''), 'public')) = 'public'
      OR (
        LOWER(COALESCE(NULLIF(TRIM(p.visibility), ''), 'public')) = 'friends'
        AND (
          p.user_id = :tag_viewer_own
          OR EXISTS (
            SELECT 1 FROM user_contacts uc
            WHERE uc.owner_user_id = :tag_viewer_a AND uc.friend_user_id = p.user_id
          )
          OR EXISTS (
            SELECT 1 FROM user_contacts uc2
            WHERE uc2.owner_user_id = p.user_id AND uc2.friend_user_id = :tag_viewer_b
          )
        )
      )
      OR p.user_id = :tag_viewer_author
    )";
    $tagParams[':tag_viewer_own'] = $meId;
    $tagParams[':tag_viewer_a'] = $meId;
    $tagParams[':tag_viewer_b'] = $meId;
    $tagParams[':tag_viewer_author'] = $meId;
  }
  if ($selectedGalleryCategoryId > 0) {
    $tagWhere .= " AND p.category_id = :gallery_category_id";
    $tagParams[':gallery_category_id'] = $selectedGalleryCategoryId;
  }
  if ($gallerySearch !== '') {
    $tagWhere .= "
      AND (
        COALESCE(p.title,'') LIKE :gallery_search
        OR COALESCE(p.description,'') LIKE :gallery_search
        OR COALESCE(p.body,'') LIKE :gallery_search
      )
    ";
    $tagParams[':gallery_search'] = '%' . $gallerySearch . '%';
  }
  $stTags = $dbh->prepare("
    SELECT
      p.id AS post_id,
      COALESCE(NULLIF(p.title,''), '') AS title,
      COALESCE(NULLIF(p.description,''), '') AS descr,
      COALESCE(NULLIF(p.body,''), '') AS body,
      {$gridLayoutSelect}
      COALESCE(p.category_id, 0) AS category_id,
      COALESCE(NULLIF(pc.name,''), '') AS category_name,
      COALESCE(NULLIF(pc.category_type,''), '') AS category_type,
      LOWER(COALESCE(NULLIF(TRIM(p.visibility), ''), 'public')) AS visibility,
      COALESCE(NULLIF(a.type,''), '') AS atype,
      COALESCE(NULLIF(a.thumb_path,''), '') AS thumb,
      COALESCE(NULLIF(a.file_path,''), '') AS file_path,
      p.created_at,
      COALESCE(p.updated_at, p.created_at) AS updated_at,
      (SELECT reaction FROM public_post_reactions r WHERE r.post_id = p.id AND r.user_id = :viewer_id LIMIT 1) AS my_reaction,
      COALESCE(p.views_count, 0) AS views_count,
      (SELECT COUNT(*) FROM public_post_comments c WHERE c.post_id = p.id AND c.is_deleted = 0) AS comment_count,
      (SELECT COUNT(*) FROM public_post_reactions r WHERE r.post_id = p.id AND r.reaction = 'love') AS love_count,
      (SELECT COUNT(*) FROM public_post_reactions r WHERE r.post_id = p.id AND r.reaction <> 'love') AS like_count,
      (SELECT COUNT(*) FROM public_post_shares s WHERE s.post_id = p.id) AS share_count,
      (SELECT COUNT(*) FROM public_post_saves sv WHERE sv.post_id = p.id) AS save_count,
      EXISTS(SELECT 1 FROM public_post_shares s WHERE s.post_id = p.id AND s.user_id = :viewer_share_id) AS my_shared,
      EXISTS(SELECT 1 FROM public_post_saves sv WHERE sv.post_id = p.id AND sv.user_id = :viewer_save_id) AS my_saved,
      (SELECT COUNT(*) FROM public_post_attachments ac WHERE ac.post_id = p.id) AS attachment_count,
      p.user_id AS author_id
    FROM public_post_tags t
    INNER JOIN public_posts p ON p.id = t.post_id
    LEFT JOIN public_post_attachments a
      ON a.id = (
        SELECT aa.id
        FROM public_post_attachments aa
        WHERE aa.post_id = p.id
        ORDER BY aa.id DESC
        LIMIT 1
      )
    LEFT JOIN user_post_categories pc
      ON pc.id = p.category_id
    WHERE {$tagWhere}
    ORDER BY t.created_at DESC, p.id DESC
    LIMIT 60
  ");
  $stTags->execute($tagParams);
  $tagsGrid = array_values(array_filter($stTags->fetchAll(PDO::FETCH_ASSOC) ?: [], static function (array $it): bool {
    return !post_is_story_only($it);
  }));
} catch (Throwable $e) {
  $tagsGrid = [];
}

$galleryGridIds = [];
foreach ($galleryGrid as $it) {
  $pid = (int)($it['post_id'] ?? 0);
  if ($pid > 0) {
    $galleryGridIds[] = $pid;
  }
}
$tagsGridIds = [];
foreach ($tagsGrid as $it) {
  $pid = (int)($it['post_id'] ?? 0);
  if ($pid > 0) {
    $tagsGridIds[] = $pid;
  }
}

if (!function_exists('profile_render_gallery_filter')) {
  function profile_render_gallery_filter(
    string $tab,
    int $selectedGalleryCategoryId,
    string $gallerySearch,
    array $galleryCategories,
    int $reqId,
    string $reqUsername,
    string $reqFriendCode,
    bool $hidden = false
  ): void {
    $tab = in_array($tab, ['gallery', 'posts', 'tags'], true) ? $tab : 'posts';
    ?>
    <div class="ig-gallery-filter"<?php echo $hidden ? ' hidden aria-hidden="true"' : ''; ?>>
      <form class="ig-gallery-search" method="get" action="profile.php">
        <?php if ($reqId > 0): ?><input type="hidden" name="id" value="<?php echo (int)$reqId; ?>"><?php endif; ?>
        <?php if ($reqUsername !== ''): ?><input type="hidden" name="username" value="<?php echo h($reqUsername); ?>"><?php endif; ?>
        <?php if ($reqFriendCode !== ''): ?><input type="hidden" name="friend_code" value="<?php echo h($reqFriendCode); ?>"><?php endif; ?>
        <input type="hidden" name="tab" value="<?php echo h($tab); ?>">
        <input type="hidden" name="gallery_category" value="<?php echo (int)$selectedGalleryCategoryId; ?>" id="gallerySearchCategoryMirror">
        <input type="search" name="gallery_search" value="<?php echo h($gallerySearch); ?>" placeholder="Search photo, video, topic, or date like 2026, April, or 12">
        <button type="submit">Search</button>
      </form>
      <div class="ig-gallery-right">
        <select id="galleryCategoryFilter" aria-label="Gallery category">
          <option value="0">All categories</option>
          <?php foreach ($galleryCategories as $cat): ?>
            <option value="<?php echo (int)($cat['id'] ?? 0); ?>" <?php echo $selectedGalleryCategoryId === (int)($cat['id'] ?? 0) ? 'selected' : ''; ?>>
              <?php echo h((string)($cat['name'] ?? 'Category')); ?> (<?php echo h(postCategoryTypeLabel((string)($cat['category_type'] ?? 'topic'))); ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <?php
  }
}

if (!function_exists('profile_tab_empty_html')) {
  function profile_tab_empty_html(string $title, string $iconClass = 'ion-ios-paper-outline'): string {
    return '<div class="mf-feed-empty" role="status">'
      . '<i class="icon ' . h($iconClass) . '" aria-hidden="true"></i>'
      . '<div class="mf-feed-empty-title">' . h($title) . '</div>'
      . '</div>';
  }
}

if (!function_exists('profile_render_post_grid_items')) {
  function profile_render_post_grid_items(array $items, bool $isMobile, bool $showTagPill = false): void {
    $gridIndex = 0;
    foreach ($items as $it) {
      $pid = (int)($it['post_id'] ?? 0);
      if ($pid <= 0) {
        continue;
      }

      $atype = trim((string)($it['atype'] ?? ''));
      $thumb = trim((string)($it['thumb'] ?? ''));
      $filePath = trim((string)($it['file_path'] ?? ''));

      $ttl = trim((string)($it['title'] ?? ''));
      $dsc = trim((string)($it['descr'] ?? ''));
      $bdy = trim((string)($it['body'] ?? ''));

      $snippetSource = $dsc !== '' ? $dsc : $bdy;
      $snippet = sentence_snippet($snippetSource, $isMobile ? 2 : 3, $isMobile ? 110 : 170);

      $showVideo = ($atype === 'video' && $filePath !== '' && is_video_path($filePath));
      $imgSrc = $thumb !== '' ? $thumb : $filePath;
      $showThumb = (!$showVideo && $imgSrc !== '');

      $capTitle = $ttl;
      $capDesc = $snippet;
      if ($capTitle === '' && $capDesc !== '') {
        $capTitle = $capDesc;
        $capDesc = '';
      }

      $viewsC = (int)($it['views_count'] ?? 0);
      $comC = (int)($it['comment_count'] ?? 0);
      $loveC = (int)($it['love_count'] ?? 0);
      $categoryName = trim((string)($it['category_name'] ?? ''));
      $vis = strtolower(trim((string)($it['visibility'] ?? 'public')));
      if ($vis !== 'private' && $vis !== 'public' && $vis !== 'friends') {
        $vis = 'public';
      }
      $noMedia = (!$showVideo && !$showThumb);
      ?>
      <a class="ig-item<?php echo $noMedia ? ' no-media' : ''; ?>"
         data-post-id="<?php echo $pid; ?>"
         data-index="<?php echo $gridIndex; ?>"
         data-visibility="<?php echo h($vis); ?>"
         data-mobile="<?php echo $isMobile ? '1' : '0'; ?>"
         href="#"
         title="Open post">
        <?php if ($showVideo): ?>
          <video class="ig-vid" src="<?php echo h($filePath); ?>" muted playsinline preload="metadata"></video>
          <?php if ($capTitle !== ''): ?>
            <div class="cap">
              <div class="cap-title"><?php echo h($capTitle); ?></div>
              <?php if ($capDesc !== ''): ?><div class="cap-desc"><?php echo h($capDesc); ?></div><?php endif; ?>
            </div>
          <?php endif; ?>
        <?php elseif ($showThumb): ?>
          <div class="ph" style="background-image:url('<?php echo h($imgSrc); ?>');"></div>
          <?php if ($capTitle !== ''): ?>
            <div class="cap">
              <div class="cap-title"><?php echo h($capTitle); ?></div>
              <?php if ($capDesc !== ''): ?><div class="cap-desc"><?php echo h($capDesc); ?></div><?php endif; ?>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <?php
            $textTitle = $ttl;
            $textBody = $snippet !== '' ? $snippet : ($dsc !== '' ? $dsc : $bdy);
            if ($textTitle === '' && $textBody !== '') {
              $textTitle = $textBody;
              $textBody = '';
            }
          ?>
          <div class="txtdesc">
            <div class="txtcap">
              <div class="t"><?php echo ($textTitle !== '' ? h($textTitle) : ''); ?></div>
              <?php if ($textBody !== ''): ?><div class="d"><?php echo h($textBody); ?></div><?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
        <?php if ($showTagPill && $categoryName !== ''): ?>
          <div class="ig-tag-pill" title="Category"><?php echo h($categoryName); ?></div>
        <?php endif; ?>
        <div class="react-overlay" aria-label="Reacts">
          <div class="react-btn" title="Love"><i class="icon ion-heart"></i> <span class="n"><?php echo $loveC; ?></span></div>
          <div class="react-btn" title="Comment"><i class="icon ion-chatbubble"></i> <span class="n"><?php echo $comC; ?></span></div>
          <div class="react-btn" title="Views"><i class="icon ion-eye"></i> <span class="vnum"><?php echo $viewsC; ?></span></div>
        </div>
      </a>
      <?php
      $gridIndex++;
    }
  }
}

if (!function_exists('profile_render_post_grid')) {
  function profile_render_post_grid(array $items, bool $showPeerNotFound, bool $isMobile, string $emptyTitle, string $emptyIcon = 'ion-ios-paper-outline', bool $showTagPill = false, string $gridScope = 'all'): void {
    if (!empty($items) && !$showPeerNotFound) {
      echo '<div class="ig-grid" data-grid-scope="' . h($gridScope) . '">';
      profile_render_post_grid_items($items, $isMobile, $showTagPill);
      echo '</div>';
      return;
    }
    echo profile_tab_empty_html($emptyTitle, $emptyIcon);
  }
}

if (isset($_GET['ajax']) && (string)$_GET['ajax'] === 'gallery') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  $items = [];
  foreach ($gridFeedSource as $it) {
    $pid = (int)($it['post_id'] ?? 0);
    if ($pid <= 0) continue;

    $atype = strtolower(trim((string)($it['atype'] ?? '')));
    $thumb = trim((string)($it['thumb'] ?? ''));
    $filePath = trim((string)($it['file_path'] ?? ''));
    $title = trim((string)($it['title'] ?? ''));
    $description = trim((string)($it['descr'] ?? ''));
    $body = trim((string)($it['body'] ?? ''));
    $snippetSource = $description !== '' ? $description : $body;
    $snippet = sentence_snippet($snippetSource, $isMobile ? 2 : 3, $isMobile ? 110 : 170);
    $isVideo = ($atype === 'video' && $filePath !== '' && is_video_path($filePath));
    $hasMedia = ($filePath !== '' || $thumb !== '');

    $attachments = [];
    if ($hasMedia) {
      $attachments[] = [
        'type' => $isVideo ? 'video' : ($atype !== '' ? $atype : 'image'),
        'file_path' => $filePath,
        'thumb_path' => $thumb,
      ];
    }

    $items[] = [
      'id' => $pid,
      'post_id' => $pid,
      'user_id' => $viewId,
      'display_name' => $displayName,
      'username' => $username,
      'account_kind' => $profileIsPublisher ? 'publisher' : (trim((string)($me['account_kind'] ?? 'personal')) ?: 'personal'),
      'is_publisher' => $profileIsPublisher ? 1 : 0,
      'friend_code' => $canViewProfilePrivateContact ? trim((string)($me['friend_code'] ?? '')) : '',
      'email' => $canViewProfilePrivateContact ? trim((string)($me['email'] ?? '')) : '',
      'title' => $title,
      'description' => $description,
      'body' => $body,
      'snippet' => $snippet,
      'category_id' => (int)($it['category_id'] ?? 0),
      'category_name' => trim((string)($it['category_name'] ?? '')),
      'category_type' => trim((string)($it['category_type'] ?? '')),
      'preview_type' => $isVideo ? 'video' : ($hasMedia ? 'image' : 'text'),
      'preview_path' => $thumb !== '' ? $thumb : $filePath,
      'file_path' => $filePath,
      'thumb_path' => $thumb,
      'attachments' => $attachments,
      'views_count' => (int)($it['views_count'] ?? 0),
      'comment_count' => (int)($it['comment_count'] ?? 0),
      'love_count' => (int)($it['love_count'] ?? 0),
      'like_count' => (int)($it['like_count'] ?? 0),
      'reaction_count' => (int)($it['love_count'] ?? 0) + (int)($it['like_count'] ?? 0),
      'share_count' => (int)($it['share_count'] ?? 0),
      'save_count' => (int)($it['save_count'] ?? 0),
      'my_reaction' => trim((string)($it['my_reaction'] ?? '')),
      'my_shared' => !empty($it['my_shared']) ? 1 : 0,
      'my_saved' => !empty($it['my_saved']) ? 1 : 0,
      'attachment_count' => (int)($it['attachment_count'] ?? count($attachments)),
      'created_at' => (string)($it['created_at'] ?? ''),
      'updated_at' => (string)($it['updated_at'] ?? $it['created_at'] ?? ''),
      'has_media' => $hasMedia ? 1 : 0,
      'is_video' => $isVideo ? 1 : 0,
    ];
  }

  echo json_encode([
    'ok' => true,
    'tab' => 'gallery',
    'user' => [
      'id' => $viewId,
      'display_name' => $displayName,
      'username' => $username,
      'friend_code' => $canViewProfilePrivateContact ? trim((string)($me['friend_code'] ?? '')) : '',
      'avatar_url' => $avatarUrl,
      'post_count' => $statPosts,
      'friend_count' => $statSocialCount,
      'friend_count_label' => $statSocialLabel,
      'following_count' => $statFollowing,
    ],
    'count' => count($items),
    'items' => $items,
  ], JSON_UNESCAPED_SLASHES);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Profile</title>

  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="./lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link rel="stylesheet" href="./css/shamcey.css">

  <link rel="stylesheet" href="assets/ui_best.css">
  <link rel="stylesheet" href="assets/layout-fixed.css">

  <script src="./lib/jquery/jquery.js"></script>
  <script src="./js/device_profile.js"></script>
  <script src="./lib/popper.js/popper.js"></script>
  <script src="./lib/bootstrap/bootstrap.js"></script>
  <script src="./lib/perfect-scrollbar/js/perfect-scrollbar.jquery.js"></script>
  <script src="./js/shamcey.js"></script>
  <?php theme_prefs_print_head_bootstrap($dbh, theme_prefs_viewer_user_id()); ?>

  <script defer src="assets/layout-fixed.js"></script>
  <script defer src="assets/ui_best.js"></script>

  <style>
    .ig-wrap{max-width:980px;width:100%;margin:0 auto;}
    .ig-card{background:var(--msb-palette-bg, #f5f7fb);border:1px solid var(--msb-palette-border, #c0c2c4);}
    body.profile-page{
      overflow:hidden !important;
      height:100vh;
      background-color:var(--msb-palette-bg, #f5f7fb) !important;
      color:var(--msb-palette-text, #0b1220);
    }
    body.profile-page::after{
      content:"";
      position:fixed;
      left:var(--feedRailW, 84px);
      right:0;
      top:var(--profile-header-divider-top, -10px);
      height:1px;
      background:var(--msb-palette-border, rgba(15,23,42,.08));
      pointer-events:none;
      z-index:1210;
    }
    body.profile-page .sh-mainpanel{
      height:100vh !important;
      max-height:100vh !important;
      overflow:hidden !important;
      background-color:var(--msb-palette-bg, #f5f7fb) !important;
    }
    body.profile-page .sh-pagebody{
      display:flex !important;
      flex-direction:column !important;
      flex:1 1 auto !important;
      min-height:0 !important;
      height:100% !important;
      max-height:100% !important;
      overflow:hidden !important;
      box-sizing:border-box;
      background-color:var(--msb-palette-bg, #f5f7fb) !important;
    }
    body.profile-page .ig-wrap{
      flex:1 1 auto;
      min-height:0;
      display:flex;
      flex-direction:column;
    }
    body.profile-page .ig-profile-shell{
      display:flex;
      flex-direction:column;
      flex:1 1 auto;
      min-height:0;
      overflow:hidden;
      border:0;
      background-color:var(--msb-palette-bg, #f5f7fb);
    }
    .ig-profile-head{
      flex:0 0 auto;
      z-index:40;
      background:var(--msb-palette-bg, #f5f7fb);
      border-bottom:0;
      box-shadow:0 4px 14px rgba(15,23,42,.04);
    }
    .ig-profile-scroll{
      flex:1 1 auto;
      min-height:0;
      border-left:1px solid var(--msb-palette-border, #c0c2c4);
      border-right:1px solid var(--msb-palette-border, #c0c2c4);
      box-sizing:border-box;
      overflow-y:auto;
      overflow-x:hidden;
      -webkit-overflow-scrolling:touch;
      overscroll-behavior:contain;
      background-color:var(--msb-palette-bg, #f5f7fb);
    }
    .ig-top{display:flex;gap:46px;align-items:flex-start;padding:26px 26px 16px;}
    .ig-avatar{width:150px;height:150px;border-radius:50%;border:3px solid rgba(15,23,42,.08);display:flex;align-items:center;justify-content:center;overflow:hidden;background:#f3f4f6;flex:0 0 auto;}
    .ig-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
    .ig-main{flex:1 1 auto;min-width:0;}
    .ig-row1{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
    .ig-name-line{
      display:inline-flex;
      align-items:center;
      gap:10px;
      min-width:0;
      max-width:100%;
      margin:0;
      flex:0 1 auto;
    }
    .ig-fullname-name{
      margin:0;
      font-size:22px;
      font-weight:700;
      line-height:1.2;
      color:var(--msb-palette-text, #0b1220);
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .ig-name-sep{
      flex:0 0 auto;
      width:1px;
      height:1.05em;
      background:var(--msb-palette-border-strong, rgba(15,23,42,.22));
      border-radius:1px;
    }
    .ig-handle{
      margin:0;
      font-size:18px;
      font-weight:400;
      line-height:1.2;
      color:var(--msb-palette-text-muted, #667085);
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .ig-username{font-size:22px;font-weight:500;color:var(--msb-palette-text, #0b1220);margin:0;line-height:1.2;}
    .ig-btn{display:inline-flex;align-items:center;justify-content:center;height:32px;padding:0 14px;border-radius:8px;border:1px solid var(--msb-palette-border-strong, rgba(15,23,42,.12));background:var(--msb-palette-bg, #f5f7fb);color:var(--msb-palette-text, #0b1220);font-weight:700;font-size:13px;}
    .ig-btn.back{margin-right:8px;}
    .ig-profile-head .ig-btn.back,
    .ig-profile-head .ig-btn.edit{display:none!important;}
    .ig-btn.icon{width:34px;padding:0;}
    .ig-btn i{font-size:16px}
    .ig-btn.publisher-follow-btn{cursor:pointer;border-radius:999px}
    .ig-btn.publisher-follow-btn.is-following{background:#111827;border-color:#111827;color:#fff}
    .ig-stats{display:flex;gap:26px;margin:14px 0 10px;flex-wrap:wrap;}
    .ig-stat{font-size:14px;color:var(--msb-palette-text, #0b1220);}
    .ig-stat b{font-weight:800;}
    .ig-bio{font-size:14px;line-height:1.55;color:var(--msb-palette-text, #0b1220);max-width:560px;}
    .ig-bio .muted{color:var(--msb-palette-text-muted, #667085);}
    .profile-account-badge{
      display:inline-block;
      margin:2px 0 4px;
      font-size:13px;
      font-weight:700;
      letter-spacing:.02em;
      color:var(--msb-palette-text, #0b1220);
    }

    .ig-highlights{display:flex;align-items:flex-start;padding:0 26px 14px;overflow:hidden;}
    .ig-stories-track{
      display:flex;
      align-items:flex-start;
      gap:18px;
      flex:1;
      min-width:0;
      overflow-x:auto;
      overflow-y:hidden;
      scroll-behavior:smooth;
      scrollbar-width:none;
      -ms-overflow-style:none;
      padding:2px 2px 4px;
    }
    .ig-stories-track::-webkit-scrollbar{display:none;}
    .ig-story-item{
      flex:0 0 auto;
      width:72px;
      text-align:center;
      cursor:pointer;
      user-select:none;
      border:0;
      padding:0;
      background:transparent;
      font:inherit;
      color:inherit;
      text-decoration:none;
    }
    .ig-story-ring{
      width:66px;
      height:66px;
      margin:0 auto 6px;
      padding:2px;
      border-radius:50%;
      background:linear-gradient(45deg,#f58529,#dd2a7b,#8134af,#515bd4);
      box-sizing:border-box;
    }
    .ig-story-ring img,
    .ig-story-thumb{
      display:block;
      width:100%;
      height:100%;
      border-radius:50%;
      border:2px solid #fff;
      object-fit:cover;
      background:#efefef;
      box-sizing:border-box;
    }
    .ig-story-ring video.ig-story-thumb{
      background:#000;
    }
    .ig-story-ring-text{
      display:flex;
      align-items:center;
      justify-content:center;
      width:100%;
      height:100%;
      border-radius:50%;
      border:2px solid #fff;
      box-sizing:border-box;
      padding:6px;
      text-align:center;
      font-size:9px;
      font-weight:800;
      line-height:1.15;
      color:#fff;
      background:linear-gradient(135deg,#334155,#0f172a);
      overflow:hidden;
    }
    .ig-story-name{
      display:block;
      max-width:72px;
      font-size:12px;
      line-height:1.2;
      color:#344054;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .ig-story-empty{
      font-size:13px;
      color:#667085;
      padding:18px 4px 8px;
    }
    .ig-story-create .ig-story-ring{
      background:rgba(15,23,42,.08);
      display:flex;
      align-items:center;
      justify-content:center;
    }
    .ig-story-create .ig-story-ring i{
      font-size:24px;
      color:#667085;
    }

    .ig-tabs{border-top:1px solid var(--msb-palette-border, #c0c2c4);display:flex;justify-content:center;align-items:stretch;gap:8px;padding:4px 10px 0;flex-wrap:wrap;background:var(--msb-palette-bg, #f5f7fb);}
    .ig-tab{
      position:relative;
      display:flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      min-width:max-content;
      padding:12px 12px 14px;
      border:0;
      border-radius:0;
      background:transparent;
      color:var(--msb-palette-text-muted, #667085);
      font-size:12px;
      font-weight:400;
      letter-spacing:.08em;
      line-height:1.2;
      text-transform:uppercase;
      text-align:center;
      white-space:nowrap;
      cursor:pointer;
      user-select:none;
    }
    .ig-tab:hover,
    .ig-tab:focus{
      color:var(--msb-palette-text, #0b1220);
      background:rgba(127,127,127,.07);
      outline:none;
    }
    .ig-tab.active{
      background:transparent;
      color:var(--msb-palette-text, #0b1220);
      font-weight:400;
    }
    .ig-tab.active::after{
      content:"";
      position:absolute;
      left:50%;
      bottom:0;
      width:68px;
      max-width:78%;
      height:4px;
      border-radius:999px;
      background:var(--msb-palette-text, #0b1220);
      transform:translateX(-50%);
      pointer-events:none;
    }
    .ig-tab i{font-size:14px;line-height:1;}
    html.dark-auto .ig-tab:hover,
    html[data-theme="dark"] .ig-tab:hover,
    html.dark-auto .ig-tab:focus,
    html[data-theme="dark"] .ig-tab:focus,
    html.dark-auto .ig-tab.active,
    html[data-theme="dark"] .ig-tab.active{
      color:#fff;
      background:transparent;
    }
    html.dark-auto .ig-tab:hover,
    html[data-theme="dark"] .ig-tab:hover,
    html.dark-auto .ig-tab:focus,
    html[data-theme="dark"] .ig-tab:focus{
      background:rgba(127,127,127,.07);
    }
    html.dark-auto .ig-tab.active::after,
    html[data-theme="dark"] .ig-tab.active::after{
      background:var(--msb-palette-text, #f3f6fb);
    }
    /* Match feed "For You" active underline (pill bar), beat appearance !important. */
    html body.profile-page .ig-tab.active,
    html[data-msb-appearance] body.profile-page .ig-tab.active,
    html.msb-palette-active body.profile-page .ig-tab.active,
    html.dark-auto body.profile-page .ig-tab.active,
    html[data-theme="dark"] body.profile-page .ig-tab.active{
      background:transparent!important;
      background-color:transparent!important;
      border-top-color:transparent!important;
      border-bottom-color:transparent!important;
      border-radius:0!important;
      box-shadow:none!important;
    }
    html body.profile-page .ig-tab.active::after{
      content:""!important;
      position:absolute!important;
      left:50%!important;
      right:auto!important;
      bottom:0!important;
      top:auto!important;
      width:68px!important;
      max-width:78%!important;
      height:4px!important;
      border:0!important;
      border-radius:999px!important;
      background:var(--msb-palette-text, #0b1220)!important;
      transform:translateX(-50%)!important;
      pointer-events:none!important;
      display:block!important;
    }
    .ig-gallery-filter{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 26px 12px;flex-wrap:wrap;}
    .ig-gallery-search{display:flex;align-items:center;gap:10px;flex:1 1 320px;min-width:260px;}
    .ig-gallery-search input{width:100%;height:40px;border:1px solid var(--msb-palette-border-strong, rgba(15,23,42,.14));background:var(--msb-palette-bg, #f5f7fb);color:var(--msb-palette-text, #0b1220);padding:0 12px;font-weight:700;}
    .ig-gallery-search button{height:40px;padding:0 14px;border:1px solid var(--msb-palette-btn-bg,var(--msb-palette-action,#4338ca));background:var(--msb-palette-btn-bg,var(--msb-palette-action,#4338ca));color:var(--msb-palette-btn-text,#ffffff);font-weight:800;cursor:pointer;}
    .ig-gallery-right{display:flex;align-items:center;gap:10px;flex:0 0 auto;}
    .ig-gallery-filter select{min-width:220px;height:40px;border:1px solid var(--msb-palette-border-strong, rgba(15,23,42,.14));background:var(--msb-palette-bg, #f5f7fb);color:var(--msb-palette-text, #0b1220);padding:0 12px;font-weight:700;}
    .ig-tag-pill{
      position:absolute;
      left:8px;
      top:8px;
      z-index:6;
      max-width:calc(100% - 16px);
      padding:5px 9px;
      border-radius:999px;
      background:rgba(2,8,23,.62);
      color:#fff;
      font-size:11px;
      font-weight:800;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      border:1px solid rgba(255,255,255,.12);
    }

    /* Desktop: 3 cols | Mobile/Tablet: 2 cols */
    .ig-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:16px 26px 26px;--post-media-radius:10px;}
    @media (max-width: 992px){ .ig-grid{grid-template-columns:repeat(2,1fr);} }

    /* Gallery visibility tabs — compact group near center. */
    .ig-grid-heads{
      display:flex;
      justify-content:center;
      align-items:center;
      gap:10px;
      padding:8px 26px 0;
      box-sizing:border-box;
    }
    .ig-grid-heads .ig-vis-tab{
      appearance:none;
      -webkit-appearance:none;
      border:1px solid transparent;
      background:transparent;
      margin:0;
      padding:6px 12px;
      border-radius:999px;
      text-align:center;
      font-size:12px;
      font-weight:700;
      line-height:1.2;
      color:var(--msb-palette-text-muted,#667085);
      flex:0 0 auto;
      cursor:pointer;
    }
    .ig-grid-heads .ig-vis-tab:hover{
      color:var(--msb-palette-text,#0b1220);
    }
    .ig-grid-heads .ig-vis-tab.is-active{
      color:var(--msb-palette-nav-active-text,var(--msb-palette-text,#0b1220));
      background:var(--msb-palette-nav-active-bg,var(--msb-palette-action-soft,rgba(79,70,229,.12)));
      border-color:var(--msb-palette-border-strong,rgba(15,23,42,.12));
    }
    #panel-gallery .ig-grid{padding-top:8px;}
    #panel-gallery .ig-item.is-vis-hidden{display:none !important;}
    #panel-gallery .ig-gallery-empty-filter{
      display:none;
      padding:28px 16px;
      text-align:center;
      font-size:13px;
      font-weight:700;
      color:var(--msb-palette-text-muted,#667085);
    }
    #panel-gallery .ig-gallery-empty-filter.is-visible{display:block;}
    @media (max-width:992px){
      .ig-grid-heads{padding:8px 18px 0;gap:8px;}
    }

    .ig-item{position:relative;width:100%;aspect-ratio:1/1;background:#eef2f7;overflow:hidden;border:1px solid rgba(15,23,42,.06);text-decoration:none;}
    .ig-item .ph{position:absolute;inset:0;background-size:cover;background-position:center;}
    .ig-item video.ig-vid{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;background:#000;}

    /* ✅ Views pill at TOP-LEFT */
    .view-pill{
      position:absolute;left:8px;top:8px;z-index:6;
      display:inline-flex;align-items:center;gap:6px;
      padding:7px 10px;border-radius:999px;
      background:rgba(2,8,23,.55);color:#fff;
      font-weight:900;font-size:12px;
      backdrop-filter: blur(4px);
      border:1px solid rgba(255,255,255,.10);
    }
    .view-pill i{font-size:16px;opacity:.95}

    /* ✅ React metrics pill (icons + visible counts) */
    .ig-metrics{
      position:absolute;right:8px;bottom:8px;z-index:6;
      display:inline-flex;align-items:center;gap:10px;
      padding:7px 10px;border-radius:999px;
      background:rgba(2,8,23,.55);color:#fff;
      backdrop-filter: blur(4px);
      border:1px solid rgba(255,255,255,.10);
    }
    .ig-metrics .m{display:inline-flex;align-items:center;gap:6px;opacity:.95}
    .ig-metrics .m i{font-size:16px}
    .ig-metrics .m .n{font-size:12px;font-weight:900;line-height:1;min-width:10px;text-align:left;opacity:.98}

    /* ✅ React overlay (desktop hover only) */
    .react-overlay{
      position:absolute;inset:0;z-index:8;
      background:rgba(2,8,23,.58);
      opacity:0;pointer-events:none;
      transition:opacity .16s ease;
      display:flex;align-items:center;justify-content:center;
      gap:10px;padding:10px;
    }
    .ig-item:hover .react-overlay{opacity:1;pointer-events:auto;}

    .react-btn{
      display:flex;align-items:center;gap:7px;
      padding:10px 12px;border-radius:999px;
      background:rgba(255,255,255,.12);color:#fff;
      font-weight:900;font-size:12px;
      border:1px solid rgba(255,255,255,.14);
      user-select:none;
    }
    .react-btn i{font-size:16px}
    .react-btn .n{font-size:12px;font-weight:900;min-width:10px;text-align:left;}
    .react-close{
      position:absolute;top:10px;right:10px;
      width:36px;height:36px;border-radius:999px;
      background:rgba(255,255,255,.12);
      border:1px solid rgba(255,255,255,.14);
      display:flex;align-items:center;justify-content:center;
      color:#fff;cursor:pointer;
    }
    .react-close i{font-size:20px}

    /* ✅ Cinematic bottom caption (low, avoids face area) */
    .cap{
      position:absolute;left:10px;right:10px;bottom:10px;
      padding:10px 14px;border-radius:16px;
      background:linear-gradient(
        to top,
        rgba(2,8,23,.78) 0%,
        rgba(2,8,23,.58) 60%,
        rgba(2,8,23,0) 100%
      );
      color:#fff;z-index:5;
      backdrop-filter: blur(8px);
      pointer-events:none;
    }
    .cap .cap-title{
      font-size:10px;font-weight:900;letter-spacing:.2px;
      margin-bottom:0px;
      white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    }
    .cap .cap-desc{
      font-size:7px;font-weight:700;opacity:.95;
      display:-webkit-box;-webkit-box-orient:vertical;overflow:hidden;
    }
    .ig-item[data-mobile="0"] .cap .cap-desc{-webkit-line-clamp:2;}
    .ig-item[data-mobile="1"] .cap .cap-desc{-webkit-line-clamp:1;}

    /* ✅ Text-only tile: use SAME “caption background” style at bottom */
    .txtdesc{
      position:absolute;inset:0;
      background:
        radial-gradient(120px 120px at 20% 15%, rgba(99,102,241,.18), transparent 60%),
        radial-gradient(140px 140px at 85% 20%, rgba(14,165,233,.16), transparent 60%),
        linear-gradient(145deg,#ffffff,#f8fafc);
      border:1px solid rgba(15,23,42,.06);
      box-shadow:0 6px 14px rgba(15,23,42,.06), inset 0 0 0 1px rgba(255,255,255,.65);
      overflow:hidden;
    }
    .txtdesc:before{
      content:'';
      position:absolute;left:0;top:0;bottom:0;width:4px;
      background:rgba(79,70,229,.85);opacity:.9;
    }
    /* subtle top fade so it feels like media tiles */
    .txtdesc:after{
      content:'';
      position:absolute;inset:0;
      background:linear-gradient(to bottom, rgba(2,8,23,0) 45%, rgba(2,8,23,.10) 100%);
      pointer-events:none;
    }
    /* bottom caption inside text tile (same layout as .cap, but for no-media) */
    .txtcap{
      position:absolute;left:10px;right:10px;bottom:10px;
      padding:10px 14px;border-radius:16px;
      background:linear-gradient(
        to top,
        rgba(2,8,23,.78) 0%,
        rgba(2,8,23,.58) 60%,
        rgba(2,8,23,0) 100%
      );
      color:#fff;
      backdrop-filter: blur(8px);
      z-index:3;
      pointer-events:none;
    }

    /* ✅ Profile grid ONLY: when there is NO media, move text to middle + react to top */
    .ig-item.no-media .txtcap{
      bottom:auto;
      top:50%;
      transform:translateY(-50%);
    }
    .txtreact{
      position:absolute;left:10px;right:10px;top:10px;
      z-index:4;
      display:flex;align-items:center;gap:10px;justify-content:flex-start;
      padding:8px 10px;border-radius:999px;
      background:rgba(2,8,23,.52);
      color:#fff;
      backdrop-filter: blur(6px);
      border:1px solid rgba(255,255,255,.10);
      pointer-events:none;
    }
    .txtreact .m{display:inline-flex;align-items:center;gap:6px;opacity:.95}
    .txtreact .m i{font-size:16px}
    .txtreact .m .n{font-size:12px;font-weight:900;line-height:1;min-width:10px;text-align:left;opacity:.98}
    .txtcap .t{
      font-size:13px;font-weight:900;letter-spacing:.2px;
      margin-bottom:0px;
      white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    }
    .txtcap .d{
      font-size:12px;font-weight:700;opacity:.95;
      display:-webkit-box;-webkit-box-orient:vertical;overflow:hidden;
    }
    .ig-item[data-mobile="0"] .txtcap .d{-webkit-line-clamp:3;}
    .ig-item[data-mobile="1"] .txtcap .d{-webkit-line-clamp:2;}

    .ig-empty{padding:22px 26px 26px;color:#667085;font-weight:700;font-size:13px;}
    .mf-feed-empty{
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      min-height:min(420px, calc(100vh - 360px));
      padding:48px 24px 56px;
      text-align:center;
      color:var(--msb-palette-text-muted, #667085);
      background-color:var(--msb-palette-bg, #f5f7fb);
    }
    .mf-feed-empty i{
      display:block;
      font-size:56px;
      line-height:1;
      margin:0 auto 16px;
      color:var(--msb-palette-text-muted, #98a2b3);
    }
    .mf-feed-empty .mf-feed-empty-title{
      font-size:17px;
      font-weight:700;
      color:var(--msb-palette-text, #344054);
      margin:0;
      letter-spacing:-0.01em;
    }
    #panel-gallery .mf-feed-empty,
    #panel-tags .mf-feed-empty{
      min-height:min(360px, calc(100vh - 420px));
    }

    @media (max-width: 991.98px){
      body.profile-page .sh-mainpanel{
        height:calc(100vh - 66px) !important;
        max-height:calc(100vh - 66px) !important;
      }
      body.profile-page .sh-pagebody{
        padding-bottom:0 !important;
      }
    }
    @media (max-width: 768px){
      body.profile-page .sh-mainpanel{
        height:calc(100vh - 66px) !important;
        max-height:calc(100vh - 66px) !important;
      }
      body.profile-page .sh-pagebody{
        height:100% !important;
        max-height:100% !important;
        padding-bottom:0 !important;
      }
      .profile-cover{top:100px;height:190px;margin:-18px -18px 18px;}
      .ig-top{gap:16px;padding:18px;}
      .ig-avatar{width:92px;height:92px;}
      .ig-stats{gap:16px;margin-top:10px;}
      .ig-highlights{gap:14px;overflow:auto;padding:0 18px 14px;}
      .ig-highlights{padding:0 16px 12px}
      .ig-story-ring{width:58px;height:58px}
      .ig-story-item{width:64px}
      .ig-grid{gap:6px;padding:12px 18px 18px;}
      .ig-gallery-filter{padding:0 18px 12px;justify-content:stretch;}
      .ig-gallery-search,.ig-gallery-right{width:100%;}
      .ig-gallery-search{flex-wrap:wrap;}
      .ig-gallery-search button,.ig-gallery-filter select{width:100%;min-width:0;}
      /* .ig-item{border-radius:8px} */
      .view-pill{left:6px;top:6px;}
      .cap{left:8px;right:8px;bottom:8px;padding:9px 12px;border-radius:14px;}
      .txtcap{left:8px;right:8px;bottom:8px;padding:9px 12px;border-radius:14px;}
      .txtreact{left:8px;right:8px;top:8px;padding:7px 10px;}
    }
  
    /* ✅ Mobile/Tablet ONLY: smaller description text in grid captions */
    @media (max-width: 991px){
      .cap .cap-desc,
      .txtcap .d{
        font-size:7px !important;
      }
    }

    /* Mobile + Tablet only */
    @media (max-width: 991px){

      /* Grid title */
      .cap .cap-title,
      .txtcap .t{
        font-size:8px !important;
      }

      /* Grid description */
      .cap .cap-desc,
      .txtcap .d{
        font-size:6px !important;
      }
    }

    .ig-highlights{
      border-top:0;
      border-bottom:0;
      position:relative;
      overflow:visible;
      padding-top:22px;
      background-color:var(--msb-palette-bg, #f5f7fb);
    }
    .ig-story-item:focus-visible{
      outline:2px solid rgba(37,99,235,.35);
      outline-offset:3px;
      border-radius:8px;
    }
    .ig-story-item.is-viewing .ig-story-ring{
      box-shadow:0 0 0 2px #fff, 0 0 0 4px #2563eb;
    }

    .profile-panel{display:none;background-color:var(--msb-palette-bg, #f5f7fb);}
    .profile-panel.active{display:block;}

    /* Posts tab feed — match feed.php card column (614px) and dimensions */
    #profilePostsFeed.mf-feed{
      width:100%;max-width:614px;margin:0 auto;padding:10px 10px 26px;box-sizing:border-box;
      --post-media-radius:10px;
    }
    #profilePostsFeed .mf-card{
      width:100%;max-width:100%;
      background:var(--msb-palette-bg, #f5f7fb);border:1px solid var(--msb-palette-border, rgba(15,23,42,.08));border-radius:22px;overflow:hidden;
      margin:0 auto 16px;box-shadow:none;
      --post-media-radius:10px;
    }
    #profilePostsFeed .mf-card.mf-card-text-only:not(.mf-card-phone-shot){
      width:100% !important;max-width:100% !important;
    }
    #profilePostsFeed .mf-card.mf-card-text-only.mf-card-phone-shot{border-radius:28px;}
    #profilePostsFeed .mf-card.is-single-video-post:not(.mf-video-ready),
    #profilePostsFeed .mf-card.is-single-video-post.mf-video-error,
    #profilePostsFeed .mf-card.is-single-image-post:not(.mf-image-ready),
    #profilePostsFeed .mf-card.is-single-image-post.mf-image-error{display:none !important;}
    #profilePostsFeed .mf-card.is-single-video-post .media-stage.standard-video-stage:not(.mf-media-sized){display:none !important;}
    #profilePostsFeed .mf-card.is-single-video-post .mf-media,
    #profilePostsFeed .mf-card.is-single-image-post .mf-media,
    #profilePostsFeed .mf-card.is-single-video-post .media-stage,
    #profilePostsFeed .mf-card.is-single-image-post .media-stage,
    #profilePostsFeed .mf-card.is-single-video-post .media-stage video,
    #profilePostsFeed .mf-card.is-single-image-post .media-stage img{background:transparent !important;}
    #profilePostsFeed .media-stage{
      border-radius:var(--post-media-radius) !important;
      background:transparent !important;
      overflow:hidden !important;
      position:relative;
    }
    #profilePostsFeed .mf-media-carousel,
    #profilePostsFeed .media-carousel{
      position:relative;
      width:100%;
      overflow:hidden;
      background:transparent;
    }
    #profilePostsFeed .mf-media-slides,
    #profilePostsFeed .media-slides{
      display:flex;
      flex-wrap:nowrap;
      width:100%;
      transition:transform .28s ease;
    }
    #profilePostsFeed .mf-media-slide,
    #profilePostsFeed .media-slide{
      flex:0 0 100%;
      width:100%;
      max-width:100%;
      display:flex;
      align-items:center;
      justify-content:center;
      overflow:hidden;
      background:transparent;
    }
    #profilePostsFeed .mf-media-slide > img,
    #profilePostsFeed .media-slide > img{
      width:100%;
      height:auto;
      display:block;
      object-fit:cover;
      object-position:center center;
    }
    #profilePostsFeed .mf-media-slide > video,
    #profilePostsFeed .media-slide > video{
      width:100%;
      height:auto;
      display:block;
      object-fit:contain;
      object-position:center center;
    }
    #profilePostsFeed .mf-media-nav,
    #profilePostsFeed .media-nav{
      position:absolute !important;
      top:50% !important;
      transform:translateY(-50%) !important;
      width:20px !important;
      height:20px !important;
      border:none !important;
      border-radius:999px !important;
      background:rgba(159, 153, 153, 0.9) !important;
      color:#fff !important;
      display:flex !important;
      align-items:center !important;
      justify-content:center !important;
      /* font-size:18px !important; */
      cursor:pointer;
      box-shadow:0 8px 24px rgba(0,0,0,.18) !important;
      z-index:6 !important;
    }
    #profilePostsFeed .mf-media-nav i,
    #profilePostsFeed .media-nav i{
      color:#fff !important;
      font-size:10px !important;
      line-height:1 !important;
    }
    #profilePostsFeed .mf-media-nav.prev,
    #profilePostsFeed .media-nav.prev{ left:12px !important; }
    #profilePostsFeed .mf-media-nav.next,
    #profilePostsFeed .media-nav.next{ right:12px !important; }
    #profilePostsFeed .mf-media-dots,
    #profilePostsFeed .media-dots{
      position:absolute;
      left:50%;
      bottom:12px;
      transform:translateX(-50%);
      display:flex;
      align-items:center;
      justify-content:center;
      gap:5px;
      padding:0;
      border-radius:0;
      background:transparent;
      z-index:5;
    }
    #profilePostsFeed .mf-media-dot,
    #profilePostsFeed .media-dot{
      width:5px !important;
      height:5px !important;
      min-width:5px !important;
      min-height:5px !important;
      flex:0 0 5px !important;
      display:block !important;
      border:none !important;
      border-radius:50% !important;
      padding:0 !important;
      margin:0 !important;
      background:rgba(255,255,255,.55) !important;
      cursor:pointer;
      appearance:none;
      -webkit-appearance:none;
      box-shadow:none !important;
      font-size:0 !important;
      line-height:0 !important;
      color:transparent !important;
      text-indent:-9999px !important;
      overflow:hidden !important;
      transition:background-color .15s ease, width .15s ease, height .15s ease;
    }
    #profilePostsFeed .mf-media-dot.is-active,
    #profilePostsFeed .media-dot.is-active{
      width:6px !important;
      height:6px !important;
      min-width:6px !important;
      min-height:6px !important;
      flex:0 0 6px !important;
      background:#3897f0 !important;
      transform:none;
    }
    #profilePostsFeed .media-stage.standard-video-stage,
    #profilePostsFeed .media-stage.standard-image-stage{
      padding:20px;box-sizing:border-box;
      border:0 !important;
      overflow:hidden !important;
      border-radius:var(--post-media-radius) !important;
      background:transparent !important;
    }
    #profilePostsFeed{
      --post-media-max-height: min(74vh, 640px);
      --post-phone-max:340px;
      --post-portrait-max:340px;
    }
    #profilePostsFeed .media-stage.standard-video-stage > video,
    #profilePostsFeed .media-stage.standard-image-stage > img,
    #profilePostsFeed video.ig-smart-feed-video{
      width:100% !important;height:auto !important;display:block;
      max-height:var(--post-media-max-height, min(74vh, 640px)) !important;
      object-fit:contain !important;object-position:center center !important;
      border:0 !important;padding:0 !important;
      border-radius:var(--post-media-radius) !important;
      background:transparent !important;background-color:transparent !important;
    }
    #profilePostsFeed video.ig-smart-feed-video::-webkit-media-controls-panel,
    #profilePostsFeed video.ig-smart-feed-video::-webkit-media-controls-enclosure{
      background:transparent !important;background-image:none !important;
    }
    /* Match feed.php: full-width card; constrain media (head-outside keeps stage full-width). */
    #profilePostsFeed .mf-card.is-single-video-post:not(.mf-card-reel),
    #profilePostsFeed .mf-card.is-single-image-post:not(.mf-card-reel){
      width:100% !important;
      max-width:100% !important;
      margin-left:0 !important;
      margin-right:0 !important;
    }
    #profilePostsFeed .mf-card.is-single-video-post:not(.mf-card-reel):not(.mf-card-media-head-outside) .media-stage.standard-video-stage,
    #profilePostsFeed .mf-card.is-single-image-post:not(.mf-card-reel):not(.mf-card-media-head-outside) .media-stage.standard-image-stage{
      width:min(100%, var(--post-media-card-width, 440px)) !important;
      max-width:100% !important;
      max-height:var(--post-media-max-height, min(74vh, 640px)) !important;
      margin-left:0 !important;
      margin-right:auto !important;
    }
    #profilePostsFeed .mf-card.mf-card-media-head-outside .media-stage.standard-video-stage,
    #profilePostsFeed .mf-card.mf-card-media-head-outside .media-stage.standard-image-stage{
      width:100% !important;
      max-width:100% !important;
      max-height:none !important;
      margin-left:0 !important;
      margin-right:0 !important;
      overflow:visible !important;
    }
    #profilePostsFeed .mf-card.mf-card-media-head-outside.is-single-video-post .media-stage.standard-video-stage > video,
    #profilePostsFeed .mf-card.mf-card-media-head-outside.is-single-image-post .media-stage.standard-image-stage > img{
      width:min(100%, var(--post-media-card-width, 440px)) !important;
      max-width:100% !important;
      max-height:var(--post-media-max-height, min(74vh, 640px)) !important;
      margin-left:0 !important;
      margin-right:auto !important;
      justify-self:start !important;
    }
    @media (max-width:767.98px){
      #profilePostsFeed{
        --post-media-max-height: min(58vh, 620px);
        --post-phone-max:340px;
        --post-portrait-max:340px;
      }
      #profilePostsFeed .media-stage.phone-shot{
        width:min(78vw,var(--post-phone-max,340px)) !important;
        max-width:100% !important;max-height:var(--post-media-max-height, min(58vh, 620px)) !important;
        margin-inline:auto !important;padding:0 !important;
        aspect-ratio:var(--device-ar-w,375)/var(--device-ar-h,667) !important;
        border-radius:28px !important;overflow:hidden !important;
        background:transparent !important;
      }
      #profilePostsFeed .media-stage.phone-shot.standard-video-stage,
      #profilePostsFeed .media-stage.phone-shot.standard-image-stage{
        overflow:hidden !important;padding:0 !important;
        aspect-ratio:var(--device-ar-w,375)/var(--device-ar-h,667) !important;
        border-radius:28px !important;
      }
      #profilePostsFeed .media-stage.phone-shot.standard-video-stage > video,
      #profilePostsFeed .media-stage.phone-shot.standard-image-stage > img{
        width:100% !important;height:100% !important;max-height:none !important;
        border-radius:0 !important;object-fit:contain !important;padding:0 !important;
        background:transparent !important;
      }
      #profilePostsFeed .mf-card.is-single-video-post:not(.mf-card-reel):not(.mf-card-media-head-outside) .media-stage.standard-video-stage:not(.phone-shot),
      #profilePostsFeed .mf-card.is-single-image-post:not(.mf-card-reel):not(.mf-card-media-head-outside) .media-stage.standard-image-stage:not(.phone-shot){
        width:min(100%, var(--post-media-card-width, 340px)) !important;
        max-width:min(100%, 360px) !important;
      }
      #profilePostsFeed .mf-card.mf-card-media-head-outside.is-single-video-post .media-stage.standard-video-stage:not(.phone-shot) > video,
      #profilePostsFeed .mf-card.mf-card-media-head-outside.is-single-image-post .media-stage.standard-image-stage:not(.phone-shot) > img{
        width:min(100%, var(--post-media-card-width, 340px)) !important;
        max-height:var(--post-media-max-height, min(58vh, 620px)) !important;
      }
    }
    @media (min-width:768px) and (max-width:1024.98px){
      #profilePostsFeed{
        --post-media-max-height: min(60vh, 620px);
      }
      #profilePostsFeed .mf-card.is-single-video-post:not(.mf-card-reel):not(.mf-card-media-head-outside) .media-stage.standard-video-stage:not(.phone-shot),
      #profilePostsFeed .mf-card.is-single-image-post:not(.mf-card-reel):not(.mf-card-media-head-outside) .media-stage.standard-image-stage:not(.phone-shot){
        width:min(100%, var(--post-media-card-width, 440px)) !important;
        max-width:min(100%, 480px) !important;
      }
      #profilePostsFeed .mf-card.mf-card-media-head-outside.is-single-video-post .media-stage.standard-video-stage > video,
      #profilePostsFeed .mf-card.mf-card-media-head-outside.is-single-image-post .media-stage.standard-image-stage > img{
        width:min(100%, var(--post-media-card-width, 440px)) !important;
        max-height:var(--post-media-max-height, min(60vh, 620px)) !important;
      }
    }
    @media (min-width:768px){
      #profilePostsFeed .media-stage.phone-shot,
      #profilePostsFeed .media-stage.phone-shot.standard-video-stage,
      #profilePostsFeed .media-stage.phone-shot.standard-image-stage{
        width:100% !important;max-width:100% !important;margin-inline:0 !important;
        padding:20px !important;box-sizing:border-box !important;
        aspect-ratio:auto !important;border-radius:var(--post-media-radius) !important;
        overflow:hidden !important;max-height:none !important;
        box-shadow:none !important;background:transparent !important;
      }
      #profilePostsFeed .media-stage.phone-shot.standard-video-stage > video,
      #profilePostsFeed .media-stage.phone-shot.standard-image-stage > img{
        width:100% !important;height:auto !important;
        max-height:var(--post-media-max-height, min(74vh, 640px)) !important;object-fit:contain !important;
        border-radius:var(--post-media-radius) !important;padding:0 !important;
        background:transparent !important;
      }
    }
    #profilePostsFeed .mf-head{padding:1px 1px 1px;display:flex;align-items:center;gap:12px;}
    #profilePostsFeed .mf-peer-link{display:flex;align-items:center;gap:12px;min-width:0;flex:1 1 auto;text-decoration:none;color:inherit;}
    #profilePostsFeed .mf-avatar{
      width:35px;height:35px;border-radius:999px;display:flex;align-items:center;justify-content:center;
      flex:0 0 45px;overflow:hidden;padding:2px;
      background:linear-gradient(135deg,#0ea5e9 0%,#2563eb 58%,#f8fafc 100%);
    }
    #profilePostsFeed .mf-avatar img{width:100%;height:100%;display:block;object-fit:cover;border-radius:50%;border:2px solid #fff;background:#fff;}
    #profilePostsFeed .mf-meta{min-width:0;flex:1 1 auto;}
    #profilePostsFeed .mf-name-row{display:flex;align-items:center;gap:8px;min-width:0;flex-wrap:wrap;}
    #profilePostsFeed .mf-name{font-size:16px;font-weight:800;line-height:1.2;margin:0;color:#111827;}
    #profilePostsFeed .mf-dot,#profilePostsFeed .mf-time{font-size:14px;color:#667085;margin:0;}
    #profilePostsFeed .mf-menu-wrap{position:relative;flex:0 0 auto;margin-left:auto;}
    #profilePostsFeed .mf-menu-btn:not(.post-card-menu-btn){
      width:38px;height:38px;border:0;background:transparent;border-radius:999px;
      display:flex;align-items:center;justify-content:center;color:#101828;cursor:pointer;
    }
    .post-sheet .modal-content{border:none;border-radius:18px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.18)}
    .post-sheet .modal-dialog,.confirm-sheet .modal-dialog{max-width:420px}
    .confirm-sheet .modal-content{border:none;border-radius:18px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.18)}
    .sheet-btn{width:100%;display:flex;align-items:center;justify-content:center;gap:10px;padding:16px 22px;border:none;background:#fff;color:#111;font-size:17px;font-weight:700;border-bottom:1px solid #f1f5f9}
    .sheet-btn:last-child{border-bottom:none}
    .sheet-btn:hover{background:#f8fafc}
    .sheet-btn.primary{color:var(--blue,#2563eb)}
    .sheet-btn.is-friends{color:#166534}
    .sheet-btn.is-following{color:#111827}
    .sheet-btn.is-pending{color:#9a3412}
    .sheet-btn.is-accept{color:#1d4ed8}
    .sheet-btn.danger{color:#dc2626}
    #profilePostsFeed .mf-menu{
      position:absolute;top:38px;right:0;min-width:170px;background:#fff;border:1px solid rgba(0,0,0,.10);
      border-radius:14px;box-shadow:0 14px 34px rgba(16,24,40,.16);padding:6px;z-index:50;display:none;
    }
    #profilePostsFeed .post-card-fries-icon{
      position:relative;
      display:inline-flex;
      flex-direction:column;
      align-items:flex-start;
      justify-content:flex-start;
      width:18px;
      height:18px;
      gap:2px;
      color:inherit;
      filter:drop-shadow(0 1px 2px rgba(0,0,0,.35));
    }
    #profilePostsFeed .post-card-fries-icon span{
      display:block;
      height:3px;
      border-radius:999px;
      background:currentColor;
    }
    #profilePostsFeed .post-card-fries-icon span:nth-child(1){width:17px;}
    #profilePostsFeed .post-card-fries-icon span:nth-child(2){width:11px;}
    #profilePostsFeed .post-card-fries-icon span:nth-child(3){width:17px;}
    #profilePostsFeed .post-card-fries-icon span:nth-child(4){width:11px;}
    #profilePostsFeed .mf-menu.open{display:block;}
    #profilePostsFeed .mf-menu a,#profilePostsFeed .mf-menu button{
      width:100%;display:flex;align-items:center;gap:10px;padding:10px 12px;border:0;background:transparent;
      border-radius:10px;font-weight:700;color:#101828;text-decoration:none;cursor:pointer;
    }
    #profilePostsFeed .mf-menu .mf-del{color:#b42318;}
    #profilePostsFeed .mf-title{padding:5px 5px 14px;font-size:22px;line-height:1.25;font-weight:900;color:var(--msb-palette-text, #101828);background:var(--msb-palette-bg, transparent);}
    #profilePostsFeed .mf-slide-copy{width:100%;max-width:100%;box-sizing:border-box;}
    #profilePostsFeed .mf-slide-title{padding:0 5px 6px;font-size:15px;font-weight:700;line-height:1.3;color:var(--msb-palette-text, #1f2937);}
    #profilePostsFeed .mf-slide-summary{padding:0 5px 12px;font-size:13px;line-height:1.45;color:var(--msb-palette-text-muted, #4b5563);}
    #profilePostsFeed .mf-slide-summary .post-slide-summary-p{margin:0;}
    #profilePostsFeed .mf-slide-summary .post-slide-summary-list{margin:0;padding-left:1.15em;list-style:disc}
    #profilePostsFeed .mf-slide-summary .post-slide-summary-list li{margin:0 0 .35em}
    #profilePostsFeed .mf-body{font-size:15px;color:var(--msb-palette-text-muted, #344054);word-break:break-word;text-align:left;background:var(--msb-palette-bg, transparent);}
    #profilePostsFeed .mf-body .mf-body-formatted{text-align:left;}
    #profilePostsFeed .mf-body .post-card-paragraph{margin:0 0 12px;text-align:left;white-space:normal;word-break:break-word;display:block;}
    #profilePostsFeed .mf-body .post-card-paragraph:last-child{margin-bottom:0;}
    #profilePostsFeed .mf-body .mf-body-formatted.is-clamped{max-height:6.6em;overflow:hidden;}
    #profilePostsFeed .mf-body .mf-readmore{text-decoration:none;color:var(--msb-palette-text, #0b1220);white-space:nowrap;font-weight:800;}
    #profilePostsFeed .mf-actions{padding:15px 5px 8px;display:flex;align-items:center;justify-content:space-between;gap:10px;}
    #profilePostsFeed .mf-card:has(.mf-head--on-media) > .mf-actions{padding:10px 0 8px!important;}
    #profilePostsFeed .mf-actions .mf-left{display:flex;gap:20px;align-items:center;}
    #profilePostsFeed .mf-actions .mf-right{display:flex;align-items:center;margin-left:auto;}
    #profilePostsFeed .mf-act{
      border:0;background:transparent;display:flex;align-items:center;gap:6px;padding:0;cursor:pointer;
      color:var(--msb-palette-icon, var(--msb-palette-text, #101828));text-decoration:none;
    }
    #profilePostsFeed .mf-act i{font-size:20px;color:inherit;}
    #profilePostsFeed .mf-act .mf-num{font-size:14px;font-weight:800;color:var(--msb-palette-text, #101828);}
    #profilePostsFeed .mf-act.mf-save .mf-num,#profilePostsFeed .mf-act.mf-share .mf-num{display:inline;}
    #profilePostsFeed .mf-act.is-love i{color:var(--msb-love-color, #7c3aed) !important;}
    #profilePostsFeed .mf-act.is-save i{color:#f5c518 !important;}
    #profilePostsFeed .mf-act.is-share i{color:#374151 !important;}
    #profilePostsFeed .mf-act .msb-pact{
      filter:var(--msb-pact-contrast-filter, drop-shadow(0 0 1.35px rgba(255,255,255,.98)) drop-shadow(0 1px 2px rgba(0,0,0,.55))) !important;
    }
    #profilePostsFeed .mf-act .mf-num{
      text-shadow:var(--msb-pact-contrast-text-shadow, 0 0 2px rgba(255,255,255,.95), 0 1px 2px rgba(0,0,0,.45)) !important;
    }
    #profilePostsFeed > .mf-card{
      position:relative;
      border-bottom:0 !important;
      padding-bottom:18px;
      margin-bottom:18px;
    }
    #profilePostsFeed > .mf-card::after{
      content:"";
      position:absolute;
      left:calc(-1 * var(--post-divider-left-offset, var(--profile-post-divider-offset, 0px)));
      right:calc(-1 * var(--post-divider-right-offset, var(--profile-post-divider-offset, 0px)));
      bottom:0;
      height:1px;
      background:var(--msb-palette-border, rgba(15,23,42,.08));
      pointer-events:none;
    }
    #profilePostsFeed > .mf-card:last-child{
      margin-bottom:0;
    }
    #profilePostsFeed .mf-feed-empty{padding:48px 24px 56px;}
    body.profile-page.profile-leftbar-open{overflow-x:hidden;}
    @media (min-width:1025px){
      #profilePostsFeed.mf-feed{max-width:614px;margin:0 auto;padding:0 0 96px;}
      #profilePostsFeed .mf-card{
        width:100%;max-width:100%;
        border:0;border-radius:0;box-shadow:none;
        margin:0 auto 18px;overflow:visible;background:var(--msb-palette-bg, #f5f7fb);
      }
      #profilePostsFeed .mf-card.mf-card-text-only:not(.mf-card-phone-shot){
        width:100% !important;max-width:100% !important;
      }
      #profilePostsFeed .mf-card.mf-card-phone-shot:not(.is-multi-media-post){
        width:min(100%, var(--post-media-card-width, 340px)) !important;
        max-width:100% !important;margin-inline:auto !important;
      }
      #profilePostsFeed .mf-head{padding:1px 1px 8px;gap:14px;}
      #profilePostsFeed .mf-avatar{width:35px;height:35px;flex:0 0 35px;}
      #profilePostsFeed .mf-name{font-size:14px;font-weight:800;line-height:1.2;color:#111827;}
      #profilePostsFeed .mf-title{padding:2px 1px 14px;font-size:16px;line-height:1.25;font-weight:900;}
      #profilePostsFeed .mf-body{font-size:15px;}
    }
    @media (max-width:767px){
      #profilePostsFeed.mf-feed{padding:10px 10px 80px;}
      #profilePostsFeed .mf-card.mf-card-text-only:not(.mf-card-phone-shot){
        width:100% !important;max-width:100% !important;
        margin-left:auto !important;margin-right:auto !important;
      }
      #profilePostsFeed .mf-card.mf-card-phone-shot:not(.is-multi-media-post){
        width:min(100%, var(--post-media-card-width, min(78vw, 340px))) !important;
        max-width:min(calc(100% - 20px), 360px) !important;
        margin-left:auto !important;margin-right:auto !important;
      }
      #profilePostsFeed .mf-name{font-size:17px;color:#101828;}
      #profilePostsFeed .mf-title{padding:0 22px 12px;font-size:18px;line-height:1.3;}
      #profilePostsFeed .mf-body{font-size:14px;line-height:1.7;}
    }
    @media (min-width:768px) and (max-width:1024px){
      #profilePostsFeed .mf-card.mf-card-text-only:not(.mf-card-phone-shot){
        width:100% !important;max-width:100% !important;
        margin-left:auto !important;margin-right:auto !important;
      }
    }

    .about-wrap{padding:18px 26px 28px;}
    .about-head{display:flex;align-items:center;gap:14px;margin-bottom:18px;padding:16px 18px;border:1px solid var(--msb-palette-border, rgba(15,23,42,.08));background:var(--msb-palette-bg, #f8fafc);}
    .about-head .mini-avatar{width:58px;height:58px;border-radius:50%;overflow:hidden;flex:0 0 58px;background:var(--msb-palette-hover-bg, #eef2ff);border:2px solid var(--msb-palette-border, rgba(15,23,42,.08));}
    .about-head .mini-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
    .about-head .nm{font-weight:800;font-size:18px;line-height:1.15;color:var(--msb-palette-text, #0b1220);}
    .about-head .sub{margin-top:4px;font-size:13px;color:var(--msb-palette-text-muted, #667085);font-weight:700;}
    .about-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;}
    .about-registration-head{
      grid-column:1 / -1;
      margin-top:4px;
      padding:10px 12px;
      border-radius:12px;
      background:rgba(37,99,235,.08);
      border:1px solid rgba(37,99,235,.14);
      color:#1d4ed8;
      font-size:12px;
      font-weight:800;
      letter-spacing:.02em;
      text-transform:uppercase;
    }
    .about-card{display:flex;gap:12px;align-items:flex-start;padding:14px 15px;border:1px solid var(--msb-palette-border, rgba(15,23,42,.08));background:var(--msb-palette-bg, #f5f7fb);min-height:88px;}
    .about-ico{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--msb-palette-hover-bg, #eef2ff);color:var(--msb-palette-link, #4f46e5);flex:0 0 42px;}
    .about-ico i{font-size:20px;}
    .about-card .k{font-size:12px;text-transform:uppercase;letter-spacing:.06em;font-weight:900;color:var(--msb-palette-text-muted, #667085);margin-bottom:6px;}
    .about-card .v{font-size:14px;line-height:1.45;color:var(--msb-palette-text, #0b1220);font-weight:700;word-break:break-word;}
    .about-card .v.empty{color:var(--msb-palette-text-muted, #98a2b3);font-weight:700;font-style:italic;}
    .about-note{margin-top:16px;padding:12px 14px;border:1px dashed var(--msb-palette-border-strong, rgba(79,70,229,.28));background:var(--msb-palette-surface-2, #f8faff);color:var(--msb-palette-link, #4338ca);font-size:13px;font-weight:700;}
    .about-topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;flex-wrap:wrap;}
    .about-title{font-size:15px;font-weight:900;color:var(--msb-palette-text, #0b1220);letter-spacing:.02em;}
    .about-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
    .about-edit-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;background:var(--msb-palette-action-soft,#eef2ff);color:var(--msb-palette-action,#4338ca);text-decoration:none;font-size:13px;font-weight:800;border:1px solid var(--msb-palette-border-strong,rgba(79,70,229,.22));}
    .about-edit-btn:hover,.about-edit-btn:focus{color:var(--msb-palette-action-strong,#4338ca);background:var(--msb-palette-nav-hover,#e0e7ff);text-decoration:none;box-shadow:none;}
    .about-flash{padding:12px 14px;border:1px solid rgba(34,197,94,.20);background:#ecfdf3;color:#166534;font-size:13px;font-weight:800;border-radius:10px;}
    .coming-wrap{padding:30px 26px 32px;background-color:var(--msb-palette-bg, #f5f7fb);}
    .coming-card{padding:24px;border:1px dashed var(--msb-palette-border-strong, rgba(15,23,42,.14));background:var(--msb-palette-bg, #f8fafc);color:var(--msb-palette-text-muted, #475467);font-weight:700;text-align:center;}

    @media (max-width: 991px){
      .about-wrap{padding:14px 18px 20px;}
      .about-grid{grid-template-columns:1fr;}
      .about-head{padding:14px;}
      .about-topbar{align-items:stretch;}
      .about-actions{width:100%;}
      .about-edit-btn{width:100%;justify-content:center;}
      .coming-wrap{padding:18px;}
    }

    @media (max-width: 575px){
      .profile-cover{position:relative;top:auto;}
    }



    .gear-wrap{padding:0;background:var(--msb-palette-bg, #f5f7fb);background-image:none;min-height:480px;}
    .gear-shell{display:flex;align-items:stretch;min-height:480px;border:1px solid var(--msb-palette-border, #c0c2c4);background:var(--msb-palette-bg, #f5f7fb);overflow:hidden;}
    .gear-sidebar{width:min(320px, 38vw);flex:0 0 min(320px, 38vw);border-right:1px solid var(--msb-palette-border, #c0c2c4);background:var(--msb-palette-bg, #f5f7fb);display:flex;flex-direction:column;min-height:0;overflow:hidden;}
    .gear-sidebar-head{flex:0 0 auto;padding:22px 18px 14px;border-bottom:1px solid var(--msb-palette-border, #c0c2c4);background:var(--msb-palette-bg, #f5f7fb);}
    .gear-sidebar-title{font-size:22px;font-weight:900;color:var(--msb-palette-text, #0b1220);line-height:1.15;margin:0;}
    .gear-archive-shortcut{
      display:flex;
      align-items:center;
      gap:10px;
      margin-top:12px;
      padding:11px 12px;
      border-radius:12px;
      border:1px solid var(--msb-palette-border-strong, rgba(79,70,229,.22));
      background:var(--msb-palette-action-soft, #eef2ff);
      color:var(--msb-palette-action, #4338ca);
      text-decoration:none;
      font-size:13px;
      font-weight:900;
    }
    .gear-archive-shortcut:hover,
    .gear-archive-shortcut:focus{
      text-decoration:none;
      color:var(--msb-palette-action-strong, #3730a3);
      background:var(--msb-palette-nav-hover, #e0e7ff);
    }
    .gear-archive-shortcut i{font-size:18px;}
    .gear-search{width:100%;height:40px;border-radius:999px;border:1px solid var(--msb-palette-border-strong, rgba(15,23,42,.12));background:var(--msb-palette-bg, #f5f7fb);color:var(--msb-palette-text, #0b1220);font-size:13px;font-weight:700;padding:0 14px 0 38px;outline:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2398a2b3' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cline x1='16.65' y1='16.65' x2='21' y2='21'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:14px center;}
    .gear-search:focus{border-color:#4f46e5;box-shadow:0 0 0 4px rgba(79,70,229,.12);}
    .gear-search-wrap{display:none;margin-left:auto;flex:1 1 240px;max-width:min(360px, 42vw);min-width:180px;}
    body.profile-page.profile-gear-mode .gear-search-wrap{display:block;}
    .gear-search--head{width:100%;}
    .gear-nav{flex:1 1 0;min-height:0;overflow-y:auto;overflow-x:hidden;padding:8px 10px 16px;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:rgba(79,70,229,.35) transparent;}
    .gear-nav::-webkit-scrollbar{width:8px;}
    .gear-nav::-webkit-scrollbar-thumb{background:rgba(79,70,229,.28);border-radius:999px;}
    .gear-nav::-webkit-scrollbar-track{background:transparent;}
    .gear-nav-section{margin-bottom:4px;}
    .gear-nav-section-toggle{width:100%;display:flex;align-items:center;gap:10px;padding:11px 12px;border:1px solid var(--msb-palette-border, rgba(15,23,42,.08));background:var(--msb-palette-action-soft, var(--msb-palette-hover-bg, rgba(15,23,42,.05)));color:var(--msb-palette-text, #0b1220);font-size:14px;font-weight:900;text-align:left;cursor:pointer;flex:0 0 auto;}
    .gear-nav-section-toggle:hover,.gear-nav-section-toggle:focus{background:var(--msb-palette-nav-hover, var(--msb-palette-hover-bg, rgba(15,23,42,.08)));}
    .gear-nav-section-icon{width:34px;height:34px;border-radius:10px;background:var(--msb-palette-nav-active-bg, var(--msb-palette-action-soft, #eef2ff));color:var(--msb-palette-action, var(--msb-palette-link, #4338ca));display:flex;align-items:center;justify-content:center;flex:0 0 34px;}
    .gear-nav-section-icon i{font-size:18px;}
    .gear-nav-section-label{flex:1 1 auto;min-width:0;}
    .gear-nav-section-chevron{color:var(--msb-palette-text-muted, #98a2b3);font-size:14px;transition:transform .18s ease;}
    .gear-nav-section.is-open .gear-nav-section-chevron{transform:rotate(180deg);}
    .gear-nav-items{display:none;padding:2px 0 6px 44px;position:relative;z-index:1;}
    .gear-nav-section.is-open .gear-nav-items{display:block;max-height:min(320px, 42vh);overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:rgba(79,70,229,.35) transparent;}
    .gear-nav-items::-webkit-scrollbar{width:8px;}
    .gear-nav-items::-webkit-scrollbar-thumb{background:rgba(79,70,229,.28);border-radius:999px;}
    .gear-nav-items::-webkit-scrollbar-track{background:transparent;}
    .gear-nav-item{width:100%;display:flex;flex-direction:column;align-items:flex-start;gap:2px;padding:9px 12px;border:0;border-radius:10px;background:transparent;color:var(--msb-palette-text, #0b1220);font-size:13px;font-weight:800;text-align:left;cursor:pointer;position:relative;z-index:2;}
    .gear-nav-item:hover,.gear-nav-item:focus{background:var(--msb-palette-hover-bg, rgba(15,23,42,.05));}
    .gear-nav-item.is-active{background:var(--msb-palette-nav-active, rgba(79,70,229,.12));color:var(--msb-palette-link, #4338ca);}
    .gear-nav-item-meta{font-size:11px;font-weight:700;color:var(--msb-palette-text-muted, #667085);line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
    .gear-nav-item.is-active .gear-nav-item-meta{color:var(--msb-palette-link, #4338ca);opacity:.85;}
    .gear-main{flex:1 1 auto;min-width:0;min-height:0;overflow-y:auto;overflow-x:hidden;padding:24px 28px 30px;background:var(--msb-palette-bg, #f5f7fb);overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:rgba(79,70,229,.35) transparent;}
    .gear-main::-webkit-scrollbar{width:10px;}
    .gear-main::-webkit-scrollbar-thumb{background:rgba(79,70,229,.24);border-radius:999px;border:2px solid transparent;background-clip:padding-box;}
    .gear-main::-webkit-scrollbar-track{background:transparent;}
    .gear-detail-empty{display:flex;align-items:center;justify-content:center;min-height:320px;padding:24px;text-align:center;color:var(--msb-palette-text-muted, #667085);font-size:14px;font-weight:700;}
    .gear-detail-panel{display:none !important;}
    .gear-detail-panel.is-active{display:block !important;}
    .gear-detail-panel[hidden]{display:none !important;}
    .gear-detail-head{display:flex;align-items:flex-start;gap:14px;margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid var(--msb-palette-border, #c0c2c4);}
    .gear-detail-icon{width:52px;height:52px;border-radius:16px;background:var(--msb-palette-hover-bg, #eef2ff);color:var(--msb-palette-link, #4338ca);display:flex;align-items:center;justify-content:center;flex:0 0 52px;}
    .gear-detail-icon i{font-size:24px;}
    .gear-detail-title{font-size:20px;font-weight:900;color:var(--msb-palette-text, #0b1220);line-height:1.2;margin:0 0 6px;}
    .gear-detail-desc{font-size:13px;color:var(--msb-palette-text-muted, #667085);font-weight:700;line-height:1.55;margin:0;}
    .gear-detail-chips{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px;}
    .gear-chip{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;background:var(--msb-palette-hover-bg, #eef2ff);color:var(--msb-palette-link, #4338ca);font-size:11px;font-weight:900;letter-spacing:.02em;}
    .gear-detail-body{max-width:640px;}
    .gear-detail-control-label{display:block;font-size:12px;font-weight:900;color:var(--msb-palette-text-muted, #667085);margin:0 0 8px;text-transform:uppercase;letter-spacing:.04em;}
    .gear-detail-open-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 18px;border-radius:12px;background:var(--msb-palette-btn-bg,var(--msb-palette-action,#4338ca));color:var(--msb-palette-btn-text,#ffffff);border:1px solid var(--msb-palette-btn-bg,var(--msb-palette-action,#4338ca));text-decoration:none;font-size:14px;font-weight:900;-webkit-text-fill-color:var(--msb-palette-btn-text,#ffffff);}
    .gear-detail-open-btn:hover,.gear-detail-open-btn:focus{text-decoration:none;background:var(--msb-palette-btn-hover-bg,var(--msb-palette-action-strong,#3730a3));color:var(--msb-palette-btn-text,#ffffff);border-color:var(--msb-palette-btn-hover-bg,var(--msb-palette-action-strong,#3730a3));-webkit-text-fill-color:var(--msb-palette-btn-text,#ffffff);}
    .gear-detail-open-btn i,.gear-detail-open-btn .icon,.gear-detail-open-btn [class*="ion-"]{color:var(--msb-palette-btn-text,#ffffff);}
    .gear-tag{display:inline-flex;align-items:center;justify-content:center;padding:5px 9px;border-radius:999px;background:var(--msb-palette-hover-bg, #f3f4f6);color:var(--msb-palette-text-muted, #475467);font-size:10px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;}
    .gear-note{margin-top:16px;padding:12px 14px;border:1px dashed var(--msb-palette-border-strong, rgba(79,70,229,.24));background:var(--msb-palette-surface-2, #f8faff);color:var(--msb-palette-link, #4338ca);font-size:13px;font-weight:700;}
    .gear-control-wrap{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
    .gear-control-wrap--detail{align-items:flex-start;flex-direction:column;}
    .gear-control{width:100%;max-width:360px;min-width:180px;height:44px;border-radius:10px;border:1px solid var(--msb-palette-border-strong, #c0c2c4);background:var(--msb-palette-bg, #f5f7fb);color:var(--msb-palette-text, #0b1220);font-size:13px;font-weight:800;padding:0 12px;outline:none;}
    .gear-control:focus{border-color:#4f46e5;box-shadow:0 0 0 4px rgba(79,70,229,.12);}
    .gear-control-wrap--color{flex-direction:row;align-items:center;gap:12px;}
    .gear-progress-picker{
      width:100%;
      max-width:360px;
      margin:0 0 14px;
      padding:10px;
      border-radius:12px;
      border:1px solid var(--msb-palette-border-strong, rgba(15,23,42,.14));
      background:var(--msb-palette-surface-2, rgba(255,255,255,.55));
      box-shadow:inset 0 0 0 1px rgba(255,255,255,.2);
    }
    .gear-progress-sv{
      position:relative;
      width:100%;
      height:200px;
      border-radius:8px;
      border:1px solid rgba(15,23,42,.12);
      overflow:hidden;
      cursor:crosshair;
      touch-action:none;
      background:
        linear-gradient(to bottom, rgba(0,0,0,0), #000000),
        linear-gradient(to right, #ffffff, var(--gear-progress-hue, #ff0000));
    }
    .gear-progress-sv-thumb{
      position:absolute;
      width:16px;
      height:16px;
      margin:-8px 0 0 -8px;
      border-radius:50%;
      border:2px solid #fff;
      box-shadow:0 0 0 1px rgba(15,23,42,.45), 0 1px 4px rgba(15,23,42,.35);
      pointer-events:none;
      left:100%;
      top:0;
      background:transparent;
      z-index:2;
    }
    .gear-progress-sv.is-dragging .gear-progress-sv-thumb{
      transition:none;
    }
    .gear-progress-hue-row{
      display:flex;
      align-items:center;
      gap:10px;
      margin-top:10px;
    }
    .gear-progress-swatch{
      width:28px;
      height:28px;
      border-radius:50%;
      border:2px solid rgba(255,255,255,.9);
      box-shadow:0 0 0 1px rgba(15,23,42,.25);
      flex:0 0 auto;
      background:var(--gear-progress-swatch, #8d514f);
    }
    .gear-progress-hue{
      -webkit-appearance:none;
      appearance:none;
      flex:1 1 auto;
      height:14px;
      border-radius:999px;
      border:1px solid rgba(15,23,42,.12);
      outline:none;
      cursor:pointer;
      background:linear-gradient(
        to right,
        #ff0000 0%,
        #ffff00 17%,
        #00ff00 33%,
        #00ffff 50%,
        #0000ff 67%,
        #ff00ff 83%,
        #ff0000 100%
      );
    }
    .gear-progress-hue::-webkit-slider-thumb{
      -webkit-appearance:none;
      appearance:none;
      width:18px;
      height:18px;
      border-radius:50%;
      background:#fff;
      border:2px solid rgba(15,23,42,.35);
      box-shadow:0 1px 4px rgba(15,23,42,.3);
      cursor:pointer;
    }
    .gear-progress-hue::-moz-range-thumb{
      width:18px;
      height:18px;
      border-radius:50%;
      background:#fff;
      border:2px solid rgba(15,23,42,.35);
      box-shadow:0 1px 4px rgba(15,23,42,.3);
      cursor:pointer;
    }
    /* During Progress preview: every shell uses the exact same bg — no lagging overlays. */
    html.msb-progress-previewing body,
    html.msb-progress-previewing .sh-mainpanel,
    html.msb-progress-previewing .sh-pagebody,
    html.msb-progress-previewing .sh-headpanel,
    html.msb-progress-previewing .sh-sideleft-menu,
    html.msb-progress-previewing .feed-ig-rail,
    html.msb-progress-previewing .ig-wrap,
    html.msb-progress-previewing .ig-profile-shell,
    html.msb-progress-previewing .ig-profile-head,
    html.msb-progress-previewing .ig-profile-scroll,
    html.msb-progress-previewing .ig-highlights,
    html.msb-progress-previewing .ig-tabs,
    html.msb-progress-previewing .profile-panel,
    html.msb-progress-previewing .coming-wrap,
    html.msb-progress-previewing .gear-wrap,
    html.msb-progress-previewing .gear-shell,
    html.msb-progress-previewing .gear-sidebar,
    html.msb-progress-previewing .gear-sidebar-head,
    html.msb-progress-previewing .gear-main,
    html.msb-progress-previewing .gear-nav,
    html.msb-progress-previewing .gear-nav-section-toggle,
    html.msb-progress-previewing .gear-nav-item,
    html.msb-progress-previewing .gear-nav-item.is-active,
    html.msb-progress-previewing .gear-nav-item:hover,
    html.msb-progress-previewing .gear-nav-item:focus,
    html.msb-progress-previewing .gear-detail-panel,
    html.msb-progress-previewing .gear-detail-head,
    html.msb-progress-previewing .gear-detail-body,
    html.msb-progress-previewing .gear-detail-control,
    html.msb-progress-previewing .gear-progress-picker,
    html.msb-progress-previewing .gear-search-wrap,
    html.msb-progress-previewing .about-head,
    html.msb-progress-previewing .about-card,
    html.msb-progress-previewing .coming-card{
      background: var(--msb-palette-bg, #f5f7fb) !important;
      background-color: var(--msb-palette-bg, #f5f7fb) !important;
      background-image: none !important;
      transition: none !important;
    }
    html.msb-progress-previewing .gear-search,
    html.msb-progress-previewing .gear-search--head{
      background-color: var(--msb-palette-bg, #f5f7fb) !important;
      transition: none !important;
    }
    html.msb-progress-previewing .gear-nav-section-icon{
      background: var(--msb-palette-bg, #f5f7fb) !important;
      background-color: var(--msb-palette-bg, #f5f7fb) !important;
    }
    html.msb-progress-previewing{
      transition: none !important;
    }
    .gear-color-control{
      width:56px !important;
      min-width:56px !important;
      max-width:56px !important;
      height:44px !important;
      padding:4px !important;
      cursor:pointer;
      background:transparent !important;
    }
    .gear-color-hex{
      font-size:13px;
      font-weight:800;
      color:var(--msb-palette-text, #0b1220);
      letter-spacing:.02em;
    }
    .gear-progress-save-btn{
      height:44px;
      min-width:96px;
      padding:0 18px;
      border-radius:10px;
      border:1px solid var(--msb-palette-btn-bg, var(--msb-palette-action, #4338ca));
      background:var(--msb-palette-btn-bg, var(--msb-palette-action, #4338ca));
      color:var(--msb-palette-btn-text, #fff);
      font-size:13px;
      font-weight:900;
      cursor:pointer;
    }
    .gear-progress-save-btn:hover:not(:disabled),
    .gear-progress-save-btn:focus:not(:disabled){
      background:var(--msb-palette-btn-hover-bg, var(--msb-palette-action-strong, #3730a3));
      border-color:var(--msb-palette-btn-hover-bg, var(--msb-palette-action-strong, #3730a3));
      outline:none;
    }
    .gear-progress-save-btn:disabled{
      opacity:.45;
      cursor:default;
    }
    .gear-progress-save-hint{
      margin:10px 0 0;
      font-size:12px;
      font-weight:700;
      color:var(--msb-palette-text-muted, #667085);
      line-height:1.45;
    }
    .gear-appearance-select{max-width:360px;}
    .gear-save-state{font-size:11px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;color:#98a2b3;min-width:46px;}
    .gear-save-state.is-saving{color:#b45309;}
    .gear-save-state.is-saved{color:#047857;}
    .gear-save-state.is-error{color:#b42318;}
    @media (max-width: 991px){
      .gear-shell{flex-direction:column;min-height:0;}
      .gear-sidebar{width:100%;flex:0 0 auto;min-height:0;max-height:none;border-right:0;border-bottom:1px solid var(--msb-palette-border, #c0c2c4);}
      .gear-nav{max-height:min(340px, 42vh);}
      .gear-nav-section.is-open .gear-nav-items{max-height:min(280px, 36vh);}
      .gear-main{padding:18px 16px 24px;}
    }

    body.profile-page.profile-gear-mode .ig-profile-scroll{display:flex;flex-direction:column;min-height:0;overflow:hidden;}
    body.profile-page.profile-gear-mode .ig-tabs{flex:0 0 auto;}
    body.profile-page.profile-gear-mode .ig-gallery-filter[hidden]{display:none !important;}
    body.profile-page.profile-gear-mode #panel-gear.active{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;overflow:hidden;}
    body.profile-page.profile-gear-mode #panel-gear.active .gear-wrap{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;}
    body.profile-page.profile-gear-mode #panel-gear.active .gear-shell{flex:1 1 auto;min-height:0;height:auto;max-height:none;}
    body.profile-page.profile-gear-mode #panel-gear.active .gear-sidebar{min-height:0;height:auto;}
    body.profile-page.profile-gear-mode #panel-gear.active .gear-main{min-height:0;}
    @media (max-width: 575px){
      .gear-nav-items{padding-left:12px;}
      .gear-search-wrap{flex:1 1 100%;max-width:none;min-width:0;order:10;margin-left:0;margin-top:8px;}
      body.profile-page.profile-gear-mode .ig-row1{align-items:stretch;}
    }

    .profile-cover{position:sticky;top:0px;z-index:4;height:230px;border-radius:28px 28px 0 0;overflow:hidden;background:linear-gradient(135deg,#0f172a,#4338ca 55%,#7c3aed);margin:-28px -28px 24px;}
    .profile-cover img{width:100%;height:100%;object-fit:cover;display:block;}
    .profile-cover::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(15,23,42,.12),rgba(15,23,42,.34));pointer-events:none;}
    .profile-cover-badge{position:absolute;left:20px;bottom:18px;z-index:2;display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:rgba(255,255,255,.88);backdrop-filter:blur(10px);color:#0f172a;font-size:12px;font-weight:900;box-shadow:0 10px 24px rgba(15,23,42,.16);}
    .gear-upload-form{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
    .gear-upload-form--detail{align-items:flex-start;flex-direction:column;}
    .gear-upload-input{position:absolute;left:-9999px;opacity:0;pointer-events:none;}
    .gear-upload-btn{display:inline-flex;align-items:center;justify-content:center;height:40px;padding:0 14px;border-radius:10px;border:1px solid var(--msb-palette-btn-bg,var(--msb-palette-action,#4338ca));background:var(--msb-palette-btn-bg,var(--msb-palette-action,#4338ca));color:var(--msb-palette-btn-text,#ffffff);font-size:12px;font-weight:900;cursor:pointer;white-space:nowrap;margin:0;}
    .gear-upload-btn:hover{background:var(--msb-palette-btn-hover-bg,var(--msb-palette-action-strong,#3730a3));border-color:var(--msb-palette-btn-hover-bg,var(--msb-palette-action-strong,#3730a3));color:var(--msb-palette-btn-text,#ffffff);}
    .gear-upload-hint{font-size:11px;color:#667085;font-weight:800;max-width:180px;text-align:right;}
    .bestprofile-avatar img,.bestprofile-avatar .avatar-photo,.peerAvatar img,.avatar-circle img,.chat-item .avatar img{width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block;}

    html[data-theme="dark"] body.profile-page .ig-card,
    html[data-theme="dark"] body.profile-page .ig-profile-shell,
    html[data-theme="dark"] body.profile-page .ig-profile-head,
    html[data-theme="dark"] body.profile-page .ig-profile-scroll,
    html[data-theme="dark"] body.profile-page .ig-highlights,
    html[data-theme="dark"] body.profile-page .ig-tabs,
    html[data-theme="dark"] body.profile-page .ig-avatar,
    html[data-theme="dark"] body.profile-page .profile-panel,
    html[data-theme="dark"] body.profile-page .coming-wrap,
    html[data-theme="dark"] body.profile-page .profile-cover-badge,
    html[data-theme="dark"] body.profile-page .ig-gallery-search input,
    html[data-theme="dark"] body.profile-page .ig-gallery-search button,
    html[data-theme="dark"] body.profile-page .ig-gallery-filter select,
    html[data-theme="dark"] body.profile-page .about-head,
    html[data-theme="dark"] body.profile-page .about-card,
    html[data-theme="dark"] body.profile-page .about-note,
    html[data-theme="dark"] body.profile-page .about-wrap,
    html[data-theme="dark"] body.profile-page .coming-card,
    html[data-theme="dark"] body.profile-page .gear-wrap,
    html[data-theme="dark"] body.profile-page .gear-shell,
    html[data-theme="dark"] body.profile-page .gear-sidebar-head,
    html[data-theme="dark"] body.profile-page .gear-main,
    html[data-theme="dark"] body.profile-page .gear-search,
    html[data-theme="dark"] body.profile-page .gear-control,
    html[data-theme="dark"] body.profile-page .gear-upload-btn,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-card,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-head:not(.mf-head--on-media),
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-title,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-body,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-actions,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-foot,
    html.dark-auto body.profile-page .ig-card,
    html.dark-auto body.profile-page .ig-profile-shell,
    html.dark-auto body.profile-page .ig-profile-head,
    html.dark-auto body.profile-page .ig-profile-scroll,
    html.dark-auto body.profile-page .ig-highlights,
    html.dark-auto body.profile-page .ig-tabs,
    html.dark-auto body.profile-page .ig-avatar,
    html.dark-auto body.profile-page .ig-top,
    html.dark-auto body.profile-page .profile-panel,
    html.dark-auto body.profile-page .coming-wrap,
    html.dark-auto body.profile-page .profile-cover-badge,
    html.dark-auto body.profile-page .ig-gallery-search input,
    html.dark-auto body.profile-page .ig-gallery-search button,
    html.dark-auto body.profile-page .ig-gallery-filter select,
    html.dark-auto body.profile-page .about-head,
    html.dark-auto body.profile-page .about-card,
    html.dark-auto body.profile-page .about-note,
    html.dark-auto body.profile-page .about-wrap,
    html.dark-auto body.profile-page .coming-card,
    html.dark-auto body.profile-page .gear-wrap,
    html.dark-auto body.profile-page .gear-shell,
    html.dark-auto body.profile-page .gear-sidebar-head,
    html.dark-auto body.profile-page .gear-main,
    html.dark-auto body.profile-page .gear-search,
    html.dark-auto body.profile-page .gear-control,
    html.dark-auto body.profile-page .gear-upload-btn,
    html.dark-auto body.profile-page #profilePostsFeed .mf-card,
    html.dark-auto body.profile-page #profilePostsFeed .mf-head:not(.mf-head--on-media),
    html.dark-auto body.profile-page #profilePostsFeed .mf-title,
    html.dark-auto body.profile-page #profilePostsFeed .mf-body,
    html.dark-auto body.profile-page #profilePostsFeed .mf-actions,
    html.dark-auto body.profile-page #profilePostsFeed .mf-foot,
    html.dark-auto body.profile-page #profilePostsFeed,
    html.dark-auto body.profile-page #profilePostsFeed.mf-feed,
    html[data-theme="dark"] body.profile-page #profilePostsFeed,
    html[data-theme="dark"] body.profile-page #profilePostsFeed.mf-feed {
      background-color: var(--msb-palette-bg, #171d24) !important;
      background-image: none !important;
      color: var(--msb-palette-text, #f3f6fb) !important;
      border-color: var(--msb-palette-border, rgba(255,255,255,.12)) !important;
    }
    html.dark-auto body.profile-page .ig-story-ring img,
    html.dark-auto body.profile-page .ig-story-thumb,
    html[data-theme="dark"] body.profile-page .ig-story-ring img,
    html[data-theme="dark"] body.profile-page .ig-story-thumb {
      border-color: var(--msb-palette-bg, #171d24) !important;
      background: var(--msb-palette-bg, #171d24) !important;
    }
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-body,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-time,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-dot {
      color: var(--msb-palette-text-muted, #a9b6c8) !important;
    }
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-body .mf-readmore {
      color: var(--msb-palette-text, #f3f6fb) !important;
    }
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-act,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-act .mf-num,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-act i {
      color: var(--msb-palette-icon, var(--msb-palette-text, #f3f6fb)) !important;
    }
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-act.is-love,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-act.is-love i,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-act.is-love .mf-num {
      color: #ea445a !important;
    }
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-act.is-save i {
      color: #f5c518 !important;
    }
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-act.is-share i {
      color: var(--msb-palette-action, #93c5fd) !important;
    }

    html.dark-auto .profile-cover,
    html[data-theme="dark"] .profile-cover {
      background: linear-gradient(135deg,#0b1220,#312e81 55%,#581c87);
    }

    html.dark-auto:not([data-msb-appearance]) .ig-avatar,
    html[data-theme="dark"]:not([data-msb-appearance]) .ig-avatar,
    html.dark-auto:not([data-msb-appearance]) .ig-story-name,
    html[data-theme="dark"]:not([data-msb-appearance]) .ig-story-name,
    html.dark-auto:not([data-msb-appearance]) .ig-story-empty,
    html[data-theme="dark"]:not([data-msb-appearance]) .ig-story-empty,
    html.dark-auto:not([data-msb-appearance]) .gear-detail-icon,
    html[data-theme="dark"]:not([data-msb-appearance]) .gear-detail-icon,
    html.dark-auto:not([data-msb-appearance]) .gear-nav-section-icon,
    html[data-theme="dark"]:not([data-msb-appearance]) .gear-nav-section-icon,
    html.dark-auto:not([data-msb-appearance]) .about-ico,
    html[data-theme="dark"]:not([data-msb-appearance]) .about-ico,
    html.dark-auto:not([data-msb-appearance]) .gear-chip,
    html[data-theme="dark"]:not([data-msb-appearance]) .gear-chip,
    html.dark-auto:not([data-msb-appearance]) .gear-tag,
    html[data-theme="dark"]:not([data-msb-appearance]) .gear-tag {
      background: var(--msb-palette-bg, #171d24) !important;
      border-color: rgba(255,255,255,.12) !important;
      color: #93c5fd !important;
    }
    html[data-msb-appearance] body.profile-page .ig-story-name,
    html[data-msb-appearance] body.profile-page .ig-story-empty,
    html[data-msb-appearance] body.profile-page .ig-story-create .ig-story-ring{
      background: var(--msb-palette-bg, #171d24) !important;
      background-color: var(--msb-palette-bg, #171d24) !important;
      background-image: none !important;
      border-color: var(--msb-palette-border, rgba(255,255,255,.12)) !important;
      color: var(--msb-palette-link, var(--msb-palette-text, #93c5fd)) !important;
    }
    html[data-msb-appearance] body.profile-page .gear-nav-section-toggle,
    html[data-msb-appearance] body.profile-page .gear-nav-section-toggle:hover,
    html[data-msb-appearance] body.profile-page .gear-nav-section-toggle:focus,
    html[data-msb-appearance] body.profile-page .gear-nav-section-icon,
    html[data-msb-appearance] body.profile-page .gear-nav-item.is-active,
    html[data-msb-appearance] body.profile-page .gear-detail-icon,
    html[data-msb-appearance] body.profile-page .gear-chip,
    html[data-msb-appearance] body.profile-page .gear-tag{
      background: var(--msb-palette-bg, #171d24) !important;
      background-color: var(--msb-palette-bg, #171d24) !important;
      background-image: none !important;
      border-color: var(--msb-palette-border, rgba(255,255,255,.12)) !important;
    }
    html[data-theme="dark"] body.profile-page .gear-nav-section-toggle,
    html[data-theme="dark"] body.profile-page .gear-nav-section-toggle:hover,
    html[data-theme="dark"] body.profile-page .gear-nav-section-toggle:focus,
    html[data-theme="dark"] body.profile-page .gear-nav-section-icon,
    html[data-theme="dark"] body.profile-page .gear-nav-item.is-active,
    html[data-theme="dark"] body.profile-page .gear-detail-icon,
    html[data-theme="dark"] body.profile-page .gear-chip,
    html[data-theme="dark"] body.profile-page .gear-tag{
      background: var(--msb-palette-bg, #171d24) !important;
      background-color: var(--msb-palette-bg, #171d24) !important;
      background-image: none !important;
      border-color: var(--msb-palette-border, rgba(255,255,255,.12)) !important;
    }
    html.dark-auto:not([data-msb-appearance]) body.profile-page .ig-story-name,
    html.dark-auto:not([data-msb-appearance]) body.profile-page .ig-story-empty,
    html.dark-auto:not([data-msb-appearance]) body.profile-page .ig-story-create .ig-story-ring,
    html[data-theme="dark"]:not([data-msb-appearance]) body.profile-page .ig-story-name,
    html[data-theme="dark"]:not([data-msb-appearance]) body.profile-page .ig-story-empty,
    html[data-theme="dark"]:not([data-msb-appearance]) body.profile-page .ig-story-create .ig-story-ring{
      background: var(--msb-palette-bg, #171d24) !important;
      background-color: var(--msb-palette-bg, #171d24) !important;
      background-image: none !important;
      border-color: var(--msb-palette-border, rgba(255,255,255,.12)) !important;
    }

    html.dark-auto .ig-username,
    html[data-theme="dark"] .ig-username,
    html.dark-auto .ig-fullname-name,
    html[data-theme="dark"] .ig-fullname-name,
    html.dark-auto .ig-stat,
    html[data-theme="dark"] .ig-stat,
    html.dark-auto .ig-bio,
    html[data-theme="dark"] .ig-bio,
    html.dark-auto:not([data-msb-appearance]) .ig-tab.active,
    html[data-theme="dark"]:not([data-msb-appearance]) .ig-tab.active,
    html.dark-auto .about-head .nm,
    html[data-theme="dark"] .about-head .nm,
    html.dark-auto .about-card .v,
    html[data-theme="dark"] .about-card .v,
    html.dark-auto .about-title,
    html[data-theme="dark"] .about-title,
    html.dark-auto .gear-sidebar-title,
    html[data-theme="dark"] .gear-sidebar-title,
    html.dark-auto .gear-detail-title,
    html[data-theme="dark"] .gear-detail-title,
    html.dark-auto .gear-nav-section-toggle,
    html[data-theme="dark"] .gear-nav-section-toggle,
    html.dark-auto .gear-nav-item,
    html[data-theme="dark"] .gear-nav-item,
    html.dark-auto .profile-panel,
    html[data-theme="dark"] .profile-panel {
      color: #f3f6fb !important;
    }

    html.dark-auto .ig-bio .muted,
    html[data-theme="dark"] .ig-bio .muted,
    html.dark-auto .ig-handle,
    html[data-theme="dark"] .ig-handle,
    html.dark-auto .ig-tab,
    html[data-theme="dark"] .ig-tab,
    html.dark-auto .about-head .sub,
    html[data-theme="dark"] .about-head .sub,
    html.dark-auto .about-card .k,
    html[data-theme="dark"] .about-card .k,
    html.dark-auto .about-card .v.empty,
    html[data-theme="dark"] .about-card .v.empty,
    html.dark-auto .mf-feed-empty,
    html[data-theme="dark"] .mf-feed-empty,
    html.dark-auto .gear-detail-desc,
    html[data-theme="dark"] .gear-detail-desc,
    html.dark-auto .gear-nav-item-meta,
    html[data-theme="dark"] .gear-nav-item-meta,
    html.dark-auto .gear-upload-hint,
    html[data-theme="dark"] .gear-upload-hint,
    html.dark-auto .gear-save-state,
    html[data-theme="dark"] .gear-save-state,
    html.dark-auto .mf-feed-empty i,
    html[data-theme="dark"] .mf-feed-empty i {
      color: #a9b6c8 !important;
    }

    html.dark-auto .ig-name-sep,
    html[data-theme="dark"] .ig-name-sep {
      background: rgba(255,255,255,.28);
    }

    html.dark-auto .mf-feed-empty .mf-feed-empty-title,
    html[data-theme="dark"] .mf-feed-empty .mf-feed-empty-title {
      color: #f3f6fb !important;
    }

    html.dark-auto .ig-tabs,
    html[data-theme="dark"] .ig-tabs,
    html.dark-auto .ig-highlights,
    html[data-theme="dark"] .ig-highlights,
    html.dark-auto .ig-item,
    html[data-theme="dark"] .ig-item,
    html.dark-auto .about-head,
    html[data-theme="dark"] .about-head,
    html.dark-auto .about-card,
    html[data-theme="dark"] .about-card,
    html.dark-auto .gear-sidebar,
    html[data-theme="dark"] .gear-sidebar,
    html.dark-auto .gear-shell,
    html[data-theme="dark"] .gear-shell,
    html.dark-auto .gear-nav-item.is-active,
    html[data-theme="dark"] .gear-nav-item.is-active {
      border-color: rgba(255,255,255,.12) !important;
    }

    html.dark-auto .ig-btn,
    html[data-theme="dark"] .ig-btn,
    html.dark-auto:not([data-msb-appearance]) .about-edit-btn,
    html[data-theme="dark"]:not([data-msb-appearance]) .about-edit-btn {
      background: #1d4ed8 !important;
      color: #fff !important;
      border-color: rgba(96,165,250,.44) !important;
    }

    html.dark-auto .ig-btn.icon,
    html[data-theme="dark"] .ig-btn.icon {
      background: #111827 !important;
      color: #f3f6fb !important;
      border-color: rgba(255,255,255,.12) !important;
    }

    html.dark-auto .gear-chip,
    html[data-theme="dark"] .gear-chip,
    html.dark-auto .gear-tag,
    html[data-theme="dark"] .gear-tag {
      background: #111827 !important;
      color: #c7d2fe !important;
      border-color: rgba(255,255,255,.12) !important;
    }

    html.dark-auto .txtdesc,
    html[data-theme="dark"] .txtdesc {
      background:
        radial-gradient(120px 120px at 20% 15%, rgba(99,102,241,.22), transparent 60%),
        radial-gradient(140px 140px at 85% 20%, rgba(14,165,233,.18), transparent 60%),
        linear-gradient(145deg,#111827,#1f2937);
      border-color: rgba(255,255,255,.10);
      box-shadow: 0 8px 18px rgba(0,0,0,.28), inset 0 0 0 1px rgba(255,255,255,.04);
    }

</style>

<style id="profile-post-media-stage-css">
<?= post_media_stage_css('#profilePostsFeed.mf-feed') ?>
</style>

<style id="profile-post-media-radius-override">
  #profilePostsFeed .media-stage.standard-video-stage,
  #profilePostsFeed .media-stage.standard-image-stage,
  #profilePostsFeed .media-stage{
    overflow:hidden !important;
    border-radius:var(--post-media-radius,10px) !important;
  }
  #profilePostsFeed .media-stage.standard-video-stage > video,
  #profilePostsFeed .media-stage.standard-image-stage > img,
  #profilePostsFeed video.ig-smart-feed-video{
    border-radius:var(--post-media-radius,10px) !important;
    overflow:hidden !important;
  }
</style>

<style id="profile-media-head-overlay-css">
#profilePostsFeed .mf-media-shell:has(> .mf-head--on-media){
  display:grid!important;grid-template:1fr / 1fr;background:transparent!important;
}
#profilePostsFeed .mf-media-shell:has(> .mf-head--on-media) > .mf-media,
#profilePostsFeed .mf-media-shell:has(> .mf-head--on-media) > .media-stage,
#profilePostsFeed .mf-media-shell:has(> .mf-head--on-media) > .mf-head--on-media,
#profilePostsFeed .mf-media-shell:has(> .mf-head--on-media) > .mf-media-top-actions{
  grid-area:1 / 1;
}
#profilePostsFeed .mf-media-shell:has(> .mf-head--on-media) > .mf-media,
#profilePostsFeed .mf-media-shell:has(> .mf-head--on-media) > .media-stage{
  width:100%!important;max-width:100%!important;margin:0!important;padding:0!important;
  background:transparent!important;background-color:transparent!important;
}
#profilePostsFeed .mf-card:has(.mf-head--on-media){
  padding:8px 12px!important;
  box-sizing:border-box!important;
}
#profilePostsFeed .mf-card.mf-card-media-head-outside{
  padding:8px 12px!important;
  box-sizing:border-box!important;
}
#profilePostsFeed .mf-card:has(.mf-head--on-media) .media-stage.standard-video-stage,
#profilePostsFeed .mf-card:has(.mf-head--on-media) .media-stage.standard-image-stage,
#profilePostsFeed .mf-card.mf-card-media-head-outside .media-stage.standard-video-stage,
#profilePostsFeed .mf-card.mf-card-media-head-outside .media-stage.standard-image-stage{
  padding:0!important;
}
#profilePostsFeed .mf-media-shell > .mf-head--on-media{
  position:relative!important;align-self:start!important;justify-self:stretch!important;
  z-index:25!important;display:flex!important;align-items:center!important;gap:12px!important;
  padding:2px 6px 14px!important;box-sizing:border-box!important;width:100%!important;
  pointer-events:none!important;background:transparent!important;background-color:transparent!important;
  margin:0!important;border:0!important;box-shadow:none!important;
}
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-peer-link,
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-meta,
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-menu-wrap,
#profilePostsFeed .mf-media-shell > .mf-head--on-media .post-card-menu-wrap{
  pointer-events:auto!important;
  background:transparent!important;
  z-index:60!important;position:relative;
  margin-top: -10px;
  margin-left: -8px;
}
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-name,
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-time,
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-dot,
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-menu-btn:not(.post-card-menu-btn),
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-menu-btn:not(.post-card-menu-btn) i,
#profilePostsFeed .mf-media-shell > .mf-head--on-media .post-card-menu-btn,
#profilePostsFeed .mf-media-shell > .mf-head--on-media .post-card-fries-icon{
  color:#fff!important;text-shadow:0 2px 10px rgba(0,0,0,.34);
}
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-avatar img{border-color:#fff!important}
#profilePostsFeed .mf-media-shell:has(.mf-head--on-media) > .mf-media-top-actions{
  align-self:start!important;justify-self:end!important;position:relative!important;
  top:12px!important;right:calc(14px + 34px + 8px)!important;z-index:40!important;
}
#profilePostsFeed .mf-media-shell > .mf-media-top-actions .mf-friend-btn{
  pointer-events:auto;margin:0;display:inline-flex;align-items:center;justify-content:center;
  box-shadow:0 4px 14px rgba(15,23,42,.28);padding:7px 12px;font-size:11px;line-height:1;
}
#profilePostsFeed .mf-card .mf-media-shell > .mf-head--on-media{
  padding:22px 14px 12px!important;
}
#profilePostsFeed .mf-card .mf-media-shell > .mf-head--on-media > .post-card-menu-wrap,
#profilePostsFeed .mf-card .mf-media-shell > .mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap{
  margin-right:0!important;
  margin-left:auto!important;
}
#profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-wrap,
#profilePostsFeed .mf-head:not(.mf-head--on-media) .mf-menu-wrap.post-card-menu-wrap{
  flex:0 0 var(--pcm-on-media-circle-size, 36px)!important;
  width:var(--pcm-on-media-circle-size, 36px)!important;
  margin-left:auto!important;
}
#profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn{
  width:var(--pcm-on-media-circle-size, 36px)!important;
  height:var(--pcm-on-media-circle-size, 36px)!important;
  min-width:var(--pcm-on-media-circle-size, 36px)!important;
  min-height:var(--pcm-on-media-circle-size, 36px)!important;
  padding:0!important;
  flex:0 0 var(--pcm-on-media-circle-size, 36px)!important;
  border-radius:50%!important;
  /* border:1px solid var(--msb-palette-border, rgba(147,197,253,.85))!important; */
  background:var(--msb-palette-surface-2, rgba(255,255,255,.94))!important;
  color:var(--msb-palette-text, #5c3d2e)!important;
  display:inline-flex!important;
  align-items:center!important;
  justify-content:center!important;
  box-shadow:0 2px 8px rgba(15,23,42,.08)!important;
  line-height:1!important;
}
#profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn .post-card-fries-icon{
  font-size:16px!important;
  line-height:1!important;
  color:inherit!important;
  text-shadow:none!important;
}
#profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn:hover,
#profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn:focus{
  background:var(--msb-palette-surface, #fff)!important;
  outline:none!important;
  box-shadow:0 4px 12px rgba(15,23,42,.12)!important;
}
#profilePostsFeed .mf-menu.post-card-menu,
#profilePostsFeed .post-card-menu{
  top:calc(100% + 8px)!important;
}
</style>
<?php post_card_actions_menu_render_css(); ?>
<?php post_action_thin_icons_render_css(); ?>

</head>

<style>
body.profile-page #globalLiveModal:not(.is-open){
  display:none !important;
  visibility:hidden !important;
  opacity:0 !important;
  pointer-events:none !important;
}

body.profile-page #globalLiveModal:not(.is-open) .global-live-modal-dialog,
body.profile-page #globalLiveModal:not(.is-open) iframe,
body.profile-page #globalLiveModal:not(.is-open) video,
body.profile-page #globalLiveModal:not(.is-open) img,
body.profile-page #globalLiveModal:not(.is-open) aside{
  display:none !important;
}
</style>

<body class="profile-page<?php echo $selectedTab === 'posts' ? ' profile-posts-mode' : ''; ?><?php echo $selectedTab === 'gear' ? ' profile-gear-mode' : ''; ?>">
<?php
$forceFeedRail = true;
$skipHeaderThemeBootstrap = true;
include __DIR__ . '/includes/header.php';
?>

<div class="sh-mainpanel">
  <div class="sh-pagebody">

<div class="ig-wrap">
  <div class="ig-card ig-profile-shell">
    <!-- <div class="profile-cover">
      <?php if ($coverUrl !== ''): ?>
        <img src="<?php echo h($coverUrl); ?>?v=<?php echo time(); ?>" alt="Cover image" id="profileCoverPreview">
      <?php else: ?>
        <div id="profileCoverPreview"></div>
      <?php endif; ?>
      <div class="profile-cover-badge"><i class="icon ion-image"></i> Talentra cover</div>
    </div> -->

    <div class="ig-top ig-profile-head">
      <div class="ig-avatar"><img src="<?php echo h($avatarUrl); ?>" data-live-avatar="1" data-avatar-base="<?php echo h($avatarUrl); ?>" alt="Avatar"></div>

      <div class="ig-main">
        <div class="ig-row1">
          <div class="ig-name-line">
            <h2 class="ig-fullname-name"><?php echo h($profileDisplayName); ?></h2>
            <?php if ($username !== ''): ?>
              <span class="ig-name-sep" aria-hidden="true"></span>
              <span class="ig-handle"><?php echo h($username); ?></span>
            <?php endif; ?>
          </div>

          <a class="ig-btn back" href="#" onclick="if(window.history.length > 1){ history.back(); return false; } window.location.href='feed.php'; return false;"><i class="icon ion-arrow-left-c"></i>&nbsp;Back</a>
          <?php if ($isOwnProfile): ?>
            <a class="ig-btn edit" href="user_edit.php?return=<?php echo rawurlencode('profile.php'); ?>"><i class="icon ion-edit"></i>&nbsp;Edit</a>
            <a class="ig-btn icon" href="messages.php" title="Messages"><i class="icon ion-chatboxes"></i></a>
            <a class="ig-btn icon" href="contacts.php" title="Friends"><i class="icon ion-person-stalker"></i></a>
            <a class="ig-btn" href="contact_requests.php"><i class="icon ion-person-add"></i>&nbsp;Friend Requests</a>
          <?php elseif ($isViewedPublisher && $canFollowPublishers): ?>
            <button type="button" class="ig-btn publisher-follow-btn<?= $isFollowingPublisher ? ' is-following' : '' ?>" data-publisher-id="<?= (int)$viewId ?>">
              <?= $isFollowingPublisher ? 'Following' : 'Follow' ?>
            </button>
          <?php else: ?>
            <?php if ($liveVisitorMode): ?>
              <?php if ($friendStatus === 'outgoing_pending'): ?>
                <a class="ig-btn" href="contact_requests.php"><i class="icon ion-paper-airplane"></i>&nbsp;Request Sent</a>
              <?php elseif ($friendStatus === 'incoming_pending'): ?>
                <a class="ig-btn" href="contact_requests.php"><i class="icon ion-checkmark-circled"></i>&nbsp;Accept Friend</a>
              <?php else: ?>
                <a class="ig-btn" href="add_contact.php?friend=<?php echo rawurlencode($me['friend_code'] !== '' ? strtoupper($me['friend_code']) : ($username !== '' ? $username : (string)$viewId)); ?>"><i class="icon ion-person-add"></i>&nbsp;Add Friend</a>
              <?php endif; ?>
            <?php elseif ($friendStatus === 'friends'): ?>
              <a class="ig-btn icon" href="messages.php?<?php echo $me['friend_code'] !== '' ? 'peer=' . rawurlencode(strtoupper($me['friend_code'])) : 'id=' . (int)$viewId; ?>" title="Message"><i class="icon ion-chatboxes"></i></a>
              <a class="ig-btn" href="contacts.php"><i class="icon ion-checkmark"></i>&nbsp;Friends</a>
            <?php elseif ($friendStatus === 'outgoing_pending'): ?>
              <a class="ig-btn" href="contact_requests.php"><i class="icon ion-paper-airplane"></i>&nbsp;Request Sent</a>
            <?php elseif ($friendStatus === 'incoming_pending'): ?>
              <a class="ig-btn" href="contact_requests.php"><i class="icon ion-checkmark-circled"></i>&nbsp;Accept Friend</a>
            <?php else: ?>
              <a class="ig-btn" href="add_contact.php?friend=<?php echo rawurlencode($me['friend_code'] !== '' ? strtoupper($me['friend_code']) : ($username !== '' ? $username : (string)$viewId)); ?>"><i class="icon ion-person-add"></i>&nbsp;Add Friend</a>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (!$liveVisitorMode && $canManageProfilePrivate): ?>
            <div class="gear-search-wrap" id="gearSearchWrap">
              <label class="sr-only" for="gearSearchInput">Search settings</label>
              <input type="search" class="gear-search gear-search--head" id="gearSearchInput" placeholder="Search settings" autocomplete="off">
            </div>
          <?php endif; ?>
        </div>

        <?php if ($showPeerNotFound): ?>
          <div style="margin:16px 0 10px;padding:14px 16px;border-radius:10px;background:#fff3cd;color:#7a5a00;border:1px solid rgba(122,90,0,.18);font-weight:700;">
            Peer profile was not found for this link, so no friend data was loaded.
          </div>
        <?php endif; ?>

        <div class="ig-stats">
          <div class="ig-stat" data-profile-stat="posts"><b><?php echo (int)$statPosts; ?></b> posts</div>
          <div class="ig-stat"><b><?php echo (int)$statSocialCount; ?></b> <?php echo h($statSocialLabel); ?></div>
          <div class="ig-stat"><b><?php echo (int)$statFollowing; ?></b> following</div>
        </div>

        <div class="ig-bio">
          <?php if ($profileAccountBadge !== ''): ?><span class="profile-account-badge"><?php echo h($profileAccountBadge); ?></span><br><?php endif; ?>
          <?php if (trim($me['designation']) !== ''): ?><span class="muted"><?php echo h($me['designation']); ?></span><br><?php endif; ?>
          <!-- <?php if (trim($me['role']) !== ''): ?><span class="muted"><?php echo h($me['role']); ?></span><br><?php endif; ?>
          <?php if (trim($me['status']) !== ''): ?><?php echo h($me['status']); ?><br><?php endif; ?> -->
          <span class="muted">Joined <?php echo h($joinedLabel); ?></span>
        </div>
      </div>
    </div>

    <div class="ig-profile-scroll">

    <div class="ig-highlights" aria-label="Profile stories">
      <div class="ig-stories-track" id="profileStoriesTrack">
        <?php if ($isOwnProfile): ?>
          <a class="ig-story-item ig-story-create" href="dashboard.php?modal=1&amp;story=1&amp;from=profile" data-create-post-modal="1" aria-label="Create a story">
            <div class="ig-story-ring"><i class="icon ion-plus"></i></div>
            <span class="ig-story-name">New</span>
          </a>
        <?php endif; ?>
        <?php if ($profileStoryCatalog): ?>
          <?php foreach ($profileStoryCatalog as $storyIndex => $story): ?>
            <?php
              $storyKey = (string)($story['key'] ?? '');
              $ringSrc = trim((string)($story['ringSrc'] ?? $story['avatarUrl'] ?? ''));
              $ringType = strtolower(trim((string)($story['ringType'] ?? 'image')));
              $storyLabel = trim((string)($story['subtitle'] ?? ''));
              if ($storyLabel === '') {
                $storyLabel = 'Story';
              }
              $slide0 = (isset($story['slides'][0]) && is_array($story['slides'][0])) ? $story['slides'][0] : [];
              $capPreview = trim((string)($slide0['caption'] ?? ''));
            ?>
            <button
              type="button"
              class="ig-story-item"
              data-story-key="<?php echo h($storyKey); ?>"
              data-story-index="<?php echo (int)$storyIndex; ?>"
              data-post-id="<?php echo (int)($slide0['postId'] ?? 0); ?>"
              aria-label="Open story <?php echo h($storyLabel); ?>"
            >
              <div class="ig-story-ring">
                <?php if ($ringType === 'video' && $ringSrc !== ''): ?>
                  <video class="ig-story-thumb" src="<?php echo h($ringSrc); ?>" muted playsinline preload="metadata"></video>
                <?php elseif ($ringSrc !== ''): ?>
                  <img class="ig-story-thumb" src="<?php echo h($ringSrc); ?>" alt="">
                <?php elseif ($capPreview !== ''): ?>
                  <span class="ig-story-ring-text"><?php echo h(function_exists('mb_substr') ? (string)mb_substr($capPreview, 0, 18) : substr($capPreview, 0, 18)); ?></span>
                <?php else: ?>
                  <span class="ig-story-thumb" style="background:linear-gradient(135deg,#667eea,#764ba2);"></span>
                <?php endif; ?>
              </div>
              <span class="ig-story-name"><?php echo h($storyLabel); ?></span>
            </button>
          <?php endforeach; ?>
        <?php elseif (!$isOwnProfile): ?>
          <div class="ig-story-empty">No stories yet.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="ig-tabs" role="tablist" aria-label="Profile sections">
      <div class="ig-tab<?php echo $selectedTab === 'gallery' ? ' active' : ''; ?>" data-panel="gallery" role="tab" tabindex="<?php echo $selectedTab === 'gallery' ? '0' : '-1'; ?>" aria-selected="<?php echo $selectedTab === 'gallery' ? 'true' : 'false'; ?>">
        <i class="icon ion-images"></i>Gallery
      </div>
      <div class="ig-tab<?php echo $selectedTab === 'posts' ? ' active' : ''; ?>" data-panel="posts" role="tab" tabindex="<?php echo $selectedTab === 'posts' ? '0' : '-1'; ?>" aria-selected="<?php echo $selectedTab === 'posts' ? 'true' : 'false'; ?>">
        <i class="icon ion-grid"></i>Posts
      </div>
      <div class="ig-tab<?php echo $selectedTab === 'tags' ? ' active' : ''; ?>" data-panel="tags" role="tab" tabindex="<?php echo $selectedTab === 'tags' ? '0' : '-1'; ?>" aria-selected="<?php echo $selectedTab === 'tags' ? 'true' : 'false'; ?>">
        <i class="icon ion-ios-pricetag"></i>Tags
      </div>
      <div class="ig-tab<?php echo $selectedTab === 'about' ? ' active' : ''; ?>" data-panel="about" role="tab" tabindex="<?php echo $selectedTab === 'about' ? '0' : '-1'; ?>" aria-selected="<?php echo $selectedTab === 'about' ? 'true' : 'false'; ?>">
        <i class="icon ion-ios-person"></i>About
      </div>
      <?php if (!$liveVisitorMode && $canManageProfilePrivate): ?>
        <div class="ig-tab<?php echo $selectedTab === 'preserve' ? ' active' : ''; ?>" data-panel="preserve" role="tab" tabindex="<?php echo $selectedTab === 'preserve' ? '0' : '-1'; ?>" aria-selected="<?php echo $selectedTab === 'preserve' ? 'true' : 'false'; ?>">
          <i class="icon ion-ios-book"></i>Preserve
        </div>
        <div class="ig-tab<?php echo $selectedTab === 'gear' ? ' active' : ''; ?>" data-panel="gear" role="tab" tabindex="<?php echo $selectedTab === 'gear' ? '0' : '-1'; ?>" aria-selected="<?php echo $selectedTab === 'gear' ? 'true' : 'false'; ?>">
          <i class="icon ion-ios-gear"></i>Gear
        </div>
      <?php endif; ?>
    </div>

    <?php
      $profileFilterTab = in_array($selectedTab, ['gallery', 'posts', 'tags'], true) ? $selectedTab : 'posts';
      profile_render_gallery_filter(
        $profileFilterTab,
        $selectedGalleryCategoryId,
        $gallerySearch,
        $galleryCategories,
        $reqId,
        $reqUsername,
        $reqFriendCode,
        !in_array($selectedTab, ['gallery', 'posts', 'tags'], true)
      );
    ?>

    <div id="panel-gallery" class="profile-panel<?php echo $selectedTab === 'gallery' ? ' active' : ''; ?>">
      <?php
        $isProfileFriend = ($friendStatus === 'friends');
        $galleryVisDefault = $isOwnProfile ? 'private' : 'public';
        if ($isOwnProfile) {
          $galleryVisDefault = 'private';
        } elseif ($isProfileFriend) {
          $galleryVisDefault = 'friends';
        } else {
          $galleryVisDefault = 'public';
        }
        if ($galleryVisParam !== '') {
          if ($galleryVisParam === 'private' && !$isOwnProfile) {
            $galleryVisDefault = $isProfileFriend ? 'friends' : 'public';
          } elseif ($galleryVisParam === 'friends' && !$isOwnProfile && !$isProfileFriend) {
            $galleryVisDefault = 'public';
          } else {
            $galleryVisDefault = $galleryVisParam;
          }
        }
        if ($isOwnProfile) {
          $galleryVisTabs = [
            ['key' => 'private', 'label' => 'Private'],
            ['key' => 'friends', 'label' => 'Friend'],
            ['key' => 'public', 'label' => 'Public'],
          ];
        } elseif (!empty($profileIsPublisher)) {
          // Publisher visitors: public-only content — no visibility tab chrome.
          $galleryVisTabs = [];
          $galleryVisDefault = 'public';
        } elseif ($isProfileFriend) {
          $galleryVisTabs = [
            ['key' => 'friends', 'label' => 'Friend'],
            ['key' => 'public', 'label' => 'Public'],
          ];
        } else {
          // Strangers: public-only content — no visibility tab chrome.
          $galleryVisTabs = [];
          $galleryVisDefault = 'public';
        }
      ?>
      <?php if (!$showPeerNotFound && !empty($galleryVisTabs)): ?>
        <div class="ig-grid-heads" role="tablist" aria-label="Gallery visibility">
          <?php foreach ($galleryVisTabs as $tabMeta): ?>
            <button
              type="button"
              class="ig-vis-tab<?= $tabMeta['key'] === $galleryVisDefault ? ' is-active' : '' ?>"
              role="tab"
              data-vis="<?= h($tabMeta['key']) ?>"
              aria-selected="<?= $tabMeta['key'] === $galleryVisDefault ? 'true' : 'false' ?>"
            ><?= h($tabMeta['label']) ?></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php
        profile_render_post_grid(
          $galleryGrid,
          $showPeerNotFound,
          $isMobile,
          'No Gallery Available',
          'ion-images',
          false,
          'gallery'
        );
      ?>
      <?php if (!$showPeerNotFound && !empty($galleryGrid)): ?>
        <div class="ig-gallery-empty-filter" id="galleryVisEmpty" role="status">No posts in this tab.</div>
      <?php endif; ?>
    </div>

    <div id="panel-posts" class="profile-panel<?php echo $selectedTab === 'posts' ? ' active' : ''; ?>">
      <div id="profilePostsFeed" class="mf-feed" aria-live="polite"></div>
    </div>

    <div id="panel-tags" class="profile-panel<?php echo $selectedTab === 'tags' ? ' active' : ''; ?>">
      <?php
        profile_render_post_grid(
          $tagsGrid,
          $showPeerNotFound,
          $isMobile,
          'No tagged posts yet',
          'ion-ios-pricetag',
          true,
          'tags'
        );
      ?>
    </div>

    <div id="panel-about" class="profile-panel<?php echo $selectedTab === 'about' ? ' active' : ''; ?>">
      <div class="about-wrap">
        <div class="about-topbar">
          <div class="about-title">About details</div>
          <div class="about-actions">
            <?php if ($showUpdated): ?>
              <div class="about-flash"><i class="icon ion-checkmark-circled"></i> Background details updated successfully.</div>
            <?php endif; ?>
            <?php if ($canManageProfilePrivate): ?>
              <a class="about-edit-btn" href="user_edit.php?tab=about&amp;return=<?php echo rawurlencode('profile.php?tab=about&updated=1'); ?>">
                <i class="icon ion-edit"></i> Open user_edit.php
              </a>
            <?php endif; ?>
          </div>
        </div>
        <div class="about-head">
          <div class="mini-avatar"><img src="<?php echo h($avatarUrl); ?>" data-live-avatar="1" data-avatar-base="<?php echo h($avatarUrl); ?>" alt="Avatar"></div>
          <div>
            <div class="nm"><?php echo h($profileDisplayName); ?></div>
            <?php if ($profileAccountBadge !== ''): ?><div class="profile-account-badge"><?php echo h($profileAccountBadge); ?></div><?php endif; ?>
            <div class="sub"><?php echo h($profileHandleLabel); ?></div>
          </div>
        </div>

        <div class="about-grid">
          <?php $aboutRegistrationHeadShown = false; ?>
          <?php foreach ($aboutCards as $card): ?>
            <?php
              $label = trim((string)($card['label'] ?? ''));
              $isRegistrationCard = in_array($label, ['Terms & Policy', 'Age confirmation', 'Publisher name approval'], true);
              if ($isRegistrationCard && !$aboutRegistrationHeadShown):
                $aboutRegistrationHeadShown = true;
            ?>
              <div class="about-registration-head">Registration at signup</div>
            <?php endif; ?>
            <?php $val = trim((string)($card['value'] ?? '')); ?>
            <div class="about-card">
              <div class="about-ico"><i class="icon <?php echo h((string)$card['icon']); ?>"></i></div>
              <div>
                <div class="k"><?php echo h($label); ?></div>
                <div class="v<?php echo $val === '' ? ' empty' : ''; ?>"><?php echo $val !== '' ? nl2br(h($val)) : h(trim((string)($card['empty_text'] ?? 'No background added yet'))); ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if (!$hasBackgroundTable): ?>
          <div class="about-note">
            SQL background table has not been added yet. Run <b>sql_user_backgrounds.sql</b> first, then your About tab can store and read the extra background details.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$liveVisitorMode && $canManageProfilePrivate): ?>
      <div id="panel-preserve" class="profile-panel<?php echo $selectedTab === 'preserve' ? ' active' : ''; ?>">
        <div class="coming-wrap">
          <div class="coming-card">Preserve section is ready for your next update.</div>
        </div>
      </div>

      <div id="panel-gear" class="profile-panel<?php echo $selectedTab === 'gear' ? ' active' : ''; ?>">
        <div class="gear-wrap">
          <?php
            $gearDefaultDetailId = '';
            foreach ($gearGroups as $gi => $group) {
              $slug = profile_gear_group_slug((string)$group['title']);
              $rows = (array)($group['rows'] ?? []);
              if ($gearDefaultDetailId === '' && !empty($rows)) {
                $gearDefaultDetailId = 'gear-detail-' . $slug . '-0';
              }
            }
          ?>
          <div class="gear-shell">
            <aside class="gear-sidebar" aria-label="Settings navigation">
              <div class="gear-sidebar-head">
                <h2 class="gear-sidebar-title">Settings</h2>
                <a class="gear-archive-shortcut" href="archive.php">
                  <i class="icon ion-ios-box" aria-hidden="true"></i>
                  <span>Archived posts</span>
                </a>
              </div>

              <nav class="gear-nav" id="gearNav">
                <?php foreach ($gearGroups as $gi => $group): ?>
                  <?php
                    $slug = profile_gear_group_slug((string)$group['title']);
                    $navLabel = trim((string)($group['nav_label'] ?? $group['title']));
                    $rows = (array)($group['rows'] ?? []);
                    $isOpen = false;
                  ?>
                  <div class="gear-nav-section<?php echo $isOpen ? ' is-open' : ''; ?>" id="<?php echo h($slug); ?>" data-group-slug="<?php echo h($slug); ?>">
                    <button type="button" class="gear-nav-section-toggle" aria-expanded="<?php echo $isOpen ? 'true' : 'false'; ?>">
                      <span class="gear-nav-section-icon"><i class="icon <?php echo h((string)$group['icon']); ?>"></i></span>
                      <span class="gear-nav-section-label"><?php echo h($navLabel); ?></span>
                      <span class="gear-nav-section-chevron"><i class="icon ion-chevron-down"></i></span>
                    </button>
                    <div class="gear-nav-items">
                      <?php foreach ($rows as $ri => $row): ?>
                        <?php
                          $rowId = 'gear-detail-' . $slug . '-' . $ri;
                          $rowLabel = trim((string)($row['label'] ?? ''));
                          $rowField = trim((string)($row['field'] ?? ''));
                          $rowLocalField = trim((string)($row['field_local'] ?? ''));
                          $rowControl = strtolower(trim((string)($row['control'] ?? '')));
                          $rowMeta = profile_gear_row_value_label($row, $profileSettings, $themeAutoDefault);
                          $navSub = $rowMeta;
                          if ($navSub === '' && !empty($row['tag'])) {
                            $navSub = trim((string)$row['tag']);
                          } elseif ($navSub === '' && !empty($row['meta'])) {
                            $navSub = trim((string)$row['meta']);
                          }
                          $searchBits = strtolower($navLabel . ' ' . $rowLabel . ' ' . (string)($row['meta'] ?? '') . ' ' . $navSub);
                          $isActive = ($rowId === $gearDefaultDetailId);
                        ?>
                        <button
                          type="button"
                          class="gear-nav-item<?php echo $isActive ? ' is-active' : ''; ?>"
                          data-detail-id="<?php echo h($rowId); ?>"
                          <?php if ($rowField !== ''): ?>data-field="<?php echo h($rowField); ?>"<?php endif; ?>
                          <?php if ($rowLocalField !== ''): ?>data-local-field="<?php echo h($rowLocalField); ?>"<?php endif; ?>
                          <?php if ($rowControl === 'color'): ?>data-progress-color="1"<?php endif; ?>
                          data-search-text="<?php echo h($searchBits); ?>"
                        >
                          <span><?php echo h($rowLabel); ?></span>
                          <?php if ($navSub !== ''): ?>
                            <span class="gear-nav-item-meta"><?php echo h($navSub); ?></span>
                          <?php endif; ?>
                        </button>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </nav>
            </aside>

            <main class="gear-main" id="gearMain">
              <div class="gear-detail-empty" id="gearDetailEmpty"<?php echo $gearDefaultDetailId !== '' ? ' hidden' : ''; ?>>Select a setting from the list on the left.</div>

              <?php foreach ($gearGroups as $gi => $group): ?>
                <?php
                  $slug = profile_gear_group_slug((string)$group['title']);
                  $rows = (array)($group['rows'] ?? []);
                  $chips = (array)($group['chips'] ?? []);
                ?>
                <?php foreach ($rows as $ri => $row): ?>
                  <?php
                    $rowId = 'gear-detail-' . $slug . '-' . $ri;
                    $isActive = ($rowId === $gearDefaultDetailId);
                  ?>
                  <section class="gear-detail-panel<?php echo $isActive ? ' is-active' : ''; ?>" id="<?php echo h($rowId); ?>" aria-labelledby="<?php echo h($rowId); ?>-title"<?php echo $isActive ? '' : ' hidden'; ?>>
                    <div class="gear-detail-head">
                      <div class="gear-detail-icon"><i class="icon <?php echo h((string)($row['icon'] ?? 'ion-ios-gear')); ?>"></i></div>
                      <div>
                        <h3 class="gear-detail-title" id="<?php echo h($rowId); ?>-title"><?php echo h((string)($row['label'] ?? '')); ?></h3>
                        <p class="gear-detail-desc"><?php echo h((string)($row['meta'] ?? '')); ?></p>
                      </div>
                    </div>

                    <?php if (!empty($chips)): ?>
                      <div class="gear-detail-chips">
                        <?php foreach ($chips as $chip): ?>
                          <span class="gear-chip"><?php echo h((string)$chip); ?></span>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>

                    <div class="gear-detail-body">
                      <?php
                        profile_gear_render_detail_action($row, $profileSettings, $themeAutoDefault);
                      ?>
                    </div>
                  </section>
                <?php endforeach; ?>
              <?php endforeach; ?>

              <div class="gear-note">
                Gear live-saves privacy, timeline, notifications, security, appearance, and account settings from this tab. Links such as <b>Edit Profile</b>, <b>Change password</b>, and <b>Logout</b> still open their own pages.
              </div>
            </main>
          </div>
        </div>
      </div>
    <?php endif; ?>

    </div><!-- ig-profile-scroll -->

  </div>
</div>


<!-- ✅ Post Viewer Modal (Instagram-style) -->
<div id="pvOverlay" class="pv-overlay" aria-hidden="true">
  <button type="button" class="pv-x" id="pvClose" aria-label="Close"><i class="icon ion-close"></i></button>
  <button type="button" class="pv-nav pv-prev" id="pvPrev" aria-label="Previous"><i class="fa fa-chevron-left" aria-hidden="true"></i></button>
  <button type="button" class="pv-nav pv-next" id="pvNext" aria-label="Next"><i class="fa fa-chevron-right" aria-hidden="true"></i></button>

  <div class="pv-modal" role="dialog" aria-modal="true" aria-label="Post viewer">
    <div class="pv-left" aria-label="Media">
      <div class="pv-media" id="pvMedia"></div>
    </div>
    <div class="pv-mid is-empty" id="pvMid">
      <!-- Title (top) + description — center column; left is media (photo or video) -->
      <div class="pv-caption" id="pvCaption" style="display:none;"></div>
    </div>
    <div class="pv-right">
      <div class="pv-head">
        <div class="pv-user">
          <img id="pvAvatar" class="pv-ava" alt="" src="" />
          <div class="pv-namewrap">
            <div id="pvName" class="pv-name">—</div>
            <div id="pvMeta" class="pv-meta">—</div>
          </div>
        </div>
        <div class="pv-head-actions">
          <button type="button" class="pv-friend-btn friend-btn mf-media-action-circle mf-media-follow-btn primary" id="pvFriendBtn" hidden aria-label="Add Friend" title="Add Friend"><i class="fa fa-plus" aria-hidden="true"></i></button>
          <div class="post-card-menu-wrap mf-menu-wrap pv-menu-wrap" id="pvMenuWrap" data-post-id="0" data-peer-id="0" data-is-owner="0" data-menu-surface="profile">
            <button type="button" class="pv-dots post-card-menu-btn" id="pvDots" aria-label="More" title="Menu" aria-haspopup="true" aria-expanded="false"><?= post_card_menu_fries_icon_html() ?></button>
            <div class="post-card-menu mf-menu" id="pvMenu" role="menu"></div>
          </div>
        </div>
      </div>

      <!-- ✅ Scrollable comments only — caption lives in .pv-mid -->
      <div class="pv-body" id="pvBody">
        <div class="pv-comments" id="pvComments" aria-label="Comments"></div>
      </div>

      <div class="pv-actions">
        <div class="pv-actrow">
          <button type="button" class="pv-act pv-act-love" id="pvLove" title="Love" aria-label="Love">
            <i class="msb-pact msb-pact-heart" id="pvLoveIcon" aria-hidden="true"></i>
            <span class="pv-n" id="pvLoveN">0</span>
          </button>
          <button type="button" class="pv-act pv-act-like" id="pvLike" title="Like" aria-label="Like" hidden>
            <svg class="pv-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14z"/>
              <path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/>
            </svg>
            <span class="pv-n" id="pvLikeN">0</span>
          </button>
          <button type="button" class="pv-act pv-act-comment" id="pvComment" title="Comment" aria-label="Comment">
            <svg class="pv-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
            </svg>
            <span class="pv-n" id="pvComN">0</span>
          </button>
          <button type="button" class="pv-act pv-act-share" id="pvShare" title="Share" aria-label="Share">
            <svg class="pv-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M4 14v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>
              <polyline points="16 7 12 3 8 7"/>
              <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <span class="pv-n" id="pvShareN">0</span>
          </button>
          <div class="pv-sp"></div>
          <button type="button" class="pv-act pv-act-save" id="pvSave" title="Save" aria-label="Save">
            <svg class="pv-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>
            </svg>
            <span class="pv-n" id="pvSaveN">0</span>
          </button>
        </div>
        <div class="pv-replybar" id="pvReplyBar" style="display:none;">
          <span><span id="pvReplyLead">Replying to</span> <b id="pvReplyName">—</b></span>
          <button type="button" class="pv-replyx" id="pvReplyCancel" aria-label="Cancel reply"><i class="icon ion-close"></i></button>
        </div>
        <div class="pv-input">
          <button type="button" class="pv-iconbtn" id="pvAtBtn" title="Mention" aria-label="Mention">
            <i class="icon ion-at"></i>
          </button>
          <input type="text" id="pvText" placeholder="Add comment..." autocomplete="off" />
          <button type="button" class="pv-iconbtn" id="pvEmojiBtn" title="Emoji" aria-label="Emoji">
            <i class="icon ion-happy-outline"></i>
          </button>
          <button type="button" class="pv-send" id="pvPostBtn" title="Send" aria-label="Send">
            <i class="icon ion-arrow-up-a"></i>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
  /* ✅ Modal (instagram-style)
     Desktop/laptop: fixed frame so next/prev never shifts the comments card.
     Mobile/tablet overrides below use their own stable dimensions. */
  .pv-overlay{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:#000;z-index:9999;padding:24px;overflow:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;isolation:isolate;}
  .pv-overlay.show{display:flex;}
  .pv-overlay.show .pv-modal{position:relative;z-index:1;}
  /* Soft hold while next/prev loads — never wipe to a bright empty panel */
  .pv-overlay.pv-is-switching .pv-mid,
  .pv-overlay.pv-is-switching .pv-right{
    pointer-events:none;
  }
  .pv-media .pv-loading-only{
    display:flex;align-items:center;justify-content:center;
    width:100%;height:100%;
    background:#0b1220;color:rgba(255,255,255,.7);
    font-size:13px;font-weight:600;
  }
  /* GPU video layers from the gallery grid punch through the modal — hide them while open */
  body.pv-body-lock .ig-item video.ig-vid,
  body.pv-body-lock .ig-story-ring video.ig-story-thumb,
  body.pv-body-lock video.ig-story-thumb{
    visibility:hidden !important;
    opacity:0 !important;
    pointer-events:none !important;
  }
  .pv-modal{width:fit-content;max-width:min(1320px,96vw);height:min(720px,88vh);background:transparent;color:var(--msb-palette-text, #0f172a);overflow:hidden;display:flex;align-items:stretch;gap:0;box-shadow:none;}
  /* Media (photo or video) | title+desc | comments — right column width unchanged */
  .pv-left{flex:0 1 auto;width:auto;max-width:min(720px,calc(96vw - min(380px,38vw) - min(380px,32vw) - 48px));height:100%;background:#000;display:flex;align-items:stretch;justify-content:flex-start;overflow:hidden;position:relative;}
  .pv-media{width:auto;max-width:100%;height:100%;margin-left:0;margin-right:0;display:flex;align-items:center;justify-content:flex-start;--post-media-radius:0;position:relative;background:#000;overflow:hidden;isolation:isolate;z-index:1;transition:opacity .18s ease;}
  .pv-media.pv-media-fade{opacity:0;}
  /* Desktop: locked frame so next/prev never reflows width twice */
  @media (min-width: 901px){
    .pv-overlay.show .pv-modal{
      width:min(1320px,96vw) !important;
      max-width:min(1320px,96vw) !important;
    }
    .pv-overlay.show .pv-left{
      flex:1 1 0 !important;
      width:auto !important;
      min-width:0 !important;
      max-width:none !important;
      background:#000 !important;
      background-color:#000 !important;
      justify-content:center !important;
      position:relative !important;
    }
    .pv-overlay.show .pv-media{
      width:100% !important;
      max-width:100% !important;
      height:100% !important;
      justify-content:center !important;
      align-items:center !important;
      background:#000 !important;
      background-color:#000 !important;
    }
    .pv-overlay.show .pv-media > img,
    .pv-overlay.show .pv-media > video,
    .pv-overlay.show .pv-media .mf-media-slide > img,
    .pv-overlay.show .pv-media .media-slide > img,
    .pv-overlay.show .pv-media .mf-media-slide > video,
    .pv-overlay.show .pv-media .media-slide > video{
      object-position:center center !important;
    }
    /* Tall media: hug image width so no empty black gutter beside the photo */
    .pv-overlay.show.pv-is-portrait .pv-modal{
      width:fit-content !important;
      max-width:min(1320px,96vw) !important;
    }
    .pv-overlay.show.pv-is-portrait .pv-left{
      flex:0 1 auto !important;
      width:auto !important;
      max-width:min(56vh, 520px) !important;
      justify-content:center !important;
    }
    .pv-overlay.show.pv-is-portrait .pv-media{
      width:auto !important;
      max-width:100% !important;
      justify-content:center !important;
    }
    .pv-overlay.show.pv-is-portrait .pv-media > img,
    .pv-overlay.show.pv-is-portrait .pv-media > video,
    .pv-overlay.show.pv-is-portrait .pv-media .mf-media-slide > img,
    .pv-overlay.show.pv-is-portrait .pv-media .media-slide > img,
    .pv-overlay.show.pv-is-portrait .pv-media .mf-media-slide > video,
    .pv-overlay.show.pv-is-portrait .pv-media .media-slide > video{
      width:auto !important;
      max-width:100% !important;
      height:100% !important;
      max-height:100% !important;
      object-fit:contain !important;
    }
    /* Landscape keeps full media column with or without center caption */
    .pv-overlay.show.pv-is-landscape .pv-modal{
      width:min(1320px,96vw) !important;
      max-width:min(1320px,96vw) !important;
    }
    .pv-overlay.show.pv-is-landscape .pv-left{
      flex:1 1 0 !important;
      max-width:none !important;
    }
    .pv-overlay.show.pv-is-landscape .pv-media{
      width:100% !important;
      max-width:100% !important;
    }
  }
  .pv-media:has(> video),
  .pv-left:has(.pv-media > video),
  html[data-msb-appearance] .pv-media:has(> video),
  html[data-msb-appearance] .pv-left:has(.pv-media > video),
  html.dark-auto .pv-media:has(> video),
  html.dark-auto .pv-left:has(.pv-media > video),
  html[data-theme="dark"] .pv-media:has(> video),
  html[data-theme="dark"] .pv-left:has(.pv-media > video){
    background:#000 !important;
    background-color:#000 !important;
    background-image:none !important;
  }
  .pv-media > video,
  .pv-media > img{position:relative;z-index:2;transform:translateZ(0);}
  /* Never stack a second copy of media inside the viewer */
  .pv-media > img ~ img,
  .pv-media > video ~ video,
  .pv-media > img ~ video,
  .pv-media > video ~ img{display:none !important;}
  .pv-mid{
    flex:0 0 min(380px,32vw);
    width:min(380px,32vw);
    min-width:280px;
    max-width:min(380px,32vw);
    height:100%;
    display:flex;
    flex-direction:column;
    min-height:0;
    background:var(--msb-palette-bg, #f2f1e8);
    color:var(--msb-palette-text, #0f172a);
    border-left:1px solid var(--msb-palette-border, rgba(15,23,42,.18));
    border-right:1px solid var(--msb-palette-border, rgba(15,23,42,.18));
    overflow:hidden;
    box-sizing:border-box;
    transition:opacity .18s ease;
  }
  .pv-mid.is-empty{display:none;}
  .pv-right{transition:opacity .18s ease;}
  .pv-overlay.pv-is-switching .pv-mid,
  .pv-overlay.pv-is-switching .pv-right{opacity:.92;}
  .pv-mid .pv-caption{
    flex:1 1 auto;
    min-height:0;
    max-height:none !important;
    overflow-x:hidden;
    overflow-y:auto !important;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior:contain;
    border-bottom:0;
    padding:18px 16px;
    scrollbar-width:thin;
    scrollbar-color:rgba(15,23,42,.3) transparent;
  }
  .pv-mid .pv-caption::-webkit-scrollbar{width:6px}
  .pv-mid .pv-caption::-webkit-scrollbar-thumb{
    background:rgba(15,23,42,.28);
    border-radius:999px;
  }
  .pv-mid .pv-caption::-webkit-scrollbar-track{background:transparent}
  .pv-media img,.pv-media video,.pv-media iframe{max-width:100%;max-height:100%;width:auto;height:auto;border-radius:0;background:transparent;display:block;object-fit:contain;object-position:left center;}
  .pv-media video{width:auto;height:auto;object-fit:contain;object-position:left center;border-radius:0;background:transparent;}
  .pv-media .mf-media-carousel,
  .pv-media .media-carousel{
    position:relative;
    width:100%;
    max-width:100%;
    height:100%;
    margin-left:0;
    overflow:hidden;
    background:transparent;
    border-radius:0;
  }
  .pv-media .mf-media-slides,
  .pv-media .media-slides{
    display:flex;
    flex-wrap:nowrap;
    width:100%;
    height:100%;
    transition:transform .28s ease;
  }
  .pv-media .mf-media-slide,
  .pv-media .media-slide{
    flex:0 0 100%;
    width:100%;
    min-width:100%;
    max-width:100%;
    height:100%;
    display:flex;
    align-items:center;
    justify-content:flex-start;
    overflow:hidden;
    background:transparent;
    box-sizing:border-box;
  }
  .pv-media .mf-media-slide > img,
  .pv-media .media-slide > img{
    max-width:100%;
    max-height:100%;
    width:auto;
    height:auto;
    object-fit:contain;
    object-position:left center;
    border-radius:0;
    display:block;
  }
  .pv-media .mf-media-slide > video,
  .pv-media .media-slide > video{
    max-width:100%;
    max-height:100%;
    width:auto;
    height:auto;
    object-fit:contain;
    object-position:left center;
    border-radius:0;
    background:transparent;
    display:block;
  }
  .pv-media .mf-media-nav,
  .pv-media .media-nav{
    position:absolute !important;
    top:50% !important;
    transform:translateY(-50%) !important;
    width:20px !important;
    height:20px !important;
    border:none !important;
    border-radius:999px !important;
    background:rgba(159,153,153,.9) !important;
    color:#fff !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    font-size:10px !important;
    cursor:pointer;
    box-shadow:0 8px 24px rgba(0,0,0,.18) !important;
    z-index:6 !important;
    padding:0 !important;
  }
  .pv-media .mf-media-nav:hover,
  .pv-media .media-nav:hover{background:rgba(180,180,180,.95) !important;}
  .pv-media .mf-media-nav.prev,
  .pv-media .media-nav.prev{left:12px !important;}
  .pv-media .mf-media-nav.next,
  .pv-media .media-nav.next{right:12px !important;}
  .pv-media .mf-media-nav i,
  .pv-media .media-nav i{font-size:10px !important;line-height:1 !important;color:#fff !important;}
  .pv-media .mf-media-dots,
  .pv-media .media-dots{
    position:absolute;
    left:50%;
    bottom:12px;
    transform:translateX(-50%);
    display:flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    padding:0;
    border-radius:0;
    background:transparent;
    z-index:5;
  }
  .pv-media .mf-media-dot,
  .pv-media .media-dot{
    width:5px !important;
    height:5px !important;
    min-width:5px !important;
    min-height:5px !important;
    flex:0 0 5px !important;
    display:block !important;
    border:none !important;
    border-radius:50% !important;
    padding:0 !important;
    margin:0 !important;
    background:rgba(255,255,255,.55) !important;
    cursor:pointer;
    appearance:none;
    -webkit-appearance:none;
    box-shadow:none !important;
    font-size:0 !important;
    line-height:0 !important;
    color:transparent !important;
    text-indent:-9999px !important;
    overflow:hidden !important;
  }
  .pv-media .mf-media-dot.is-active,
  .pv-media .media-dot.is-active{
    width:6px !important;
    height:6px !important;
    min-width:6px !important;
    min-height:6px !important;
    flex:0 0 6px !important;
    background:#3897f0 !important;
  }
  /* Text-only gallery modal: scroll long title/description on every viewport */
  .pv-left.pv-left-scroll{
    align-items:stretch !important;
    justify-content:stretch !important;
  }
  .pv-left.pv-left-scroll .pv-media{
    overflow:auto !important;
    -webkit-overflow-scrolling:touch;
    overscroll-behavior:contain;
    align-items:flex-start !important;
    justify-content:flex-start !important;
    scrollbar-width:thin;
    scrollbar-color:rgba(255,255,255,.35) transparent;
  }
  .pv-left.pv-left-scroll .pv-media::-webkit-scrollbar{width:6px;height:6px}
  .pv-left.pv-left-scroll .pv-media::-webkit-scrollbar-thumb{background:rgba(255,255,255,.35);border-radius:999px}
  .pv-left.pv-left-scroll .pv-media::-webkit-scrollbar-track{background:transparent}
  .pv-text-card{
    width:100%;
    min-height:100%;
    box-sizing:border-box;
    display:flex;
    align-items:flex-start;
    justify-content:center;
    padding:28px 26px 40px;
    color:var(--msb-palette-text, #0f172a);
    background:var(--msb-palette-bg, #f2f1e8);
  }
  .pv-text-card-inner{
    width:100%;
    max-width:640px;
    text-align:left;
  }
  .pv-text-card-title{
    font-weight:800;
    font-size:clamp(22px,2.4vw,28px);
    line-height:1.25;
    white-space:normal;
    word-break:break-word;
    margin:0 0 12px;
    color:var(--msb-palette-text, #0f172a);
  }
  .pv-text-card-body{
    margin:0;
    font-size:15px;
    line-height:1.55;
    font-weight:500;
    white-space:normal;
    word-break:break-word;
    color:var(--msb-palette-text, #0f172a);
  }
  .pv-text-card-body .pv-richtext,
  .pv-text-card-body .pv-rich-p{color:inherit;font:inherit;line-height:inherit}
  .pv-text-card-body .pv-rich-p{margin:0 0 .85em}
  .pv-text-card-body .pv-rich-p:last-child{margin-bottom:0}
  /* Mid caption: intro + slide description scroll when long */
  .pv-mid .pv-cap{
    min-height:0;
  }
  .pv-mid .pv-cap-intro,
  .pv-mid .pv-cap-summary{
    white-space:normal;
    word-break:break-word;
  }

  @media (max-width: 900px){
    .pv-left.pv-left-scroll .pv-text-card{padding:22px 18px 28px;}
  }

  .pv-right{flex:0 0 min(380px,38vw);width:min(380px,38vw);min-width:280px;display:flex;flex-direction:column;background:var(--msb-palette-bg, #fff);color:var(--msb-palette-text, #0f172a);min-height:0;border-radius:0 12px 12px 0;overflow:hidden;box-shadow:none;border-left:1px solid var(--msb-palette-border, rgba(15,23,42,.14));}
  .pv-head{padding:14px 14px;border-bottom:1px solid var(--msb-palette-border, rgba(15,23,42,.08));display:flex;align-items:center;justify-content:space-between;gap:10px;background:var(--msb-palette-bg, #fff);}
  .pv-user{display:flex;align-items:center;gap:10px;min-width:0;}
  .pv-ava{width:38px;height:38px;border-radius:999px;object-fit:cover;background:#eef2ff;}
  .pv-namewrap{min-width:0;}
  .pv-name{font-weight:700;font-size:14px;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--msb-palette-text, inherit);}
  .pv-meta{font-size:12px;color:var(--msb-palette-text-muted, rgba(15,23,42,.55));white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .pv-head-actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:8px;
    flex:0 0 auto;
    margin-left:auto;
    position:relative;
    z-index:5;
  }
  .pv-friend-btn{
    width:36px;
    height:36px;
    min-width:36px;
    min-height:36px;
    border-radius:999px;
    border:0;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    padding:0;
  }
  .pv-friend-btn[hidden]{display:none !important;}
  .pv-menu-wrap{
    position:relative;
    flex:0 0 auto;
    margin-left:0;
  }
  .pv-dots{border:0;background:transparent;width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--msb-palette-icon, inherit);}
  .pv-dots:hover{background:var(--msb-palette-hover-bg, rgba(15,23,42,.06));}
  .pv-dots .pcm-fries-icon{
    display:inline-flex;
    flex-direction:column;
    align-items:flex-start;
    justify-content:center;
    gap:2.5px;
    width:14px;
    line-height:0;
  }
  .pv-dots .pcm-fries-bar{
    display:block;
    height:2px;
    width:14px;
    border-radius:999px;
    background:currentColor;
  }
  .pv-dots .pcm-fries-bar--short{width:8px;}
  .pv-dots.post-card-menu-btn{
    min-width:36px;
    min-height:36px;
    padding:0;
    box-shadow:none;
  }
  /* Keep in-place menu usable if portal is unavailable. */
  #pvOverlay .pv-menu-wrap .post-card-menu:not(.pcm-menu-portal){
    display:none;
    position:absolute;
    top:calc(100% + 6px);
    right:0;
    min-width:220px;
    z-index:10050;
  }
  #pvOverlay .pv-menu-wrap .post-card-menu.open:not(.pcm-menu-portal){
    display:block;
  }

  /* ✅ Middle scroll area: prevents input/actions from being pushed off-screen on mobile/tablet */
  .pv-body{flex:1;min-height:0;overflow:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;background:var(--msb-palette-bg, #fff);scrollbar-width:thin;scrollbar-color:rgba(15,23,42,.35) transparent;}
  #pvOverlay .pv-body::-webkit-scrollbar,
  .pv-body::-webkit-scrollbar{width:2px !important;height:2px !important;}
  #pvOverlay .pv-body::-webkit-scrollbar-thumb,
  .pv-body::-webkit-scrollbar-thumb{background:rgba(15,23,42,.35) !important;border-radius:999px;border:0 !important;min-height:24px;}
  #pvOverlay .pv-body::-webkit-scrollbar-track,
  .pv-body::-webkit-scrollbar-track{background:transparent !important;}
  #pvOverlay .pv-body::-webkit-scrollbar-corner,
  .pv-body::-webkit-scrollbar-corner{background:transparent !important;}
  .pv-comments{padding:4px 10px 10px;background:var(--msb-palette-bg, transparent);}
  /* keep space so last comment never hides behind the footer/input */
  .pv-comments{padding-bottom:160px;}

  /* ✅ Footer stays visible; input is sticky inside footer */
  .pv-actions{position:sticky;bottom:0;background:var(--msb-palette-bg, #fff);z-index:3;}
  /* make input sticky so it never gets hidden by long scroll/keyboard */
  .pv-input{position:sticky;bottom:0;background:var(--msb-palette-bg, #fff);padding:10px 0 calc(10px + env(safe-area-inset-bottom));margin-top:10px;z-index:4;}
  .pv-input::before{content:"";position:absolute;left:0;right:0;top:-10px;height:10px;background:linear-gradient(to top, var(--msb-palette-bg, #fff), rgba(255,255,255,0));}

  /* ✅ Mobile/tablet: stable frame (comments card does not jump on next/prev) */
  @media (max-width: 980px){
    .pv-overlay{padding:10px;align-items:center;justify-content:center;}
    .pv-modal{width:min(720px,96vw);max-width:min(720px,96vw);height:calc(var(--vh, 1vh) * 100 - 20px);max-height:calc(var(--vh, 1vh) * 100 - 20px);border-radius:18px;}
  }
  @media (max-width: 640px){
    .pv-overlay{padding:10px;}
    .pv-modal{width:calc(100vw - 20px);max-width:calc(100vw - 20px);height:calc(var(--vh, 1vh) * 100 - 20px);max-height:calc(var(--vh, 1vh) * 100 - 20px);border-radius:18px;}
  }


  /* ✅ Caption (post text) — center column: title top, description under */
  .pv-caption{border-bottom:1px solid rgba(15,23,42,.08);padding:10px 14px;max-height:140px;overflow:auto;}
  .pv-mid .pv-caption{border-bottom:0;max-height:none !important;overflow-y:auto !important;}
  .pv-cap{font-size:13px;line-height:1.35;color:#0f172a;word-break:break-word;}
  .pv-cap-title{font-size:15px;font-weight:800;line-height:1.25;margin:0 0 10px;color:var(--msb-palette-text, #0f172a);}
  .pv-cap-desc{font-size:13px;line-height:1.45;color:var(--msb-palette-text, #0f172a);}
  .pv-cap-subtitle{font-size:14px;font-weight:700;line-height:1.3;margin:12px 0 6px;color:var(--msb-palette-text, #1f2937);}
  .pv-cap-summary{font-size:13px;line-height:1.45;color:var(--msb-palette-text-muted, #4b5563);}
  .pv-cap-summary .post-slide-summary-list{margin:0;padding-left:1.15em;list-style:disc}
  .pv-cap-summary .post-slide-summary-list li{margin:0 0 .35em}
  .pv-cap-short,.pv-cap-full{white-space:normal;word-break:break-word;}

  .pv-cap b{font-weight:800;}
  .pv-readmore{margin-left:6px;font-weight:800;color:var(--msb-palette-text, #0b1220);cursor:pointer;white-space:nowrap;}
  .pv-readmore:hover{text-decoration:underline;}
  a.msb-mention{
    color:var(--msb-palette-text, #0b1220);
    font-weight:800;
    text-decoration:none;
  }
  a.msb-mention:hover{text-decoration:underline;opacity:.85;}
  html.dark-auto a.msb-mention,
  html[data-theme="dark"] a.msb-mention,
  html[data-msb-appearance] a.msb-mention{
    color:var(--msb-palette-text, #f3f6fb);
  }
  .pv-richtext{display:block;}
  .pv-richtext .pv-rich-p{margin:0 0 12px;white-space:normal;word-break:break-word;}
  .pv-richtext .pv-rich-p:last-child{margin-bottom:0;}
  .pv-richtext .pv-rich-list{margin:0 0 12px;padding-left:22px;}
  .pv-richtext .pv-rich-list.is-ordered{list-style:decimal;}
  .pv-richtext .pv-rich-list.is-bullet{list-style:disc;}
  .pv-richtext .pv-rich-li{margin:0 0 6px;}
  .pv-richtext .pv-rich-li:last-child{margin-bottom:0;}
  .pv-cap-desc .pv-richtext,.pv-media-text .pv-richtext{color:inherit;font:inherit;line-height:inherit;}
  .pv-cap-short .pv-rich-p,.pv-cap-full .pv-rich-p,.pv-media-short .pv-rich-p,.pv-media-full .pv-rich-p{display:block;}
  .pv-rich-ellipsis{display:inline;}
  .pv-node{position:relative;--pv-avatar-size:20px;--pv-thread:var(--msb-palette-border-strong, rgba(15,23,42,.18));}
  .pv-node.has-children::after{content:"";position:absolute;left:calc(var(--pv-avatar-size) / 2);top:calc(var(--pv-avatar-size) + 10px);bottom:20px;width:2px;background:var(--pv-thread);border-radius:999px;}
  .pv-node.has-children.is-collapsed::after{display:none;}
  .pv-children{margin-left:calc(var(--pv-avatar-size) / 2);padding-left:28px;}
  .pv-children.depth-capped{margin-left:0;padding-left:0;}
  .pv-node.is-reply::before{content:"";position:absolute;left:-30px;top:8px;width:30px;height:17px;border-left:2px solid var(--pv-thread);border-bottom:2px solid var(--pv-thread);border-bottom-left-radius:18px;}
  .pv-node.is-depth-clamped::before{display:none;}
  .pv-com{display:flex;gap:7px;padding:14px 12px 12px;border-radius:18px;margin-bottom:0;}
  .pv-com.is-alert-focus{background:var(--msb-palette-hover-bg, rgba(37,99,235,.06));border:1px solid var(--msb-palette-border-strong, rgba(37,99,235,.16));box-shadow:none;margin:2px 0 10px;}
  .pv-com .a{width:20px;height:20px;border-radius:999px;background:#111;color:#fff;flex:0 0 20px;overflow:hidden;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:8px;}
  .pv-com .a img{width:100%;height:100%;object-fit:cover;display:block;}
  .pv-com .b{min-width:0;flex:1;display:flex;flex-direction:column;}
  .pv-com .bubble{display:block;max-width:100%;background:transparent;border:1px solid transparent;border-radius:0;padding:0;min-width:0;}
  .pv-com .nm{font-weight:700;font-size:15px;line-height:1.25;color:var(--msb-palette-text, #101828);margin-bottom:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .pv-com .tx{font-size:14px;color:var(--msb-palette-text, #101828);line-height:1.4;word-wrap:break-word;}
  .pv-com .t{font-size:14px;line-height:1.4;color:var(--msb-palette-text, #101828);}
  .pv-com .t b{font-weight:700;}
  .pv-com .m{margin-top:8px;font-size:12px;color:var(--msb-palette-text-muted, #667085);display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding-left:0;}
  .pv-com .m > span:first-child{min-width:auto;}
  .pv-com .m .link{cursor:pointer;border:0;background:transparent;padding:0;color:inherit;font:inherit;font-weight:700;}
  .pv-com .m .link:hover{color:var(--msb-palette-text, #101828);text-decoration:none;}
  .pv-com .m .replies-toggle{border:0;background:transparent;padding:0;color:inherit;font:inherit;font-weight:700;cursor:pointer;}
  .pv-com .m .replies-toggle:hover{color:var(--msb-palette-text, #101828);text-decoration:none;}
  .pv-com .m .pv-toggle-replies{color:var(--msb-palette-text-muted, #667085);font-weight:700;position:relative;padding-left:36px !important;display:inline-flex;align-items:center;gap:8px;}
  .pv-com .m .pv-toggle-replies::before{content:"";position:absolute;left:0;top:50%;width:22px;height:1px;background:var(--pv-thread);transform:translateY(-50%);}
  .pv-com .m .pv-toggle-replies::after{content:"\f3d0";font-family:"Ionicons";font-size:13px;line-height:1;}
  .pv-com .m .likebtn{border:0;background:transparent;padding:0;color:inherit;font:inherit;font-weight:500;cursor:pointer;margin-left:auto;order:10;}
  .pv-com .m .likebtn i{font-size:15px;margin-right:5px;vertical-align:-1px;}
  .pv-com .m .likebtn.is-liked{color:var(--msb-palette-text, #101828);}
  .pv-likepill{display:none;}
  html.dark-auto .pv-node.has-children::after,
  html[data-theme="dark"] .pv-node.has-children::after{background:var(--msb-palette-border-strong, rgba(148,163,184,.38));}
  html.dark-auto .pv-node.is-reply::before,
  html[data-theme="dark"] .pv-node.is-reply::before{border-left-color:var(--msb-palette-border-strong, rgba(148,163,184,.38));border-bottom-color:var(--msb-palette-border-strong, rgba(148,163,184,.38));}
  html.dark-auto .pv-com .bubble,
  html[data-theme="dark"] .pv-com .bubble{background:transparent;border-color:transparent;}
  html.dark-auto .pv-com.is-alert-focus,
  html[data-theme="dark"] .pv-com.is-alert-focus{background:var(--msb-palette-hover-bg, rgba(96,165,250,.16));border-color:var(--msb-palette-border-strong, rgba(147,197,253,.34));box-shadow:none;}
  html.dark-auto .pv-right,
  html[data-theme="dark"] .pv-right,
  html[data-msb-appearance] .pv-right,
  html.dark-auto .pv-mid,
  html[data-theme="dark"] .pv-mid,
  html[data-msb-appearance] .pv-mid,
  html.dark-auto .pv-head,
  html[data-theme="dark"] .pv-head,
  html[data-msb-appearance] .pv-head,
  html.dark-auto .pv-body,
  html[data-theme="dark"] .pv-body,
  html[data-msb-appearance] .pv-body,
  html.dark-auto .pv-actions,
  html[data-theme="dark"] .pv-actions,
  html[data-msb-appearance] .pv-actions,
  html.dark-auto .pv-input,
  html[data-theme="dark"] .pv-input,
  html[data-msb-appearance] .pv-input{
    background:var(--msb-palette-bg, #171d24) !important;
    background-color:var(--msb-palette-bg, #171d24) !important;
    color:var(--msb-palette-text, #f3f6fb);
  }
  html.dark-auto .pv-modal,
  html[data-theme="dark"] .pv-modal,
  html[data-msb-appearance] .pv-modal{
    background:transparent !important;
    background-color:transparent !important;
    background-image:none !important;
  }
  html.dark-auto .pv-left,
  html[data-theme="dark"] .pv-left,
  html[data-msb-appearance] .pv-left,
  html.dark-auto .pv-media,
  html[data-theme="dark"] .pv-media,
  html[data-msb-appearance] .pv-media{
    background:var(--msb-palette-bg, #0b1220) !important;
    background-color:var(--msb-palette-bg, #0b1220) !important;
    background-image:none !important;
  }
  html.dark-auto .pv-head,
  html[data-theme="dark"] .pv-head,
  html[data-msb-appearance] .pv-head,
  html.dark-auto .pv-caption,
  html[data-theme="dark"] .pv-caption,
  html[data-msb-appearance] .pv-caption,
  html.dark-auto .pv-actions,
  html[data-theme="dark"] .pv-actions,
  html[data-msb-appearance] .pv-actions{border-color:var(--msb-palette-border, rgba(255,255,255,.12));}
  html.dark-auto .pv-name,
  html[data-theme="dark"] .pv-name,
  html[data-msb-appearance] .pv-name,
  html.dark-auto .pv-cap,
  html[data-theme="dark"] .pv-cap,
  html[data-msb-appearance] .pv-cap,
  html.dark-auto .pv-act,
  html[data-theme="dark"] .pv-act,
  html[data-msb-appearance] .pv-act,
  html.dark-auto .pv-act .pv-ico,
  html[data-theme="dark"] .pv-act .pv-ico,
  html[data-msb-appearance] .pv-act .pv-ico,
  html.dark-auto .pv-act i,
  html[data-theme="dark"] .pv-act i,
  html[data-msb-appearance] .pv-act i,
  html.dark-auto .pv-likebar,
  html[data-theme="dark"] .pv-likebar,
  html[data-msb-appearance] .pv-likebar{color:var(--msb-palette-text, #f3f6fb);}
  html.dark-auto .pv-act .pv-n,
  html[data-theme="dark"] .pv-act .pv-n,
  html[data-msb-appearance] .pv-act .pv-n{color:var(--msb-palette-text, #f3f6fb);}
  html.dark-auto .pv-meta,
  html[data-theme="dark"] .pv-meta,
  html[data-msb-appearance] .pv-meta,
  html.dark-auto .pv-act .pv-n,
  html[data-theme="dark"] .pv-act .pv-n,
  html[data-msb-appearance] .pv-act .pv-n,
  html.dark-auto .pv-meta-link,
  html[data-theme="dark"] .pv-meta-link,
  html[data-msb-appearance] .pv-meta-link,
  html.dark-auto .pv-views,
  html[data-theme="dark"] .pv-views,
  html[data-msb-appearance] .pv-views{color:var(--msb-palette-text-muted, #a9b6c8);}
  html.dark-auto .pv-dots:hover,
  html[data-theme="dark"] .pv-dots:hover,
  html[data-msb-appearance] .pv-dots:hover{background:var(--msb-palette-hover-bg, rgba(255,255,255,.08));}
  html.dark-auto .pv-input::before,
  html[data-theme="dark"] .pv-input::before,
  html[data-msb-appearance] .pv-input::before{background:linear-gradient(to top, var(--msb-palette-bg, #182130), transparent);}
  html.dark-auto .pv-input input,
  html[data-theme="dark"] .pv-input input,
  html[data-msb-appearance] .pv-input input{
    background:var(--msb-palette-input-bg, #1f1f1f);
    border-color:var(--msb-palette-border-strong, rgba(255,255,255,.12));
    color:var(--msb-palette-text, #f3f6fb);
  }
  html.dark-auto .pv-input input::placeholder,
  html[data-theme="dark"] .pv-input input::placeholder,
  html[data-msb-appearance] .pv-input input::placeholder{color:var(--msb-palette-placeholder, #98a2b3);}
  html.dark-auto .pv-iconbtn,
  html[data-theme="dark"] .pv-iconbtn,
  html[data-msb-appearance] .pv-iconbtn{
    background:var(--msb-palette-hover-bg, #1f2630);
    color:var(--msb-palette-text, #f3f6fb);
  }
  html.dark-auto .pv-com .bubble,
  html[data-theme="dark"] .pv-com .bubble,
  html[data-msb-appearance] .pv-com .bubble{
    background:transparent;
    border-color:transparent;
  }
  html.dark-auto .pv-com .nm,
  html[data-theme="dark"] .pv-com .nm,
  html[data-msb-appearance] .pv-com .nm,
  html.dark-auto .pv-com .tx,
  html[data-theme="dark"] .pv-com .tx,
  html[data-msb-appearance] .pv-com .tx,
  html.dark-auto .pv-com .t,
  html[data-theme="dark"] .pv-com .t,
  html[data-msb-appearance] .pv-com .t{color:var(--msb-palette-text, #f3f6fb);}
  html.dark-auto .pv-com .m,
  html[data-theme="dark"] .pv-com .m,
  html[data-msb-appearance] .pv-com .m{color:var(--msb-palette-text-muted, #b1bcce);}
  html.dark-auto .pv-com .m .likebtn.is-liked,
  html[data-theme="dark"] .pv-com .m .likebtn.is-liked,
  html[data-msb-appearance] .pv-com .m .likebtn.is-liked{color:var(--msb-palette-text, #f3f6fb);}

  .pv-actions{border-top:1px solid var(--msb-palette-border, rgba(15,23,42,.08));padding:12px 14px 12px;}
  .pv-actrow{display:flex;align-items:center;gap:16px;min-height:28px;}
  .pv-act{
    border:0;
    background:transparent;
    padding:0;
    min-width:0;
    height:auto;
    border-radius:0;
    display:inline-flex;
    align-items:center;
    justify-content:flex-start;
    gap:6px;
    cursor:pointer;
    color:var(--msb-palette-text, #111827);
    font-size:14px;
    line-height:1;
  }
  .pv-act .pv-ico{
    width:26px;
    height:26px;
    display:block;
    flex:0 0 auto;
    fill:none;
    stroke:currentColor;
    stroke-width:1.85;
    stroke-linecap:round;
    stroke-linejoin:round;
    transition:transform .15s ease, stroke .15s ease, fill .15s ease;
  }
  /* Reaction picker replaces the love icon — glyph must stay visible in the modal */
  .pv-act.has-rx-icon .pv-ico{display:none !important;}
  #pvLove .msb-reaction-glyph,
  .pv-act-love .msb-reaction-glyph,
  .pv-act i.msb-pact,
  .pv-act i.msb-reaction-host,
  .pv-act i.fa,
  .pv-act .msb-reaction-glyph,
  .pv-act .msb-pact,
  .pv-act span.msb-reaction-glyph{
    display:inline-flex !important;
    align-items:center;
    justify-content:center;
    width:26px;
    height:26px;
    min-width:26px;
    min-height:26px;
    flex:0 0 26px;
    font-size:22px !important;
    line-height:1 !important;
    font-family:"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji","Segoe UI Symbol",sans-serif !important;
    background:transparent !important;
    -webkit-mask:none !important;
    mask:none !important;
    visibility:visible !important;
    opacity:1 !important;
  }
  #pvLove[data-rx]:not([data-rx="love"]) .msb-pact,
  #pvLove[data-rx]:not([data-rx="love"]) .pv-ico,
  .pv-act.has-rx-icon[data-rx]:not([data-rx="love"]) .msb-pact,
  .pv-act.has-rx-icon[data-rx]:not([data-rx="love"]) .pv-ico{
    display:none !important;
  }
  .pv-act i.icon{display:none;}
  #pvLove .msb-reaction-glyph{
    /* win over any appearance color overrides */
    -webkit-text-fill-color: initial !important;
  }
  .pv-act .pv-n{
    font-size:14px;
    font-weight:600;
    line-height:1;
    color:var(--msb-palette-text, #111827);
    min-width:0;
    font-variant-numeric:tabular-nums;
  }
  .pv-act:hover{background:transparent;}
  .pv-act:hover .pv-ico{transform:scale(1.06);}
  .pv-sp{flex:1;min-width:12px;}
  .pv-likebar{margin-top:10px;font-size:15px;font-weight:700;color:var(--msb-palette-text, #111827);line-height:1.2;}
  .pv-metabar{margin-top:8px;display:flex;align-items:center;justify-content:space-between;gap:12px;}
  .pv-meta-link{border:0;background:transparent;padding:0;color:var(--msb-palette-text-muted, #374151);font-size:15px;line-height:1.25;cursor:pointer;text-align:left;}
  .pv-meta-link:hover{text-decoration:underline;}
  .pv-views{font-size:15px;line-height:1.25;color:var(--msb-palette-text-muted, #374151);white-space:nowrap;}

  /* ✅ toggled colors (filled outline icons) */
  .pv-act.is-love{color:var(--msb-love-color, #7c3aed);}
  .pv-act.is-love .pv-ico{fill:currentColor;stroke:currentColor;}
  .pv-act.is-reacted{color:inherit;}
  .pv-act.is-like{color:#2563eb;}
  .pv-act.is-like .pv-ico{fill:currentColor;stroke:currentColor;}
  .pv-act.is-save{color:#111827;}
  .pv-act.is-save .pv-ico{fill:currentColor;stroke:currentColor;}
  .pv-act.is-share{color:#111827;}
  html[data-msb-appearance] .pv-act.is-save,
  html.dark-auto .pv-act.is-save,
  html[data-theme="dark"] .pv-act.is-save,
  html[data-msb-appearance] .pv-act.is-share,
  html.dark-auto .pv-act.is-share,
  html[data-theme="dark"] .pv-act.is-share{color:var(--msb-palette-text, #f3f6fb);}

  @media (max-width: 520px){
    .pv-actrow{gap:14px;}
    .pv-act .pv-ico{width:24px;height:24px;}
    .pv-act .pv-n,.pv-likebar,.pv-meta-link,.pv-views{font-size:14px;}
  }

  .pv-input{margin-top:10px;display:flex;gap:10px;align-items:center;}
  .pv-input input{
    flex:1;min-width:0;min-height:46px;height:auto;
    border-radius:999px;
    border:1px solid var(--msb-palette-border-strong, rgba(15,23,42,.08));
    padding:12px 14px;outline:none;font-size:14px;
    background:var(--msb-palette-input-bg, #1f1f1f) !important;
    color:var(--msb-palette-text, #101828) !important;
  }
  .pv-input input::placeholder{color:var(--msb-palette-placeholder, #98a2b3) !important;font-size:14px;}
  .pv-input input:focus{border-color:var(--msb-palette-border-strong, rgba(15,23,42,.14));box-shadow:none;}
  .pv-iconbtn{
    width:25px;height:25px;border-radius:999px;
    border:1px solid transparent;
    background:var(--msb-palette-hover-bg, #f2f4f7);
    color:var(--msb-palette-text, #101828);
    display:flex;align-items:center;justify-content:center;
    cursor:pointer;font-size:22px;padding:0;flex:0 0 auto;
  }
  .pv-iconbtn:hover{background:var(--msb-palette-nav-hover, #e9edf3);}
  .pv-iconbtn i{font-size:22px;line-height:1;}
  #pvAtBtn{
    background:linear-gradient(180deg, #ff2e89 0%, #c11353 100%) !important;
    color:#fff !important;box-shadow:none;
  }
  #pvAtBtn:hover{background:linear-gradient(180deg, #ff2e89 0%, #c11353 100%);color:#fff;}
  .pv-send{
    width:25px;height:25px;border-radius:999px;border:none;
    background:#7c1730 !important;color:#fff !important;
    display:flex;align-items:center;justify-content:center;
    cursor:pointer;padding:0;flex:0 0 auto;
  }
  .pv-send:hover{background:#991c3d !important;}
  .pv-send i{font-size:21px;line-height:1;color:#fff;}
  .pv-send:disabled{opacity:.55;cursor:not-allowed;}

  .pv-replybar{margin-top:0;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:8px;background:transparent;border:0;padding:0 8px;border-radius:0;font-size:13px;color:var(--msb-palette-text-muted, #667085);}
  .pv-replyx{border:0;background:transparent;width:auto;height:auto;border-radius:0;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--msb-palette-text, #101828);font-weight:800;padding:0;}
  .pv-replyx:hover{background:transparent;}
  .pv-replyx i{font-size:14px;}

  .pv-x{position:fixed;top:14px;right:14px;z-index:10000;border:0;background:rgba(255,255,255,.12);backdrop-filter: blur(8px);color:#fff;width:42px;height:42px;border-radius:999px;display:flex;align-items:center;justify-content:center;cursor:pointer;}
  .pv-x:hover{background:rgba(255,255,255,.18);}
  /* Same circular gray chevrons as post-card media-nav */
  .pv-nav{
    position:fixed;
    top:50%;
    transform:translateY(-50%);
    z-index:10000;
    border:0;
    background:rgba(159,153,153,.9);
    color:#fff;
    width:28px;
    height:28px;
    border-radius:999px;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    box-shadow:0 8px 24px rgba(0,0,0,.18);
    padding:0;
  }
  .pv-nav:hover{background:rgba(180,180,180,.95);}
  .pv-nav i{font-size:12px;line-height:1;color:#fff;}
  .pv-prev{left:14px;}
  .pv-next{right:14px;}

  @media (max-width: 860px){
    .pv-overlay{align-items:center;justify-content:center;}
    .pv-modal{flex-direction:column;width:min(720px,96vw);max-width:min(720px,96vw);height:min(calc(var(--vh, 1vh) * 92),860px);max-height:min(calc(var(--vh, 1vh) * 92),860px);margin:auto;position:relative;gap:0;}
    .pv-right{min-width:0;width:100%;max-width:100%;flex:1 1 auto;max-height:none;border-radius:0 0 12px 12px;}
    .pv-left{flex:0 0 min(52vh,520px);width:100%;max-width:100%;height:min(52vh,520px);min-height:0;background:transparent;margin-inline:0;justify-content:center;align-items:stretch;}
    .pv-mid{
      flex:0 1 28%;
      width:100%;
      max-width:100%;
      min-width:0;
      min-height:0;
      height:auto;
      max-height:28%;
      overflow:hidden;
      border-left:0;
      border-right:0;
      border-bottom:1px solid var(--msb-palette-border, rgba(15,23,42,.08));
    }
    .pv-mid .pv-caption{
      padding:12px 14px;
      flex:1 1 auto;
      min-height:0;
      max-height:100% !important;
      overflow-y:auto !important;
    }
    .pv-media,.pv-media .mf-media-carousel,.pv-media .media-carousel{max-width:100%;width:100%;height:100%;max-height:100%;margin-left:0;border-radius:0;}
    .pv-media img,.pv-media video,.pv-media iframe,
    .pv-media .mf-media-slide > img,
    .pv-media .media-slide > img,
    .pv-media .mf-media-slide > video,
    .pv-media .media-slide > video{max-width:100%;max-height:100%;margin-inline:auto;border-radius:0;object-position:center bottom;}
    .pv-media .mf-media-slide,
    .pv-media .media-slide{justify-content:center;}

    /* prevent nav from colliding with avatar/header on small screens */
    .pv-nav{position:absolute;top:calc(22vh);transform:translateY(-50%);}
    .pv-prev{left:10px;}
    .pv-next{right:10px;}
  }
  @media (max-width: 520px){
    .pv-overlay{padding:10px;}
    .pv-modal{width:calc(100vw - 20px);max-width:calc(100vw - 20px);height:calc(var(--vh, 1vh) * 100 - 20px);max-height:calc(var(--vh, 1vh) * 100 - 20px);}
    .pv-head{padding:12px;}
    .pv-comments{padding:10px 12px;padding-bottom:160px;}

    .pv-nav{width:24px;height:24px;}
    .pv-nav i{font-size:11px;}
  }

  body.pv-body-lock{touch-action:none;}

  /* Gallery grid modal: keep black letterbox (beats appearance palette) */
  html body.profile-page #pvOverlay.pv-overlay,
  html[data-msb-appearance] body.profile-page #pvOverlay.pv-overlay,
  html[data-theme="dark"] body.profile-page #pvOverlay.pv-overlay,
  html.dark-auto body.profile-page #pvOverlay.pv-overlay{
    background:#000 !important;
    background-color:#000 !important;
  }
  html body.profile-page #pvOverlay.pv-overlay::before,
  html body.profile-page #pvOverlay .pv-left::after{
    content:none !important;
    display:none !important;
    background:none !important;
  }
  html body.profile-page #pvOverlay .pv-left,
  html body.profile-page #pvOverlay .pv-media,
  html[data-msb-appearance] body.profile-page #pvOverlay .pv-left,
  html[data-msb-appearance] body.profile-page #pvOverlay .pv-media,
  html[data-theme="dark"] body.profile-page #pvOverlay .pv-left,
  html[data-theme="dark"] body.profile-page #pvOverlay .pv-media,
  html.dark-auto body.profile-page #pvOverlay .pv-left,
  html.dark-auto body.profile-page #pvOverlay .pv-media{
    background:#000 !important;
    background-color:#000 !important;
    background-image:none !important;
  }
  /* Title/description panels only — Gear Appearance / Progress color / Dark auto */
  html body.profile-page #pvOverlay .pv-mid,
  html body.profile-page #pvOverlay .pv-mid .pv-caption,
  html[data-msb-appearance] body.profile-page #pvOverlay .pv-mid,
  html[data-msb-appearance] body.profile-page #pvOverlay .pv-mid .pv-caption,
  html[data-theme="dark"] body.profile-page #pvOverlay .pv-mid,
  html[data-theme="dark"] body.profile-page #pvOverlay .pv-mid .pv-caption,
  html.dark-auto body.profile-page #pvOverlay .pv-mid,
  html.dark-auto body.profile-page #pvOverlay .pv-mid .pv-caption{
    background:var(--msb-palette-bg, #f2f1e8) !important;
    background-color:var(--msb-palette-bg, #f2f1e8) !important;
    color:var(--msb-palette-text, #0f172a) !important;
  }
  html body.profile-page #pvOverlay.pv-text-only .pv-left,
  html body.profile-page #pvOverlay.pv-text-only .pv-media,
  html[data-msb-appearance] body.profile-page #pvOverlay.pv-text-only .pv-left,
  html[data-msb-appearance] body.profile-page #pvOverlay.pv-text-only .pv-media,
  html[data-theme="dark"] body.profile-page #pvOverlay.pv-text-only .pv-left,
  html[data-theme="dark"] body.profile-page #pvOverlay.pv-text-only .pv-media,
  html.dark-auto body.profile-page #pvOverlay.pv-text-only .pv-left,
  html.dark-auto body.profile-page #pvOverlay.pv-text-only .pv-media{
    background:var(--msb-palette-bg, #f2f1e8) !important;
    background-color:var(--msb-palette-bg, #f2f1e8) !important;
    background-image:none !important;
  }
  /* Description-only (no media): separate title/body panel with border */
  html body.profile-page #pvOverlay.pv-text-only .pv-left,
  html[data-msb-appearance] body.profile-page #pvOverlay.pv-text-only .pv-left,
  html[data-theme="dark"] body.profile-page #pvOverlay.pv-text-only .pv-left,
  html.dark-auto body.profile-page #pvOverlay.pv-text-only .pv-left{
    border-radius:12px 0 0 12px !important;
    overflow:hidden !important;
    z-index:2 !important;
    box-shadow:none !important;
    border-right:1px solid var(--msb-palette-border, rgba(15,23,42,.12)) !important;
  }
  html body.profile-page #pvOverlay .pv-text-card,
  html[data-msb-appearance] body.profile-page #pvOverlay .pv-text-card,
  html[data-theme="dark"] body.profile-page #pvOverlay .pv-text-card,
  html.dark-auto body.profile-page #pvOverlay .pv-text-card{
    background:var(--msb-palette-bg, #f2f1e8) !important;
    background-color:var(--msb-palette-bg, #f2f1e8) !important;
    color:var(--msb-palette-text, #0f172a) !important;
  }
  /* Keep true black letterbox only behind video */
  html body.profile-page #pvOverlay .pv-media:has(> video),
  html body.profile-page #pvOverlay .pv-left:has(.pv-media > video),
  html[data-msb-appearance] body.profile-page #pvOverlay .pv-media:has(> video),
  html[data-msb-appearance] body.profile-page #pvOverlay .pv-left:has(.pv-media > video),
  html[data-theme="dark"] body.profile-page #pvOverlay .pv-media:has(> video),
  html[data-theme="dark"] body.profile-page #pvOverlay .pv-left:has(.pv-media > video),
  html.dark-auto body.profile-page #pvOverlay .pv-media:has(> video),
  html.dark-auto body.profile-page #pvOverlay .pv-left:has(.pv-media > video){
    background:#000 !important;
    background-color:#000 !important;
  }
</style>

<script>
(function(){
  const tabs = Array.from(document.querySelectorAll('.ig-tab[data-panel]'));
  const panels = Array.from(document.querySelectorAll('.profile-panel'));
  if (!tabs.length) return;

  function activate(panelName){
    tabs.forEach((tab) => {
      const isActive = tab.getAttribute('data-panel') === panelName;
      tab.classList.toggle('active', isActive);
      tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
      tab.setAttribute('tabindex', isActive ? '0' : '-1');
    });
    panels.forEach((panel) => {
      panel.classList.toggle('active', panel.id === 'panel-' + panelName);
    });
    var filterWrap = document.querySelector('.ig-gallery-filter');
    var contentTabs = ['gallery', 'posts', 'tags'];
    if (filterWrap) {
      var showFilter = contentTabs.includes(panelName);
      filterWrap.hidden = !showFilter;
      filterWrap.setAttribute('aria-hidden', showFilter ? 'false' : 'true');
      if (showFilter) {
        var tabInput = filterWrap.querySelector('input[name="tab"]');
        if (tabInput) tabInput.value = panelName;
      }
    }
    document.body.classList.toggle('profile-posts-mode', panelName === 'posts');
    document.body.classList.toggle('profile-gear-mode', panelName === 'gear');
    var gearSearch = document.getElementById('gearSearchInput');
    if (gearSearch && panelName !== 'gear') {
      gearSearch.value = '';
      gearSearch.dispatchEvent(new Event('input', { bubbles: true }));
    }
    if (panelName === 'posts' && window.ProfilePostsFeed && typeof window.ProfilePostsFeed.ensureLoaded === 'function') {
      window.ProfilePostsFeed.ensureLoaded(false);
    }
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('tab', panelName);
      window.history.replaceState({}, '', url.toString());
    } catch (e) {}
  }

  activate(<?php echo json_encode($selectedTab); ?>);

  tabs.forEach((tab) => {
    tab.addEventListener('click', () => activate(tab.getAttribute('data-panel') || 'posts'));
    tab.addEventListener('keydown', (e) => {
      const idx = tabs.indexOf(tab);
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        activate(tab.getAttribute('data-panel') || 'posts');
        return;
      }
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
        e.preventDefault();
        const next = tabs[(idx + 1) % tabs.length];
        next.focus();
        activate(next.getAttribute('data-panel') || 'posts');
        return;
      }
      if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
        e.preventDefault();
        const prev = tabs[(idx - 1 + tabs.length) % tabs.length];
        prev.focus();
        activate(prev.getAttribute('data-panel') || 'posts');
      }
    });
  });

  var galleryFilter = document.getElementById('galleryCategoryFilter');
  if (galleryFilter) {
    galleryFilter.addEventListener('change', function(){
      try {
        var url = new URL(window.location.href);
        var activeTab = String(url.searchParams.get('tab') || 'posts');
        if (!['gallery', 'posts', 'tags'].includes(activeTab)) activeTab = 'posts';
        url.searchParams.set('tab', activeTab);
        if (String(galleryFilter.value || '0') === '0') url.searchParams.delete('gallery_category');
        else url.searchParams.set('gallery_category', String(galleryFilter.value || '0'));
        var mirror = document.getElementById('gallerySearchCategoryMirror');
        if (mirror) mirror.value = String(galleryFilter.value || '0');
        window.location.href = url.toString();
      } catch (e) {}
    });
  }
})();

// Video thumbnail start
document.querySelectorAll('video.ig-vid').forEach(v => { try { v.currentTime = 0.1; } catch(e){} });

/* Prevent navigating when clicking react UI */
document.addEventListener('click', (e) => {
  if (e.target.closest('.react-btn') || e.target.closest('.react-close')) {
    e.preventDefault();
    e.stopPropagation();
  }
});

// ----------------------------
// ✅ Profile grid -> open post modal (NO post_view.php)
// Re-uses your feed_api.php endpoints (view/react/share/save/comment)
// ----------------------------

const GRID_IDS = <?php echo json_encode(array_values(array_map('intval', $gridIds ?? [])), JSON_UNESCAPED_SLASHES); ?>;
const GALLERY_GRID_IDS = <?php echo json_encode(array_values(array_map('intval', $galleryGridIds ?? [])), JSON_UNESCAPED_SLASHES); ?>;
const TAGS_GRID_IDS = <?php echo json_encode(array_values(array_map('intval', $tagsGridIds ?? [])), JSON_UNESCAPED_SLASHES); ?>;
let pvActiveGridIds = GRID_IDS;
try {
  window.GRID_IDS = GRID_IDS;
  window.GALLERY_GRID_IDS = GALLERY_GRID_IDS;
  window.TAGS_GRID_IDS = TAGS_GRID_IDS;
  Object.defineProperty(window, 'pvActiveGridIds', {
    configurable: true,
    get: function(){ return pvActiveGridIds; },
    set: function(v){ pvActiveGridIds = v; }
  });
} catch (eGridWin) {}
let pvActiveGridScope = 'all';
let galleryVisFilter = <?php
  $jsGalleryVis = 'public';
  if ($isOwnProfile) {
    $jsGalleryVis = ($galleryVisParam !== '' && in_array($galleryVisParam, ['private', 'friends', 'public'], true))
      ? $galleryVisParam
      : 'private';
  } elseif (!empty($profileIsPublisher)) {
    $jsGalleryVis = 'public';
  } elseif ($friendStatus === 'friends') {
    $jsGalleryVis = ($galleryVisParam === 'public') ? 'public' : 'friends';
  } else {
    $jsGalleryVis = 'public';
  }
  echo json_encode($jsGalleryVis, JSON_UNESCAPED_SLASHES);
?>;

(function initGalleryVisTabs(){
  const panel = document.getElementById('panel-gallery');
  if (!panel) return;
  const heads = panel.querySelector('.ig-grid-heads');
  const emptyEl = document.getElementById('galleryVisEmpty');
  if (!heads) return;

  function visibleGalleryIds(){
    const ids = [];
    panel.querySelectorAll('.ig-grid[data-grid-scope="gallery"] .ig-item:not(.is-vis-hidden)').forEach(function(item){
      const id = parseInt(item.getAttribute('data-post-id') || '0', 10) || 0;
      if (id > 0) ids.push(id);
    });
    return ids;
  }

  function applyGalleryVis(vis){
    galleryVisFilter = String(vis || 'public').toLowerCase();
    if (galleryVisFilter !== 'private' && galleryVisFilter !== 'public' && galleryVisFilter !== 'friends') {
      galleryVisFilter = 'public';
    }
    let shown = 0;
    panel.querySelectorAll('.ig-grid[data-grid-scope="gallery"] .ig-item').forEach(function(item){
      const itemVis = String(item.getAttribute('data-visibility') || 'public').toLowerCase();
      const match = itemVis === galleryVisFilter;
      item.classList.toggle('is-vis-hidden', !match);
      if (match) shown += 1;
    });
    heads.querySelectorAll('.ig-vis-tab').forEach(function(btn){
      const active = String(btn.getAttribute('data-vis') || '') === galleryVisFilter;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    if (emptyEl) emptyEl.classList.toggle('is-visible', shown === 0 && panel.querySelectorAll('.ig-grid[data-grid-scope="gallery"] .ig-item').length > 0);
    window.__MSB_GALLERY_VISIBLE_IDS = visibleGalleryIds();
  }

  heads.addEventListener('click', function(e){
    const btn = e.target.closest('.ig-vis-tab');
    if (!btn || !heads.contains(btn)) return;
    e.preventDefault();
    applyGalleryVis(btn.getAttribute('data-vis'));
  });

  applyGalleryVis(galleryVisFilter);
  window.msbApplyGalleryVisFilter = applyGalleryVis;
  window.msbGalleryVisibleIds = visibleGalleryIds;
})();

let pvIndex = -1;
let pvPostId = 0;
let pvReplyTo = 0;
let pvReplyToName = '';
let pvAlertFocusCommentId = <?php echo (int)$profileAlertCommentId; ?>;
let pvCommentsCache = [];
const pvCollapsedReplyIds = new Set();
const pvMaxReplyCurveDepth = 4;
let pvReplyToMode = 'Reply';
let pvCurrentReaction = '';
const PV_ME_ID = <?php echo (int)$meId; ?>;
let PV_FRIEND_STATUS = <?php echo json_encode($isOwnProfile ? 'self' : $friendStatus, JSON_UNESCAPED_SLASHES); ?>;
const PV_IS_PUBLISHER = <?php echo !empty($profileIsPublisher) ? 'true' : 'false'; ?>;
const PV_CAN_FOLLOW_PUBLISHERS = <?php echo !empty($canFollowPublishers) ? 'true' : 'false'; ?>;
let pvCurrentPost = null;
let pvCurrentAttachments = [];
function pvReplyToggleLabel(count, isOpen){
  const noun = count === 1 ? 'reply' : 'replies';
  return isOpen ? 'Close replies' : ('Open ' + count + ' ' + noun);
}
function pvReplyActionLabel(depth){
  return depth >= pvMaxReplyCurveDepth ? 'Comment' : 'Reply';
}


// ✅ Reliable viewport height on mobile (fixes keyboard/VH issues)
function pvSetVh(){
  try{
    const vh = (window.innerHeight || document.documentElement.clientHeight || 0) * 0.01;
    document.documentElement.style.setProperty('--vh', vh + 'px');
  }catch(e){}
}
pvSetVh();
window.addEventListener('resize', pvSetVh, {passive:true});
window.addEventListener('orientationchange', () => setTimeout(pvSetVh, 120), {passive:true});

let pvScrollY = 0;
function pvLockBodyScroll(){
  try{
    pvScrollY = window.scrollY || document.documentElement.scrollTop || 0;
    document.body.classList.add('pv-body-lock');
    // iOS: position fixed prevents background scroll + "scroll freeze"
    document.body.style.position = 'fixed';
    document.body.style.top = (-pvScrollY) + 'px';
    document.body.style.left = '0';
    document.body.style.right = '0';
    document.body.style.width = '100%';
    // Pause grid/story videos so they cannot paint over the modal (GPU layer punch-through).
    document.querySelectorAll('.ig-item video.ig-vid, .ig-story-ring video.ig-story-thumb, video.ig-story-thumb').forEach((v) => {
      try { v.pause(); } catch (ePause) {}
    });
  }catch(e){}
}
function pvUnlockBodyScroll(){
  try{
    document.body.classList.remove('pv-body-lock');
    const top = document.body.style.top;
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.width = '';
    const y = top ? Math.abs(parseInt(top, 10)) : (pvScrollY||0);
    window.scrollTo(0, y);
  }catch(e){}
}

const pv = {
  ov: document.getElementById('pvOverlay'),
  media: document.getElementById('pvMedia'),
  body: document.getElementById('pvBody'),
  caption: document.getElementById('pvCaption'),
  comments: document.getElementById('pvComments'),
  avatar: document.getElementById('pvAvatar'),
  name: document.getElementById('pvName'),
  meta: document.getElementById('pvMeta'),
  love: document.getElementById('pvLove'),
  like: document.getElementById('pvLike'),
  share: document.getElementById('pvShare'),
  save: document.getElementById('pvSave'),
  focusComment: document.getElementById('pvComment'),
  text: document.getElementById('pvText'),
  postBtn: document.getElementById('pvPostBtn'),
  loveN: document.getElementById('pvLoveN'),
  likeN: document.getElementById('pvLikeN'),
  comN: document.getElementById('pvComN'),
  shareN: document.getElementById('pvShareN'),
  saveN: document.getElementById('pvSaveN'),
  likesText: document.getElementById('pvLikesText'),
  commentsLink: document.getElementById('pvCommentsLink'),
  viewsText: document.getElementById('pvViewsText'),
  replyBar: document.getElementById('pvReplyBar'),
  replyLead: document.getElementById('pvReplyLead'),
  replyName: document.getElementById('pvReplyName'),
  replyCancel: document.getElementById('pvReplyCancel'),
  close: document.getElementById('pvClose'),
  prev: document.getElementById('pvPrev'),
  next: document.getElementById('pvNext'),
  left: document.querySelector('#pvOverlay .pv-left'),
};

// ✅ Mobile/tablet: when keyboard opens, keep input visible
if (pv.text) {
  pv.text.addEventListener('focus', () => {
    pvSetVh();
    setTimeout(() => {
      try {
        (pv.postBtn || pv.text).scrollIntoView({ block:'end', behavior:'smooth' });
        if (pv.body) pv.body.scrollTop = pv.body.scrollHeight;
      } catch(e) {}
    }, 180);
  });
  pv.text.addEventListener('blur', () => setTimeout(pvSetVh, 80));
}

function pvEsc(s){
  return (s ?? '').toString()
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');
}


function pvFormatRichText(text){
  const src = String(text == null ? '' : text).replace(/\r\n?/g, '\n').trim();
  if (!src) return '';

  const lines = src.split('\n');
  const out = [];
  let para = [];
  let listStack = [];

  function escLine(s){
    var html = pvEsc(s).replace(/  /g, ' &nbsp;');
    if (window.MSBMentionAC && typeof window.MSBMentionAC.linkify === 'function') {
      // Already escaped; linkify only @tokens
      html = html.replace(/(^|[^A-Za-z0-9_])@([A-Za-z0-9_]{2,50})\b/g, function(_, pre, user){
        return pre + '<a class="msb-mention" href="profile.php?username=' + encodeURIComponent(user) + '">@' + user + '</a>';
      });
    } else {
      html = html.replace(/(^|[^A-Za-z0-9_])@([A-Za-z0-9_]{2,50})\b/g, function(_, pre, user){
        return pre + '<a class="msb-mention" href="profile.php?username=' + encodeURIComponent(user) + '">@' + user + '</a>';
      });
    }
    return html;
  }
  function lineIndent(line){ const m = String(line || '').match(/^(\s*)/); return m ? m[1].replace(/\t/g, '    ').length : 0; }
  function listInfo(line){
    const raw = String(line || '');
    const bullet = raw.match(/^(\s*)([-*•◦▪‣])\s+(.*)$/);
    if (bullet) return { type:'ul', indent: Math.floor((bullet[1] || '').replace(/\t/g, '    ').length / 2), text: bullet[3] || '' };
    const ordered = raw.match(/^(\s*)((?:\d+|[A-Za-z]|[ivxlcdmIVXLCDM]+)[\.)])\s+(.*)$/);
    if (ordered) return { type:'ol', indent: Math.floor((ordered[1] || '').replace(/\t/g, '    ').length / 2), marker: ordered[2] || '', text: ordered[3] || '' };
    return null;
  }
  function flushPara(){
    if (!para.length) return;
    out.push('<p class="pv-rich-p">' + para.map(escLine).join('<br>') + '</p>');
    para = [];
  }
  function closeLists(toLevel){
    while (listStack.length > toLevel) {
      out.push('</li></' + listStack.pop() + '>');
    }
  }
  function openList(type){
    out.push('<' + type + ' class="pv-rich-list ' + (type === 'ol' ? 'is-ordered' : 'is-bullet') + '"><li class="pv-rich-li">');
    listStack.push(type);
  }

  lines.forEach(function(line){
    const raw = String(line || '');
    const trimmed = raw.trim();
    const info = listInfo(raw);
    if (!trimmed) {
      flushPara();
      closeLists(0);
      return;
    }
    if (info) {
      flushPara();
      const targetLevel = Math.max(0, info.indent + 1);
      while (listStack.length < targetLevel) openList(info.type);
      while (listStack.length > targetLevel) out.push('</li></' + listStack.pop() + '>');
      if (listStack.length && listStack[listStack.length - 1] !== info.type) {
        out.push('</li></' + listStack.pop() + '>');
        openList(info.type);
      } else if (listStack.length) {
        out.push('</li><li class="pv-rich-li">');
      }
      out.push('<span class="pv-rich-line">' + escLine(info.text) + '</span>');
    } else {
      if (listStack.length) closeLists(0);
      para.push(raw);
    }
  });

  flushPara();
  closeLists(0);
  return '<div class="pv-richtext">' + out.join('') + '</div>';
}

function pvTruncateText(s, maxSent){
  const txt = (s ?? '').toString().trim();
  const max = Math.max(1, Number(maxSent || 3));
  const maxChars = 170;
  if (!txt) return { short:'', full:'', truncated:false };
  const sents = txt.split(/[.!?]+/).map(function(x){ return String(x || '').trim(); }).filter(Boolean);
  if (sents.length <= max && txt.length <= maxChars) {
    return { short:txt, full:txt, truncated:false };
  }
  if (sents.length > max) {
    return { short: sents.slice(0, max).join('. ') + '.', full:txt, truncated:true };
  }
  let short = txt.slice(0, maxChars).trimEnd();
  const sp = short.lastIndexOf(' ');
  if (sp > Math.floor(maxChars * 0.6)) short = short.slice(0, sp);
  return { short, full:txt, truncated:true };
}


function pvTimeAgo(ts){
  const t = Date.parse(ts || '');
  if (!t) return '';
  const sec = Math.floor((Date.now() - t)/1000);
  if (sec < 60) return sec + 's';
  const m = Math.floor(sec/60); if (m < 60) return m + 'm';
  const h = Math.floor(m/60); if (h < 24) return h + 'h';
  const d = Math.floor(h/24); if (d < 7) return d + 'd';
  const w = Math.floor(d/7); if (w < 4) return w + 'w';
  const mo = Math.floor(d/30); if (mo < 12) return mo + 'mo';
  const y = Math.floor(d/365); return y + 'y';
}

function pvSetReply(parentId, displayName, mode){
  pvReplyTo = parentId || 0;
  pvReplyToName = displayName || '';
  pvReplyToMode = String(mode || 'Reply');
  const isCommentMode = pvReplyToMode === 'Comment';
  if (pvReplyTo > 0) {
    if (pv.replyLead) pv.replyLead.textContent = isCommentMode ? 'Commenting on' : 'Replying to';
    pv.replyName.textContent = pvReplyToName || '—';
    pv.replyBar.style.display = '';
    if (pv.text) pv.text.placeholder = (isCommentMode ? 'Comment on ' : 'Reply to ') + (pvReplyToName || 'comment');
  } else {
    pv.replyBar.style.display = 'none';
    if (pv.replyLead) pv.replyLead.textContent = 'Replying to';
    if (pv.text) pv.text.placeholder = 'Add a comment…';
  }
}

function pvGridScopeForElement(el){
  const grid = el && el.closest ? el.closest('.ig-grid[data-grid-scope]') : null;
  return grid ? String(grid.getAttribute('data-grid-scope') || 'all') : 'all';
}

function pvGridIdsForElement(el){
  const scope = pvGridScopeForElement(el);
  if (scope === 'gallery') {
    if (typeof window.msbGalleryVisibleIds === 'function') {
      const visible = window.msbGalleryVisibleIds();
      if (Array.isArray(visible) && visible.length) return visible;
    }
    return GALLERY_GRID_IDS;
  }
  if (scope === 'tags') return TAGS_GRID_IDS;
  return GRID_IDS;
}

function pvFindGridContext(postId){
  postId = Number(postId || 0);
  if (!postId) return null;
  const lists = [
    { ids: GALLERY_GRID_IDS, scope: 'gallery' },
    { ids: TAGS_GRID_IDS, scope: 'tags' },
    { ids: GRID_IDS, scope: 'all' },
  ];
  for (const row of lists) {
    const idx = Array.isArray(row.ids) ? row.ids.indexOf(postId) : -1;
    if (idx >= 0) return { ids: row.ids, idx, scope: row.scope };
  }
  return null;
}

function pvUpdateNavBtns(){
  const ids = Array.isArray(pvActiveGridIds) ? pvActiveGridIds : [];
  pv.prev.style.display = (pvIndex > 0) ? '' : 'none';
  pv.next.style.display = (pvIndex >= 0 && pvIndex < ids.length - 1) ? '' : 'none';
}

async function pvJson(url, opts){
  const res = await fetch(url, opts);
  const data = await res.json().catch(()=>null);
  if (!data || data.ok === false) {
    const msg = (data && data.error) ? data.error : 'Request failed';
    throw new Error(msg);
  }
  return data;
}

function pvOpenByIndex(idx){
  const ids = Array.isArray(pvActiveGridIds) ? pvActiveGridIds : [];
  if (!ids.length) return;
  if (idx < 0) idx = 0;
  if (idx >= ids.length) idx = ids.length - 1;
  pvIndex = idx;
  pvPostId = Number(ids[pvIndex] || 0);
  if (!pvPostId) return;
  pvCollapsedReplyIds.clear();
  pvCommentsCache = [];
  pvSetReply(0, '');
  pvSetVh();
  pv.ov.classList.add('show');
  pv.ov.setAttribute('aria-hidden', 'false');
  pvLockBodyScroll();
  pvLoad(pvPostId);
  pvUpdateNavBtns();
  pvPreloadNeighbors();
}

function pvOpenInGrid(postId, gridIds, scope){
  postId = Number(postId || 0);
  gridIds = Array.isArray(gridIds) ? gridIds : [];
  if (!postId || !gridIds.length) return;
  pvActiveGridIds = gridIds;
  pvActiveGridScope = scope || 'all';
  const idx = gridIds.indexOf(postId);
  if (idx >= 0) {
    pvOpenByIndex(idx);
    return;
  }
  pvPostId = postId;
  pvIndex = -1;
  pvCollapsedReplyIds.clear();
  pvCommentsCache = [];
  pvSetReply(0, '');
  pvSetVh();
  pv.ov.classList.add('show');
  pv.ov.setAttribute('aria-hidden', 'false');
  pvLockBodyScroll();
  pvLoad(pvPostId);
  if (pv.prev) pv.prev.style.display = 'none';
  if (pv.next) pv.next.style.display = 'none';
}

window.pvOpenById = function(postId){
  postId = Number(postId || 0);
  if (!postId) return;
  const ctx = pvFindGridContext(postId);
  if (ctx) {
    pvActiveGridIds = ctx.ids;
    pvActiveGridScope = ctx.scope || 'all';
    pvOpenByIndex(ctx.idx);
    return;
  }
  pvActiveGridIds = GRID_IDS;
  pvActiveGridScope = 'all';
  pvPostId = postId;
  pvIndex = -1;
  pvCollapsedReplyIds.clear();
  pvCommentsCache = [];
  pvSetReply(0, '');
  pvSetVh();
  pv.ov.classList.add('show');
  pv.ov.setAttribute('aria-hidden', 'false');
  pvLockBodyScroll();
  pvLoad(pvPostId);
  if (pv.prev) pv.prev.style.display = 'none';
  if (pv.next) pv.next.style.display = 'none';
};

function pvClose(){
  pv.ov.classList.remove('show');
  pv.ov.setAttribute('aria-hidden', 'true');
  pvUnlockBodyScroll();
  pvSetVh();
  pv.media.innerHTML = '';
  pv.caption.innerHTML = '';
  pv.caption.style.display = 'none';
  pv.comments.innerHTML = '';
  pvCommentsCache = [];
  pvCollapsedReplyIds.clear();
  pvPostId = 0;
  pvIndex = -1;
  pvSetReply(0, '');
}
try { window.pvClose = pvClose; } catch (ePvClose) {}
window.MSBClosePostViewer = function(postId){
  postId = Number(postId || 0);
  function prune(list){
    if (!Array.isArray(list)) return;
    for (var i = list.length - 1; i >= 0; i--) {
      if (Number(list[i] || 0) === postId) list.splice(i, 1);
    }
  }
  if (postId > 0) {
    prune(GRID_IDS);
    prune(GALLERY_GRID_IDS);
    prune(TAGS_GRID_IDS);
    prune(pvActiveGridIds);
  }
  try {
    var emptyEl = document.getElementById('galleryVisEmpty');
    var panel = document.getElementById('panel-gallery');
    if (emptyEl && panel) {
      var visible = panel.querySelectorAll('.ig-grid[data-grid-scope="gallery"] .ig-item:not(.is-vis-hidden)').length;
      var total = panel.querySelectorAll('.ig-grid[data-grid-scope="gallery"] .ig-item').length;
      emptyEl.classList.toggle('is-visible', visible === 0 && total > 0);
    }
  } catch (eEmpty) {}
  var showing = Number(pvPostId || 0);
  if (postId > 0 && showing > 0 && showing !== postId) return;
  if (typeof pvClose === 'function') pvClose();
  try {
    var u = new URL(window.location.href);
    var changed = false;
    ['post', 'post_id', 'open_post', 'fresh'].forEach(function(key){
      if (u.searchParams.has(key)) {
        u.searchParams.delete(key);
        changed = true;
      }
    });
    if (changed) window.history.replaceState({}, '', u.pathname + (u.search || '') + u.hash);
  } catch (eUrl) {}
};
function pvSyncMidCaptionState(hasCaption){
  const ov = pv && pv.ov ? pv.ov : document.getElementById('pvOverlay');
  const mid = document.getElementById('pvMid');
  if (mid) mid.classList.toggle('is-empty', !hasCaption);
  if (!ov) return;
  ov.classList.toggle('pv-has-mid-caption', !!hasCaption);
  ov.classList.toggle('pv-no-mid-caption', !hasCaption);
}

function pvSyncMediaOrientation(){
  const ov = pv && pv.ov ? pv.ov : document.getElementById('pvOverlay');
  if (!ov || !pv || !pv.media) return;
  let el = null;
  const carousel = pv.media.querySelector('.mf-media-carousel, .media-carousel');
  if (carousel) {
    const idx = Math.max(0, Number(carousel.getAttribute('data-index') || 0));
    const slides = carousel.querySelectorAll('.mf-media-slide, .media-slide');
    const slide = slides[idx] || slides[0];
    el = slide ? slide.querySelector('img, video') : null;
  }
  if (!el) el = pv.media.querySelector('img, video');
  let w = 0, h = 0;
  if (el) {
    if (String(el.tagName || '').toUpperCase() === 'VIDEO') {
      w = Number(el.videoWidth || 0);
      h = Number(el.videoHeight || 0);
    } else {
      w = Number(el.naturalWidth || 0);
      h = Number(el.naturalHeight || 0);
    }
    if ((!w || !h) && el.getBoundingClientRect) {
      const r = el.getBoundingClientRect();
      w = Number(r.width || 0);
      h = Number(r.height || 0);
    }
  }
  const isPortrait = (w > 0 && h > 0) ? (h > w * 1.05) : false;
  const isLandscape = (w > 0 && h > 0) ? (w >= h * 0.95) : !isPortrait;
  ov.classList.toggle('pv-is-portrait', !!isPortrait);
  ov.classList.toggle('pv-is-landscape', !!isLandscape && !isPortrait);
}

function pvRenderCaption(post, atts, slideIndex){
  const mid = document.getElementById('pvMid');
  const list = Array.isArray(atts) ? atts : (Array.isArray(pvCurrentAttachments) ? pvCurrentAttachments : []);
  const idx = Math.max(0, Number(slideIndex || 0));
  const anySlideText = list.some(function(a){
    return String(a && (a.slide_title || a.slide_body) || '').trim() !== '';
  });
  const title = (post?.title || '').toString().trim();
  const desc = (post?.body || post?.description || '').toString().trim();
  let slideTitle = '';
  let slideDesc = '';
  if (anySlideText) {
    const att = list[idx] || {};
    slideTitle = String(att.slide_title || '').trim();
    slideDesc = String(att.slide_body || '').trim();
  }
  const hasMedia = list.length > 0 || (Array.isArray(atts) && atts.length > 0);
  const hasCaption = !!(title || desc || slideTitle || slideDesc);

  function slideSummaryHtml(text){
    const raw = String(text || '').replace(/\r\n/g,'\n').replace(/\r/g,'\n').trim();
    if (!raw) return '';
    const lines = raw.split('\n').map(function(line){
      return String(line || '').trim().replace(/^(?:[•\-\*]|\d+[\.\)])\s+/, '');
    }).filter(Boolean);
    if (!lines.length) return '';
    if (lines.length === 1) {
      return `<div class="post-slide-summary"><p class="post-slide-summary-p">${pvEsc(lines[0])}</p></div>`;
    }
    return `<div class="post-slide-summary"><ul class="post-slide-summary-list">${
      lines.map(function(line){ return `<li>${pvEsc(line)}</li>`; }).join('')
    }</ul></div>`;
  }

  // ✅ If there is NO media on the left, we show text on the left only.
  // So the center caption should be hidden to avoid duplicate text.
  if (!hasMedia && !anySlideText) {
    pv.caption.style.display = 'none';
    pv.caption.innerHTML = '';
    pvSyncMidCaptionState(false);
    return;
  }

  // Nothing to show in the center column
  if (!hasCaption) {
    pv.caption.style.display = 'none';
    pv.caption.innerHTML = '';
    pvSyncMidCaptionState(false);
    return;
  }

  pv.caption.style.display = '';
  pvSyncMidCaptionState(true);

  const titleHtml = title ? `<div class="pv-cap-title">${(window.MSBMentionAC && window.MSBMentionAC.linkify) ? window.MSBMentionAC.linkify(title) : pvEsc(title)}</div>` : '';
  const descHtml = desc ? `<div class="pv-cap-desc pv-cap-intro">${pvFormatRichText(desc)}</div>` : '';
  const subHtml = (anySlideText && slideTitle) ? `<div class="pv-cap-subtitle">${(window.MSBMentionAC && window.MSBMentionAC.linkify) ? window.MSBMentionAC.linkify(slideTitle) : pvEsc(slideTitle)}</div>` : '';
  const sumHtml = (anySlideText && slideDesc) ? `<div class="pv-cap-summary">${slideSummaryHtml(slideDesc)}</div>` : '';
  pv.caption.innerHTML = `<div class="pv-cap">${titleHtml}${descHtml}${subHtml}${sumHtml}</div>`;
}

function pvRenderMedia(post, atts){
  // Show attachments (carousel when multi); otherwise show title/body card
  const title = (post?.title || '').trim();
  const desc  = (post?.description || '').trim();
  const body  = (post?.body || '').trim();

  // ✅ Text-only posts: allow the left panel to scroll on every viewport
  try{
    const hasMedia = Array.isArray(atts) && atts.length > 0;
    const textOnly = !hasMedia && ((title || desc || body || '').trim() !== '');
    if (pv.left) pv.left.classList.toggle('pv-left-scroll', !!textOnly);
    if (pv.ov) pv.ov.classList.toggle('pv-text-only', !!textOnly);
  }catch(e){}

  function pvAttSrc(a){
    // Prefer full media file over thumb so we never render a second smaller copy.
    return String((a?.url || a?.file_path || a?.thumb_url || a?.thumb_path || '') || '').trim();
  }
  function pvAttKey(a){
    let p = pvAttSrc(a).split('?')[0].toLowerCase();
    if (!p) return '';
    p = p.replace(/\/thumbs\//g, '/');
    p = p.replace(/([_-])thumb(\.[a-z0-9]+)$/i, '$2');
    p = p.replace(/\/thumb_/g, '/');
    return p;
  }
  function pvNormalizeAtts(list){
    if (!Array.isArray(list)) return [];
    const out = [];
    const seen = new Set();
    list.forEach((a) => {
      const key = pvAttKey(a);
      if (!key || seen.has(key)) return;
      seen.add(key);
      out.push(a);
    });
    return out;
  }
  function pvAttKind(a, url){
    const type = String(a?.type || '').toLowerCase();
    if (type === 'video' || /\.(mp4|webm|ogg|mov|m4v)(\?.*)?$/i.test(url)) return 'video';
    if (type === 'pdf' || /\.(pdf|docx|pptx|doc)(\?.*)?$/i.test(url)) return 'pdf';
    return 'image';
  }
  function pvSlideInner(a){
    const url = pvAttSrc(a);
    const kind = pvAttKind(a, url);
    if (kind === 'video') {
      return `<video class="msb-clean-loop-video" src="${pvEsc(url)}" autoplay loop muted playsinline webkit-playsinline preload="metadata" disablepictureinpicture controlslist="nodownload noplaybackrate nofullscreen"></video>`;
    }
    if (kind === 'pdf') {
      return `<iframe src="${pvEsc(url)}" style="width:100%;height:100%;border:0;"></iframe>`;
    }
    // Full file only — do not also paint thumb (avoids “media on media”).
    return `<img src="${pvEsc(url)}" alt="" />`;
  }
  function pvMediaDots(count){
    if (count <= 1) return '';
    let dots = '';
    for (let i = 0; i < count; i++) {
      dots += `<button type="button" class="mf-media-dot${i === 0 ? ' is-active' : ''}" data-index="${i}" aria-label="Go to media ${i + 1}"></button>`;
    }
    return `<div class="mf-media-dots" role="tablist" aria-label="Media slides">${dots}</div>`;
  }
  function pvCarouselNav(){
    return '' +
      '<button type="button" class="media-nav mf-media-nav prev js-pv-media-prev" aria-label="Previous media"><i class="fa fa-chevron-left" aria-hidden="true"></i></button>' +
      '<button type="button" class="media-nav mf-media-nav next js-pv-media-next" aria-label="Next media"><i class="fa fa-chevron-right" aria-hidden="true"></i></button>';
  }

  atts = pvNormalizeAtts(atts);

  if (Array.isArray(atts) && atts.length > 1) {
    let slides = '';
    atts.forEach((a, i) => {
      slides += `<div class="media-slide mf-media-slide" data-slide-index="${i}">${pvSlideInner(a)}</div>`;
    });
    pv.media.innerHTML = `
      <div class="media-carousel mf-media-carousel" data-index="0">
        <div class="media-slides mf-media-slides">${slides}</div>
        ${pvCarouselNav()}
        ${pvMediaDots(atts.length)}
      </div>
    `;
    const carousel = pv.media.querySelector('.mf-media-carousel');
    if (carousel) pvSetMediaCarouselIndex(carousel, 0);
    return;
  }

  if (Array.isArray(atts) && atts.length === 1) {
    pv.media.innerHTML = pvSlideInner(atts[0]);
    return;
  }

  // no attachments — full description with vertical scroll (no Read more clamp)
  const t = (title || '').trim();
  const text = (desc || body || '').trim();

  if (t && !text) {
    pv.media.innerHTML = `
      <div class="pv-text-card">
        <div class="pv-text-card-inner">
          <div class="pv-text-card-title">${pvEsc(t)}</div>
        </div>
      </div>
    `;
    return;
  }

  pv.media.innerHTML = `
    <div class="pv-text-card">
      <div class="pv-text-card-inner">
        ${t ? `<div class="pv-text-card-title">${pvEsc(t)}</div>` : ``}
        ${text ? `<div class="pv-media-text pv-text-card-body">${pvFormatRichText(text)}</div>` : ''}
      </div>
    </div>
  `;
}

function pvSetMediaCarouselIndex(carousel, nextIndex){
  if (!carousel) return;
  const slides = carousel.querySelector('.mf-media-slides, .media-slides');
  const dots = carousel.querySelectorAll('.mf-media-dot, .media-dot');
  const slideCount = carousel.querySelectorAll('.mf-media-slide, .media-slide').length;
  if (slideCount < 1) return;
  let idx = Number(nextIndex || 0);
  if (!isFinite(idx)) idx = 0;
  if (idx < 0) idx = 0;
  if (idx > slideCount - 1) idx = slideCount - 1;
  carousel.setAttribute('data-index', String(idx));
  if (slides) slides.style.transform = 'translateX(' + String(idx * -100) + '%)';
  dots.forEach((dot) => {
    const di = Number(dot.getAttribute('data-index'));
    const on = di === idx;
    dot.classList.toggle('is-active', on);
    dot.style.background = on ? '#3897f0' : 'rgba(255,255,255,.55)';
  });
  const prevBtn = carousel.querySelector('.js-pv-media-prev, .mf-media-nav.prev');
  const nextBtn = carousel.querySelector('.js-pv-media-next, .mf-media-nav.next');
  if (prevBtn) prevBtn.style.display = idx > 0 ? '' : 'none';
  if (nextBtn) nextBtn.style.display = idx < slideCount - 1 ? '' : 'none';
  // Presentation mode: mid description follows the active slide.
  try {
    if (typeof pvRenderCaption === 'function') {
      pvRenderCaption(pvCurrentPost, pvCurrentAttachments, idx);
    }
    if (typeof pvSyncMediaOrientation === 'function') {
      pvSyncMediaOrientation();
    }
  } catch (eCap) {}
}

function pvRenderComments(post, comments){
  const items = Array.isArray(comments) ? comments : [];
  pvCommentsCache = items;
  if (items.length === 0) {
    pv.comments.innerHTML = `<div class="t";style="color:rgba(15,23,42,.55);font-size:13px;padding:14px 4px;">No comments yet.</div>`;
    return;
  }
  const byId = {};
  items.forEach((c) => { byId[Number(c?.id || 0)] = Object.assign({}, c, { _replies: [] }); });
  const roots = [];
  Object.values(byId).forEach((c) => {
    const parentId = Number(c?.parent_id || 0);
    if (parentId > 0 && byId[parentId]) byId[parentId]._replies.push(c);
    else roots.push(c);
  });
  function annotateReplyDepth(node, depth, cappedAncestorId){
    const nextCappedAncestorId = (depth === pvMaxReplyCurveDepth - 1) ? Number(node?.id || 0) : cappedAncestorId;
    node._reply_target_id = (depth >= pvMaxReplyCurveDepth && cappedAncestorId > 0) ? cappedAncestorId : Number(node?.id || 0);
    node._reply_action_label = pvReplyActionLabel(depth);
    node._replies.forEach((child) => annotateReplyDepth(child, depth + 1, nextCappedAncestorId));
  }
  roots.forEach((node) => annotateReplyDepth(node, 0, 0));

  function commentHtml(c, depth){
    const cid = Number(c?.id || 0);
    const nm  = (c?.display_name || c?.username || 'User').toString();
    const txt = (c?.comment_text || '').toString();
    const t   = pvTimeAgo(c?.created_at);
    const ava = `avatar.php?name=${encodeURIComponent(nm)}`;
    const liked = Number(c?.me_liked || 0) === 1;
    const likeCount = Number(c?.like_count || 0);
    const myReaction = String(c?.my_reaction || '');
    const reactionLabel = (window.MSBReactions && typeof window.MSBReactions.label === 'function')
      ? window.MSBReactions.label(myReaction || 'love')
      : (myReaction ? myReaction : 'Love');
    const kids = Array.isArray(c?._replies) ? c._replies : [];
    const replyCount = kids.length;
    const repliesOpen = !pvCollapsedReplyIds.has(cid);
    const childrenHtml = kids.map((child) => commentHtml(child, depth + 1)).join('');
    const depthClamped = depth > pvMaxReplyCurveDepth;
    const childDepthCapped = (depth + 1) > pvMaxReplyCurveDepth;
    const replyActionLabel = String(c?._reply_action_label || pvReplyActionLabel(depth));
    const replyTargetId = Number(c?._reply_target_id || cid);
    return `
      <div class="pv-node${depth > 0 ? ' is-reply' : ''}${replyCount > 0 ? ' has-children' : ''}${replyCount > 0 && !repliesOpen ? ' is-collapsed' : ''}${depthClamped ? ' is-depth-clamped' : ''}" data-cid="${cid}">
        <div class="pv-com" data-cid="${cid}">
          <div class="a" title="${pvEsc(nm)}"><img src="${pvEsc(ava)}" alt="${pvEsc(nm)}" /></div>
          <div class="b">
            <div class="bubble">
              <div class="nm">${pvEsc(nm)}</div>
              <div class="tx">${(window.MSBMentionAC && window.MSBMentionAC.linkify) ? window.MSBMentionAC.linkify(txt) : pvEsc(txt)}</div>
            </div>
            <div class="m">
              <span>${pvEsc(t)}</span>
              <button type="button" class="likebtn ${liked ? 'is-liked' : ''} pv-clike" data-cid="${cid}" data-reaction="${pvEsc(myReaction)}"><i class="fa fa-heart-o"></i><span data-reaction-label>${pvEsc(liked ? reactionLabel : 'Love')}</span></button>
              <button type="button" class="link replies-toggle pv-reply" data-cid="${replyTargetId}" data-name="${pvEsc(nm)}" data-mode="${pvEsc(replyActionLabel)}">${pvEsc(replyActionLabel)}</button>
              ${replyCount > 0 ? `<button type="button" class="link replies-toggle pv-toggle-replies" data-toggle-replies="${cid}">${pvEsc(pvReplyToggleLabel(replyCount, repliesOpen))}</button>` : ``}
              ${likeCount > 0 ? `<span class="pv-likepill"><i class="fa fa-thumbs-up"></i><span>${pvEsc(String(likeCount))}</span></span>` : ``}
            </div>
          </div>
        </div>
        ${replyCount > 0 && repliesOpen ? `<div class="pv-children${childDepthCapped ? ' depth-capped' : ''}">${childrenHtml}</div>` : ``}
      </div>
    `;
  }

  pv.comments.innerHTML = roots.map((c) => commentHtml(c, 0)).join('');
  if(window.MSBReactions){
    pv.comments.querySelectorAll('.pv-clike').forEach((btn) => {
      window.MSBReactions.applyReactionButton(btn, btn.getAttribute('data-reaction') || '', 'love');
    });
  }

  if (pvAlertFocusCommentId > 0) {
    setTimeout(() => { pvFocusCommentById(pvAlertFocusCommentId); }, 0);
  }
}

function pvFocusCommentById(commentId){
  commentId = Number(commentId || 0);
  if (!commentId || !pv.comments) return false;
  pv.comments.querySelectorAll('.pv-com.is-alert-focus').forEach((node) => node.classList.remove('is-alert-focus'));
  const row = pv.comments.querySelector(`.pv-com[data-cid="${commentId}"]`);
  if (!row) return false;
  row.classList.add('is-alert-focus');
  try { row.scrollIntoView({ block:'center', behavior:'smooth' }); } catch (e) {}
  return true;
}

function pvApplyLoveReaction(my){
  my = String(my || '');
  pvCurrentReaction = my;
  const btn = document.getElementById('pvLove') || pv.love;
  if (!btn) return;

  // Direct DOM swap for the modal — do not depend on SVG/mask leftovers
  const countEl = document.getElementById('pvLoveN') || btn.querySelector('.pv-n');
  Array.prototype.slice.call(btn.children).forEach(function(node){
    if (countEl && node === countEl) return;
    if (node && node.matches && node.matches('svg, i, .msb-pact, .msb-reaction-glyph, .msb-reaction-host')) {
      try { node.remove(); } catch (e) { if (node.parentNode) node.parentNode.removeChild(node); }
    }
  });

  let el;
  if (my && my !== 'love') {
    el = document.createElement('span');
    el.className = 'msb-reaction-glyph';
    el.id = 'pvLoveIcon';
    el.setAttribute('aria-hidden', 'true');
    const emojiMap = {
      like:'👍', dislike:'👎', smile:'😊', laugh:'😂', clap:'👏',
      wow:'😮', sad:'😢', angry:'😡'
    };
    el.textContent = emojiMap[my] || ((window.MSBReactions && window.MSBReactions.emoji)
      ? window.MSBReactions.emoji(my)
      : '👍');
    btn.classList.remove('is-love');
    btn.classList.add('is-reacted', 'has-rx-icon');
    btn.setAttribute('data-rx', my);
    btn.setAttribute('data-selected-reaction', my);
    btn.setAttribute('title', (window.MSBReactions && window.MSBReactions.label) ? window.MSBReactions.label(my) : my);
    btn.setAttribute('aria-label', btn.getAttribute('title') || my);
  } else {
    el = document.createElement('i');
    el.className = 'msb-pact msb-pact-heart' + (my === 'love' ? ' is-active' : '');
    el.id = 'pvLoveIcon';
    el.setAttribute('aria-hidden', 'true');
    if (my === 'love') el.style.color = 'var(--msb-love-color, #7c3aed)';
    btn.classList.toggle('is-love', my === 'love');
    btn.classList.toggle('is-reacted', false);
    btn.classList.add('has-rx-icon');
    btn.setAttribute('data-rx', my === 'love' ? 'love' : 'love');
    btn.setAttribute('data-selected-reaction', my === 'love' ? 'love' : '');
    btn.setAttribute('title', 'Love');
    btn.setAttribute('aria-label', 'Love');
  }
  if (countEl && countEl.parentNode === btn) btn.insertBefore(el, countEl);
  else btn.insertBefore(el, btn.firstChild || null);

  if (window.MSBReactions && typeof window.MSBReactions.applyReactionButton === 'function') {
    try { window.MSBReactions.applyReactionButton(btn, my, 'love'); } catch (e) {}
  }
}
try { window.pvApplyLoveReaction = pvApplyLoveReaction; } catch (eWin) {}

function pvApplyCounts(data){
  const post = data?.post || {};
  const counts = data?.counts || {};

  // avatar/name
  const dn = (post.display_name || post.username || '').toString();
  if (dn) {
    pv.name.textContent = dn;
    pv.avatar.src = `avatar.php?name=${encodeURIComponent(dn)}`;
  }
  if (post.created_at) {
    pv.meta.textContent = 'Posted ' + pvTimeAgo(post.created_at);
    if (window.MSBPostCardMenu && typeof window.MSBPostCardMenu.visibilityBadgeHtml === 'function') {
      pv.meta.insertAdjacentHTML('beforeend', ' ' + window.MSBPostCardMenu.visibilityBadgeHtml(post.visibility || 'friends'));
    }
  }

  // counts
  const loveN = Number(counts.love_count || 0);
  const likeN = Number(counts.like_count || 0);
  const commentN = Number(post.comment_count ?? pv.comN?.textContent ?? 0);
  pv.loveN.textContent = String(loveN);
  pv.likeN.textContent = String(likeN);
  pv.comN.textContent = String(commentN);
  if (pv.likesText) pv.likesText.textContent = `${loveN + likeN} ${(loveN + likeN) === 1 ? 'reaction' : 'reactions'}`;
  if (pv.commentsLink) pv.commentsLink.textContent = `View all ${commentN} ${commentN === 1 ? 'comment' : 'comments'}`;
  if (pv.viewsText) {
    const viewN = Number(post.views_count ?? 0);
    pv.viewsText.textContent = `${viewN} ${viewN === 1 ? 'view' : 'views'}`;
  }

  // my reaction — prefer counts, then post, keep sticky selection during engage window
  let my = '';
  if (Object.prototype.hasOwnProperty.call(counts, 'my_reaction')) {
    my = String(counts.my_reaction || '');
  } else if (Object.prototype.hasOwnProperty.call(post, 'my_reaction')) {
    my = String(post.my_reaction || '');
  } else {
    my = String(pvCurrentReaction || '');
  }
  const engageAt = Number((document.getElementById('pvOverlay') || {}).getAttribute?.('data-engage-at') || 0);
  if (engageAt && (Date.now() - engageAt) < 8000 && pvCurrentReaction) {
    // Don't let blank/stale API responses snap the modal icon back to love
    if (!Object.prototype.hasOwnProperty.call(counts, 'my_reaction') || my === '') {
      my = pvCurrentReaction;
    }
  }
  pvApplyLoveReaction(my);
  if (window.MSBReactions && pv.like) {
    window.MSBReactions.applyLikeButton(pv.like, my === 'like' ? my : '');
  } else if (pv.like) {
    pv.like.classList.toggle('is-like', my === 'like');
  }
}

function pvApplyTrack(res){
  if (!res) return;
  const state = res.state || {};
  if (pv.shareN) pv.shareN.textContent = String(Number(res.share_count || 0));
  if (pv.saveN) pv.saveN.textContent = String(Number(res.save_count || 0));
  const shared = Number(state.shared ?? res.my_shared ?? 0) === 1;
  const saved = Number(state.saved ?? res.my_saved ?? 0) === 1;
  if (pv.share) pv.share.classList.toggle('is-share', shared);
  if (pv.save) pv.save.classList.toggle('is-save', saved);
}

function pvCountMeta(){
  return {
    comment_count: Number(pv.comN?.textContent || 0),
    views_count: Number(String(pv.viewsText?.textContent || '0').split(' ')[0] || 0)
  };
}

function pvSyncMenu(post){
  const wrap = document.getElementById('pvMenuWrap');
  const menu = document.getElementById('pvMenu');
  const friendBtn = document.getElementById('pvFriendBtn');
  if (!wrap || !menu) return;
  post = post || pvCurrentPost || {};
  const pid = Number(post.id || post.post_id || pvPostId || 0);
  const peerId = Number(post.user_id || post.author_id || 0);
  const isOwner = peerId > 0 && peerId === PV_ME_ID;
  let friendStatus = String(post.friend_status || '').trim();
  if (!friendStatus) {
    friendStatus = isOwner ? 'self' : String(PV_FRIEND_STATUS || 'none');
  }
  const isPublisher = PV_IS_PUBLISHER
    || Number(post.is_publisher || 0) === 1
    || String(post.account_kind || '').toLowerCase() === 'publisher';
  const isFollowing = Number(post.is_following || 0) === 1;
  const friendCode = String(post.friend_code || '').trim();
  const username = String(post.username || '').trim();
  let profileUrl = '';
  if (friendCode) profileUrl = 'profile.php?friend_code=' + encodeURIComponent(friendCode.toUpperCase());
  else if (username) profileUrl = 'profile.php?username=' + encodeURIComponent(username);
  else if (peerId > 0) profileUrl = 'profile.php?id=' + String(peerId);

  wrap.setAttribute('data-post-id', String(pid));
  wrap.setAttribute('data-peer-id', String(peerId));
  wrap.setAttribute('data-is-owner', isOwner ? '1' : '0');
  wrap.setAttribute('data-menu-surface', 'profile');
  wrap.setAttribute('data-friend-status', friendStatus || 'none');
  wrap.setAttribute('data-account-kind', isPublisher ? 'publisher' : String(post.account_kind || 'personal'));
  wrap.setAttribute('data-is-publisher', isPublisher ? '1' : '0');
  wrap.setAttribute('data-is-following', isFollowing ? '1' : '0');
  wrap.setAttribute('data-my-saved', String(Number(post.my_saved || post.is_saved || 0) === 1 ? 1 : 0));
  if (friendCode) wrap.setAttribute('data-peer-code', friendCode);
  else wrap.removeAttribute('data-peer-code');
  if (profileUrl) wrap.setAttribute('data-profile-url', profileUrl);
  else wrap.removeAttribute('data-profile-url');

  const it = {
    id: pid,
    post_id: pid,
    user_id: peerId,
    author_id: peerId,
    friend_code: friendCode,
    username: username,
    account_kind: isPublisher ? 'publisher' : String(post.account_kind || 'personal'),
    is_publisher: isPublisher ? 1 : 0,
    is_following: isFollowing ? 1 : 0,
    friend_status: friendStatus,
    profile_url: profileUrl,
    my_saved: Number(post.my_saved || post.is_saved || 0),
    is_saved: Number(post.my_saved || post.is_saved || 0),
    is_archived: Number(post.is_archived || 0),
    contact_id: Number(post.contact_id || 0),
    contact_name: String(post.contact_name || '')
  };

  let html = '';
  if (window.MSBPostCardMenu && typeof window.MSBPostCardMenu.buildItems === 'function') {
    html = window.MSBPostCardMenu.buildItems(it, isOwner, pid, window.MSBProfileMenuHelpers || {}) || '';
  }
  menu.innerHTML = html;
  wrap.style.display = '';
  const dots = document.getElementById('pvDots');
  if (dots) dots.style.display = '';

  if (friendBtn) {
    const showPlus = !isOwner && !isPublisher && friendStatus !== 'friends' && friendStatus !== 'self' && peerId > 0;
    friendBtn.hidden = !showPlus;
    if (showPlus) {
      friendBtn.setAttribute('data-peer-id', String(peerId));
      friendBtn.setAttribute('data-status', friendStatus || 'none');
      friendBtn.classList.remove('is-friends', 'is-pending', 'is-accept');
      friendBtn.disabled = friendStatus === 'outgoing_pending';
      if (friendStatus === 'incoming_pending') {
        friendBtn.classList.add('is-accept');
        friendBtn.innerHTML = '<span class="mf-media-action-label">Accept</span>';
      } else if (friendStatus === 'outgoing_pending') {
        friendBtn.classList.add('is-pending');
        friendBtn.innerHTML = '<span class="mf-media-action-label">Sent</span>';
      } else {
        friendBtn.classList.add('primary');
        friendBtn.innerHTML = '<i class="fa fa-plus" aria-hidden="true"></i>';
      }
    }
  }
}
try { window.pvSyncMenu = pvSyncMenu; } catch (eWin) {}

let pvLoadSeq = 0;
const pvViewCache = new Map(); // postId -> Promise|payload

function pvFindGridItem(postId){
  postId = Number(postId || 0);
  if (!postId) return null;
  let el = null;
  if (pvActiveGridScope === 'gallery' || pvActiveGridScope === 'tags') {
    el = document.querySelector(`.ig-grid[data-grid-scope="${pvActiveGridScope}"] .ig-item[data-post-id="${postId}"]`);
  }
  if (!el) el = document.querySelector(`.ig-item[data-post-id="${postId}"]`);
  return el;
}

function pvCachePut(postId, payload){
  postId = Number(postId || 0);
  if (postId > 0 && payload) pvViewCache.set(postId, payload);
}

function pvFetchView(postId){
  postId = Number(postId || 0);
  if (postId <= 0) return Promise.reject(new Error('Missing post'));
  const hit = pvViewCache.get(postId);
  if (hit && typeof hit.then === 'function') return hit;
  if (hit && hit.post) return Promise.resolve(hit);
  const req = pvJson(`feed_api.php?ajax=view&id=${encodeURIComponent(postId)}&count_view=1`, { credentials:'same-origin' })
    .then(function(view){
      pvCachePut(postId, view);
      return view;
    })
    .catch(function(err){
      pvViewCache.delete(postId);
      throw err;
    });
  pvViewCache.set(postId, req);
  return req;
}

function pvPrefetchView(postId){
  postId = Number(postId || 0);
  if (postId <= 0) return;
  if (pvViewCache.has(postId)) return;
  pvFetchView(postId).catch(function(){});
}

function pvWaitMediaReady(root){
  return new Promise(function(resolve){
    if (!root) { resolve(); return; }
    const mediaEls = Array.prototype.slice.call(root.querySelectorAll('img, video'));
    if (!mediaEls.length) { resolve(); return; }
    let left = mediaEls.length;
    let settled = false;
    const done = function(){
      if (settled) return;
      left -= 1;
      if (left <= 0) {
        settled = true;
        resolve();
      }
    };
    mediaEls.forEach(function(el){
      if (el.tagName === 'IMG') {
        if (el.complete && el.naturalWidth > 0) { done(); return; }
        el.addEventListener('load', done, { once:true });
        el.addEventListener('error', done, { once:true });
        return;
      }
      if (el.tagName === 'VIDEO') {
        if (el.readyState >= 2 && (el.videoWidth > 0 || el.currentSrc)) { done(); return; }
        const onReady = function(){ done(); };
        el.addEventListener('loadeddata', onReady, { once:true });
        el.addEventListener('error', onReady, { once:true });
        try { el.load(); } catch (eLoad) {}
        return;
      }
      done();
    });
    setTimeout(function(){ if (!settled) { settled = true; resolve(); } }, 700);
  });
}

function pvFadeMedia(out){
  return new Promise(function(resolve){
    if (!pv.media) { resolve(); return; }
    if (!out) {
      pv.media.classList.remove('pv-media-fade');
      resolve();
      return;
    }
    pv.media.classList.add('pv-media-fade');
    setTimeout(resolve, 160);
  });
}

async function pvApplyView(view, { animate } = {}){
  if (animate) await pvFadeMedia(true);
  pvCurrentPost = view.post || null;
  pvCurrentAttachments = Array.isArray(view.attachments) ? view.attachments.slice() : [];
  if (pvCurrentPost && !pvCurrentPost.friend_status) {
    pvCurrentPost.friend_status = (Number(pvCurrentPost.user_id || 0) === PV_ME_ID)
      ? 'self'
      : String(PV_FRIEND_STATUS || 'none');
  }
  pvRenderMedia(view.post, view.attachments);
  pvRenderCaption(view.post, view.attachments, 0);
  pvRenderComments(view.post, view.comments);
  pvApplyCounts(view);
  pvSyncMenu(pvCurrentPost);
  pv.comN.textContent = String((Array.isArray(view.comments) ? view.comments.length : 0));
  if (pv.commentsLink) pv.commentsLink.textContent = `View all ${pv.comN.textContent} ${Number(pv.comN.textContent) === 1 ? 'comment' : 'comments'}`;
  await pvWaitMediaReady(pv.media);
  try { pvSyncMediaOrientation(); } catch (eOrient) {}
  if (animate) await pvFadeMedia(false);
  else if (pv.media) pv.media.classList.remove('pv-media-fade');
}

async function pvLoad(postId){
  postId = Number(postId || 0);
  const seq = ++pvLoadSeq;
  const alreadyOpen = !!(pv.ov && pv.ov.classList.contains('show'));
  const hadContent = !!(pv.media && pv.media.childElementCount && !pv.media.querySelector('.pv-loading-only'));
  const switching = alreadyOpen && hadContent;
  if (pv.ov) pv.ov.classList.toggle('pv-is-switching', switching);

  // First open only: dark placeholder. Never paint a square grid preview during
  // next/prev — that was the "width twice" / flash glitch.
  if (!switching && !(pv.media && pv.media.childElementCount)) {
    pv.media.innerHTML = `<div class="pv-loading-only">Loading…</div>`;
  }

  try {
    const view = await pvFetchView(postId);
    if (seq !== pvLoadSeq) return;
    await pvApplyView(view, { animate: switching });
    if (seq !== pvLoadSeq) return;

    // Counts can update in the background without touching media layout.
    pvJson(`feed_api.php?ajax=track_counts&post_id=${encodeURIComponent(postId)}`, { credentials:'same-origin' })
      .then(function(tc){
        if (seq !== pvLoadSeq) return;
        pvApplyTrack(tc);
        if (pvCurrentPost && tc) {
          if (typeof tc.my_saved !== 'undefined') pvCurrentPost.my_saved = Number(tc.my_saved || 0);
          if (typeof tc.is_saved !== 'undefined') pvCurrentPost.is_saved = Number(tc.is_saved || 0);
          pvSyncMenu(pvCurrentPost);
        }
      })
      .catch(function(){});

  } catch (e) {
    if (seq !== pvLoadSeq) return;
    if (pv.media) pv.media.classList.remove('pv-media-fade');
    pv.media.innerHTML = `<div style="color:#fff;opacity:.85;padding:24px;">Failed to load post.</div>`;
    pv.caption.style.display = 'none';
    pv.caption.innerHTML = '';
    try { pvSyncMidCaptionState(false); } catch (eMid) {}
    pv.comments.innerHTML = `<div style="color:#b91c1c;font-size:13px;padding:14px 4px;">${pvEsc(e?.message || 'Failed')}</div>`;
    pvSyncMenu(null);
  } finally {
    if (seq === pvLoadSeq && pv.ov) pv.ov.classList.remove('pv-is-switching');
  }
}

// Ensure fries menu is populated before shared toggle runs.
document.addEventListener('click', function(e){
  const btn = e.target && e.target.closest ? e.target.closest('#pvDots, #pvMenuWrap .post-card-menu-btn') : null;
  if (!btn || !btn.closest('#pvOverlay')) return;
  const menu = document.getElementById('pvMenu');
  if (menu && !String(menu.innerHTML || '').trim()) {
    pvSyncMenu(pvCurrentPost);
  }
}, true);

document.addEventListener('click', function(e){
  const btn = e.target && e.target.closest ? e.target.closest('#pvFriendBtn') : null;
  if (!btn || btn.hidden) return;
  e.preventDefault();
  e.stopPropagation();
  const peerId = Number(btn.getAttribute('data-peer-id') || 0);
  const status = String(btn.getAttribute('data-status') || 'none');
  if (!peerId) return;
  if (status === 'incoming_pending' || status === 'outgoing_pending') {
    window.location.href = 'contact_requests.php';
    return;
  }
  if (status === 'friends') return;
  btn.disabled = true;
  fetch('ajax/friend_action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
    body: new URLSearchParams({ action: 'send', peer_id: String(peerId) })
  }).then(function(r){ return r.json(); }).then(function(res){
    btn.disabled = false;
    if (!res || !res.status) return;
    const next = String(res.status || 'outgoing_pending');
    PV_FRIEND_STATUS = next;
    if (pvCurrentPost) pvCurrentPost.friend_status = next;
    if (typeof window.mfSyncFriendUiForPeer === 'function') {
      window.mfSyncFriendUiForPeer(peerId, next);
    } else {
      pvSyncMenu(pvCurrentPost);
    }
  }).catch(function(){ btn.disabled = false; });
});

// ✅ "Read more" toggles inside the modal (caption + no-attachment card)
document.addEventListener('click', (e) => {
  const rm = e.target.closest('.pv-readmore');
  if (!rm) return;
  if (!rm.closest('#pvOverlay')) return;
  e.preventDefault();

  // Caption toggle
  const cap = rm.closest('.pv-cap');
  if (cap && cap.querySelector('.pv-cap-short') && cap.querySelector('.pv-cap-full')) {
    const expanded = cap.getAttribute('data-expanded') === '1';
    cap.setAttribute('data-expanded', expanded ? '0' : '1');
    cap.querySelector('.pv-cap-short').style.display = expanded ? '' : 'none';
    cap.querySelector('.pv-cap-full').style.display  = expanded ? 'none' : '';
    rm.textContent = expanded ? 'Read more' : 'Show less';
    return;
  }

  // No-attachment card text toggle
  const mt = rm.closest('.pv-media-text');
  if (mt && mt.querySelector('.pv-media-short') && mt.querySelector('.pv-media-full')) {
    const expanded = mt.getAttribute('data-expanded') === '1';
    mt.setAttribute('data-expanded', expanded ? '0' : '1');
    mt.querySelector('.pv-media-short').style.display = expanded ? '' : 'none';
    mt.querySelector('.pv-media-full').style.display  = expanded ? 'none' : '';
    rm.textContent = expanded ? 'Read more' : 'Show less';
  }
});

// ✅ Modal media carousel (same dots + circular arrows as post cards)
document.addEventListener('click', (e) => {
  if (!e.target.closest('#pvMedia')) return;
  const btn = e.target.closest('.js-pv-media-prev, .js-pv-media-next, .mf-media-dot, .media-dot');
  if (!btn) return;
  e.preventDefault();
  e.stopPropagation();
  const carousel = btn.closest('.mf-media-carousel, .media-carousel');
  if (!carousel) return;
  const current = Number(carousel.getAttribute('data-index') || 0);
  let next = current;
  if (btn.classList.contains('js-pv-media-prev') || btn.classList.contains('prev')) next = current - 1;
  else if (btn.classList.contains('js-pv-media-next') || btn.classList.contains('next')) next = current + 1;
  else next = Number(btn.getAttribute('data-index') || 0);
  pvSetMediaCarouselIndex(carousel, next);
});

// ✅ Preload neighbor grid tiles (fast next/prev feel)
function pvPreloadTileByPostId(postId){
  try {
    postId = Number(postId || 0);
    if (!postId) return;
    let el = null;
    if (pvActiveGridScope === 'gallery' || pvActiveGridScope === 'tags') {
      el = document.querySelector(`.ig-grid[data-grid-scope="${pvActiveGridScope}"] .ig-item[data-post-id="${postId}"]`);
    }
    if (!el) el = document.querySelector(`.ig-item[data-post-id="${postId}"]`);
    if (!el) return;
    const ph = el.querySelector('.ph');
    if (ph) {
      const bg = (ph.style.backgroundImage || '').toString();
      const m = bg.match(/url\(["']?(.*?)["']?\)/i);
      const src = m && m[1] ? m[1] : '';
      if (src) { const im = new Image(); im.src = src; }
      return;
    }
    const vid = el.querySelector('video.ig-vid');
    if (vid && vid.getAttribute('src')) {
      const v = document.createElement('video');
      v.preload = 'metadata';
      v.muted = true;
      v.playsInline = true;
      v.src = vid.getAttribute('src');
    }
  } catch(e) {}
}
function pvPreloadNeighbors(){
  if (pvIndex < 0) return;
  const ids = Array.isArray(pvActiveGridIds) ? pvActiveGridIds : [];
  const nextId = Number(ids[pvIndex + 1] || 0);
  const prevId = Number(ids[pvIndex - 1] || 0);
  pvPreloadTileByPostId(nextId);
  pvPreloadTileByPostId(prevId);
  // Prefetch full view payloads so next/prev can crossfade immediately.
  if (typeof pvPrefetchView === 'function') {
    if (nextId > 0) pvPrefetchView(nextId);
    if (prevId > 0) pvPrefetchView(prevId);
  }
}

// Grid click
document.querySelectorAll('.ig-grid .ig-item').forEach(a => {
  a.addEventListener('click', (e) => {
    e.preventDefault();
    const postId = Number(a.getAttribute('data-post-id') || 0);
    if (!postId) return;
    pvOpenInGrid(postId, pvGridIdsForElement(a), pvGridScopeForElement(a));
  });
});

// Close by clicking outside
pv.ov.addEventListener('mousedown', (e) => {
  if (e.target === pv.ov) pvClose();
});
pv.close.addEventListener('click', pvClose);

// Prev/Next
pv.prev.addEventListener('click', () => { if (pvIndex > 0) pvOpenByIndex(pvIndex - 1); });
pv.next.addEventListener('click', () => {
  const ids = Array.isArray(pvActiveGridIds) ? pvActiveGridIds : [];
  if (pvIndex < ids.length - 1) pvOpenByIndex(pvIndex + 1);
});

// Keyboard
document.addEventListener('keydown', (e) => {
  if (!pv.ov.classList.contains('show')) return;
  const ids = Array.isArray(pvActiveGridIds) ? pvActiveGridIds : [];
  if (e.key === 'Escape') { e.preventDefault(); pvClose(); }
  if (e.key === 'ArrowLeft') { e.preventDefault(); if (pvIndex > 0) pvOpenByIndex(pvIndex - 1); }
  if (e.key === 'ArrowRight') { e.preventDefault(); if (pvIndex < ids.length - 1) pvOpenByIndex(pvIndex + 1); }
});

// ✅ Mobile swipe (left/right) like Instagram
let pvTouchX = 0;
let pvTouchY = 0;
pv.ov.addEventListener('touchstart', (e) => {
  if (!pv.ov.classList.contains('show')) return;
  // Don't hijack scrolling inside comments
  const t = e.target;
  if (t && t.closest && t.closest('.pv-comments')) return;
  const p = e.changedTouches && e.changedTouches[0];
  if (!p) return;
  pvTouchX = p.screenX;
  pvTouchY = p.screenY;
}, { passive: true });

pv.ov.addEventListener('touchend', (e) => {
  if (!pv.ov.classList.contains('show')) return;
  const t = e.target;
  if (t && t.closest && t.closest('.pv-comments')) return;
  const p = e.changedTouches && e.changedTouches[0];
  if (!p) return;
  const dx = p.screenX - pvTouchX;
  const dy = p.screenY - pvTouchY;
  // require mostly horizontal gesture
  if (Math.abs(dx) < 60 || Math.abs(dx) < Math.abs(dy) * 1.2) return;
  const ids = Array.isArray(pvActiveGridIds) ? pvActiveGridIds : [];
  if (dx > 0) { if (pvIndex > 0) pvOpenByIndex(pvIndex - 1); }
  else { if (pvIndex < ids.length - 1) pvOpenByIndex(pvIndex + 1); }
}, { passive: true });

// Reply click
pv.comments.addEventListener('click', (e) => {
  const toggleBtn = e.target.closest('.pv-toggle-replies');
  if (toggleBtn) {
    const cid = Number(toggleBtn.getAttribute('data-toggle-replies') || 0);
    if (!cid) return;
    if (pvCollapsedReplyIds.has(cid)) pvCollapsedReplyIds.delete(cid);
    else pvCollapsedReplyIds.add(cid);
    pvRenderComments({}, pvCommentsCache);
    return;
  }
  const likeBtn = e.target.closest('.pv-clike');
  if (likeBtn) {
    const cid = Number(likeBtn.getAttribute('data-cid') || 0);
    const currentReaction = String(likeBtn.getAttribute('data-reaction') || '');
    if (!pvPostId || !cid) return;
    if (currentReaction === 'love') return;
    pvAlertFocusCommentId = cid;
    pvJson('feed_api.php?ajax=comment_like', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:`post_id=${encodeURIComponent(pvPostId)}&comment_id=${encodeURIComponent(cid)}&reaction=${encodeURIComponent('love')}`,
      credentials:'same-origin'
    }).then(() => pvLoad(pvPostId)).catch(() => {});
    return;
  }
  const r = e.target.closest('.pv-reply');
  if (!r) return;
  const cid = Number(r.getAttribute('data-cid') || 0);
  const nm  = (r.getAttribute('data-name') || '').toString();
  const mode = (r.getAttribute('data-mode') || 'Reply').toString();
  pvSetReply(cid, nm, mode);
  pv.text.focus();
});
pv.replyCancel.addEventListener('click', () => pvSetReply(0,''));

// Focus comment
pv.focusComment.addEventListener('click', () => pv.text.focus());
if (pv.commentsLink) {
  pv.commentsLink.addEventListener('click', () => {
    try {
      pv.comments.scrollIntoView({ block:'nearest', behavior:'smooth' });
      pv.text.focus();
    } catch (e) {
      pv.text.focus();
    }
  });
}

// React (love/like) — picker owns this button when MSBReactions is present
pv.love.addEventListener('click', async () => {
  if (window.MSBReactions) return;
  if (!pvPostId) return;
  const next = pvCurrentReaction === 'love' ? 'none' : 'love';
  try {
    const data = await pvJson('feed_api.php?ajax=react', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:`post_id=${encodeURIComponent(pvPostId)}&reaction=${encodeURIComponent(next)}`,
      credentials:'same-origin'
    });
    pvApplyCounts({ post: pvCountMeta(), counts: data.counts || {} });
    if (window.MSBPostEngagement) window.MSBPostEngagement.publishFromReact(pvPostId, data, { source: 'profile-modal' });
  } catch (e) {}
});

pv.like.addEventListener('click', async () => {
  if (window.MSBReactions) return;
  if (!pvPostId) return;
  const next = pvCurrentReaction === 'like' ? 'none' : 'like';
  try {
    const data = await pvJson('feed_api.php?ajax=react', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:`post_id=${encodeURIComponent(pvPostId)}&reaction=${encodeURIComponent(next)}`,
      credentials:'same-origin'
    });
    pvApplyCounts({ post: pvCountMeta(), counts: data.counts || {} });
    if (window.MSBPostEngagement) window.MSBPostEngagement.publishFromReact(pvPostId, data, { source: 'profile-modal' });
  } catch (e) {}
});

if(window.MSBReactions){
  window.MSBReactions.bindLikePicker('#pvLove', async function(_btn, reaction){
    if (!pvPostId || !reaction) return;
    const next = String(reaction || 'none');
    if (next !== 'none' && next === pvCurrentReaction) return;
    const prev = pvCurrentReaction;
    const nextMy = next === 'none' ? '' : next;
    pvCurrentReaction = nextMy;
    const ov = document.getElementById('pvOverlay');
    if (ov) ov.setAttribute('data-engage-at', String(Date.now()));
    try { pvApplyLoveReaction(nextMy); } catch (e0) {}
    try {
      const data = await pvJson('feed_api.php?ajax=react', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body:`post_id=${encodeURIComponent(pvPostId)}&reaction=${encodeURIComponent(next)}`,
        credentials:'same-origin'
      });
      const counts = Object.assign({}, data.counts || {});
      if (typeof counts.my_reaction === 'undefined' || counts.my_reaction === null || counts.my_reaction === '') {
        // Keep sticky selection if server omits/returns blank during race
        if (nextMy) counts.my_reaction = nextMy;
      }
      pvApplyCounts({ post: pvCountMeta(), counts: counts });
      if (window.MSBPostEngagement) window.MSBPostEngagement.publishFromReact(pvPostId, { counts: counts }, { source: 'profile-modal' });
    } catch (e) {
      pvCurrentReaction = prev;
      try { pvApplyLoveReaction(prev); } catch (e1) {}
    }
  });
  window.MSBReactions.bindLikePicker('.pv-clike', async function(btn, reaction){
    const cid = Number(btn.getAttribute('data-cid') || 0);
    if (!pvPostId || !cid || !reaction) return;
    const next = String(reaction || 'none');
    if (next !== 'none' && String(btn.getAttribute('data-reaction') || '') === next) return;
    pvAlertFocusCommentId = cid;
    try {
      if (window.MSBReactions.applyReactionButton) {
        window.MSBReactions.applyReactionButton(btn, next === 'none' ? '' : next, 'love');
      }
      await pvJson('feed_api.php?ajax=comment_like', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body:`post_id=${encodeURIComponent(pvPostId)}&comment_id=${encodeURIComponent(cid)}&reaction=${encodeURIComponent(next)}`,
        credentials:'same-origin'
      });
      pvLoad(pvPostId);
    } catch (e) {}
  });
}

// Share / Save
pv.share.addEventListener('click', async () => {
  if (!pvPostId) return;
  if (window.MSBPostCardMenu && typeof window.MSBPostCardMenu.openShare === 'function') {
    window.MSBPostCardMenu.openShare(pvPostId);
    return;
  }
  try {
    const res = await pvJson('feed_api.php?ajax=share', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:`post_id=${encodeURIComponent(pvPostId)}&share_action=add`,
      credentials:'same-origin'
    });
    pvApplyTrack(res);
    if (window.MSBPostEngagement) window.MSBPostEngagement.publishFromTrack(pvPostId, res, { source: 'profile-modal' });
  } catch (e) {}
});

pv.save.addEventListener('click', async () => {
  if (!pvPostId) return;
  try {
    const res = await pvJson('feed_api.php?ajax=save', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:`post_id=${encodeURIComponent(pvPostId)}`,
      credentials:'same-origin'
    });
    pvApplyTrack(res);
    if (window.MSBPostEngagement) window.MSBPostEngagement.publishFromTrack(pvPostId, res, { source: 'profile-modal' });
    try{
      var savedNow = !!(res && res.state && Number(res.state.saved || 0) === 1);
      if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.toast === 'function'){
        window.MSBPostCardMenu.toast(savedNow
          ? 'Saved to Bookmarks. Find it in Settings → Bookmarks.'
          : 'Removed from Bookmarks.');
      }
    }catch(_eToast){}
  } catch (e) {}
});

// Post comment / reply
async function pvPostComment(){
  if (!pvPostId) return;
  const text = (pv.text.value || '').trim();
  if (!text) return;
  pv.postBtn.disabled = true;
  try {
    const body = `post_id=${encodeURIComponent(pvPostId)}&comment_text=${encodeURIComponent(text)}${pvReplyTo>0?`&parent_id=${encodeURIComponent(pvReplyTo)}`:''}`;
    await pvJson('feed_api.php?ajax=comment', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body,
      credentials:'same-origin'
    });
    pv.text.value = '';
    pvSetReply(0,'');
    // reload only comments + counts
    await pvLoad(pvPostId);
    pv.comments.scrollTop = pv.comments.scrollHeight;
    if (window.MSBPostEngagement && pv.comN) {
      window.MSBPostEngagement.publishCommentCount(pvPostId, pv.comN.textContent, { source: 'profile-modal' });
    }
  } catch (e) {
    // ignore
  } finally {
    pv.postBtn.disabled = false;
  }
}
pv.postBtn.addEventListener('click', pvPostComment);
pv.text.addEventListener('keydown', (e)=>{
  if (e.key === 'Enter') { e.preventDefault(); pvPostComment(); }
});

(function pvComposerChrome(){
  function insertAtCursor(input, chunk){
    if (!input) return;
    const start = input.selectionStart ?? input.value.length;
    const end = input.selectionEnd ?? input.value.length;
    const before = input.value.slice(0, start);
    const after = input.value.slice(end);
    input.value = before + chunk + after;
    const caret = start + chunk.length;
    try { input.setSelectionRange(caret, caret); } catch (e) {}
    input.focus();
  }
  const atBtn = document.getElementById('pvAtBtn');
  const emojiBtn = document.getElementById('pvEmojiBtn');
  if (atBtn) atBtn.addEventListener('click', () => {
    insertAtCursor(pv.text, '@');
    try { pv.text.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
    pv.text.focus();
  });
  if (emojiBtn) emojiBtn.addEventListener('click', () => insertAtCursor(pv.text, '😊'));
})();

(function(){
  const alertPostId = <?php echo (int)$profileAlertPostId; ?>;
  const alertCommentId = <?php echo (int)$profileAlertCommentId; ?>;
  if (!alertPostId) return;

  function clearAlertParams(){
    try{
      const nextUrl = new URL(window.location.href);
      nextUrl.searchParams.delete('open_post');
      nextUrl.searchParams.delete('post_id');
      nextUrl.searchParams.delete('open_comment');
      history.replaceState({}, document.title, nextUrl.pathname + nextUrl.search + nextUrl.hash);
    }catch(e){}
  }

  function openAlertTarget(){
    if (typeof window.pvOpenById !== 'function') return;
    pvAlertFocusCommentId = alertCommentId;
    window.pvOpenById(alertPostId);
    if (alertCommentId > 0) {
      let tries = 0;
      (function waitForComment(){
        tries += 1;
        if (Number(pvPostId || 0) === alertPostId && pvFocusCommentById(alertCommentId)) return;
        if (tries < 20) setTimeout(waitForComment, 160);
      })();
    }
    clearAlertParams();
  }

  setTimeout(openAlertTarget, 160);
})();
</script>

  </div><!-- sh-pagebody -->
</div><!-- sh-mainpanel -->

<script>
(function(){
  if (!document.getElementById('panel-gear')) return;

  function flashState(state, text, cls){
    if (!state) return;
    state.textContent = text || '';
    state.className = 'gear-save-state' + (cls ? ' ' + cls : '');
  }

  function currentThemePrefs(){
    var uid = parseInt(String(window.__MSB_THEME_USER_ID || '0'), 10);
    if (uid > 0
      && window.__MSBThemePrefs
      && parseInt(String(window.__MSBThemePrefsUserId || '0'), 10) === uid) {
      return {
        autoEnabled: !!window.__MSBThemePrefs.autoEnabled,
        manualMode: (window.__MSBThemePrefs.manualMode === 'light') ? 'light' : 'dark',
        appearanceMode: String(window.__MSBThemePrefs.appearanceMode || window.__MSBThemePrefs.manualMode || 'dark')
      };
    }
    if (window.MSBTheme && typeof window.MSBTheme.getPrefs === 'function') {
      return window.MSBTheme.getPrefs();
    }
    return {
      autoEnabled: <?php echo $themeAutoDefault === '1' ? 'true' : 'false'; ?>,
      manualMode: <?php echo json_encode(in_array($manualAppearanceDefault, ['light','dark'], true) ? $manualAppearanceDefault : 'dark'); ?>,
      appearanceMode: <?php echo json_encode($manualAppearanceDefault); ?>
    };
  }

  function manualModeForAppearance(mode){
    mode = String(mode || 'dark').toLowerCase();
    if (mode === 'light' || mode === 'dark') return mode;
    if (/^#[0-9a-f]{6}$/i.test(mode)) {
      // Approximate: darker customs → dark chrome preference
      var n = parseInt(mode.slice(1), 16);
      var r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
      var luma = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
      return luma < 0.45 ? 'dark' : 'light';
    }
    var meta = window.__MSB_APPEARANCE_PALETTES && window.__MSB_APPEARANCE_PALETTES[mode];
    return (meta && meta.dark) ? 'dark' : 'light';
  }

  function normalizeAppearanceMode(mode){
    mode = String(mode || '').toLowerCase().trim();
    if (mode === 'light' || mode === 'dark' || mode === 'system') return mode;
    if (/^#?[0-9a-f]{6}$/i.test(mode)) {
      return mode.charAt(0) === '#' ? mode : ('#' + mode);
    }
    if (window.__MSB_APPEARANCE_PALETTES && window.__MSB_APPEARANCE_PALETTES[mode]) return mode;
    return 'system';
  }

  function getThemeControls(){
    return {
      autoCtrl: document.querySelector('#panel-gear .gear-control[data-local-field="theme_auto_enabled"]'),
      manualCtrl: document.querySelector('#panel-gear select.gear-control[data-field="appearance_mode"]'),
      progressColorCtrl: document.querySelector('#panel-gear input.gear-color-control[data-progress-color="1"]')
    };
  }

  function progressHexForMode(mode){
    mode = normalizeAppearanceMode(mode || 'system');
    if (/^#[0-9a-f]{6}$/i.test(mode)) return mode.toLowerCase();
    if (window.__MSB_APPEARANCE_PALETTES && window.__MSB_APPEARANCE_PALETTES[mode] && window.__MSB_APPEARANCE_PALETTES[mode].hex) {
      return String(window.__MSB_APPEARANCE_PALETTES[mode].hex).toLowerCase();
    }
    if (mode === 'dark') return '#171d24';
    if (mode === 'light') return '#f5f7fb';
    return '#8d514f';
  }

  function syncProgressColorControl(mode, disabled){
    var ctrls = getThemeControls();
    if (!ctrls.progressColorCtrl) return;
    var hex = progressHexForMode(mode);
    ctrls.progressColorCtrl.value = hex;
    ctrls.progressColorCtrl.disabled = !!disabled;
    ctrls.progressColorCtrl.setAttribute('data-saved-hex', hex.toLowerCase());
    var label = document.getElementById('gearProgressColorHex');
    if (label) label.textContent = String(hex).toUpperCase();
    var saveBtn = document.getElementById('gearProgressColorSave');
    if (saveBtn) {
      saveBtn.disabled = true;
      if (disabled) saveBtn.disabled = true;
    }
    var mapEl = document.getElementById('gearProgressSv');
    var pickerEl = document.getElementById('gearProgressPicker');
    var hueEl = document.getElementById('gearProgressHue');
    if (mapEl) {
      mapEl.setAttribute('aria-disabled', disabled ? 'true' : 'false');
      mapEl.style.pointerEvents = disabled ? 'none' : '';
      mapEl.style.opacity = disabled ? '0.55' : '';
    }
    if (pickerEl) pickerEl.style.opacity = disabled ? '0.55' : '';
    if (hueEl) hueEl.disabled = !!disabled;
    // Thumb sync is handled by the spectrum wire after load; dispatch a tiny custom event.
    try {
      document.dispatchEvent(new CustomEvent('msb-progress-color-sync', { detail: { hex: hex } }));
    } catch (_e) {}
  }

  function setProgressSaveDirty(isDirty){
    var saveBtn = document.getElementById('gearProgressColorSave');
    if (!saveBtn) return;
    var ctrls = getThemeControls();
    saveBtn.disabled = !isDirty || !!(ctrls.progressColorCtrl && ctrls.progressColorCtrl.disabled);
  }

  var __msbProgressLerp = {
    from: null,
    to: null,
    current: null,
    t0: 0,
    dur: 700,
    raf: 0,
    active: false
  };

  var __MSB_PROGRESS_BG_VARS = [
    '--msb-palette-bg',
    '--msb-palette-panel',
    '--msb-palette-surface',
    '--msb-palette-surface-2',
    '--msb-palette-sidebar',
    '--msb-palette-header',
    '--msb-palette-nav',
    '--msb-palette-footer',
    '--msb-palette-input-bg',
    '--msb-palette-btn-secondary-bg',
    '--msb-palette-hover-bg',
    '--msb-palette-surface-hover',
    '--msb-palette-nav-hover',
    '--msb-palette-nav-active-bg',
    '--msb-palette-action-soft',
    '--msb-palette-header-hover',
    '--msb-palette-dropdown-hover',
    '--msb-palette-btn-secondary-hover',
    '--bg',
    '--bg-main',
    '--bg-card',
    '--bg-sidebar',
    '--surface',
    '--surface-2',
    '--org-bg',
    '--feed-surface',
    '--feed-surface-alt',
    '--feed-surface-strong',
    '--feed-topbar-bg',
    '--feed-control-bg',
    '--feed-control-soft'
  ];

  function mixHexColors(a, b, t){
    function parse(hex){
      hex = String(hex || '').replace('#','');
      if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
      return {
        r: parseInt(hex.slice(0,2), 16) || 0,
        g: parseInt(hex.slice(2,4), 16) || 0,
        b: parseInt(hex.slice(4,6), 16) || 0
      };
    }
    function hx(n){
      n = Math.max(0, Math.min(255, Math.round(n)));
      var s = n.toString(16);
      return s.length === 1 ? '0' + s : s;
    }
    t = Math.max(0, Math.min(1, t));
    var e = t;
    var A = parse(a), B = parse(b);
    return ('#' + hx(A.r + (B.r - A.r) * e) + hx(A.g + (B.g - A.g) * e) + hx(A.b + (B.b - A.b) * e)).toLowerCase();
  }

  function paintProgressUnifiedBg(hex){
    hex = normalizeAppearanceMode(hex || '#8d514f');
    if (!/^#[0-9a-f]{6}$/i.test(hex)) return;
    var root = document.documentElement;
    for (var i = 0; i < __MSB_PROGRESS_BG_VARS.length; i++) {
      root.style.setProperty(__MSB_PROGRESS_BG_VARS[i], hex);
    }
    root.style.setProperty('--msb-palette-accent', hex);
    root.style.setProperty('--msb-palette-nav-active', hex);
    if (document.body) {
      document.body.style.backgroundColor = hex;
      document.body.style.backgroundImage = 'none';
    }
  }

  function progressLerpCurrent(now){
    now = now || performance.now();
    if (__msbProgressLerp.current && /^#[0-9a-f]{6}$/i.test(__msbProgressLerp.current)) {
      if (!__msbProgressLerp.active) return __msbProgressLerp.current;
      var t = (now - __msbProgressLerp.t0) / Math.max(1, __msbProgressLerp.dur);
      if (t >= 1) return __msbProgressLerp.to || __msbProgressLerp.current;
      return mixHexColors(__msbProgressLerp.from, __msbProgressLerp.to, t);
    }
    var colorInput = document.getElementById('gear-ctrl-progress-color');
    var fromAttr = colorInput && colorInput.getAttribute('data-preview-hex');
    if (fromAttr && /^#[0-9a-f]{6}$/i.test(fromAttr)) return fromAttr.toLowerCase();
    var prefs = currentThemePrefs();
    var mode = normalizeAppearanceMode(prefs.appearanceMode || '');
    if (/^#[0-9a-f]{6}$/i.test(mode)) return mode.toLowerCase();
    return progressHexForMode(mode);
  }

  function previewProgressColor(hex, opts){
    hex = normalizeAppearanceMode(hex || '#8d514f');
    if (!/^#[0-9a-f]{6}$/i.test(hex)) return;
    opts = opts || {};
    var label = document.getElementById('gearProgressColorHex');
    if (label) label.textContent = hex.toUpperCase();
    var root = document.documentElement;
    root.classList.add('msb-progress-previewing');

    var colorInput = document.getElementById('gear-ctrl-progress-color');
    if (colorInput) colorInput.setAttribute('data-preview-hex', hex);

    var now = performance.now();
    var fromHex = progressLerpCurrent(now);
    if (!/^#[0-9a-f]{6}$/i.test(fromHex)) fromHex = hex;

    if (__msbProgressLerp.raf) {
      cancelAnimationFrame(__msbProgressLerp.raf);
      __msbProgressLerp.raf = 0;
    }

    __msbProgressLerp.from = fromHex;
    __msbProgressLerp.to = hex;
    __msbProgressLerp.t0 = now;
    __msbProgressLerp.dur = opts.dur || (opts.dragging ? 520 : 640);
    __msbProgressLerp.active = true;
    __msbProgressLerp.current = fromHex;

    function paintMid(midHex){
      __msbProgressLerp.current = midHex;
      // One shared mid → every background token + full theme surfaces stay equal.
      paintProgressUnifiedBg(midHex);
      applyThemePrefs({
        autoEnabled: false,
        appearanceMode: midHex,
        manualMode: manualModeForAppearance(midHex)
      });
      // Re-assert equal bgs after theme apply (custom hex already equal; this covers leftovers).
      paintProgressUnifiedBg(midHex);
    }

    function finish(finalHex){
      __msbProgressLerp.active = false;
      __msbProgressLerp.raf = 0;
      paintMid(finalHex);
    }

    if (fromHex === hex) {
      finish(hex);
    } else {
      function tick(ts){
        var t = (ts - __msbProgressLerp.t0) / __msbProgressLerp.dur;
        if (t >= 1) {
          finish(__msbProgressLerp.to);
          return;
        }
        paintMid(mixHexColors(__msbProgressLerp.from, __msbProgressLerp.to, t));
        __msbProgressLerp.raf = requestAnimationFrame(tick);
      }
      paintMid(fromHex);
      __msbProgressLerp.raf = requestAnimationFrame(tick);
    }

    if (window.__msbProgressPreviewTimer) window.clearTimeout(window.__msbProgressPreviewTimer);
    window.__msbProgressPreviewTimer = window.setTimeout(function(){
      if (!__msbProgressLerp.active) root.classList.remove('msb-progress-previewing');
    }, opts.holdMs || 1200);
  }

  function saveProgressColor(hex, state){
    hex = normalizeAppearanceMode(hex || '#8d514f');
    if (!/^#[0-9a-f]{6}$/i.test(hex)) {
      if (state) flashState(state, 'Invalid', 'is-error');
      return Promise.reject(new Error('Invalid color'));
    }
    var previousPrefs = currentThemePrefs();
    var ctrls = getThemeControls();
    if (ctrls.autoCtrl) ctrls.autoCtrl.value = '0';
    if (ctrls.manualCtrl) {
      ctrls.manualCtrl.value = 'system';
      ctrls.manualCtrl.disabled = false;
    }
    var saves = [saveThemeAppearanceMode(hex, state)];
    if (!!previousPrefs.autoEnabled) {
      saves.push(saveThemeAutoEnabled(false, state));
    }
    return Promise.all(saves)
      .then(function(results){
        var data = results[0] || {};
        var savedMode = normalizeAppearanceMode(data.value || hex);
        var savedPrefs = updateThemeMemory({
          autoEnabled: false,
          appearanceMode: savedMode,
          manualMode: manualModeForAppearance(savedMode)
        });
        applyThemePrefs(savedPrefs);
        syncThemeGearControls();
        syncThemeNavMeta(savedPrefs);
        setProgressSaveDirty(false);
        return savedPrefs;
      });
  }

  function setGearNavMetaBySelector(selector, label){
    var item = document.querySelector('#panel-gear .gear-nav-item' + selector);
    if (!item) return;
    var meta = item.querySelector('.gear-nav-item-meta');
    if (!meta) {
      meta = document.createElement('span');
      meta.className = 'gear-nav-item-meta';
      item.appendChild(meta);
    }
    meta.textContent = label || '';
  }

  function isNamedAppearanceMode(mode){
    mode = normalizeAppearanceMode(mode);
    if (mode === 'system' || mode === 'light' || mode === 'dark') return false;
    if (/^#[0-9a-f]{6}$/i.test(mode)) return true;
    return !!(window.__MSB_APPEARANCE_PALETTES && window.__MSB_APPEARANCE_PALETTES[mode]);
  }

  function appearanceModeLabel(mode){
    mode = normalizeAppearanceMode(mode);
    var ctrls = getThemeControls();
    if (ctrls.manualCtrl) {
      for (var i = 0; i < ctrls.manualCtrl.options.length; i++) {
        if (String(ctrls.manualCtrl.options[i].value) === mode) {
          return String(ctrls.manualCtrl.options[i].textContent || mode).trim() || mode;
        }
      }
    }
    if (mode === 'system') return 'Off';
    if (mode === 'light') return 'Light';
    if (mode === 'dark') return 'Dark';
    if (/^#[0-9a-f]{6}$/i.test(mode)) return 'Progress color';
    return mode;
  }

  function syncThemeNavMeta(prefs){
    prefs = prefs || currentThemePrefs();
    var mode = prefs.autoEnabled ? 'system' : (prefs.appearanceMode || 'system');
    setGearNavMetaBySelector('[data-local-field="theme_auto_enabled"]', prefs.autoEnabled ? 'On' : 'Off');
    var appearanceItems = document.querySelectorAll('#panel-gear .gear-nav-item[data-field="appearance_mode"]');
    appearanceItems.forEach(function(item){
      var meta = item.querySelector('.gear-nav-item-meta');
      if (!meta) {
        meta = document.createElement('span');
        meta.className = 'gear-nav-item-meta';
        item.appendChild(meta);
      }
      if (item.getAttribute('data-progress-color') === '1') {
        meta.textContent = prefs.autoEnabled ? '' : progressHexForMode(mode).toUpperCase();
      } else {
        meta.textContent = appearanceModeLabel(mode);
      }
    });
  }

  function applyThemePrefs(next){
    next = next || {};
    if (window.MSBTheme && typeof window.MSBTheme.setPrefs === 'function') {
      return window.MSBTheme.setPrefs(next);
    }
    if (window.__MSBThemeCore && typeof window.__MSBThemeCore.applyThemeFromPrefs === 'function') {
      var prefs = currentThemePrefs();
      if (typeof next.autoEnabled === 'boolean') prefs.autoEnabled = next.autoEnabled;
      if (typeof next.manualMode === 'string') prefs.manualMode = (next.manualMode === 'light') ? 'light' : 'dark';
      if (typeof next.appearanceMode === 'string') prefs.appearanceMode = next.appearanceMode;
      if (window.__MSBThemeCore.writeScopedPrefs) {
        window.__MSBThemeCore.writeScopedPrefs(prefs);
      } else {
        window.__MSBThemePrefs = prefs;
        window.__MSBThemePrefsUserId = <?php echo (int)theme_prefs_viewer_user_id(); ?>;
      }
      window.__MSBThemeCore.applyThemeFromPrefs(prefs);
      try {
        window.dispatchEvent(new CustomEvent('msb-theme-change', { detail: prefs }));
      } catch (e) {}
      return prefs;
    }
    return currentThemePrefs();
  }

  function updateThemeMemory(prefs){
    prefs = prefs || currentThemePrefs();
    prefs.autoEnabled = !!prefs.autoEnabled;
    prefs.manualMode = manualModeForAppearance(prefs.manualMode || prefs.appearanceMode || 'dark');
    prefs.appearanceMode = normalizeAppearanceMode(prefs.appearanceMode || prefs.manualMode || 'system');
    window.__MSBThemePrefsUserId = <?php echo (int)theme_prefs_viewer_user_id(); ?>;
    window.__MSBThemePrefs = prefs;
    if (window.__MSB_THEME_DEFAULTS) {
      window.__MSB_THEME_DEFAULTS.autoEnabled = prefs.autoEnabled;
      window.__MSB_THEME_DEFAULTS.manualMode = prefs.manualMode;
      window.__MSB_THEME_DEFAULTS.appearanceMode = prefs.appearanceMode;
    }
    window.__MSB_THEME_DB_MODE = prefs.appearanceMode;
    return prefs;
  }

  function saveThemeAutoEnabled(enabled, state){
    var form = new FormData();
    form.append('field', 'theme_auto_enabled');
    form.append('value', enabled ? '1' : '0');
    if (state) flashState(state, 'Saving', 'is-saving');
    return fetch('save_privacy.php', {
      method: 'POST',
      body: form,
      credentials: 'same-origin',
      headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(function(res){ return res.json(); })
    .then(function(data){
      if (!data || !data.ok) throw new Error((data && data.message) ? data.message : 'Save failed');
      data.value = (String(data.value || (enabled ? '1' : '0')) === '1') ? '1' : '0';
      if (state) {
        flashState(state, 'Saved', 'is-saved');
        window.setTimeout(function(){ flashState(state, '', ''); }, 1300);
      }
      return data;
    })
    .catch(function(err){
      if (state) flashState(state, 'Error', 'is-error');
      throw err;
    });
  }

  function applyAppearanceMode(mode, state){
    var ctrls = getThemeControls();
    mode = normalizeAppearanceMode(mode || 'system');
    if (ctrls.manualCtrl) {
      if ([].some.call(ctrls.manualCtrl.options, function(opt){ return String(opt.value) === mode; })) {
        ctrls.manualCtrl.value = mode;
      }
      ctrls.manualCtrl.disabled = false;
    }
    syncProgressColorControl(mode, false);
    if (ctrls.autoCtrl && mode !== 'system') {
      ctrls.autoCtrl.value = '0';
    }
    applyThemePrefs({
      autoEnabled: mode === 'system' ? !!(window.__MSBThemePrefs && window.__MSBThemePrefs.autoEnabled) : false,
      appearanceMode: mode,
      manualMode: manualModeForAppearance(mode)
    });
    return saveThemeAppearanceMode(mode, state);
  }

  function syncThemeGearControls(){
    var prefs = currentThemePrefs();
    var ctrls = getThemeControls();
    var beforeAuto = !!prefs.autoEnabled;
    var beforeMode = normalizeAppearanceMode(prefs.appearanceMode || 'system');
    var hasFixedAppearance = beforeMode === 'light' || beforeMode === 'dark' || isNamedAppearanceMode(beforeMode);

    // Heal conflicted stored state (Dark auto On + fixed Appearance color):
    // keep the chosen color and turn Dark auto Off so pages stop flashing.
    if (prefs.autoEnabled && hasFixedAppearance) {
      prefs.autoEnabled = false;
    }

    prefs = updateThemeMemory(prefs);
    if (ctrls.autoCtrl) ctrls.autoCtrl.value = prefs.autoEnabled ? '1' : '0';
    if (ctrls.manualCtrl) {
      // Dark auto On locks Appearance color to Off.
      if (prefs.autoEnabled) {
        prefs.appearanceMode = 'system';
        prefs = updateThemeMemory(prefs);
        ctrls.manualCtrl.value = 'system';
        ctrls.manualCtrl.disabled = true;
        syncProgressColorControl('system', true);
      } else {
        var mode = normalizeAppearanceMode(prefs.appearanceMode || 'system');
        if ([].some.call(ctrls.manualCtrl.options, function(opt){ return String(opt.value) === mode; })) {
          ctrls.manualCtrl.value = mode;
        } else if (/^#[0-9a-f]{6}$/i.test(mode)) {
          // Keep select on Off visually when a custom progress hex is active.
          ctrls.manualCtrl.value = 'system';
        } else {
          ctrls.manualCtrl.value = 'system';
        }
        ctrls.manualCtrl.disabled = false;
        syncProgressColorControl(mode, false);
      }
    } else {
      syncProgressColorControl(prefs.appearanceMode || 'system', !!prefs.autoEnabled);
    }
    syncThemeNavMeta(prefs);
    applyThemePrefs(prefs);

    // Persist heal so reload does not reintroduce the conflict.
    if (beforeAuto && hasFixedAppearance && !prefs.autoEnabled) {
      saveThemeAutoEnabled(false, null).catch(function(){});
    }
  }

  function saveThemeAppearanceMode(mode, state){
    mode = normalizeAppearanceMode(mode || 'system');
    var form = new FormData();
    form.append('field', 'appearance_mode');
    form.append('value', mode);
    if (state) flashState(state, 'Saving', 'is-saving');
    return fetch('save_privacy.php', {
      method: 'POST',
      body: form,
      credentials: 'same-origin',
      headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(function(res){ return res.json(); })
    .then(function(data){
      if (!data || !data.ok) throw new Error((data && data.message) ? data.message : 'Save failed');
      data.value = normalizeAppearanceMode(data.value || mode);
      if (state) {
        flashState(state, 'Saved', 'is-saved');
        window.setTimeout(function(){ flashState(state, '', ''); }, 1300);
      }
      return data;
    })
    .catch(function(err){
      if (state) flashState(state, 'Error', 'is-error');
      throw err;
    });
  }

  syncThemeGearControls();
  document.addEventListener('DOMContentLoaded', syncThemeGearControls);

  document.querySelectorAll('#panel-gear .gear-control').forEach(function(ctrl){
    ctrl.addEventListener('change', function(){
      var state = ctrl.parentElement ? ctrl.parentElement.querySelector('.gear-save-state') : null;
      var localField = ctrl.getAttribute('data-local-field') || '';
      var field = ctrl.getAttribute('data-field') || '';

      // Progress color uses an explicit Save button — preview only on pick.
      if (ctrl.getAttribute('data-progress-color') === '1' || ctrl.getAttribute('data-autosave') === '0') {
        return;
      }

      if (localField === 'theme_auto_enabled') {
        var ctrls = getThemeControls();
        var previousPrefs = currentThemePrefs();
        var autoEnabled = (ctrl.value === '1');
        // Dark auto On forces Appearance color to Off (system) to avoid flash/conflict.
        var appearanceMode = autoEnabled
          ? 'system'
          : normalizeAppearanceMode(ctrls.manualCtrl ? (ctrls.manualCtrl.value || previousPrefs.appearanceMode || 'system') : (previousPrefs.appearanceMode || 'system'));
        // If a progress hex was active, restore it when turning Dark auto Off.
        if (!autoEnabled && /^#[0-9a-f]{6}$/i.test(normalizeAppearanceMode(previousPrefs.appearanceMode || ''))) {
          appearanceMode = normalizeAppearanceMode(previousPrefs.appearanceMode);
        }
        if (ctrls.manualCtrl) {
          if ([].some.call(ctrls.manualCtrl.options, function(opt){ return String(opt.value) === appearanceMode; })) {
            ctrls.manualCtrl.value = appearanceMode;
          } else {
            ctrls.manualCtrl.value = 'system';
          }
          ctrls.manualCtrl.disabled = !!autoEnabled;
        }
        syncProgressColorControl(appearanceMode, !!autoEnabled);
        var saves = [saveThemeAutoEnabled(autoEnabled, state)];
        if (autoEnabled && normalizeAppearanceMode(previousPrefs.appearanceMode || '') !== 'system') {
          saves.push(saveThemeAppearanceMode('system', state));
        }
        Promise.all(saves)
          .then(function(){
            var savedPrefs = updateThemeMemory({
              autoEnabled: autoEnabled,
              appearanceMode: appearanceMode,
              manualMode: manualModeForAppearance(appearanceMode)
            });
            applyThemePrefs(savedPrefs);
            syncThemeGearControls();
            syncThemeNavMeta(savedPrefs);
          })
          .catch(function(){
            ctrl.value = previousPrefs.autoEnabled ? '1' : '0';
            updateThemeMemory(previousPrefs);
            applyThemePrefs(previousPrefs);
            syncThemeGearControls();
            syncThemeNavMeta(previousPrefs);
          });
        return;
      }

      if (field === 'appearance_mode') {
        var previousAppearancePrefs = currentThemePrefs();
        var nextMode = normalizeAppearanceMode(ctrl.value || 'system');
        var ctrlsAppearance = getThemeControls();
        // Selecting any appearance color (or Light/Dark) turns Dark auto Off.
        // Off (system) leaves Dark auto as-is unless a color was active with auto on.
        var nextAuto = (nextMode === 'system') ? !!previousAppearancePrefs.autoEnabled : false;
        if (ctrlsAppearance.autoCtrl) {
          ctrlsAppearance.autoCtrl.value = nextAuto ? '1' : '0';
        }
        if (ctrlsAppearance.manualCtrl) {
          ctrlsAppearance.manualCtrl.disabled = !!nextAuto;
        }
        syncProgressColorControl(nextMode, !!nextAuto);
        var appearanceSaves = [saveThemeAppearanceMode(nextMode, state)];
        if (!!previousAppearancePrefs.autoEnabled !== nextAuto) {
          appearanceSaves.push(saveThemeAutoEnabled(nextAuto, state));
        }
        Promise.all(appearanceSaves)
          .then(function(results){
            var data = results[0] || {};
            var savedMode = normalizeAppearanceMode(data.value || nextMode);
            var savedPrefs = updateThemeMemory({
              autoEnabled: nextAuto,
              appearanceMode: savedMode,
              manualMode: manualModeForAppearance(savedMode)
            });
            applyThemePrefs(savedPrefs);
            syncThemeGearControls();
            syncThemeNavMeta(savedPrefs);
          })
          .catch(function(){
            if (ctrl.type === 'color') {
              ctrl.value = progressHexForMode(previousAppearancePrefs.appearanceMode || '#8d514f');
            } else {
              ctrl.value = normalizeAppearanceMode(previousAppearancePrefs.appearanceMode || previousAppearancePrefs.manualMode || 'system');
            }
            updateThemeMemory(previousAppearancePrefs);
            applyThemePrefs(previousAppearancePrefs);
            syncThemeGearControls();
            syncThemeNavMeta(previousAppearancePrefs);
          });
        return;
      }

      flashState(state, 'Saving', 'is-saving');
      var form = new FormData();
      form.append('field', field);
      form.append('value', ctrl.value || '');
      fetch('save_privacy.php', {
        method: 'POST',
        body: form,
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
      })
      .then(function(res){ return res.json(); })
      .then(function(data){
        if (!data || !data.ok) throw new Error((data && data.message) ? data.message : 'Save failed');
        flashState(state, 'Saved', 'is-saved');
        window.setTimeout(function(){ flashState(state, '', ''); }, 1300);
      })
      .catch(function(){ flashState(state, 'Error', 'is-error'); });
    });
  });

  (function wireProgressColorSave(){
    var colorCtrl = document.getElementById('gear-ctrl-progress-color');
    var saveBtn = document.getElementById('gearProgressColorSave');
    var stateEl = document.getElementById('gearProgressColorState');
    var svEl = document.getElementById('gearProgressSv');
    var thumbEl = document.getElementById('gearProgressSvThumb');
    var hueEl = document.getElementById('gearProgressHue');
    var swatchEl = document.getElementById('gearProgressSwatch');
    var pickerEl = document.getElementById('gearProgressPicker');
    if (!colorCtrl || !saveBtn) return;

    var hsv = { h: 0, s: 1, v: 1 };
    var dragging = false;
    var raf = 0;
    var pending = null;

    function currentDraftHex(){
      return normalizeAppearanceMode(colorCtrl.value || '#8d514f');
    }
    function savedHex(){
      return normalizeAppearanceMode(colorCtrl.getAttribute('data-saved-hex') || progressHexForMode(currentThemePrefs().appearanceMode || '#8d514f'));
    }
    function refreshDirty(){
      var draft = currentDraftHex().toLowerCase();
      var saved = savedHex().toLowerCase();
      var label = document.getElementById('gearProgressColorHex');
      if (label) label.textContent = draft.toUpperCase();
      setProgressSaveDirty(draft !== saved && !colorCtrl.disabled);
    }

    function clamp01(n){ return Math.max(0, Math.min(1, n)); }
    function clampHue(h){
      h = Number(h) || 0;
      h = h % 360;
      if (h < 0) h += 360;
      return h;
    }
    function hsvToRgb(h, s, v){
      h = clampHue(h); s = clamp01(s); v = clamp01(v);
      var c = v * s;
      var x = c * (1 - Math.abs(((h / 60) % 2) - 1));
      var m = v - c;
      var r = 0, g = 0, b = 0;
      if (h < 60) { r = c; g = x; }
      else if (h < 120) { r = x; g = c; }
      else if (h < 180) { g = c; b = x; }
      else if (h < 240) { g = x; b = c; }
      else if (h < 300) { r = x; b = c; }
      else { r = c; b = x; }
      return {
        r: Math.round((r + m) * 255),
        g: Math.round((g + m) * 255),
        b: Math.round((b + m) * 255)
      };
    }
    function rgbToHex(r, g, b){
      function hx(n){ n = Math.max(0, Math.min(255, n|0)); var t = n.toString(16); return t.length === 1 ? '0' + t : t; }
      return ('#' + hx(r) + hx(g) + hx(b)).toLowerCase();
    }
    function hexToRgb(hex){
      hex = String(hex || '').replace('#','');
      if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
      if (!/^[0-9a-f]{6}$/i.test(hex)) return null;
      return {
        r: parseInt(hex.slice(0,2), 16),
        g: parseInt(hex.slice(2,4), 16),
        b: parseInt(hex.slice(4,6), 16)
      };
    }
    function rgbToHsv(r, g, b){
      r /= 255; g /= 255; b /= 255;
      var max = Math.max(r, g, b), min = Math.min(r, g, b);
      var d = max - min;
      var h = 0;
      var s = max === 0 ? 0 : d / max;
      var v = max;
      if (d !== 0) {
        switch (max) {
          case r: h = ((g - b) / d + (g < b ? 6 : 0)); break;
          case g: h = ((b - r) / d + 2); break;
          default: h = ((r - g) / d + 4); break;
        }
        h *= 60;
      }
      return { h: h, s: s, v: v };
    }
    function hsvToHex(h, s, v){
      var rgb = hsvToRgb(h, s, v);
      return rgbToHex(rgb.r, rgb.g, rgb.b);
    }
    function hueCss(h){
      var rgb = hsvToRgb(h, 1, 1);
      return rgbToHex(rgb.r, rgb.g, rgb.b);
    }
    function paintPicker(){
      var hex = hsvToHex(hsv.h, hsv.s, hsv.v);
      var pure = hueCss(hsv.h);
      if (pickerEl) pickerEl.style.setProperty('--gear-progress-hue', pure);
      if (pickerEl) pickerEl.style.setProperty('--gear-progress-swatch', hex);
      if (svEl) {
        svEl.style.setProperty('--gear-progress-hue', pure);
        svEl.style.background =
          'linear-gradient(to bottom, rgba(0,0,0,0), #000000),' +
          'linear-gradient(to right, #ffffff, ' + pure + ')';
      }
      if (thumbEl) {
        thumbEl.style.left = (clamp01(hsv.s) * 100) + '%';
        thumbEl.style.top = ((1 - clamp01(hsv.v)) * 100) + '%';
      }
      if (hueEl && document.activeElement !== hueEl) hueEl.value = String(Math.round(clampHue(hsv.h)));
      if (swatchEl) swatchEl.style.background = hex;
      if (svEl) svEl.setAttribute('aria-valuetext', hex.toUpperCase());
      return hex;
    }
    function setFromHex(hex, opts){
      opts = opts || {};
      hex = normalizeAppearanceMode(hex || '#8d514f');
      var rgb = hexToRgb(hex);
      if (!rgb) return;
      var next = rgbToHsv(rgb.r, rgb.g, rgb.b);
      // Keep current hue when picking near-black/white so the square does not jump.
      if (next.s < 0.02 && next.v > 0.98) next.h = hsv.h;
      if (next.v < 0.02) next.h = hsv.h;
      hsv = next;
      var out = paintPicker();
      colorCtrl.value = out;
      colorCtrl.setAttribute('data-preview-hex', out);
      refreshDirty();
      if (!opts.silent) {
        previewProgressColor(out, {
          dragging: !!opts.dragging,
          dur: opts.dragging ? 640 : 760,
          holdMs: opts.dragging ? 1600 : 1100
        });
      }
      return out;
    }
    function setFromSvPointer(clientX, clientY){
      if (!svEl) return currentDraftHex();
      var rect = svEl.getBoundingClientRect();
      if (rect.width < 1 || rect.height < 1) return currentDraftHex();
      hsv.s = clamp01((clientX - rect.left) / rect.width);
      hsv.v = 1 - clamp01((clientY - rect.top) / rect.height);
      var hex = paintPicker();
      colorCtrl.value = hex;
      colorCtrl.setAttribute('data-preview-hex', hex);
      refreshDirty();
      return hex;
    }
    function queuePreview(hex, endDrag){
      pending = { hex: hex, endDrag: !!endDrag };
      if (raf) return;
      raf = window.requestAnimationFrame(function(){
        raf = 0;
        if (!pending) return;
        var next = pending.hex;
        var settling = pending.endDrag;
        pending = null;
        previewProgressColor(next, {
          dragging: !settling && dragging,
          dur: settling ? 760 : 640,
          holdMs: settling ? 1400 : 1600
        });
      });
    }

    if (svEl) {
      svEl.addEventListener('pointerdown', function(e){
        if (colorCtrl.disabled) return;
        dragging = true;
        svEl.classList.add('is-dragging');
        try { svEl.setPointerCapture(e.pointerId); } catch (_e) {}
        queuePreview(setFromSvPointer(e.clientX, e.clientY));
      });
      svEl.addEventListener('pointermove', function(e){
        if (!dragging) return;
        queuePreview(setFromSvPointer(e.clientX, e.clientY));
      });
      function endSv(e){
        if (!dragging) return;
        dragging = false;
        svEl.classList.remove('is-dragging');
        var hex = e ? setFromSvPointer(e.clientX, e.clientY) : currentDraftHex();
        queuePreview(hex, true);
        try { svEl.releasePointerCapture(e.pointerId); } catch (_e2) {}
      }
      svEl.addEventListener('pointerup', endSv);
      svEl.addEventListener('pointercancel', endSv);
      svEl.addEventListener('keydown', function(e){
        if (colorCtrl.disabled) return;
        var step = e.shiftKey ? 0.05 : 0.02;
        if (e.key === 'ArrowLeft') { hsv.s = clamp01(hsv.s - step); e.preventDefault(); }
        else if (e.key === 'ArrowRight') { hsv.s = clamp01(hsv.s + step); e.preventDefault(); }
        else if (e.key === 'ArrowUp') { hsv.v = clamp01(hsv.v + step); e.preventDefault(); }
        else if (e.key === 'ArrowDown') { hsv.v = clamp01(hsv.v - step); e.preventDefault(); }
        else return;
        var hex = paintPicker();
        colorCtrl.value = hex;
        colorCtrl.setAttribute('data-preview-hex', hex);
        refreshDirty();
        previewProgressColor(hex, { dur: 760, holdMs: 1100 });
      });
    }

    if (hueEl) {
      hueEl.addEventListener('input', function(){
        if (colorCtrl.disabled) return;
        hsv.h = clampHue(hueEl.value);
        var hex = paintPicker();
        colorCtrl.value = hex;
        colorCtrl.setAttribute('data-preview-hex', hex);
        refreshDirty();
        previewProgressColor(hex, { dragging: true, dur: 640, holdMs: 1600 });
      });
      hueEl.addEventListener('change', function(){
        if (colorCtrl.disabled) return;
        hsv.h = clampHue(hueEl.value);
        var hex = paintPicker();
        colorCtrl.value = hex;
        refreshDirty();
        previewProgressColor(hex, { dur: 760, holdMs: 1100 });
      });
    }

    colorCtrl.addEventListener('input', function(){
      setFromHex(currentDraftHex());
    });
    colorCtrl.addEventListener('change', function(){
      setFromHex(currentDraftHex());
    });

    saveBtn.addEventListener('click', function(){
      if (saveBtn.disabled || colorCtrl.disabled) return;
      var hex = currentDraftHex();
      saveBtn.disabled = true;
      saveProgressColor(hex, stateEl)
        .then(function(){
          colorCtrl.setAttribute('data-saved-hex', hex.toLowerCase());
          colorCtrl.setAttribute('data-preview-hex', hex.toLowerCase());
          setFromHex(hex, { silent: true });
          setProgressSaveDirty(false);
        })
        .catch(function(){
          refreshDirty();
        });
    });

    document.addEventListener('msb-progress-color-sync', function(ev){
      var hex = (ev && ev.detail && ev.detail.hex) ? ev.detail.hex : currentDraftHex();
      setFromHex(hex, { silent: true });
    });

    colorCtrl.setAttribute('data-saved-hex', savedHex().toLowerCase());
    colorCtrl.setAttribute('data-preview-hex', currentDraftHex().toLowerCase());
    setFromHex(currentDraftHex(), { silent: true });
    setProgressSaveDirty(false);
  })();

  document.querySelectorAll('#panel-gear .gear-upload-form').forEach(function(formEl){
    var input = formEl.querySelector('.gear-upload-input');
    var state = formEl.querySelector('.gear-save-state');
    if (!input) return;
    input.addEventListener('change', function(){
      if (!input.files || !input.files[0]) return;
      var fd = new FormData();
      fd.append('kind', formEl.getAttribute('data-kind') || '');
      fd.append('media', input.files[0]);
      flashState(state, 'Saving', 'is-saving');
      fetch('save_gear_media.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
      })
      .then(function(res){ return res.json(); })
      .then(function(data){
        if (!data || !data.ok) throw new Error((data && data.message) ? data.message : 'Upload failed');
        flashState(state, 'Saved', 'is-saved');
        var kind = formEl.getAttribute('data-kind') || '';
        var now = Date.now();
        if (kind === 'avatar') {
          document.querySelectorAll('img[data-live-avatar="1"]').forEach(function(img){
            var base = img.getAttribute('data-avatar-base') || img.getAttribute('src') || '';
            base = base.replace(/([?&])v=\d+/g, '$1').replace(/[?&]$/, '');
            img.setAttribute('src', base + (base.indexOf('?') >= 0 ? '&' : '?') + 'v=' + now);
          });
        } else if (kind === 'cover') {
          var cover = document.getElementById('profileCoverPreview');
          if (cover) {
            if (cover.tagName && cover.tagName.toLowerCase() === 'img') {
              cover.setAttribute('src', data.preview);
            } else {
              var img = document.createElement('img');
              img.id = 'profileCoverPreview';
              img.alt = 'Cover image';
              img.src = data.preview;
              cover.replaceWith(img);
            }
          }
        }
        input.value = '';
        window.setTimeout(function(){ flashState(state, '', ''); }, 1400);
      })
      .catch(function(){
        flashState(state, 'Error', 'is-error');
        input.value = '';
      });
    });
  });

})();
</script>

<script>
(function(){
  function initGearSidebarNav(){
    if (!document.getElementById('panel-gear')) return;

    var panel = document.getElementById('panel-gear');
    if (panel.getAttribute('data-gear-nav-ready') === '1') return;
    panel.setAttribute('data-gear-nav-ready', '1');

    var gearNav = panel.querySelector('#gearNav');
    var empty = panel.querySelector('#gearDetailEmpty');
    var main = panel.querySelector('#gearMain');
    var syncingHash = false;

    function detailPanels(){
      return panel.querySelectorAll('.gear-detail-panel');
    }

    function navItems(){
      return panel.querySelectorAll('.gear-nav-item');
    }

    function closeSection(section){
      if (!section) return;
      section.classList.remove('is-open');
      var toggle = section.querySelector('.gear-nav-section-toggle');
      if (toggle) toggle.setAttribute('aria-expanded', 'false');
    }

    function openSection(section, closeOthers){
      if (!section) return;
      if (closeOthers !== false) {
        panel.querySelectorAll('.gear-nav-section.is-open').forEach(function(other){
          if (other !== section) closeSection(other);
        });
      }
      section.classList.add('is-open');
      var toggle = section.querySelector('.gear-nav-section-toggle');
      if (toggle) toggle.setAttribute('aria-expanded', 'true');
      if (gearNav) {
        window.requestAnimationFrame(function(){
          section.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        });
      }
    }

    function toggleSection(section){
      if (!section) return;
      var willOpen = !section.classList.contains('is-open');
      if (willOpen) {
        openSection(section, true);
        return;
      }
      closeSection(section);
    }

    function showDetail(id){
      if (!id) return;
      var target = document.getElementById(id);
      if (!target || !panel.contains(target)) return;

      detailPanels().forEach(function(detail){
        var active = detail.id === id;
        detail.classList.toggle('is-active', active);
        detail.hidden = !active;
      });

      navItems().forEach(function(btn){
        var active = btn.getAttribute('data-detail-id') === id;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-current', active ? 'page' : 'false');
      });

      if (empty) empty.hidden = true;

      var activeBtn = null;
      navItems().forEach(function(btn){
        if (btn.getAttribute('data-detail-id') === id) activeBtn = btn;
      });
      if (activeBtn) openSection(activeBtn.closest('.gear-nav-section'), true);

      if (main) {
        if (window.matchMedia('(max-width: 991px)').matches) {
          main.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
          target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      }

      try {
        syncingHash = true;
        var url = new URL(window.location.href);
        if (url.hash !== '#' + id) {
          url.hash = id;
          window.history.replaceState({}, '', url.pathname + url.search + url.hash);
        }
      } catch (e) {}
      syncingHash = false;
    }

    window.MSBGearNav = { showDetail: showDetail };

    if (gearNav) {
      gearNav.addEventListener('click', function(e){
        var toggle = e.target.closest('.gear-nav-section-toggle');
        if (toggle && gearNav.contains(toggle)) {
          var section = toggle.closest('.gear-nav-section');
          if (!section) return;
          toggleSection(section);
          return;
        }

        var btn = e.target.closest('.gear-nav-item');
        if (!btn || !gearNav.contains(btn)) return;
        e.preventDefault();
        e.stopPropagation();
        showDetail(btn.getAttribute('data-detail-id') || '');
      });
    }

    var search = document.getElementById('gearSearchInput');
    if (search) {
      search.addEventListener('input', function(){
        var q = search.value.trim().toLowerCase();
        navItems().forEach(function(btn){
          var text = btn.getAttribute('data-search-text') || '';
          btn.hidden = q !== '' && text.indexOf(q) === -1;
        });
        panel.querySelectorAll('.gear-nav-section').forEach(function(section){
          var visible = section.querySelectorAll('.gear-nav-item:not([hidden])').length > 0;
          section.hidden = q !== '' && !visible;
          if (visible && q !== '') openSection(section, false);
        });
      });
    }

    function resolveHashTarget(){
      var hash = (window.location.hash || '').replace(/^#/, '');
      if (!hash) return '';

      var detail = document.getElementById(hash);
      if (detail && panel.contains(detail) && detail.classList.contains('gear-detail-panel')) {
        return hash;
      }

      var section = panel.querySelector('#' + hash + '.gear-nav-section');
      if (section) {
        openSection(section, true);
        var first = section.querySelector('.gear-nav-item:not([hidden])');
        return first ? (first.getAttribute('data-detail-id') || '') : '';
      }

      return '';
    }

    function bootFromHash(){
      var hashTarget = resolveHashTarget();
      if (hashTarget) {
        showDetail(hashTarget);
        return;
      }
      var active = panel.querySelector('.gear-detail-panel.is-active');
      if (!active) {
        var firstBtn = panel.querySelector('.gear-nav-item:not([hidden])');
        if (firstBtn) showDetail(firstBtn.getAttribute('data-detail-id') || '');
      }
    }

    bootFromHash();

    window.addEventListener('hashchange', function(){
      if (syncingHash) return;
      var target = resolveHashTarget();
      if (target) showDetail(target);
    });

    document.querySelectorAll('.ig-tab[data-panel="gear"]').forEach(function(tab){
      tab.addEventListener('click', function(){
        window.setTimeout(bootFromHash, 0);
      });
    });

    panel.querySelectorAll('.gear-control').forEach(function(ctrl){
      ctrl.addEventListener('change', function(){
        var label = '';
        if (ctrl.selectedIndex >= 0 && ctrl.options[ctrl.selectedIndex]) {
          label = ctrl.options[ctrl.selectedIndex].textContent || '';
        }
        navItems().forEach(function(btn){
          if (btn.classList.contains('is-active')) {
            var meta = btn.querySelector('.gear-nav-item-meta');
            if (meta && label) meta.textContent = label;
          }
        });
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initGearSidebarNav);
  } else {
    initGearSidebarNav();
  }
})();
</script>

<script>
(function($){
  'use strict';
  var AUTHOR_ID = <?php echo (int)$viewId; ?>;
  var ME_ID = <?php echo (int)$meId; ?>;
  window.ME_ID = ME_ID;
  window.__MSB_FEED_ME_ID = ME_ID;
  var PROFILE_FRIEND_STATUS = <?php echo json_encode($isOwnProfile ? 'self' : $friendStatus); ?>;
  var PROFILE_CAN_FOLLOW_PUBLISHERS = <?php echo $canFollowPublishers ? 'true' : 'false'; ?>;
  var PROFILE_HIDE_PRIVATE_CONTACT = <?php echo $canViewProfilePrivateContact ? 'false' : 'true'; ?>;
  var PROFILE_IS_PUBLISHER = <?php echo $profileIsPublisher ? 'true' : 'false'; ?>;
  var API_URL = 'feed_api.php';
  var loaded = false;
  var loading = false;
  var profileCommentsCache = {};

  try { window.API_URL = API_URL; } catch(e) {}

  function openProfileCommentsTray(postId){
    postId = Number(postId || 0);
    if(!postId) return;
    if(window.TTComments && typeof window.TTComments.isOpen === 'function' && window.TTComments.isOpen() && window.TTComments.getPostId() === postId){
      window.TTComments.toggle(postId, profileCommentsCache[postId] || []);
      return;
    }
    if(window.TTComments && typeof window.TTComments.clearFocusComment === 'function'){
      window.TTComments.clearFocusComment();
    }
    if(window.TTComments && profileCommentsCache[postId]){
      var opened = window.TTComments.toggle(postId, profileCommentsCache[postId]);
      if(opened) document.body.classList.add('profile-leftbar-open');
      return;
    }
    document.body.classList.add('profile-leftbar-open');
    if(window.TTComments && typeof window.TTComments.setPost === 'function'){
      window.TTComments.setPost(postId, [], true);
      var list = document.getElementById('ttCommentsList');
      if(list) list.innerHTML = '<div class="text-muted" style="padding:10px 6px;">Loading comments...</div>';
    }
    $.getJSON(API_URL, { ajax:'view', id: postId }, function(res){
      if(!(res && res.ok)){
        var failList = document.getElementById('ttCommentsList');
        if(failList) failList.innerHTML = '<div class="text-danger" style="padding:10px 6px;">Unable to load comments.</div>';
        return;
      }
      var comments = Array.isArray(res.comments) ? res.comments : [];
      profileCommentsCache[postId] = comments;
      var $card = $('#profilePostsFeed .mf-card[data-id="'+postId+'"]');
      if($card.length) $card.find('.mf-cmt').text(String(comments.length));
      if(window.TTComments && typeof window.TTComments.setPost === 'function'){
        window.TTComments.setPost(postId, comments, true);
      }
    }).fail(function(){
      var failList = document.getElementById('ttCommentsList');
      if(failList) failList.innerHTML = '<div class="text-danger" style="padding:10px 6px;">Unable to load comments.</div>';
    });
  }

  function mfOpenProfileReadMoreDrawer($card, bodyText){
    $card = $card && $card.jquery ? $card : $($card);
    if(!$card.length) return;
    var body = formatReadMoreTextPreserve(String(bodyText || $card.attr('data-full-desc') || '').trim());
    if(!body) return;
    var title = String($card.attr('data-title') || 'Post');
    var author = String($card.attr('data-author') || '');
    var date = String($card.attr('data-date') || '');
    var avatarText = String($card.attr('data-avatar-text') || 'P');
    var avatarUrl = String($card.attr('data-avatar-url') || '');
    if(window.TTComments && typeof window.TTComments.close === 'function'){
      window.TTComments.close();
    }
    if(window.TTReadMore && typeof window.TTReadMore.toggle === 'function'){
      var opened = window.TTReadMore.toggle({
        title: title,
        author: author,
        date: date,
        avatarText: avatarText,
        avatarBg: '#111827',
        avatarUrl: avatarUrl,
        body: body
      });
      if(opened) document.body.classList.add('profile-leftbar-open');
    }
  }

  window.TTComments = window.TTComments || {};
  window.TTComments.refreshCurrent = function(){
    var pid = Number($('#ttPostId').val() || 0);
    if(!pid) return;
    $.getJSON(API_URL, { ajax:'view', id: pid }, function(res){
      if(!(res && res.ok)) return;
      var comments = Array.isArray(res.comments) ? res.comments : [];
      profileCommentsCache[pid] = comments;
      var $card = $('#profilePostsFeed .mf-card[data-id="'+pid+'"]');
      if($card.length) $card.find('.mf-cmt').text(String(comments.length));
      if(window.MSBPostEngagement){
        window.MSBPostEngagement.publishCommentCount(pid, comments.length, { source: 'profile-comments' });
      }
      if(window.TTComments && typeof window.TTComments.setPost === 'function'){
        window.TTComments.setPost(pid, comments, false);
      }
    });
  };

  document.addEventListener('click', function(e){
    var closeBtn = e.target && e.target.closest ? e.target.closest('#ttCommentsClose, #ttRmClose') : null;
    if(closeBtn) document.body.classList.remove('profile-leftbar-open');
  });

  if(window.TTComments){
    try{
      var profileCommentsClose = window.TTComments.close;
      window.TTComments.close = function(){
        document.body.classList.remove('profile-leftbar-open');
        if(typeof profileCommentsClose === 'function') profileCommentsClose();
      };
    }catch(err){}
  }
  if(window.TTReadMore){
    try{
      var profileReadMoreClose = window.TTReadMore.close;
      window.TTReadMore.close = function(){
        document.body.classList.remove('profile-leftbar-open');
        if(typeof profileReadMoreClose === 'function') profileReadMoreClose();
      };
    }catch(err){}
  }

  function esc(s){
    return String(s == null ? '' : s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }
  function isProfileStoryPost(it){
    if(!it) return false;
    if(Number(it.is_story || 0) === 1) return true;
    var layout = String(it.declared_layout || it.layout || it.layout_type || it.post_type || it.type || '').toLowerCase().trim();
    if(layout === 'story') return true;
    var desc = String(it.description || it.descr || '');
    return /\[\[layout:story\]\]/i.test(desc);
  }
  function profileFeedItems(items){
    return (Array.isArray(items) ? items : []).filter(function(it){ return !isProfileStoryPost(it); });
  }
  function parseDate(dt){
    if(!dt) return null;
    var d = new Date(String(dt).replace(' ', 'T'));
    return isNaN(d.getTime()) ? null : d;
  }
  function timeAgoShort(dt){
    var d = parseDate(dt);
    if(!d) return '';
    var mins = Math.floor((Date.now() - d.getTime()) / 60000);
    if(mins < 1) return 'now';
    if(mins < 60) return mins + 'm';
    var hrs = Math.floor(mins / 60);
    if(hrs < 24) return hrs + 'h';
    var days = Math.floor(hrs / 24);
    if(days < 7) return days + 'd';
    return d.toLocaleDateString(undefined, { month:'short', day:'numeric' });
  }
  function postDate(it){ return (it && (it.updated_at || it.created_at)) ? (it.updated_at || it.created_at) : ''; }
  function mfDeviceTimeLabel(it, dt){
    return timeAgoShort(dt) || String(dt || '').slice(0, 16);
  }
  function detectKind(path, typeHint){
    var t = String(typeHint || '').toLowerCase().trim();
    if(t && t !== 'file') return t;
    var clean = String(path || '').split('?')[0].split('#')[0].toLowerCase();
    if(/\.(mp4|webm|ogg)$/.test(clean)) return 'video';
    if(/\.(jpg|jpeg|png|gif|webp|bmp|svg)$/.test(clean)) return 'image';
    return 'file';
  }
  function stripLayout(txt){
    return String(txt || '').replace(/\s*\[\[layout:[a-z0-9_]+\]\]\s*/ig, ' ').replace(/\s{2,}/g, ' ').trim();
  }
  function formatReadMoreTextPreserve(text){
    text = String(text || '');
    text = text.replace(/\[\[layout:[a-z0-9_]+\]\]/ig, '');
    text = text.replace(/<\/p>\s*<p[^>]*>/ig, '\n\n');
    text = text.replace(/<br\s*\/?>/ig, '\n');
    text = text.replace(/<[^>]+>/g, '');
    text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    text = text.replace(/[ \t]+\n/g, '\n').replace(/\n[ \t]+/g, '\n');
    text = text.replace(/\n{3,}/g, '\n\n');
    return text.trim();
  }
  function mfSentenceCount(text){
    text = String(text || '').trim();
    if(!text) return 0;
    return text.split(/[.!?]+/).map(function(s){ return s.trim(); }).filter(Boolean).length;
  }
  function mfTruncate(text, maxSent){
    text = String(text || '').trim();
    maxSent = Number(maxSent || 3);
    if(!text) return { short:'', full:'', truncated:false };
    var sents = text.split(/[.!?]+/).map(function(s){ return s.trim(); }).filter(Boolean);
    if(sents.length <= maxSent) return { short:text, full:text, truncated:false };
    return { short:sents.slice(0, maxSent).join('. ') + '.', full:text, truncated:true };
  }
  function mfAvatarInit(name){
    name = String(name || '').trim();
    if(!name) return '?';
    var words = name.split(/\s+/).filter(Boolean);
    var a = (words[0] || '')[0] || '?';
    var b = (words.length > 1 ? (words[1] || '')[0] : (words[0] || '')[1]) || '';
    return (a + b).toUpperCase();
  }
  function formatPostCardTextHtml(text){
    text = formatReadMoreTextPreserve(text);
    if(!text) return '';
    return text.split(/\n\s*\n/).map(function(block){
      block = block.trim();
      if(!block) return '';
      var lines = block.split(/\n/).map(function(line){
        return esc(String(line || '').trim());
      }).filter(Boolean).join('<br>');
      return '<p class="post-card-paragraph">'+lines+'</p>';
    }).filter(Boolean).join('');
  }
  function mfBuildBodyHtml(text){
    var maxSent = 3;
    var maxChars = 170;
    text = formatReadMoreTextPreserve(String(text || '').trim());
    if(!text) return '';
    var sents = text.split(/[.!?]+/).map(function(s){ return s.trim(); }).filter(Boolean);
    var needsMore = (sents.length > maxSent) || (sents.length <= maxSent && text.length > maxChars);
    var display = text;
    if(needsMore){
      if(sents.length > maxSent){
        display = mfTruncate(text, maxSent).short || text;
      } else {
        display = text.slice(0, maxChars).trim();
        var sp = display.lastIndexOf(' ');
        if(sp > Math.floor(maxChars * 0.6)) display = display.slice(0, sp);
        display = display.replace(/[.,;:\s]+$/,'') + '…';
      }
    }
    var formatted = formatPostCardTextHtml(display);
    if(needsMore){
      return '<div class="mf-body mf-body-has-more" data-full="'+esc(text)+'" data-expanded="0"><div class="mf-body-formatted is-clamped">'+formatted+'</div><a href="#" class="mf-readmore js-open-readmore">Read more</a></div>';
    }
    return '<div class="mf-body"><div class="mf-body-formatted">'+formatted+'</div></div>';
  }
  function avatarUrlFor(it){
    it = it || {};
    var params = [];
    var uid = Number(it.user_id || 0);
    if(uid > 0) params.push('u=' + encodeURIComponent(String(uid)));
    var fc = PROFILE_HIDE_PRIVATE_CONTACT ? '' : String(it.friend_code || '').trim();
    var un = String(it.username || '').trim();
    var nm = String(it.display_name || it.name || un || 'User').trim();
    if(fc) params.push('friend_code=' + encodeURIComponent(fc));
    if(un) params.push('username=' + encodeURIComponent(un));
    if(nm) params.push('name=' + encodeURIComponent(nm));
    params.push('s=96');
    return 'avatar.php?' + params.join('&');
  }
  function peerProfileHref(it){
    it = it || {};
    var fc = PROFILE_HIDE_PRIVATE_CONTACT ? '' : String(it.friend_code || '').trim();
    var un = String(it.username || '').trim();
    var uid = Number(it.user_id || 0);
    var params = [];
    if(un) params.push('username=' + encodeURIComponent(un));
    else if(uid > 0) params.push('id=' + encodeURIComponent(String(uid)));
    else if(fc) params.push('friend_code=' + encodeURIComponent(fc.toUpperCase()));
    return 'profile.php' + (params.length ? ('?' + params.join('&')) : '');
  }
  var profileEngagementById = {};

  function countsFromItem(it){
    it = it || {};
    var base = {
      comment_count: Number(it.comment_count || 0),
      love_count: Number(it.love_count || 0),
      like_count: Number(it.like_count || 0),
      reaction_count: Number(it.reaction_count != null ? it.reaction_count : (Number(it.love_count || 0) + Number(it.like_count || 0))),
      share_count: Number(it.share_count || 0),
      save_count: Number(it.save_count || 0),
      my_reaction: String(it.my_reaction || ''),
      is_saved: Number(it.my_saved || 0),
      is_shared: Number(it.my_shared || 0)
    };
    var cached = profileEngagementById[Number(it.id || 0)];
    return cached ? Object.assign({}, base, cached) : base;
  }
  function rememberEngagement(postId, counts){
    postId = Number(postId || 0);
    if(!postId || !counts) return;
    var prev = profileEngagementById[postId] || {};
    var next = Object.assign({}, prev);
    ['comment_count','love_count','like_count','reaction_count','share_count','save_count','my_reaction','is_saved','is_shared'].forEach(function(k){
      if(Object.prototype.hasOwnProperty.call(counts, k) && counts[k] != null) next[k] = counts[k];
    });
    profileEngagementById[postId] = next;
  }
  function writeCountAttr($card, key, value){
    if(!$card || !$card.length) return;
    $card.attr('data-' + key.replace(/_/g, '-'), String(value));
  }
  function applyCounts($card, counts){
    counts = counts || {};
    function setCount(sel, key){
      if(!Object.prototype.hasOwnProperty.call(counts, key) || counts[key] == null) return;
      var n = Number(counts[key] || 0);
      $card.find(sel).text(String(n));
      writeCountAttr($card, key, n);
    }
    setCount('.mf-cmt, .mf-act.mf-comment .mf-num', 'comment_count');
    if(Object.prototype.hasOwnProperty.call(counts, 'reaction_count') && counts.reaction_count != null){
      $card.find('.mf-act.mf-love .mf-num').text(String(Number(counts.reaction_count || 0)));
    } else if(Object.prototype.hasOwnProperty.call(counts, 'love_count') && Object.prototype.hasOwnProperty.call(counts, 'like_count')){
      $card.find('.mf-act.mf-love .mf-num').text(String(Number(counts.love_count || 0) + Number(counts.like_count || 0)));
    } else {
      setCount('.mf-act.mf-love .mf-num', 'love_count');
    }
    setCount('.mf-act.mf-save .mf-num', 'save_count');
    setCount('.mf-act.mf-share .mf-num', 'share_count');

    if(Object.prototype.hasOwnProperty.call(counts, 'my_reaction')){
      var my = String(counts.my_reaction || '');
      $card.attr('data-my-reaction', my);
      $card.find('.mf-act.mf-love').each(function(){
        if(window.MSBReactions && typeof window.MSBReactions.applyReactionButton === 'function'){
          window.MSBReactions.applyReactionButton(this, my, 'love');
        } else {
          var onLove = my === 'love';
          $(this).toggleClass('is-love', onLove || (my !== '' && my !== 'like'));
          $(this).find('.msb-pact-heart').toggleClass('is-active', onLove);
        }
      });
    }
    if(Object.prototype.hasOwnProperty.call(counts, 'is_saved')){
      var saved = Number(counts.is_saved || 0) === 1;
      $card.attr('data-my-saved', saved ? '1' : '0');
      $card.find('.mf-act.mf-save').toggleClass('is-save', saved);
      $card.find('.mf-act.mf-save .msb-pact-bookmark').toggleClass('is-active', saved);
    }
    if(Object.prototype.hasOwnProperty.call(counts, 'is_shared')){
      $card.find('.mf-act.mf-share').toggleClass('is-share', Number(counts.is_shared || 0) === 1);
    }
  }
  function cardCountsFromDom($card){
    var pid = Number($card.data('id') || $card.attr('data-id') || 0);
    var cached = profileEngagementById[pid] || {};
    return {
      comment_count: Number(cached.comment_count != null ? cached.comment_count : ($card.find('.mf-cmt, .mf-act.mf-comment .mf-num').first().text() || 0)),
      love_count: Number(cached.love_count != null ? cached.love_count : ($card.find('.mf-act.mf-love .mf-num').first().text() || 0)),
      share_count: Number(cached.share_count != null ? cached.share_count : ($card.find('.mf-act.mf-share .mf-num').first().text() || 0)),
      save_count: Number(cached.save_count != null ? cached.save_count : ($card.find('.mf-act.mf-save .mf-num').first().text() || 0)),
      my_reaction: String(cached.my_reaction != null ? cached.my_reaction : ($card.attr('data-my-reaction') || '')),
      is_saved: cached.is_saved != null ? Number(cached.is_saved) : ($card.find('.mf-act.mf-save').hasClass('is-save') ? 1 : 0),
      is_shared: cached.is_shared != null ? Number(cached.is_shared) : ($card.find('.mf-act.mf-share').hasClass('is-share') ? 1 : 0)
    };
  }
  function stampCardEngagement($card){
    $card = $card && $card.jquery ? $card : $($card);
    if($card.length) $card.attr('data-engage-at', String(Date.now()));
  }
  function applyCountsGuarded($card, counts, opts){
    counts = counts || {};
    opts = opts || {};
    var pid = Number($card.data('id') || $card.attr('data-id') || 0);
    var engageAt = Number($card.attr('data-engage-at') || 0);
    var guarded = engageAt && (Date.now() - engageAt) < 8000;
    // After a user click, ignore stale hydrate payloads so counts don't snap back to 0
    if(guarded && !opts.force){
      if(profileEngagementById[pid]) applyCounts($card, profileEngagementById[pid]);
      return;
    }
    applyCounts($card, counts);
  }
  function commitEngagement($card, postId, counts){
    rememberEngagement(postId, counts);
    stampCardEngagement($card);
    applyCounts($card, counts);
  }
  function deviceCardMeta(it){
    it = it || {};
    var meta = {};
    if(window.MSBDeviceProfile && typeof window.MSBDeviceProfile.cardMeta === 'function'){
      meta = window.MSBDeviceProfile.cardMeta(it.device_label || '', it.device_viewport || '') || {};
    } else {
      var viewport = String(it.device_viewport || '').trim();
      var m = viewport.match(/^(\d{2,5})x(\d{2,5})/);
      var phoneShot = false;
      var tabletShot = false;
      if(m){
        var w = Number(m[1] || 0);
        var h = Number(m[2] || 0);
        var short = Math.min(w, h);
        var long = Math.max(w, h);
        if(short <= 480 && (long / Math.max(short, 1)) >= 1.2) phoneShot = true;
        else if(short > 480 && short < 900) tabletShot = true;
      }
      if(!phoneShot && /iphone|android phone|pixel/i.test(String(it.device_label || ''))) phoneShot = true;
      meta = {
        phone_shot: phoneShot,
        tablet_shot: tabletShot,
        style: m ? ('--device-ar-w:' + m[1] + ';--device-ar-h:' + m[2] + ';') : '',
        label: String(it.device_label || '').trim(),
        viewport: viewport
      };
    }
    if(typeof it.phone_shot !== 'undefined') meta.phone_shot = !!Number(it.phone_shot);
    if(typeof it.tablet_shot !== 'undefined') meta.tablet_shot = !!Number(it.tablet_shot);
    if(meta.phone_shot) meta.tablet_shot = false;
    if(!String(meta.style || '').trim() && String(it.device_style || '').trim()){
      meta.style = String(it.device_style || '').trim();
    }
    if(!String(meta.style || '').trim() && window.MSBDeviceProfile && typeof window.MSBDeviceProfile.defaultStyle === 'function'){
      meta.style = window.MSBDeviceProfile.defaultStyle(
        it.device_label || meta.label || '',
        !!meta.phone_shot,
        !!meta.tablet_shot,
        it.device_viewport || meta.viewport || ''
      ) || '';
    }
    return meta;
  }
  function parseDeviceAspectFromStyle(style){
    style = String(style || '');
    var mw = style.match(/--device-ar-w:\s*(\d+)/);
    var mh = style.match(/--device-ar-h:\s*(\d+)/);
    if(!mw || !mh) return null;
    return { w: Number(mw[1] || 0), h: Number(mh[1] || 0) };
  }
  function getDeviceDimensions(card){
    if(!card) return null;
    var mediaEl = card.querySelector('.mf-media.media-stage, .mf-media');
    var fromStyle = parseDeviceAspectFromStyle(mediaEl ? mediaEl.getAttribute('style') : '');
    if(fromStyle && fromStyle.w > 0 && fromStyle.h > 0) return fromStyle;
    var dw = Number(card.getAttribute('data-device-w') || 0);
    var dh = Number(card.getAttribute('data-device-h') || 0);
    if(dw > 0 && dh > 0) return { w: dw, h: dh };
    return null;
  }
  var PROFILE_FEED_MAX = 614;
  function maxVideoHeight(){
    // Match feed.php mfMaxVideoHeight (slightly larger mobile/tablet/desktop).
    var viewportH = Math.max(window.innerHeight || 0, 320);
    var reserved = 230;
    var fitH = Math.max(300, viewportH - reserved);
    if(window.matchMedia('(max-width: 767.98px)').matches){
      return Math.min(Math.round(viewportH * 0.58), fitH, 620);
    }
    if(window.matchMedia('(max-width: 1024.98px)').matches){
      return Math.min(Math.round(viewportH * 0.60), fitH, 620);
    }
    return Math.min(Math.round(viewportH * 0.62), fitH, 640);
  }
  function mediaMaxHeightCss(){
    // Match feed.php — never bake computed px.
    if(window.matchMedia('(max-width: 767.98px)').matches){
      return 'min(58vh, 620px)';
    }
    if(window.matchMedia('(max-width: 1024.98px)').matches){
      return 'min(60vh, 620px)';
    }
    return 'min(74vh, 640px)';
  }
  function computeMediaCardWidth(aspectW, aspectH, opts){
    // Match feed.php mfComputeMediaCardWidth.
    opts = opts || {};
    aspectW = Number(aspectW || 0);
    aspectH = Number(aspectH || 0);
    if(!aspectW || !aspectH) return 0;

    var aspect = aspectW / aspectH;
    var maxVideoH = maxVideoHeight();
    var feed = opts.feedEl || document.getElementById('profilePostsFeed');
    var feedWidth = feed ? Math.floor(feed.clientWidth || 0) : Math.min(Math.max(window.innerWidth || 0, 320), PROFILE_FEED_MAX);
    var cardPad = opts.cardPad != null ? Number(opts.cardPad) : 24;
    var availableWidth = Math.max(240, (feedWidth || PROFILE_FEED_MAX) - cardPad);
    var isPhoneShot = !!opts.isPhoneShot;
    var desiredWidth = Math.round(aspect * maxVideoH);
    var isMobile = window.matchMedia('(max-width: 767.98px)').matches;
    var isTablet = window.matchMedia('(min-width: 768px) and (max-width: 1024.98px)').matches;

    if(isMobile){
      if(isPhoneShot){
        return Math.max(240, Math.min(availableWidth, Math.round(Math.min(window.innerWidth * 0.78, 340))));
      }
      var mobileMax = aspect < 0.8 ? 340 : (aspect > 1.15 ? Math.min(availableWidth, 400) : 360);
      return Math.max(240, Math.min(desiredWidth, availableWidth, mobileMax));
    }

    if(isTablet){
      var tabletMax = aspect < 0.8 ? 440 : (aspect > 1.15 ? Math.min(availableWidth, 600) : 480);
      return Math.max(280, Math.min(desiredWidth, availableWidth, tabletMax));
    }

    var maxByShape = aspect < 0.8 ? 440 : (aspect > 1.15 ? 720 : 560);
    return Math.max(280, Math.min(desiredWidth, availableWidth, maxByShape));
  }
  function initialMediaCardStyleFromDims(dims, isPhoneShot){
    if(!dims || !Number(dims.w || 0) || !Number(dims.h || 0)) return '';
    var safeWidth = computeMediaCardWidth(dims.w, dims.h, {
      isPhoneShot: !!isPhoneShot,
      feedEl: document.getElementById('profilePostsFeed'),
      cardPad: 24
    });
    if(!safeWidth) return '';
    return '--post-media-card-width:'+String(safeWidth)+'px;--post-media-max-height:'+mediaMaxHeightCss()+';width:100%;max-width:100%;margin-left:0;margin-right:0;padding:8px 12px;box-sizing:border-box;';
  }
  function initialMediaAspect(it, deviceDims){
    if(deviceDims && deviceDims.w > 0 && deviceDims.h > 0) return deviceDims;
    var shape = String((it && it.media_shape) || '').trim();
    if(shape === 'single-portrait') return { w: 9, h: 16 };
    if(shape === 'single-landscape') return { w: 16, h: 9 };
    if(shape === 'single-square') return { w: 1, h: 1 };
    return null;
  }
  function clearProfileDeviceCardWidth(card){
    if(!card) return;
    card.style.width = '';
    card.style.maxWidth = '';
    card.style.marginLeft = '';
    card.style.marginRight = '';
    card.style.padding = '';
    card.style.boxSizing = '';
    try{ card.style.removeProperty('--post-media-card-width'); }catch(e){}
    try{ card.style.removeProperty('--post-media-max-height'); }catch(e){}
  }
  function resetProfileNonMediaCardWidths(scope){
    try{
      var root = (scope && scope.jquery) ? scope[0] : (scope || document.getElementById('profilePostsFeed'));
      if(!root || !root.querySelectorAll) return;
      root.querySelectorAll('.mf-card:not(.mf-card-phone-shot):not(.is-single-video-post):not(.is-single-image-post)').forEach(clearProfileDeviceCardWidth);
    }catch(e){}
  }
  function applyPublicMediaCardWidth(card, aspectW, aspectH){
    // Match feed.php mfApplyPublicVideoCardWidth for profile Posts tab.
    if(!card) return;
    aspectW = Number(aspectW || 0);
    aspectH = Number(aspectH || 0);
    if(!aspectW || !aspectH) return;

    var media = card.querySelector('.media-stage.standard-video-stage, .media-stage.standard-image-stage');
    var video = card.querySelector('.media-stage.standard-video-stage > video');
    var image = card.querySelector('.media-stage.standard-image-stage > img');
    var isPhoneShot = card.classList.contains('mf-card-phone-shot') || !!(media && media.classList.contains('phone-shot'));
    var isHeadOutside = card.classList.contains('mf-card-media-head-outside') || !!card.querySelector('.mf-head--on-media');
    var safeWidth = computeMediaCardWidth(aspectW, aspectH, {
      isPhoneShot: isPhoneShot,
      feedEl: card.closest('.mf-feed') || document.getElementById('profilePostsFeed'),
      cardPad: 24
    });
    if(!safeWidth) return;

    var maxH = mediaMaxHeightCss();
    // Keep the card full-width; constrain/left-align media only (like feed.php).
    card.style.width = '100%';
    card.style.maxWidth = '100%';
    card.style.marginLeft = '0';
    card.style.marginRight = '0';
    card.style.setProperty('box-sizing', 'border-box', 'important');
    card.style.setProperty('padding', isHeadOutside ? '8px 12px' : '20px', 'important');
    card.style.setProperty('--post-media-card-width', String(safeWidth) + 'px');
    card.style.setProperty('--post-media-max-height', maxH);

    if(media){
      if(isHeadOutside){
        media.style.width = '100%';
        media.style.maxWidth = '100%';
        media.style.marginLeft = '0';
        media.style.marginRight = '0';
      }else{
        media.style.width = 'min(100%, ' + String(safeWidth) + 'px)';
        media.style.maxWidth = '100%';
        media.style.marginLeft = '0';
        media.style.marginRight = 'auto';
      }
      media.style.height = 'auto';
      media.style.aspectRatio = 'auto';
      media.style.background = 'transparent';
      media.style.setProperty('overflow', isHeadOutside ? 'visible' : 'hidden', 'important');
      if(!isHeadOutside){
        media.style.setProperty('max-height', maxH, 'important');
      }else{
        media.style.removeProperty('max-height');
      }
      media.style.removeProperty('min-height');
      try{
        media.classList.remove('single-portrait', 'single-landscape', 'single-square');
      }catch(e){}
    }
    if(video){
      video.style.setProperty('width', isHeadOutside ? ('min(100%, ' + String(safeWidth) + 'px)') : '100%', 'important');
      video.style.setProperty('max-width', '100%', 'important');
      video.style.setProperty('height', 'auto', 'important');
      video.style.setProperty('max-height', maxH, 'important');
      video.style.setProperty('object-fit', 'contain', 'important');
      video.style.setProperty('object-position', 'center center', 'important');
      video.style.setProperty('margin-left', '0', 'important');
      video.style.setProperty('margin-right', 'auto', 'important');
      video.style.setProperty('justify-self', 'start', 'important');
      video.style.background = 'transparent';
      video.style.removeProperty('padding');
    }
    if(image){
      image.style.setProperty('width', isHeadOutside ? ('min(100%, ' + String(safeWidth) + 'px)') : '100%', 'important');
      image.style.setProperty('max-width', '100%', 'important');
      image.style.setProperty('height', 'auto', 'important');
      image.style.setProperty('max-height', maxH, 'important');
      image.style.setProperty('object-fit', 'contain', 'important');
      image.style.setProperty('object-position', 'center center', 'important');
      image.style.setProperty('margin-left', '0', 'important');
      image.style.setProperty('margin-right', 'auto', 'important');
      image.style.setProperty('justify-self', 'start', 'important');
      image.style.background = 'transparent';
      image.style.removeProperty('padding');
      image.style.removeProperty('box-sizing');
    }
  }
  function syncProfileMediaCard(el){
    if(!el) return;
    var card = el.closest('.mf-card.is-single-video-post, .mf-card.is-single-image-post');
    if(!card) return;
    var w = 0, h = 0;
    if(String(el.tagName || '').toUpperCase() === 'VIDEO'){
      w = Number(el.videoWidth || 0);
      h = Number(el.videoHeight || 0);
    } else {
      w = Number(el.naturalWidth || 0);
      h = Number(el.naturalHeight || 0);
    }
    if(!w || !h) return;
    var stage = el.closest('.media-stage.standard-video-stage, .media-stage.standard-image-stage');
    if(stage) stage.classList.add('mf-media-sized');
    if(card.classList.contains('is-single-video-post')) card.classList.add('mf-video-ready');
    if(card.classList.contains('is-single-image-post')) card.classList.add('mf-image-ready');
    applyPublicMediaCardWidth(card, w, h);
  }
  function preflightProfileMediaCard(card, it){
    if(!card || card.classList.contains('is-single-video-post')) return;
    var dims = getDeviceDimensions(card) || initialMediaAspect(it, null);
    if(!dims || !dims.w || !dims.h) return;
    applyPublicMediaCardWidth(card, dims.w, dims.h);
    var media = card.querySelector('.media-stage.standard-video-stage, .media-stage.standard-image-stage');
    if(media && card.classList.contains('is-single-image-post')){
      media.classList.add('mf-media-sized');
    }
  }
  function bindProfilePostsFeedSizing(scope){
    var root = (scope && scope.jquery) ? scope[0] : (scope || document.getElementById('profilePostsFeed'));
    if(!root || !root.querySelectorAll) return;
    Array.prototype.forEach.call(root.querySelectorAll('.mf-card.is-single-video-post .media-stage.standard-video-stage > video'), function(video){
      var sync = function(){
        syncProfileMediaCard(video);
        var stage = video.closest('.media-stage.standard-video-stage');
        if(stage) stage.classList.add('mf-media-sized');
      };
      if(video.dataset.ppMediaSized === '1'){
        if(video.readyState >= 1) sync();
        return;
      }
      video.dataset.ppMediaSized = '1';
      video.addEventListener('error', function(){
        var card = video.closest('.mf-card.is-single-video-post');
        if(card) card.classList.add('mf-video-error');
      }, { once:true });
      video.addEventListener('loadedmetadata', sync);
      video.addEventListener('loadeddata', sync);
      video.addEventListener('resize', sync);
      if(video.readyState >= 1) sync();
    });
    Array.prototype.forEach.call(root.querySelectorAll('.mf-card.is-single-image-post .media-stage.standard-image-stage > img'), function(img){
      var sync = function(){ syncProfileMediaCard(img); };
      if(img.dataset.ppMediaSized === '1'){
        if(img.complete && img.naturalWidth) sync();
        return;
      }
      img.dataset.ppMediaSized = '1';
      img.addEventListener('load', sync);
      img.addEventListener('error', function(){
        var card = img.closest('.mf-card.is-single-image-post');
        if(card) card.classList.add('mf-image-error');
      }, { once:true });
      if(img.complete && img.naturalWidth) sync();
    });
  }
  function buildMediaClassList(opts){
    opts = opts || {};
    var classes = ['mf-media', 'media-stage'];
    if(opts.standardVideo) classes.push('standard-video-stage');
    if(opts.standardImage) classes.push('standard-image-stage');
    if(opts.isPhoneShot && opts.isSingleMedia && window.matchMedia('(max-width: 767.98px)').matches) classes.push('phone-shot');
    if(opts.isMultiMedia) classes.push('has-carousel', 'js-media-carousel');
    return classes.join(' ');
  }
  function srcOf(a){
    var s = (a && (a.url || a.file_path || a.path || a.src)) ? (a.url || a.file_path || a.path || a.src) : '';
    return String(s || '').trim().replace(/^public_user\//, '');
  }
  function mfMediaDots(count){
    count = Math.max(0, Number(count || 0));
    if(count <= 1) return '';
    var dots = '';
    for(var i = 0; i < count; i += 1){
      dots += '<button type="button" class="mf-media-dot'+(i === 0 ? ' is-active' : '')+'" data-index="'+i+'" aria-label="Go to media '+(i+1)+'" style="width:5px;height:5px;min-width:5px;min-height:5px;flex:0 0 5px;display:block;border:none;border-radius:50%;padding:0;margin:0;background:'+(i === 0 ? '#3897f0' : 'rgba(255,255,255,.55)')+';cursor:pointer;-webkit-appearance:none;appearance:none;box-shadow:none;font-size:0;line-height:0;color:transparent;text-indent:-9999px;overflow:hidden;"></button>';
    }
    return '<div class="mf-media-dots" role="tablist" aria-label="Media slides" style="position:absolute;left:50%;bottom:12px;transform:translateX(-50%);display:flex;align-items:center;justify-content:center;gap:5px;padding:0;border-radius:0;background:transparent;z-index:5;">' + dots + '</div>';
  }
  function mfCarouselNavButtonsHtml(){
    return ''+
      '<button type="button" class="media-nav mf-media-nav prev js-mf-media-prev" aria-label="Previous media"><i class="fa fa-chevron-left" style="font-size:10px;margin-left:-2px;"></i></button>'+
      '<button type="button" class="media-nav mf-media-nav next js-mf-media-next" aria-label="Next media"><i class="fa fa-chevron-right" style="font-size:10px;margin-right:-2px;"></i></button>';
  }
  function mfFileTileHtml(src, kind){
    return '<div class="mf-file">'+
      '<div class="mf-file-ic"><i class="fa fa-file"></i></div>'+
      '<div class="mf-file-main">'+
        '<a href="'+esc(src)+'" target="_blank" rel="noopener">'+esc(src.split('/').pop()||'Open file')+'</a>'+
        '<div class="mf-file-sub">'+esc(String((kind||'file').toUpperCase()))+'</div>'+
      '</div>'+
    '</div>';
  }
  function mfBuildHydratedCarousel(atts){
    atts = Array.isArray(atts) ? atts : [];
    if(atts.length <= 1) return '';
    var slides = '';
    for(var i = 0; i < atts.length; i += 1){
      var a = atts[i] || {};
      var src = srcOf(a);
      var kind = detectKind(src, a.type);
      var inner = '';
      if(kind === 'image' || kind === 'gif'){
        inner = '<img src="'+esc(src)+'" alt="">';
      } else if(kind === 'video'){
        inner = '<video class="msb-clean-loop-video" src="'+esc(src)+'" autoplay loop muted playsinline webkit-playsinline preload="metadata" disablepictureinpicture controlslist="nodownload noplaybackrate nofullscreen"></video>';
      } else {
        inner = mfFileTileHtml(src, kind);
      }
      slides += '<div class="media-slide mf-media-slide" data-slide-index="'+i+'" data-slide-title="'+esc(String(a.slide_title||''))+'" data-slide-body="'+esc(String(a.slide_body||''))+'">'+inner+'</div>';
    }
    return ''+
      '<div class="media-carousel mf-media-carousel" data-index="0">'+
        '<div class="media-slides mf-media-slides">'+slides+'</div>'+
        mfCarouselNavButtonsHtml()+
        mfMediaDots(atts.length)+
      '</div>';
  }
  function mfSlideSummaryHtml(text){
    var raw = String(text || '').replace(/\r\n/g,'\n').replace(/\r/g,'\n').trim();
    if(!raw) return '';
    var lines = raw.split('\n').map(function(line){
      return String(line || '').trim().replace(/^(?:[•\-\*]|\d+[\.\)])\s+/, '');
    }).filter(Boolean);
    if(!lines.length) return '';
    if(lines.length === 1){
      return '<div class="post-slide-summary"><p class="post-slide-summary-p">'+esc(lines[0])+'</p></div>';
    }
    return '<div class="post-slide-summary"><ul class="post-slide-summary-list">'
      + lines.map(function(line){ return '<li>'+esc(line)+'</li>'; }).join('')
      + '</ul></div>';
  }
  function mfSetCarouselIndex($carousel, nextIndex){
    $carousel = $carousel && $carousel.jquery ? $carousel : $($carousel);
    if(!$carousel.length) return;
    if(!$carousel.hasClass('mf-media-carousel') && !$carousel.hasClass('media-carousel')){
      var $inner = $carousel.find('.mf-media-carousel, .media-carousel').first();
      if($inner.length) $carousel = $inner;
    }
    var $slides = $carousel.find('.mf-media-slides, .media-slides').first();
    var $items = $slides.children('.mf-media-slide, .media-slide');
    var total = $items.length;
    if(!total) return;
    nextIndex = Number(nextIndex);
    if(!isFinite(nextIndex)) nextIndex = 0;
    if(nextIndex < 0) nextIndex = total - 1;
    if(nextIndex >= total) nextIndex = 0;
    $carousel.attr('data-index', String(nextIndex));
    $carousel.closest('.js-media-carousel, .media-stage').attr('data-index', String(nextIndex));
    $slides.css('transform', 'translateX(' + String(nextIndex * -100) + '%)');

    var $dots = $carousel.find('.mf-media-dot');
    if(!$dots.length){
      $dots = $carousel.closest('.js-media-carousel, .media-stage, .mf-media').find('.mf-media-dot');
    }
    $dots.each(function(){
      var $dot = $(this);
      var idx = Number($dot.attr('data-index'));
      if(!isFinite(idx)) idx = $dot.index();
      var on = idx === nextIndex;
      $dot.toggleClass('is-active', on);
      $dot.css('background', on ? '#3897f0' : 'rgba(255,255,255,.55)');
      if(on){
        $dot.css({width:'6px',height:'6px',minWidth:'6px',minHeight:'6px',flex:'0 0 6px'});
      } else {
        $dot.css({width:'5px',height:'5px',minWidth:'5px',minHeight:'5px',flex:'0 0 5px'});
      }
    });

    $items.each(function(slideIndex){
      var videos = this.querySelectorAll('video');
      for(var v = 0; v < videos.length; v += 1){
        try{
          if(slideIndex !== nextIndex) videos[v].pause();
        }catch(err){}
      }
    });

    try {
      var $card = $carousel.closest('.mf-card');
      var active = $items.get(nextIndex);
      if ($card.length && active) {
        var anySlideText = false;
        $items.each(function(){
          if (String(this.getAttribute('data-slide-title') || '').trim() || String(this.getAttribute('data-slide-body') || '').trim()) {
            anySlideText = true;
            return false;
          }
        });
        if (anySlideText) {
          var slideTitle = String(active.getAttribute('data-slide-title') || '').trim();
          var slideBody = String(active.getAttribute('data-slide-body') || '').trim();
          var $sub = $card.find('.mf-slide-title').first();
          var $sum = $card.find('.mf-slide-summary').first();
          if ($sub.length) {
            if (!slideTitle) $sub.hide().text('');
            else $sub.show().text(slideTitle);
          }
          if ($sum.length) {
            if (!slideBody) $sum.hide().empty();
            else $sum.show().html(mfSlideSummaryHtml(slideBody));
          }
        }
      }
    } catch (eProfileSlideCap) {}
  }
  function mfHydrateCard(postId, post, counts, atts){
    var $card = $('#profilePostsFeed .mf-card[data-id="'+Number(postId)+'"]');
    if(!$card.length) return;
    // null/undefined counts = media-only hydrate (do not wipe live engagement numbers)
    if(counts) applyCountsGuarded($card, counts);

    atts = Array.isArray(atts) ? atts : [];
    if(atts.length > 1){
      var $shell = $card.find('.mf-media-shell').first();
      var $mediaWrap = $shell.length ? $shell.find('.mf-media').first() : $card.find('.mf-media').first();
      if($mediaWrap.length){
        var $existingCarousel = $mediaWrap.find('.mf-media-carousel').first();
        var existingSlides = $existingCarousel.length
          ? $existingCarousel.find('.mf-media-slide, .media-slide').length
          : 0;
        var needsRebuild = !$existingCarousel.length
          || $existingCarousel.attr('data-pending-hydrate') === '1'
          || existingSlides < atts.length;
        if(needsRebuild){
          var keepIndex = Number(($existingCarousel.attr('data-index') || $mediaWrap.attr('data-index') || 0));
          if(!isFinite(keepIndex) || keepIndex < 0) keepIndex = 0;
          var $followOverlay = ($shell.length ? $shell : $mediaWrap).find('.mf-media-top-actions').detach();
          var $headOverlay = ($shell.length ? $shell : $mediaWrap.parent()).find('.mf-head--on-media').detach();
          $mediaWrap.addClass('media-stage has-carousel js-media-carousel');
          $mediaWrap.removeClass('standard-image-stage standard-video-stage');
          $mediaWrap.attr('data-count', String(atts.length));
          $mediaWrap.html(mfBuildHydratedCarousel(atts));
          var $mountTarget = $shell.length ? $shell : $mediaWrap;
          if($headOverlay.length) $mountTarget.append($headOverlay);
          if($followOverlay.length) $mountTarget.append($followOverlay);
          mfSetCarouselIndex($mediaWrap.find('.mf-media-carousel'), keepIndex);
          $card.removeClass('is-single-image-post mf-card-single-image is-single-video-post mf-card-single-video')
            .addClass('is-multi-media-post mf-card-multi-media');
        }
      }
    }
    // Always restore sticky engagement after media work so counts cannot snap to 0
    if(profileEngagementById[Number(postId)]){
      applyCounts($card, profileEngagementById[Number(postId)]);
    }
  }
  try { window.mfHydrateCard = mfHydrateCard; } catch(e) {}
  function mfHydrateMultiMediaCards(items){
    items = Array.isArray(items) ? items : [];
    if(!items.length) return;
    var index = 0;
    var active = 0;
    var maxConcurrent = 2;
    function pump(){
      while(active < maxConcurrent && index < items.length){
        (function(it){
          active += 1;
          $.getJSON(API_URL, { ajax:'view', id: it.id, count_view:0, lite:1 }, function(res){
            active -= 1;
            if(res && res.ok){
              // Attachments only — never re-apply stale list engagement counts
              mfHydrateCard(it.id, res.post || {}, null, res.attachments || []);
              var $card = $('#profilePostsFeed .mf-card[data-id="'+Number(it.id)+'"]');
              if($card.length && profileEngagementById[Number(it.id)]){
                applyCounts($card, profileEngagementById[Number(it.id)]);
              }
            }
            pump();
          }).fail(function(){
            active -= 1;
            pump();
          });
        })(items[index]);
        index += 1;
      }
    }
    pump();
  }
  function normalActions(){
    return '<div class="mf-actions"><div class="mf-left">'+
      '<a class="mf-act mf-love" href="javascript:void(0)" role="button" title="Love"><i class="msb-pact msb-pact-heart" aria-hidden="true"></i><span class="mf-num" data-count="love">0</span></a>'+
      '<a class="mf-act mf-comment" href="javascript:void(0)" role="button" title="Comment"><i class="msb-pact msb-pact-comment" aria-hidden="true"></i><span class="mf-num mf-cmt" data-count="comment">0</span></a>'+
      '<a class="mf-act mf-share" href="javascript:void(0)" role="button" title="Share"><i class="msb-pact msb-pact-share" aria-hidden="true"></i><span class="mf-num" data-count="share">0</span></a>'+
      '</div><div class="mf-right">'+
      '<a class="mf-act mf-save" href="javascript:void(0)" role="button" title="Save"><i class="msb-pact msb-pact-bookmark" aria-hidden="true"></i><span class="mf-num" data-count="save">0</span></a>'+
      '</div></div>';
  }
  function profileFriendBtnHtml(it, isOwner){
    if(isOwner) return '';
    var isPub = String(it.account_kind || 'personal') === 'publisher';
    var uid = String(Number(it.user_id || 0));
    var cls = 'mf-friend-btn mf-media-follow-btn';
    if(isPub){
      if(!PROFILE_CAN_FOLLOW_PUBLISHERS) return '';
      var fol = Number(it.is_following || 0) === 1;
      return '<button type="button" class="'+cls+' mf-publisher-follow publisher-follow-btn mf-publisher-follow-circle mf-media-action-circle'+(fol ? ' is-following' : ' primary')+'" data-publisher-id="'+esc(uid)+'" aria-label="'+(fol ? 'Following' : 'Follow')+'" title="'+(fol ? 'Following' : 'Follow')+'">'+(fol ? '<span class="mf-media-action-label">Sent</span>' : '<i class="fa fa-plus" aria-hidden="true"></i>')+'</button>';
    }
    var st = String(it.friend_status || PROFILE_FRIEND_STATUS || 'none');
    if(st === 'self' || st === 'friends') return '';
    var extraCls = st === 'incoming_pending' ? ' is-accept' : (st === 'outgoing_pending' ? ' is-pending' : ' primary');
    var inner = st === 'outgoing_pending' ? '<span class="mf-media-action-label">Sent</span>' : (st === 'incoming_pending' ? '<span class="mf-media-action-label">Accept</span>' : '<i class="fa fa-plus" aria-hidden="true"></i>');
    return '<button type="button" class="'+cls+' mf-media-action-circle'+extraCls+'" data-peer-id="'+esc(uid)+'" data-peer-code="'+esc(PROFILE_HIDE_PRIVATE_CONTACT ? '' : String(it.friend_code || ''))+'" data-status="'+esc(st)+'" aria-label="Add Friend" title="Add Friend">'+inner+'</button>';
  }
  function profileIsPublisherItem(it){
    it = it || {};
    if (PROFILE_IS_PUBLISHER) return true;
    if (Number(it.is_publisher || 0) === 1) return true;
    if (String(it.account_kind || '').toLowerCase() === 'publisher') return true;
    var code = String(it.friend_code || '').trim().toUpperCase();
    return code.indexOf('PUB-') === 0;
  }
  function profileFriendStatusFromItem(it){
    return String((it && it.friend_status) || PROFILE_FRIEND_STATUS || 'none');
  }
  function profilePublisherFollowingFromItem(it){
    return Number((it && it.is_following) || 0) === 1;
  }
  function profileBuildMenuItems(it, isOwner, pid){
    if (window.MSBPostCardMenu && typeof window.MSBPostCardMenu.buildItems === 'function') {
      return window.MSBPostCardMenu.buildItems(it, isOwner, pid, {
        esc: esc,
        profileHref: peerProfileHref,
        isPublisher: profileIsPublisherItem,
        isFollowing: profilePublisherFollowingFromItem,
        friendStatus: profileFriendStatusFromItem
      });
    }
    return '';
  }
  function profileBuildHeadHtml(it, isOwner, pid, onMedia){
    var name = it.display_name || it.username || '';
    var avatarUrl = avatarUrlFor(it);
    var time = mfDeviceTimeLabel(it, postDate(it));
    var headClass = 'mf-head' + (onMedia ? ' mf-head--on-media' : '');
    var menuIcon = '<span class="post-card-fries-icon" aria-hidden="true"><span></span><span></span><span></span><span></span></span>';
    return '<div class="'+headClass+'">'+
      '<a class="mf-peer-link" href="'+esc(peerProfileHref(it))+'">'+
        '<div class="mf-avatar"><img src="'+esc(avatarUrl)+'" alt="'+esc(name)+'"></div>'+
        '<div class="mf-meta"><div class="mf-name-row">'+
          '<div class="mf-name">'+esc(name)+'</div>'+
          (time ? '<span class="mf-dot">&bull;</span><div class="mf-time">'+esc(time)+'</div>' : '')+
          (window.MSBPostCardMenu && typeof window.MSBPostCardMenu.visibilityBadgeHtml === 'function'
            ? window.MSBPostCardMenu.visibilityBadgeHtml(it.visibility || 'friends')
            : '')+
        '</div></div></a>'+
      '<div class="mf-menu-wrap post-card-menu-wrap" data-post-id="'+esc(String(pid))+'" data-peer-id="'+esc(String(it.user_id || ''))+'" data-is-owner="'+(isOwner ? '1' : '0')+'" data-menu-surface="profile">'+
        '<button type="button" class="mf-menu-btn post-card-menu-btn" aria-label="Post menu" title="Menu" aria-haspopup="true" aria-expanded="false">'+menuIcon+'</button>'+
        '<div class="mf-menu post-card-menu" role="menu">'+profileBuildMenuItems(it, isOwner, pid)+'</div>'+
      '</div></div>';
  }
  function profileWrapMediaShell(mediaHtml, headHtml, followHtml){
    mediaHtml = String(mediaHtml || '').trim();
    headHtml = String(headHtml || '').trim();
    followHtml = String(followHtml || '').trim();
    if(!mediaHtml) return mediaHtml;
    if(!headHtml && !followHtml) return mediaHtml;
    var followBlock = followHtml ? ('<div class="mf-media-top-actions">'+followHtml+'</div>') : '';
    return '<div class="mf-media-shell" style="position:relative;width:100%;">'+mediaHtml+headHtml+followBlock+'</div>';
  }
  function renderCard(it){
    var pid = Number(it.id || 0);
    if(!pid) return '';
    var name = it.display_name || it.username || '';
    var avatarUrl = avatarUrlFor(it);
    var time = mfDeviceTimeLabel(it, postDate(it));
    var title = String(it.title || '').trim();
    var isOwner = Number(it.user_id || 0) === ME_ID;
    var psrc = String(it.preview_path || '').trim();
    var pthumb = String(it.preview_thumb_path || '').trim().replace(/^public_user\//, '');
    var pkind = detectKind(psrc, it.preview_type);
    var body = formatReadMoreTextPreserve(String(it.body || it.description || '').trim());
    var hasBody = body.length > 0;
    var hasSlideCap = !!(String(it.preview_slide_title||'').trim() || String(it.preview_slide_body||'').trim() || Number(it.has_slide_captions||0));
    var slideTitle0 = hasSlideCap ? String(it.preview_slide_title||'').trim() : '';
    var slideBody0 = hasSlideCap ? String(it.preview_slide_body||'').trim() : '';
    var hasMedia = !!psrc;
    if(!hasMedia && !title && !hasBody) return '';
    var isTextOnly = !hasMedia;
    var attCount = Number(it.attachment_count || 0);
    var isSingleMedia = attCount <= 1;
    var isMultiMedia = attCount > 1;

    var deviceMeta = deviceCardMeta(it);
    var isPhoneShot = !!deviceMeta.phone_shot;
    var isTabletShot = !!deviceMeta.tablet_shot && !isPhoneShot;
    var deviceStyle = String(deviceMeta.style || '').trim();
    if(!deviceStyle && window.MSBDeviceProfile && typeof window.MSBDeviceProfile.defaultStyle === 'function'){
      deviceStyle = window.MSBDeviceProfile.defaultStyle(it.device_label || '', !!isPhoneShot, !!isTabletShot, it.device_viewport || '') || '';
    }
    var deviceDims = parseDeviceAspectFromStyle(deviceStyle);
    var deviceDataAttrs = '';
    var mediaStyleAttr = '';
    if(deviceDims && deviceDims.w > 0 && deviceDims.h > 0){
      deviceDataAttrs = ' data-device-w="'+esc(String(deviceDims.w))+'" data-device-h="'+esc(String(deviceDims.h))+'"';
    }
    if(!isTextOnly){
      mediaStyleAttr = deviceStyle ? (' style="'+esc(deviceStyle)+'"') : '';
    }

    var cardClass = 'mf-card';
    if(isTextOnly) cardClass += ' mf-card-text-only';
    if(isPhoneShot && isSingleMedia) cardClass += ' mf-card-phone-shot';
    if(hasMedia) cardClass += ' mf-card-media-head-outside';

    var mediaHtml = '';
    if(hasMedia){
      if(pkind === 'image' || pkind === 'gif'){
        if(isMultiMedia) cardClass += ' is-multi-media-post mf-card-multi-media';
        else cardClass += ' is-single-image-post mf-card-single-image';
        mediaHtml = '<div class="'+buildMediaClassList({ standardImage:isSingleMedia, isSingleMedia:isSingleMedia, isPhoneShot:isPhoneShot, isMultiMedia:isMultiMedia })+'"'+mediaStyleAttr+' data-shape-ready="1" data-count="'+attCount+'" data-index="0">'+
          (isMultiMedia
            ? ('<div class="media-carousel mf-media-carousel" data-index="0" data-pending-hydrate="1">'+
                 '<div class="media-slides mf-media-slides">'+
                   '<div class="media-slide mf-media-slide" data-slide-index="0"><img src="'+esc(psrc)+'" alt=""></div>'+
                 '</div>'+
                 mfCarouselNavButtonsHtml()+
                 mfMediaDots(attCount)+
               '</div>')
            : ('<img src="'+esc(psrc)+'" alt="">'))+
          '</div>';
      } else if(pkind === 'video'){
        cardClass += ' is-single-video-post mf-card-single-video';
        if(isMultiMedia) cardClass += ' is-multi-media-post mf-card-multi-media';
        var poster = pthumb ? (' poster="'+esc(pthumb)+'"') : '';
        mediaHtml = '<div class="'+buildMediaClassList({ standardVideo:true, isPhoneShot:isPhoneShot, isSingleMedia:isSingleMedia, isMultiMedia:isMultiMedia })+'"'+mediaStyleAttr+' data-shape-ready="0" data-count="'+attCount+'" data-index="0">'+
          '<video class="ig-smart-feed-video msb-clean-loop-video" src="'+esc(psrc)+'"'+poster+' autoplay loop muted playsinline webkit-playsinline preload="metadata" disablepictureinpicture controlslist="nodownload noplaybackrate nofullscreen" data-smart-video="1"></video>'+
          (isMultiMedia ? mfMediaDots(attCount) : '')+
          '</div>';
      } else {
        mediaHtml = '<div class="mf-media"><a href="'+esc(psrc)+'" target="_blank" rel="noopener">Open attachment</a></div>';
      }
    }

    var initialCardStyle = '';
    if(isTextOnly && isPhoneShot && deviceDims){
      initialCardStyle = initialMediaCardStyleFromDims(deviceDims, isPhoneShot);
    } else if(!isTextOnly && hasMedia && !isMultiMedia && (pkind === 'image' || pkind === 'gif' || pkind === 'video')){
      initialCardStyle = initialMediaCardStyleFromDims(deviceDims || initialMediaAspect(it, null), isPhoneShot);
    }
    var initialCardStyleAttr = initialCardStyle ? (' style="'+esc(initialCardStyle)+'"') : '';
    var avatarText = mfAvatarInit(name);

    var followHtml = profileFriendBtnHtml(it, isOwner);
    if(hasMedia){
      mediaHtml = profileWrapMediaShell(
        mediaHtml,
        '',
        followHtml
      );
    }

    return '<div class="'+cardClass+'" data-id="'+pid+'" data-post-id="'+pid+'" data-post-owner="'+(isOwner ? '1' : '0')+'" data-visibility="'+esc(String((window.MSBPostCardMenu && window.MSBPostCardMenu.normalizeVisibility) ? window.MSBPostCardMenu.normalizeVisibility(it.visibility || 'friends') : (it.visibility || 'friends')))+'" data-peer-id="'+esc(String(it.user_id || ''))+'" data-peer-code="'+esc(PROFILE_HIDE_PRIVATE_CONTACT ? '' : String(it.friend_code || ''))+'" data-account-kind="'+esc(String(it.account_kind || 'personal'))+'" data-is-publisher="'+(profileIsPublisherItem(it) ? '1' : '0')+'" data-is-following="'+(profilePublisherFollowingFromItem(it) ? '1' : '0')+'" data-friend-status="'+esc(profileFriendStatusFromItem(it))+'" data-title="'+esc(title)+'" data-author="'+esc(name)+'" data-date="'+esc(time)+'" data-avatar-url="'+esc(avatarUrl)+'" data-avatar-text="'+esc(avatarText)+'" data-full-desc="'+esc(body)+'"'+deviceDataAttrs+initialCardStyleAttr+'>'+
      profileBuildHeadHtml(it, isOwner, pid, false)+
      (title ? '<div class="mf-title">'+esc(title)+'</div>' : '')+
      (hasBody ? mfBuildBodyHtml(body) : '') +
      (hasSlideCap
        ? ('<div class="mf-slide-copy">' +
             '<div class="mf-slide-title"'+(slideTitle0 ? '' : ' style="display:none"')+'>'+esc(slideTitle0)+'</div>'+
             '<div class="mf-slide-summary"'+(slideBody0 ? '' : ' style="display:none"')+'>'+mfSlideSummaryHtml(slideBody0)+'</div>'+
           '</div>')
        : '') +
      mediaHtml + normalActions()+
      '</div>';
  }
  function profileTabEmptyHtml(title, iconClass){
    return '<div class="mf-feed-empty" role="status">'
      + '<i class="icon ' + esc(iconClass || 'ion-grid') + '" aria-hidden="true"></i>'
      + '<div class="mf-feed-empty-title">' + esc(title || 'No Posts Available') + '</div>'
      + '</div>';
  }
  function renderItems(items){
    var $wrap = $('#profilePostsFeed');
    $wrap.empty();
    items = profileFeedItems(items);
    if(!items.length){
      $wrap.html(profileTabEmptyHtml('No Posts Available', 'ion-grid'));
      return;
    }
    items.forEach(function(it){
      var html = renderCard(it);
      if(!html) return;
      $wrap.append(html);
      var $card = $wrap.children('.mf-card').last();
      var initialCounts = countsFromItem(it);
      applyCounts($card, initialCounts);
      rememberEngagement(Number(it.id || 0), initialCounts);
      try{ preflightProfileMediaCard($card[0], it); }catch(e){}
    });
    bindProfilePostsFeedSizing($wrap);
    resetProfileNonMediaCardWidths($wrap);
    if(window.MSBPostCardMenu){
      if(typeof window.MSBPostCardMenu.refreshFeedCardMenus === 'function'){
        window.MSBPostCardMenu.refreshFeedCardMenus($wrap[0] || document.getElementById('profilePostsFeed'));
      }
      if(typeof window.MSBPostCardMenu.hydrate === 'function'){
        window.MSBPostCardMenu.hydrate($wrap[0] || document.getElementById('profilePostsFeed'));
      }
      if(typeof window.MSBPostCardMenu.syncFriendCards === 'function' && PROFILE_FRIEND_STATUS === 'friends' && AUTHOR_ID > 0){
        window.MSBPostCardMenu.syncFriendCards(AUTHOR_ID, 'friends');
      }
    }
    setTimeout(function(){
      var multiMediaItems = items.filter(function(it){
        return Number(it.attachment_count || 0) > 1;
      });
      mfHydrateMultiMediaCards(multiMediaItems);
    }, 40);
  }
  function currentSearch(){
    try{
      return String(new URL(window.location.href).searchParams.get('gallery_search') || '').trim();
    }catch(e){ return ''; }
  }
  function loadProfilePostsFallback(){
    var fallbackUrl = window.location.pathname + window.location.search;
    $.getJSON(fallbackUrl, { ajax:'gallery', _:Date.now() }, function(res){
      loading = false;
      loaded = true;
      if(res && res.ok){
        renderItems(res.items || []);
        return;
      }
      $('#profilePostsFeed').html('<div class="mf-feed-empty">Could not load posts.</div>');
    }).fail(function(){
      loading = false;
      $('#profilePostsFeed').html('<div class="mf-feed-empty">Could not load posts.</div>');
    });
  }
  function loadFeed(force){
    if(!AUTHOR_ID || loading) return;
    if(loaded && !force) return;
    loading = true;
    $.getJSON(API_URL, {
      ajax:'list', filter:'author', author_id:AUTHOR_ID, page:'profile', limit:60, q:currentSearch(), exclude_stories:1
    }, function(res){
      loading = false;
      loaded = true;
      if(!res || !res.ok){
        loaded = false;
        loadProfilePostsFallback();
        return;
      }
      renderItems(res.items || []);
    }).fail(function(){
      loadProfilePostsFallback();
    });
  }
  function mfPost(action, data, cb){
    data = data || {};
    data.ajax = action;
    $.post(API_URL, data, function(res){ if(typeof cb === 'function') cb(res || {}); }, 'json')
      .fail(function(){ if(typeof cb === 'function') cb({ ok:false }); });
  }

  function applyFollowForPublisher(publisherId, following){
    publisherId = Number(publisherId || 0);
    if(!publisherId) return;
    var on = !!following;
    document.querySelectorAll('.publisher-follow-btn[data-publisher-id="'+String(publisherId)+'"]').forEach(function(el){
      if(typeof window.msbApplyPublisherFollowBtnState === 'function'){
        window.msbApplyPublisherFollowBtnState(el, on);
        return;
      }
      el.classList.toggle('is-following', on);
      el.classList.toggle('primary', !on);
      el.textContent = on ? 'Following' : 'Follow';
    });
    if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.syncPublisherCards === 'function'){
      window.MSBPostCardMenu.syncPublisherCards(publisherId, on);
    }
  }

  window.mfSyncFriendUiForPeer = function(peerId, status){
    peerId = Number(peerId || 0);
    if(peerId <= 0) return;
    status = String(status || 'none');
    if(peerId === Number(AUTHOR_ID || 0)){
      PROFILE_FRIEND_STATUS = status;
    }
    try {
      if (typeof PV_FRIEND_STATUS !== 'undefined' && peerId === Number(AUTHOR_ID || 0)) {
        PV_FRIEND_STATUS = status;
      }
      if (typeof pvCurrentPost !== 'undefined' && pvCurrentPost && Number(pvCurrentPost.user_id || 0) === peerId) {
        pvCurrentPost.friend_status = status;
      }
      if (typeof pvSyncMenu === 'function') pvSyncMenu(typeof pvCurrentPost !== 'undefined' ? pvCurrentPost : null);
    } catch (ePv) {}
    $('#profilePostsFeed .mf-card[data-peer-id="'+peerId+'"]').each(function(){
      var $card = $(this);
      if(String($card.attr('data-is-publisher') || '') === '1') return;
      $card.attr('data-friend-status', status);
      if(status === 'friends'){
        $card.find('.mf-friend-btn:not(.mf-publisher-follow)').remove();
        $card.find('.mf-media-top-actions').each(function(){
          if(!this.querySelector('.mf-friend-btn, .publisher-follow-btn')) this.remove();
        });
      } else if(!$card.find('.mf-friend-btn:not(.mf-publisher-follow)').length){
        // Restore + before fries after Unfriend.
        var stub = {
          user_id: peerId,
          friend_code: String($card.attr('data-peer-code') || ''),
          account_kind: String($card.attr('data-account-kind') || 'personal'),
          is_publisher: 0,
          is_following: 0,
          friend_status: status || 'none'
        };
        var followHtml = profileFriendBtnHtml(stub, false);
        if(followHtml){
          var $shell = $card.find('.mf-media-shell').first();
          var $menu = $card.find('.post-card-menu-wrap, .mf-menu-wrap').first();
          if($shell.length){
            $shell.append($('<div class="mf-media-top-actions"></div>').html(followHtml));
          } else if($menu.length){
            $(followHtml.replace(' mf-media-follow-btn', '')).insertBefore($menu);
          }
        }
      } else {
        $card.find('.mf-friend-btn:not(.mf-publisher-follow)').each(function(){
          this.disabled = false;
          this.setAttribute('data-status', status || 'none');
          this.classList.remove('is-friends', 'is-pending', 'is-accept');
          this.classList.add('primary');
          if(this.classList.contains('mf-media-action-circle')){
            this.innerHTML = '<i class="fa fa-plus" aria-hidden="true"></i>';
          } else {
            this.textContent = '+';
          }
        });
      }
    });
    if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.syncFriendCards === 'function'){
      window.MSBPostCardMenu.syncFriendCards(peerId, status);
    }
  };
  window.applyStatusForPeer = window.mfSyncFriendUiForPeer;

  window.MSBProfileMenuHelpers = {
    esc: esc,
    profileHref: peerProfileHref,
    isPublisher: profileIsPublisherItem,
    isFollowing: profilePublisherFollowingFromItem,
    friendStatus: profileFriendStatusFromItem
  };
  window.MSBFeedMenuHelpers = window.MSBFeedMenuHelpers || window.MSBProfileMenuHelpers;

  $(document).on('click', '#profilePostsFeed .js-mf-media-prev, #profilePostsFeed .js-mf-media-next, #profilePostsFeed .mf-media-dot', function(e){
    e.preventDefault();
    e.stopPropagation();
    var $btn = $(this);
    if(!$btn.hasClass('js-mf-media-prev') && !$btn.hasClass('js-mf-media-next') && !$btn.hasClass('mf-media-dot')){
      $btn = $btn.closest('.js-mf-media-prev, .js-mf-media-next, .mf-media-dot');
    }
    if(!$btn.length) return;

    var $carousel = $btn.closest('.mf-media-carousel');
    if(!$carousel.length){
      $carousel = $btn.closest('.js-media-carousel, .media-stage, .mf-media').find('.mf-media-carousel').first();
    }
    if(!$carousel.length) return;

    var current = Number($carousel.attr('data-index') || 0);
    if(!isFinite(current)) current = 0;
    var wantIndex = current;
    if($btn.hasClass('js-mf-media-prev')) wantIndex = current - 1;
    else if($btn.hasClass('js-mf-media-next')) wantIndex = current + 1;
    else if($btn.hasClass('mf-media-dot')) wantIndex = Number($btn.attr('data-index') || 0);

    var slideCount = $carousel.find('.mf-media-slide, .media-slide').length;
    var needsHydrate = ($carousel.attr('data-pending-hydrate') === '1' || slideCount <= 1)
      && $carousel.find('.mf-media-dot').length > 1;

    if(needsHydrate){
      var $card = $carousel.closest('.mf-card');
      var pid = Number($card.data('id') || $card.attr('data-id') || 0);
      if(pid <= 0) return;
      if($carousel.data('hydrating')) return;
      $carousel.data('hydrating', 1);
      $.getJSON(API_URL, { ajax:'view', id:pid, count_view:0, lite:1, _: Date.now() }, function(res){
        $carousel.data('hydrating', 0);
        if(!res || !res.ok) return;
        // Prefer live DOM counts; only overlay fields present on the view response
        var live = cardCountsFromDom($card);
        var merged = Object.assign({}, live);
        if(res.counts){
          Object.keys(res.counts).forEach(function(k){
            if(res.counts[k] != null) merged[k] = res.counts[k];
          });
          if(Object.prototype.hasOwnProperty.call(res.counts, 'my_reaction')){
            merged.my_reaction = String(res.counts.my_reaction || '');
          }
        }
        mfHydrateCard(pid, res.post || {}, merged, res.attachments || []);
        var $fresh = $card.find('.mf-media-carousel').first();
        if($fresh.length) mfSetCarouselIndex($fresh, wantIndex);
      }).fail(function(){
        $carousel.data('hydrating', 0);
      });
      return;
    }

    mfSetCarouselIndex($carousel, wantIndex);
  });

  $(document).on('click', '.publisher-follow-btn', function(e){
    e.preventDefault();
    var btn = this;
    var id = btn.getAttribute('data-publisher-id') || '';
    if(!id) return;
    var fd = new FormData();
    fd.append('target_id', id);
    fetch('publisher_follow_toggle.php', { method:'POST', body: fd, cache:'no-store' })
      .then(function(r){ return r.json(); })
      .then(function(res){
        if(!res || !res.ok) return;
        applyFollowForPublisher(id, !!res.following);
      });
  });

  $(document).on('click', '#profilePostsFeed .mf-friend-btn:not(.mf-publisher-follow)', function(e){
    var btn = this;
    var peerId = Number(btn.getAttribute('data-peer-id') || 0);
    var status = String(btn.getAttribute('data-status') || 'none');
    if(status === 'friends' || status === 'incoming_pending' || status === 'outgoing_pending'){
      if(status === 'friends') window.location.href = 'contacts.php';
      else window.location.href = 'contact_requests.php';
      return;
    }
    e.preventDefault();
    $.post('ajax/friend_action.php', { action:'send', peer_id: peerId }, function(res){
      if(typeof res === 'string') { try { res = JSON.parse(res); } catch(err){} }
      if(!res) return;
      var next = String(res.status || 'outgoing_pending');
      if(typeof window.msbApplyFriendActionBtnState === 'function'){
        window.msbApplyFriendActionBtnState(btn, next);
        return;
      }
      btn.setAttribute('data-status', next);
      btn.classList.remove('primary','is-friends','is-pending','is-accept');
      if(next === 'friends'){ btn.textContent = 'Friends'; btn.classList.add('is-friends'); }
      else if(next === 'incoming_pending'){ btn.textContent = 'Accept'; btn.classList.add('is-accept'); }
      else if(next === 'outgoing_pending'){ btn.textContent = 'Sent'; btn.classList.add('is-pending'); }
      else { btn.textContent = 'Add Friend'; btn.classList.add('primary'); }
    }, 'json');
  });

  window.profileActionState = window.profileActionState || { postId: 0 };
  window.mfPost = mfPost;

  $(document).on('click', '#profilePostsFeed .mf-comment', function(e){
    e.preventDefault();
    e.stopPropagation();
    var pid = Number($(this).closest('.mf-card').data('id') || 0);
    if(!pid) return;
    openProfileCommentsTray(pid);
  });
  $(document).on('click', '#profilePostsFeed .mf-body .mf-readmore, #profilePostsFeed .js-open-readmore', function(e){
    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();
    var $b = $(this).closest('.mf-body');
    var $card = $(this).closest('.mf-card');
    if(!$b.length || !$card.length) return;
    mfOpenProfileReadMoreDrawer($card, String($b.attr('data-full') || ''));
  });
  $(document).on('click', '#profilePostsFeed .mf-act.mf-love', function(e){
    // MSBReactions opens the picker on press; keep this as a no-op fallback only.
    if(window.MSBReactions) return;
    e.preventDefault();
    e.stopPropagation();
    var $btn = $(this).closest('.mf-act.mf-love');
    var $card = $btn.closest('.mf-card');
    var pid = Number($card.data('id') || 0);
    if(!pid || $btn.data('busy')) return;
    var current = String($card.attr('data-my-reaction') || '');
    var next = current === 'love' ? 'none' : 'love';
    var snap = cardCountsFromDom($card);
    var optimistic = Object.assign({}, snap, {
      my_reaction: next === 'none' ? '' : 'love',
      love_count: Math.max(0, Number(snap.love_count || 0) + (next === 'none' ? -1 : (current === 'love' ? 0 : 1)))
    });
    $btn.data('busy', 1);
    commitEngagement($card, pid, optimistic);
    if(window.MSBPostEngagement) window.MSBPostEngagement.publish(pid, optimistic, { source: 'profile-card' });
    mfPost('react', { post_id: pid, reaction: next }, function(res){
      $btn.data('busy', 0);
      if(!res || !res.ok){
        commitEngagement($card, pid, snap);
        if(window.MSBPostEngagement) window.MSBPostEngagement.publish(pid, snap, { source: 'profile-card' });
        return;
      }
      var merged = Object.assign({}, snap, res.counts || {});
      if(typeof merged.my_reaction === 'undefined' || merged.my_reaction === null){
        merged.my_reaction = next === 'none' ? '' : 'love';
      }
      commitEngagement($card, pid, merged);
      if(window.MSBPostEngagement){
        window.MSBPostEngagement.publish(pid, {
          love_count: merged.love_count,
          my_reaction: merged.my_reaction,
          comment_count: merged.comment_count,
          save_count: merged.save_count,
          share_count: merged.share_count,
          is_saved: merged.is_saved,
          is_shared: merged.is_shared
        }, { source: 'profile-card' });
      }
    });
  });
  if(window.MSBReactions){
    window.MSBReactions.bindLikePicker('#profilePostsFeed .mf-act.mf-love', function(btn, reaction){
      var $btn = $(btn).closest('.mf-act.mf-love');
      var $card = $btn.closest('.mf-card');
      var pid = Number($card.data('id') || 0);
      if(!pid || $btn.data('busy')) return;
      var next = String(reaction || 'none');
      if(next !== 'none' && !window.MSBReactions.normalize(next)) return;
      var snap = cardCountsFromDom($card);
      var prev = String(snap.my_reaction || '');
      if(next === 'none' && !prev) return;
      if(next !== 'none' && prev === next) return;
      var optimistic = Object.assign({}, snap, {
        my_reaction: next === 'none' ? '' : next,
        love_count: Math.max(0, Number(snap.love_count || 0)
          + (prev === 'love' && next !== 'love' ? -1 : 0)
          + (prev !== 'love' && next === 'love' ? 1 : 0))
      });
      $btn.data('busy', 1);
      commitEngagement($card, pid, optimistic);
      try {
        if(window.MSBReactions) window.MSBReactions.applyReactionButton($btn[0], next === 'none' ? '' : next, 'love');
      } catch(err){}
      if(window.MSBPostEngagement) window.MSBPostEngagement.publish(pid, optimistic, { source: 'profile-card' });
      mfPost('react', { post_id: pid, reaction: next }, function(res){
        $btn.data('busy', 0);
        if(!res || !res.ok){
          commitEngagement($card, pid, snap);
          if(window.MSBPostEngagement) window.MSBPostEngagement.publish(pid, snap, { source: 'profile-card' });
          return;
        }
        var merged = Object.assign({}, snap, res.counts || {});
        if(typeof merged.my_reaction === 'undefined' || merged.my_reaction === null){
          merged.my_reaction = next === 'none' ? '' : next;
        }
        commitEngagement($card, pid, merged);
        if(window.MSBPostEngagement){
          window.MSBPostEngagement.publish(pid, {
            love_count: merged.love_count,
            like_count: merged.like_count,
            my_reaction: merged.my_reaction,
            comment_count: merged.comment_count,
            save_count: merged.save_count,
            share_count: merged.share_count,
            is_saved: merged.is_saved,
            is_shared: merged.is_shared
          }, { source: 'profile-card' });
        }
      });
    });
  }
  $(document).on('click', '#profilePostsFeed .mf-act.mf-save', function(e){
    e.preventDefault();
    e.stopPropagation();
    var $btn = $(this).closest('.mf-act.mf-save');
    var $card = $btn.closest('.mf-card');
    var pid = Number($card.data('id') || 0);
    if(!pid || $btn.data('busy')) return;
    var snap = cardCountsFromDom($card);
    var nextSaved = snap.is_saved ? 0 : 1;
    var optimistic = Object.assign({}, snap, {
      is_saved: nextSaved,
      save_count: Math.max(0, Number(snap.save_count || 0) + (nextSaved ? 1 : -1))
    });
    $btn.data('busy', 1);
    commitEngagement($card, pid, optimistic);
    if(window.MSBPostEngagement) window.MSBPostEngagement.publish(pid, optimistic, { source: 'profile-card' });
    mfPost('save', { post_id: pid }, function(res){
      $btn.data('busy', 0);
      if(!res || !res.ok){
        commitEngagement($card, pid, snap);
        if(window.MSBPostEngagement) window.MSBPostEngagement.publish(pid, snap, { source: 'profile-card' });
        return;
      }
      var serverSave = Number(res.save_count != null ? res.save_count : optimistic.save_count);
      var merged = Object.assign({}, snap, {
        save_count: nextSaved ? Math.max(serverSave, optimistic.save_count) : serverSave,
        is_saved: Number((res.state && res.state.saved) != null ? res.state.saved : nextSaved)
      });
      commitEngagement($card, pid, merged);
      if(window.MSBPostEngagement) window.MSBPostEngagement.publishFromTrack(pid, res, { source: 'profile-card' });
      try{
        if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.toast === 'function'){
          window.MSBPostCardMenu.toast(Number(merged.is_saved || 0) === 1
            ? 'Saved to Bookmarks. Find it in Settings → Bookmarks.'
            : 'Removed from Bookmarks.');
        }
      }catch(_eToast){}
    });
  });
  $(document).on('click', '#profilePostsFeed .mf-act.mf-share', function(e){
    e.preventDefault();
    e.stopPropagation();
    var $btn = $(this).closest('.mf-act.mf-share');
    var $card = $btn.closest('.mf-card');
    var pid = Number($card.data('id') || 0);
    if(!pid) return;
    if(window.MSBPostCardMenu && typeof window.MSBPostCardMenu.openShare === 'function'){
      window.MSBPostCardMenu.openShare(pid, $card.get(0));
      return;
    }
    if($btn.data('busy')) return;
    var snap = cardCountsFromDom($card);
    var nextShared = snap.is_shared ? 0 : 1;
    var optimistic = Object.assign({}, snap, {
      is_shared: nextShared,
      share_count: Math.max(0, Number(snap.share_count || 0) + (nextShared ? 1 : -1))
    });
    $btn.data('busy', 1);
    commitEngagement($card, pid, optimistic);
    if(window.MSBPostEngagement) window.MSBPostEngagement.publish(pid, optimistic, { source: 'profile-card' });
    mfPost('share', { post_id: pid }, function(res){
      $btn.data('busy', 0);
      if(!res || !res.ok){
        commitEngagement($card, pid, snap);
        if(window.MSBPostEngagement) window.MSBPostEngagement.publish(pid, snap, { source: 'profile-card' });
        return;
      }
      var serverShare = Number(res.share_count != null ? res.share_count : optimistic.share_count);
      var merged = Object.assign({}, snap, {
        share_count: nextShared ? Math.max(serverShare, optimistic.share_count) : serverShare,
        is_shared: Number((res.state && res.state.shared) != null ? res.state.shared : nextShared)
      });
      commitEngagement($card, pid, merged);
      if(window.MSBPostEngagement) window.MSBPostEngagement.publishFromTrack(pid, res, { source: 'profile-card' });
    });
  });

  window.ProfilePostsFeed = {
    ensureLoaded: function(force){ if(document.body.classList.contains('profile-posts-mode')) loadFeed(!!force); },
    reload: function(){ loaded = false; loadFeed(true); }
  };
  if(window.MSBPostEngagement && typeof window.MSBPostEngagement.registerAdapter === 'function'){
    window.MSBPostEngagement.registerAdapter(function(postId, patch){
      postId = Number(postId || 0);
      if(!postId || !patch) return;
      var $card = $('#profilePostsFeed .mf-card[data-id="'+postId+'"]');
      if(!$card.length) return;
      var engageAt = Number($card.attr('data-engage-at') || 0);
      if(engageAt && (Date.now() - engageAt) < 8000 && profileEngagementById[postId]){
        applyCounts($card, profileEngagementById[postId]);
        return;
      }
      if(profileEngagementById[postId]){
        rememberEngagement(postId, patch);
      }
    });
  }
  var ppResizeTimer = null;
  window.addEventListener('resize', function(){
    if(!document.body.classList.contains('profile-posts-mode')) return;
    if(ppResizeTimer) clearTimeout(ppResizeTimer);
    ppResizeTimer = setTimeout(function(){
      document.querySelectorAll('#profilePostsFeed .mf-card.is-single-video-post, #profilePostsFeed .mf-card.is-single-image-post').forEach(function(card){
        try{
          var media = card.querySelector('.media-stage.standard-video-stage, .media-stage.standard-image-stage');
          if(!media) return;
          var dims = getDeviceDimensions(card);
          if(!dims || !dims.w || !dims.h){
            var el = media.querySelector(':scope > video, :scope > img');
            if(el){
              if(String(el.tagName || '').toUpperCase() === 'VIDEO'){
                dims = { w: Number(el.videoWidth || 0), h: Number(el.videoHeight || 0) };
              }else{
                dims = { w: Number(el.naturalWidth || 0), h: Number(el.naturalHeight || 0) };
              }
            }
          }
          if(dims && dims.w && dims.h) applyPublicMediaCardWidth(card, dims.w, dims.h);
        }catch(err){}
      });
      document.querySelectorAll('#profilePostsFeed .mf-card.mf-card-phone-shot.mf-card-text-only').forEach(function(card){
        var dims = getDeviceDimensions(card);
        if(dims && dims.w && dims.h) applyPublicMediaCardWidth(card, dims.w, dims.h);
      });
    }, 150);
  });
  $(function(){
    if(document.body.classList.contains('profile-posts-mode')) loadFeed(false);
  });
})(window.jQuery);
</script>

<div class="modal fade confirm-sheet" id="profileDeleteConfirmModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-body">
        <div class="confirm-title">Delete this post?</div>
        <p class="confirm-copy">This will remove your post from your profile.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="profileConfirmDeleteBtn">OK</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/stories_right_door.php'; ?>

<script>
(function(){
  var catalog = <?php echo json_encode($profileStoryCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  var openStoryPostId = <?php echo (int)$profileStoryPostId; ?>;

  if(window.TTStories && typeof window.TTStories.setCatalog === 'function'){
    window.TTStories.setCatalog(Array.isArray(catalog) ? catalog : []);
  }

  function openStoryByPostId(postId){
    postId = Number(postId || 0);
    if(!postId || !window.TTStories) return false;
    if(typeof window.TTStories.openByKey === 'function'){
      if(window.TTStories.openByKey('s' + postId)) return true;
    }
    if(!Array.isArray(catalog)) return false;
    for(var i = 0; i < catalog.length; i += 1){
      var slides = catalog[i] && Array.isArray(catalog[i].slides) ? catalog[i].slides : [];
      for(var j = 0; j < slides.length; j += 1){
        if(Number(slides[j].postId || 0) === postId){
          var key = String(catalog[i].key || '');
          if(key && window.TTStories.openByKey(key)) return true;
          if(typeof window.TTStories.openByIndex === 'function'){
            window.TTStories.openByIndex(i);
            return true;
          }
        }
      }
    }
    return false;
  }

  document.addEventListener('click', function(e){
    var target = e.target;
    if(!target || !target.closest) return;
    var item = target.closest('.ig-story-item[data-story-key]');
    if(!item) return;
    if(item.classList.contains('ig-story-create')) return;
    e.preventDefault();
    var key = String(item.getAttribute('data-story-key') || '');
    if(!key || !window.TTStories) return;
    window.TTStories.openByKey(key);
  });

  document.addEventListener('mousedown', function(e){
    var target = e.target;
    if(!target || !target.closest) return;
    var storiesWrap = document.getElementById('tt-stories-wrap');
    if(!storiesWrap || !storiesWrap.classList.contains('is-open')) return;
    // Keep story open while using fries menu / Archive·Delete confirm popups.
    if(target.closest(
      '#tt-stories-wrap, .ig-story-item[data-story-key],' +
      '.pcm-menu-portal, #ttStoriesMenuWrap, #ttStoriesMenu, .tt-stories-menu-wrap,' +
      '#pcmArchiveConfirmDialog, #pcmDeleteConfirmDialog, #pcmShareSheet, #profileDeleteConfirmModal,' +
      'dialog.pcm-delete-dialog, dialog.pcm-archive-dialog, dialog.pcm-share-dialog, dialog.pcm-tag-dialog'
    )) return;
    if(window.TTStories && typeof window.TTStories.close === 'function'){
      window.TTStories.close();
    }
  });

  if(openStoryPostId > 0){
    var tries = 0;
    (function tryOpen(){
      tries += 1;
      if(openStoryByPostId(openStoryPostId)){
        try{
          var url = new URL(window.location.href);
          url.searchParams.delete('story_post');
          url.searchParams.delete('fresh');
          window.history.replaceState({}, '', url.toString());
        }catch(_e){}
        return;
      }
      if(tries < 20) setTimeout(tryOpen, 120);
    })();
  }
})();
</script>

<script>
(function(){
  function syncProfileHeaderDivider(){
    var head = document.querySelector('body.profile-page .ig-profile-head');
    if(!head) return;
    var scroll = document.querySelector('body.profile-page .ig-profile-scroll');
    var card = document.querySelector('body.profile-page #profilePostsFeed > .mf-card');
    var headRect = head.getBoundingClientRect();
    document.documentElement.style.setProperty('--profile-header-divider-top', Math.round(headRect.bottom) + 'px');
    if(scroll && card){
      var scrollRect = scroll.getBoundingClientRect();
      document.querySelectorAll('body.profile-page #profilePostsFeed > .mf-card').forEach(function(postCard){
        var cardRect = postCard.getBoundingClientRect();
        var leftOffset = Math.max(0, Math.round(cardRect.left - scrollRect.left));
        var rightOffset = Math.max(0, Math.round(scrollRect.right - cardRect.right));
        postCard.style.setProperty('--post-divider-left-offset', leftOffset + 'px');
        postCard.style.setProperty('--post-divider-right-offset', rightOffset + 'px');
      });
    }
  }
  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', syncProfileHeaderDivider);
  } else {
    syncProfileHeaderDivider();
  }
  window.addEventListener('load', syncProfileHeaderDivider);
  window.addEventListener('resize', syncProfileHeaderDivider);
  window.addEventListener('orientationchange', function(){ setTimeout(syncProfileHeaderDivider, 120); });
  if(window.ResizeObserver){
    try {
      var head = document.querySelector('body.profile-page .ig-profile-head');
      if(head) new ResizeObserver(syncProfileHeaderDivider).observe(head);
      var scroll = document.querySelector('body.profile-page .ig-profile-scroll');
      if(scroll) new ResizeObserver(syncProfileHeaderDivider).observe(scroll);
      var feed = document.querySelector('body.profile-page #profilePostsFeed');
      if(feed) new ResizeObserver(syncProfileHeaderDivider).observe(feed);
    } catch(e) {}
  }
  try { requestAnimationFrame(syncProfileHeaderDivider); } catch(e) {}
})();
</script>

<?php theme_prefs_print_post_card_tail($dbh, $meId); ?>
<?php
$__profilePaletteMode = theme_prefs_appearance_mode($dbh, $meId);
if (appearance_bridge_is_named_palette($__profilePaletteMode)) {
    appearance_bridge_print_profile_palette_tail($__profilePaletteMode);
}
?>

<style id="profile-media-head-overlay-tail-css">
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-peer-link,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-meta,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media a:hover,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-peer-link:hover,
html[data-msb-appearance] body #profilePostsFeed .mf-card:has(.mf-head--on-media) .mf-media-shell,
html[data-msb-appearance] body #profilePostsFeed .mf-card:has(.mf-head--on-media) .mf-media-shell .media-stage,
html[data-msb-appearance] body #profilePostsFeed .mf-card:has(.mf-head--on-media) .mf-media-shell .mf-media,
html[data-msb-appearance] body.dark-auto #profilePostsFeed .mf-card:has(.mf-head--on-media) .mf-media-shell,
html[data-msb-appearance] body.dark-auto #profilePostsFeed .mf-card:has(.mf-head--on-media) .mf-media-shell .media-stage,
html[data-msb-appearance] body.dark-auto #profilePostsFeed .mf-card:has(.mf-head--on-media) .mf-media-shell .mf-media{
  background:transparent!important;
  background-color:transparent!important;
  background-image:none!important;
  border-color:transparent!important;
  box-shadow:none!important;
}
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-name,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-time,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-dot,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-menu-btn:not(.post-card-menu-btn),
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-menu-btn:not(.post-card-menu-btn) i,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .post-card-menu-btn,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .post-card-fries-icon,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media a:hover .mf-name,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media a:hover .mf-time{
  color:#fff!important;
  text-shadow:0 2px 10px rgba(0,0,0,.34);
}
html[data-msb-appearance] body #profilePostsFeed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-head--on-media,
html[data-msb-appearance] body #profilePostsFeed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-head--on-media{
  padding:22px 14px 12px !important;
  top:auto !important;
}
html[data-msb-appearance] body #profilePostsFeed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-head--on-media > .post-card-menu-wrap,
html[data-msb-appearance] body #profilePostsFeed .mf-card[data-is-publisher="1"] .mf-media-shell > .mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap,
html[data-msb-appearance] body #profilePostsFeed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-head--on-media > .post-card-menu-wrap,
html[data-msb-appearance] body #profilePostsFeed .mf-card[data-account-kind="publisher"] .mf-media-shell > .mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap{
  margin-right:0 !important;
}
html[data-msb-appearance] body #profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn{
  background:var(--msb-palette-surface-2)!important;
  border-color:var(--msb-palette-border)!important;
  color:var(--msb-palette-text)!important;
  box-shadow:0 2px 10px rgba(15,23,42,.12)!important;
}
html[data-msb-appearance] body #profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn .post-card-fries-icon{
  color:var(--msb-palette-text)!important;
}
html[data-msb-appearance] body.dark-auto #profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn,
html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn{
  /* background:var(--msb-palette-surface-2, #1d2530)!important; */
  border-color:var(--msb-palette-border, rgba(255,255,255,.12))!important;
  color:var(--msb-palette-text, #e6edf3)!important;
}
html[data-msb-appearance] body.profile-page .gear-nav-section-toggle,
html[data-msb-appearance] body.profile-page .gear-nav-section-toggle:hover,
html[data-msb-appearance] body.profile-page .gear-nav-section-toggle:focus,
html[data-msb-appearance] body.profile-page .gear-nav-section-icon,
html[data-msb-appearance] body.profile-page .gear-nav-section.is-open .gear-nav-items,
html[data-msb-appearance] body.profile-page .gear-nav-item,
html[data-msb-appearance] body.profile-page .gear-nav-section.is-open .gear-nav-items > .gear-nav-item,
html[data-msb-appearance] body.profile-page .gear-nav-item:hover,
html[data-msb-appearance] body.profile-page .gear-nav-item:focus,
html[data-msb-appearance] body.profile-page .gear-nav-item.is-active,
html[data-msb-appearance] body.profile-page .gear-detail-icon,
html[data-msb-appearance] body.profile-page .gear-chip,
html[data-msb-appearance] body.profile-page .gear-tag,
html[data-theme="dark"] body.profile-page .gear-nav-section-toggle,
html[data-theme="dark"] body.profile-page .gear-nav-section-toggle:hover,
html[data-theme="dark"] body.profile-page .gear-nav-section-toggle:focus,
html[data-theme="dark"] body.profile-page .gear-nav-section-icon,
html[data-theme="dark"] body.profile-page .gear-nav-section.is-open .gear-nav-items,
html[data-theme="dark"] body.profile-page .gear-nav-item,
html[data-theme="dark"] body.profile-page .gear-nav-section.is-open .gear-nav-items > .gear-nav-item,
html[data-theme="dark"] body.profile-page .gear-nav-item:hover,
html[data-theme="dark"] body.profile-page .gear-nav-item:focus,
html[data-theme="dark"] body.profile-page .gear-nav-item.is-active,
html[data-theme="dark"] body.profile-page .gear-detail-icon,
html[data-theme="dark"] body.profile-page .gear-chip,
html[data-theme="dark"] body.profile-page .gear-tag{
  background:var(--msb-palette-bg, #171d24)!important;
  background-color:var(--msb-palette-bg, #171d24)!important;
  background-image:none!important;
  border-color:var(--msb-palette-border, rgba(255,255,255,.12))!important;
  box-shadow:none!important;
}
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page{
  --msb-palette-action:#0b1220;
  --msb-palette-action-strong:#000000;
  --msb-palette-link:#0b1220;
  --msb-palette-link-hover:#000000;
  --msb-palette-nav-active-text:#0b1220;
  --msb-palette-btn-bg:#0b1220;
  --msb-palette-btn-hover-bg:#000000;
  --msb-palette-btn-text:#ffffff;
}
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .ig-btn,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .ig-btn i,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .ig-tab.active,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .ig-tab.active i,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .ig-gallery-search button,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .gear-nav-section-icon,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .gear-detail-icon,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .gear-chip,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .gear-tag,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .gear-detail-open-btn,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .gear-upload-btn,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page #profilePostsFeed .mf-body .mf-readmore,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page #profilePostsFeed .mf-act,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page #profilePostsFeed .mf-act i,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page #profilePostsFeed .mf-act .mf-num,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page #profilePostsFeed .mf-act.is-love,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page #profilePostsFeed .mf-act.is-love i,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page #profilePostsFeed .mf-act.is-love .mf-num,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page #profilePostsFeed .mf-act.is-save i,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page #profilePostsFeed .mf-act.is-share i,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .pv-readmore,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .pv-com .m .link,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .pv-com .m .replies-toggle,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .pv-com .m .likebtn.is-liked,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .pv-act.is-like i{
  color:#0b1220!important;
  -webkit-text-fill-color:#0b1220!important;
}
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .ig-gallery-search button,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .gear-detail-open-btn,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .gear-upload-btn{
  background:#0b1220!important;
  border-color:#0b1220!important;
  color:#ffffff!important;
  -webkit-text-fill-color:#ffffff!important;
}
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page #pvAtBtn{
  background:linear-gradient(180deg, #ff2e89 0%, #c11353 100%)!important;
  border-color:transparent!important;
  color:#ffffff!important;
  -webkit-text-fill-color:#ffffff!important;
}
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .pv-send{
  background:#7c1730!important;
  border-color:transparent!important;
  color:#ffffff!important;
  -webkit-text-fill-color:#ffffff!important;
}
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .pv-iconbtn:not(#pvAtBtn){
  background:var(--msb-palette-hover-bg, #f2f4f7)!important;
  border-color:transparent!important;
  color:#0b1220!important;
  -webkit-text-fill-color:#0b1220!important;
}
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .mf-media-shell > .mf-head--on-media,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .mf-media-shell > .mf-head--on-media .mf-peer-link,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .mf-media-shell > .mf-head--on-media .mf-name,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .mf-media-shell > .mf-head--on-media .mf-time,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .mf-media-shell > .mf-head--on-media .mf-dot,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .mf-media-shell > .mf-head--on-media .post-card-menu-btn,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .mf-media-shell > .mf-head--on-media .post-card-fries-icon{
  color:#ffffff!important;
  -webkit-text-fill-color:#ffffff!important;
}
</style>

<style id="profile-posts-read-more-bold">
body.profile-page #profilePostsFeed .mf-body .mf-readmore,
body.profile-page #profilePostsFeed .js-open-readmore{
  font-weight:800 !important;
}
</style>

<style id="profile-posts-fries-no-circle">
body.profile-page #profilePostsFeed .post-card-menu-btn,
body.profile-page #profilePostsFeed .post-card-menu-btn:hover,
body.profile-page #profilePostsFeed .post-card-menu-btn:focus,
html[data-msb-appearance] body.profile-page #profilePostsFeed .post-card-menu-btn,
html[data-theme="dark"] body.profile-page #profilePostsFeed .post-card-menu-btn{
  width:auto !important;
  height:auto !important;
  min-width:var(--pcm-menu-btn-size, 28px) !important;
  min-height:var(--pcm-menu-btn-size, 28px) !important;
  padding:6px 4px !important;
  flex:0 0 auto !important;
  background:transparent !important;
  background-color:transparent !important;
  background-image:none !important;
  border:0 !important;
  border-color:transparent !important;
  border-radius:0 !important;
  box-shadow:none !important;
  outline:none !important;
  color:var(--msb-palette-text, #aab4c5) !important;
  line-height:1 !important;
  opacity:1 !important;
}
body.profile-page #profilePostsFeed .post-card-menu-btn .post-card-fries-icon,
body.profile-page #profilePostsFeed .post-card-menu-btn .pcm-fries-icon,
body.profile-page #profilePostsFeed .post-card-menu-btn i{
  font-size:16px !important;
  line-height:1 !important;
  color:inherit !important;
  -webkit-text-fill-color:currentColor !important;
  text-shadow:none !important;
}
body.profile-page #profilePostsFeed .post-card-menu-btn:hover,
body.profile-page #profilePostsFeed .post-card-menu-btn:focus{
  opacity:.72 !important;
}
</style>

<style id="profile-posts-display-name-contrast">
body.profile-page #profilePostsFeed .mf-head:not(.mf-head--on-media) .mf-peer-link,
body.profile-page #profilePostsFeed .mf-head:not(.mf-head--on-media) .mf-name,
body.profile-page #profilePostsFeed .mf-head:not(.mf-head--on-media) .mf-peer-link:hover .mf-name,
html[data-msb-appearance] body.profile-page #profilePostsFeed .mf-head:not(.mf-head--on-media) .mf-peer-link,
html[data-msb-appearance] body.profile-page #profilePostsFeed .mf-head:not(.mf-head--on-media) .mf-name,
html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-head:not(.mf-head--on-media) .mf-peer-link,
html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-head:not(.mf-head--on-media) .mf-name,
html.dark-auto body.profile-page #profilePostsFeed .mf-head:not(.mf-head--on-media) .mf-peer-link,
html.dark-auto body.profile-page #profilePostsFeed .mf-head:not(.mf-head--on-media) .mf-name{
  color:var(--msb-palette-text, #e6edf3) !important;
  -webkit-text-fill-color:var(--msb-palette-text, #e6edf3) !important;
  opacity:1 !important;
}
</style>

<style id="profile-posts-mobile-tablet-actions-width">
/* Match feed.php media-head-outside: full-width stacked caption + media (no side-by-side). */
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside{
  display:flex !important;
  flex-direction:column !important;
  align-items:stretch !important;
}
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-head,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-title,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-body,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-slide-copy,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-slide-title,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-slide-summary,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-media-shell,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-media,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .media-stage,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-actions{
  position:relative !important;
  left:auto !important;
  right:auto !important;
  top:auto !important;
  float:none !important;
  clear:both !important;
  display:block !important;
  width:100% !important;
  max-width:100% !important;
  margin-left:0 !important;
  margin-right:0 !important;
  transform:none !important;
  box-sizing:border-box !important;
}
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-head{
  display:flex !important;
  align-items:center !important;
  justify-content:space-between !important;
  padding:1px 0 12px !important;
}
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-title,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-body,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-slide-copy,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-slide-title,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-slide-summary{
  padding-left:0 !important;
  padding-right:0 !important;
}
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-actions{
  display:flex !important;
  align-items:center !important;
  justify-content:space-between !important;
}
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-media-shell{
  width:100% !important;
  max-width:100% !important;
}
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside .mf-media,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside .media-stage{
  width:100% !important;
  max-width:100% !important;
}
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside .mf-media-carousel,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside .media-carousel,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside .mf-media-slides,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside .media-slides{
  width:100% !important;
  max-width:100% !important;
}
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside .mf-media-slide > img,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside .media-slide > img,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside .mf-media-slide > video,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside .media-slide > video{
  width:100% !important;
  max-width:100% !important;
  height:auto !important;
  object-fit:contain !important;
  object-position:center center !important;
  margin-left:auto !important;
  margin-right:auto !important;
}
</style>

<style id="profile-post-visible-media-left-edge">
/* Keep Posts-tab media full-width under captions (match feed.php). */
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .media-stage,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-media,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-media-shell,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-media-shell > .media-stage,
body.profile-page #profilePostsFeed > .mf-card.mf-card-media-head-outside > .mf-media-shell > .mf-media{
  margin-left:0 !important;
  margin-right:0 !important;
  margin-inline:0 !important;
  transform:none !important;
}
</style>

<script id="profile-post-visible-media-left-align">
(function(){
  'use strict';
  /* Disabled: translating the media stage sideways broke slide caption stacking
     on the Posts tab (text left / media right). Feed/public already look correct. */
})();
</script>

<style id="profile-tabs-for-you-underline">
/* Exact feed "For You" active indicator on profile section tabs */
html body.profile-page .ig-tabs{
  align-items:stretch;
  gap:8px;
  padding:4px 10px 0;
}
html body.profile-page .ig-tab{
  position:relative!important;
  background:transparent!important;
  background-color:transparent!important;
  border:0!important;
  border-radius:0!important;
  box-shadow:none!important;
  padding:12px 12px 14px!important;
  font-weight:400!important;
}
html body.profile-page .ig-tab.active,
html[data-msb-appearance] body.profile-page .ig-tab.active,
html.msb-palette-active body.profile-page .ig-tab.active,
html.dark-auto body.profile-page .ig-tab.active,
html[data-theme="dark"] body.profile-page .ig-tab.active,
html:not([data-theme="dark"]):not(.dark-auto) body.profile-page .ig-tab.active{
  background:transparent!important;
  background-color:transparent!important;
  border:0!important;
  border-top-color:transparent!important;
  border-bottom-color:transparent!important;
  border-radius:0!important;
  box-shadow:none!important;
}
html.dark-auto body.profile-page .ig-tab.active,
html[data-theme="dark"] body.profile-page .ig-tab.active,
html.dark-auto body.profile-page .ig-tab.active i,
html[data-theme="dark"] body.profile-page .ig-tab.active i{
  color:#ffffff!important;
  -webkit-text-fill-color:#ffffff!important;
}
html body.profile-page .ig-tab.active::after{
  content:""!important;
  position:absolute!important;
  left:50%!important;
  right:auto!important;
  bottom:0!important;
  top:auto!important;
  width:68px!important;
  max-width:78%!important;
  height:4px!important;
  margin:0!important;
  border:0!important;
  border-radius:999px!important;
  background:var(--msb-palette-text, #0b1220)!important;
  box-shadow:none!important;
  transform:translateX(-50%)!important;
  pointer-events:none!important;
  display:block!important;
}
html.dark-auto body.profile-page .ig-tab.active::after,
html[data-theme="dark"] body.profile-page .ig-tab.active::after,
html[data-msb-appearance] body.profile-page .ig-tab.active::after{
  background:var(--msb-palette-text, #f3f6fb)!important;
}
/* Tags tab + Read more: solid palette text (no washed light blue) */
html body.profile-page .ig-tab[data-panel="tags"],
html body.profile-page .ig-tab[data-panel="tags"] i,
html[data-msb-appearance] body.profile-page .ig-tab[data-panel="tags"],
html[data-msb-appearance] body.profile-page .ig-tab[data-panel="tags"] i,
html.dark-auto body.profile-page .ig-tab[data-panel="tags"],
html.dark-auto body.profile-page .ig-tab[data-panel="tags"] i,
html[data-theme="dark"] body.profile-page .ig-tab[data-panel="tags"],
html[data-theme="dark"] body.profile-page .ig-tab[data-panel="tags"] i{
  color:var(--msb-palette-text-muted, #667085)!important;
  -webkit-text-fill-color:var(--msb-palette-text-muted, #667085)!important;
}
html body.profile-page .ig-tab[data-panel="tags"].active,
html body.profile-page .ig-tab[data-panel="tags"].active i,
html[data-msb-appearance] body.profile-page .ig-tab[data-panel="tags"].active,
html[data-msb-appearance] body.profile-page .ig-tab[data-panel="tags"].active i,
html.dark-auto body.profile-page .ig-tab[data-panel="tags"].active,
html.dark-auto body.profile-page .ig-tab[data-panel="tags"].active i,
html[data-theme="dark"] body.profile-page .ig-tab[data-panel="tags"].active,
html[data-theme="dark"] body.profile-page .ig-tab[data-panel="tags"].active i{
  color:var(--msb-palette-text, #0b1220)!important;
  -webkit-text-fill-color:var(--msb-palette-text, #0b1220)!important;
}
html.dark-auto body.profile-page .ig-tab[data-panel="tags"].active,
html.dark-auto body.profile-page .ig-tab[data-panel="tags"].active i,
html[data-theme="dark"] body.profile-page .ig-tab[data-panel="tags"].active,
html[data-theme="dark"] body.profile-page .ig-tab[data-panel="tags"].active i{
  color:var(--msb-palette-text, #ffffff)!important;
  -webkit-text-fill-color:var(--msb-palette-text, #ffffff)!important;
}
html body.profile-page #profilePostsFeed .mf-body .mf-readmore,
html body.profile-page #profilePostsFeed .js-open-readmore,
html body.profile-page .pv-readmore,
html body.profile-page a.msb-mention,
html[data-msb-appearance] body.profile-page #profilePostsFeed .mf-body .mf-readmore,
html[data-msb-appearance] body.profile-page #profilePostsFeed .js-open-readmore,
html[data-msb-appearance] body.profile-page .pv-readmore,
html[data-msb-appearance] body.profile-page a.msb-mention,
html.dark-auto body.profile-page #profilePostsFeed .mf-body .mf-readmore,
html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-body .mf-readmore,
html.dark-auto body.profile-page .pv-readmore,
html[data-theme="dark"] body.profile-page .pv-readmore,
html.dark-auto body.profile-page a.msb-mention,
html[data-theme="dark"] body.profile-page a.msb-mention{
  color:var(--msb-palette-text, #0b1220)!important;
  -webkit-text-fill-color:var(--msb-palette-text, #0b1220)!important;
}
html.dark-auto body.profile-page #profilePostsFeed .mf-body .mf-readmore,
html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-body .mf-readmore,
html.dark-auto body.profile-page .pv-readmore,
html[data-theme="dark"] body.profile-page .pv-readmore,
html.dark-auto body.profile-page a.msb-mention,
html[data-theme="dark"] body.profile-page a.msb-mention{
  color:var(--msb-palette-text, #f3f6fb)!important;
  -webkit-text-fill-color:var(--msb-palette-text, #f3f6fb)!important;
}
</style>

<?php post_card_actions_menu_render_modals(); ?>
<?php post_card_actions_menu_render_js([
  'delete_mode' => 'feed',
  'api_url' => 'feed_api.php',
  'staff_readonly' => false,
  'menu_surface' => 'profile',
  'always_portal' => true,
]); ?>
<script id="profile-post-menu-outside-card-position">
(function(){
  'use strict';

  // Keep the Posts-tab fries menu next to the button (same as feed).
  // Previously this forced the portal to feedRect.right + 12, which put
  // Edit/Delete far into the side gutter so delete felt broken.
  function placeProfilePostMenuNearButton(){
    var menu = document.querySelector('body.profile-page > .pcm-menu-portal.open');
    var button = document.querySelector('body.profile-page #profilePostsFeed .post-card-menu-wrap.pcm-wrap-open .post-card-menu-btn');
    if(!menu || !button) return;

    var buttonRect = button.getBoundingClientRect();
    var menuWidth = menu.offsetWidth || 220;
    var menuHeight = menu.offsetHeight || 275;
    var viewportWidth = Math.max(document.documentElement.clientWidth, window.innerWidth || 0);
    var viewportHeight = Math.max(document.documentElement.clientHeight, window.innerHeight || 0);

    var left = buttonRect.right - menuWidth;
    if(left < 12) left = 12;
    if(left + menuWidth > viewportWidth - 12) left = Math.max(12, viewportWidth - menuWidth - 12);

    var top = buttonRect.bottom + 8;
    if(top + menuHeight > viewportHeight - 12){
      top = Math.max(12, buttonRect.top - menuHeight - 8);
    }

    menu.style.setProperty('position', 'fixed', 'important');
    menu.style.setProperty('left', Math.round(left) + 'px', 'important');
    menu.style.setProperty('right', 'auto', 'important');
    menu.style.setProperty('top', Math.round(top) + 'px', 'important');
    menu.style.setProperty('z-index', '100000', 'important');
  }

  function bumpProfilePostsCount(delta){
    delta = Number(delta || 0);
    if(!delta) return;
    var stat = document.querySelector('[data-profile-stat="posts"] b');
    if(!stat) return;
    var n = parseInt(String(stat.textContent || '0').replace(/[^\d-]/g, ''), 10);
    if(!isFinite(n)) n = 0;
    n = Math.max(0, n + delta);
    stat.textContent = String(n);
  }

  window.MSBProfileAfterPostDeleted = function(postId){
    postId = Number(postId || 0);
    if(!postId) return;
    window.__msbProfileDeletedIds = window.__msbProfileDeletedIds || {};
    if(window.__msbProfileDeletedIds[postId]) return;
    window.__msbProfileDeletedIds[postId] = 1;
    bumpProfilePostsCount(-1);
    var feed = document.getElementById('profilePostsFeed');
    if(feed && !feed.querySelector('.mf-card')){
      feed.innerHTML = '<div class="mf-feed-empty">No posts yet.</div>';
    }
    // Keep gallery/tag grids in sync when deleting from Posts tab.
    try{
      document.querySelectorAll(
        '.ig-item[data-post-id="'+String(postId)+'"],' +
        '.ig-item[data-id="'+String(postId)+'"]'
      ).forEach(function(el){ el.remove(); });
    }catch(eGrid){}
  };

  document.addEventListener('click', function(){
    window.requestAnimationFrame(placeProfilePostMenuNearButton);
  }, true);
  window.addEventListener('resize', placeProfilePostMenuNearButton, {passive:true});
  window.addEventListener('scroll', placeProfilePostMenuNearButton, {passive:true});

  if(window.MutationObserver){
    new MutationObserver(function(records){
      var addedPortal = records.some(function(record){
        return Array.prototype.some.call(record.addedNodes || [], function(node){
          return node && node.nodeType === 1 && node.classList && node.classList.contains('pcm-menu-portal');
        });
      });
      if(addedPortal) window.requestAnimationFrame(placeProfilePostMenuNearButton);
    }).observe(document.body, {childList:true});
  }
})();
</script>

</body>
</html>
