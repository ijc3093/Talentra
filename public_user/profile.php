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
require_once __DIR__ . '/includes/post_action_thin_icons.php';
require_once __DIR__ . '/includes/appearance_palettes.php';
$controller = new Controller();
$dbh = $controller->pdo();
ensurePostCategorySchema($dbh);
publisher_ensure_schema($dbh);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$sessionOwnerId = profile_session_owner_user_id();
$meId = $sessionOwnerId > 0 ? $sessionOwnerId : (int)($_SESSION['user_id'] ?? 0);
$viewId = $meId;
$profileAlertPostId = (int)($_GET['open_post'] ?? 0);
$profileAlertCommentId = (int)($_GET['open_comment'] ?? 0);

$reqId = (int)($_GET['id'] ?? 0);
$reqUsername = trim((string)($_GET['username'] ?? ''));
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
];

if (!function_exists('profileNormalizeUserRow')) {
  function profileNormalizeUserRow(array $row): array {
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
    $mediaKind = trim((string)($row['media_kind'] ?? ''));
    $hasControl = (!$isLink && ($field !== '' || $fieldLocal !== '') && (!empty($options) || !empty($optionGroups)));
    $hasUpload = (!$isLink && in_array($mediaKind, ['avatar', 'cover'], true));
    $currentValue = profile_gear_row_value($row, $profileSettings, $themeAutoDefault);
    $tag = trim((string)($row['tag'] ?? ''));

    if ($hasControl): ?>
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

$statLoveCount = 0;
try {
  $st = $dbh->prepare("
    SELECT COUNT(*)
    FROM public_post_reactions r
    INNER JOIN public_posts p ON p.id = r.post_id
    WHERE p.user_id = :uid
      AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
      AND r.reaction = 'love'
  ");
  $st->execute([':uid' => $viewId]);
  $statLoveCount = (int)$st->fetchColumn();
} catch (Throwable $e) {}

$statFriends = 0;
try {
  $statFriends = fs_friend_count($dbh, $viewId);
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
require_once __DIR__ . '/includes/platform_rent.php';
require_once __DIR__ . '/includes/org_shop.php';
$profileShopOrgId = $profileIsPublisher ? org_shop_org_id_for_publisher($dbh, $viewId) : 0;
$profileShopVisible = $profileShopOrgId > 0 && platform_rent_shop_is_visible($dbh, $profileShopOrgId);
$profileShopProducts = $profileShopVisible ? org_shop_list_products($dbh, $profileShopOrgId, true) : [];
require_once __DIR__ . '/includes/stripe_shop.php';
$profileShopStripeEnabled = stripe_shop_is_configured();
$statSocialCount = $profileIsPublisher ? publisher_follower_count($dbh, $viewId) : $statFriends;
$statSocialLabel = $profileIsPublisher ? publisher_social_stat_label($statSocialCount) : 'friends';

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function profile_love_btn_html(int $profileUserId = 0, int $loveCount = 0): string
{
  if ($profileUserId <= 0) {
    return '';
  }
  return '<span class="ig-profile-love-wrap" role="group" aria-label="Total loves">'
    . '<span class="ig-profile-love-count"><b>' . (int)$loveCount . '</b></span>'
    . '<button type="button" class="ig-profile-love-btn" data-profile-id="' . (int)$profileUserId . '" title="Love" aria-label="Love" aria-pressed="false">'
    . '<i class="fa fa-heart-o" aria-hidden="true"></i></button>'
    . '</span>';
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
  function sentence_snippet(string $text, int $maxSentences = 5, int $maxChars = 160): string {
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
$profileLoveBtnHtml = profile_love_btn_html($viewId, $statLoveCount);
$profileLoveAfterFollow = $profileLoveBtnHtml !== '' && $profileIsPublisher && !$isOwnProfile && $isViewedPublisher && $canFollowPublishers;
$profileLoveInStats = $profileLoveBtnHtml !== '' && !$profileLoveAfterFollow;
// Publisher email, phone, and friend code are visible only to the account owner (not staff or other viewers).
$canViewProfilePrivateContact = !$profileIsPublisher || $canManageProfilePrivate;
$fromLivePublic = (string)($_GET['from_live_public'] ?? '') === '1';
$restrictedLiveView = (string)($_GET['restricted_live_view'] ?? '') === '1';
$liveVisitorMode = (!$isOwnProfile && $fromLivePublic && $restrictedLiveView && $friendStatus !== 'friends');
$selectedTab = strtolower(trim((string)($_GET['tab'] ?? 'posts')));
if ($selectedTab === 'tagged') {
  $selectedTab = 'tags';
}
$profileContentTabs = ['gallery', 'posts', 'tags', 'about', 'preserve', 'gear'];
if ($profileIsPublisher && $profileShopOrgId > 0) {
  $profileContentTabs[] = 'shop';
}
if (!in_array($selectedTab, $profileContentTabs, true)) {
  $selectedTab = 'posts';
}
if ($liveVisitorMode && !in_array($selectedTab, ['gallery', 'posts', 'tags', 'about', 'shop'], true)) {
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
$themeAutoDefault = ($storedAppearanceMode === 'system') ? '1' : '0';
$manualAppearanceDefault = ($storedAppearanceMode === 'system') ? 'dark' : $storedAppearanceMode;

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
      ['label' => 'Auto-show posts in timeline', 'meta' => 'Yes / No control for moving Dashboard posts into the life timeline automatically.', 'icon' => 'ion-ios-albums', 'tag' => 'Live', 'field' => 'auto_show_timeline', 'options' => $yesNoOptions],
      ['label' => 'Allow old memories to resurface', 'meta' => 'Bring older moments back later as meaningful memories.', 'icon' => 'ion-refresh', 'tag' => 'Live', 'field' => 'resurface_old_memories', 'options' => $yesNoOptions],
      ['label' => 'Show reactions in timeline', 'meta' => 'Decide whether likes and love appear on your life timeline.', 'icon' => 'ion-heart', 'tag' => 'Live', 'field' => 'show_timeline_reactions', 'options' => $yesNoOptions],
      ['label' => 'Show comments in timeline', 'meta' => 'Choose whether comments travel with the timeline story.', 'icon' => 'ion-chatbubble-working', 'tag' => 'Live', 'field' => 'show_timeline_comments', 'options' => $yesNoOptions],
      ['label' => 'Archive memory', 'meta' => 'Hide older moments without deleting them.', 'icon' => 'ion-filing', 'tag' => 'Live', 'field' => 'archive_memory_enabled', 'options' => $yesNoOptions],
      ['label' => 'Pin important memory', 'meta' => 'Keep your most meaningful story near the top.', 'icon' => 'ion-pin', 'tag' => 'Live', 'field' => 'pin_memory_enabled', 'options' => $yesNoOptions],
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
      ['label' => 'Dark auto', 'meta' => 'Turn automatic day/night theme switching on or off for all pages.', 'icon' => 'ion-ios-moon', 'tag' => 'Live', 'field_local' => 'theme_auto_enabled', 'options' => $themeAutoOptions, 'default_value' => $themeAutoDefault],
      ['label' => 'Appearance color', 'meta' => 'Pick Light, Dark, or any HTML color name. Applies to all pages for your account (and your publisher org).', 'icon' => 'ion-contrast', 'tag' => 'Live', 'field' => 'appearance_mode', 'option_groups' => $appearanceModeOptionGroups, 'default_value' => $manualAppearanceDefault],
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
  ";
  $gridParams = [':me' => $viewId, ':viewer_id' => $meId];
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
      (SELECT COUNT(*) FROM public_post_saves sv WHERE sv.post_id = p.id) AS save_count
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
        (SELECT COUNT(*) FROM public_post_saves sv WHERE sv.post_id = p.id) AS save_count
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

$profileStorySlides = [];
foreach ($gridStorySource as $it) {
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
  $storyWhen = trim((string)($it['updated_at'] ?? $it['created_at'] ?? ''));
  $profileStorySlides[] = [
    'src' => $src !== '' ? ltrim(preg_replace('~^\./~', '', $src), '/') : '',
    'type' => $src !== '' ? $type : 'text',
    'title' => trim((string)($it['title'] ?? '')),
    'caption' => $caption,
    'timeLabel' => profile_story_time_ago($storyWhen),
    'timeAgo' => profile_story_time_ago($storyWhen),
    'createdAt' => $storyWhen,
    'postId' => (int)($it['post_id'] ?? 0),
    'myReaction' => trim((string)($it['my_reaction'] ?? '')),
    'friendCode' => $canViewProfilePrivateContact ? strtoupper(trim((string)($me['friend_code'] ?? ''))) : '',
  ];
}
$profileStoryCatalog = [];
if ($profileStorySlides) {
  $profileStoryCatalog[] = [
    'key' => 'u' . $viewId,
    'userId' => $viewId,
    'name' => $displayName,
    'username' => $username,
    'friendCode' => $canViewProfilePrivateContact ? strtoupper(trim((string)($me['friend_code'] ?? ''))) : '',
    'verified' => $profileIsPublisher,
    'isPublisher' => $profileIsPublisher,
    'avatarUrl' => $avatarUrl,
    'subtitle' => '',
    'slides' => $profileStorySlides,
  ];
}

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

$postsGrid = $gridFeedSource;
$galleryGrid = array_values(array_filter($gridFeedSource, static function (array $it): bool {
  return profile_item_has_media($it);
}));
$tagsGrid = array_values(array_filter($gridFeedSource, static function (array $it): bool {
  return (int)($it['category_id'] ?? 0) > 0;
}));

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

if (!function_exists('profile_render_post_grid')) {
  function profile_render_post_grid(array $items, bool $showPeerNotFound, bool $isMobile, string $emptyTitle, string $emptyIcon = 'ion-ios-paper-outline', bool $showTagPill = false, string $gridScope = 'all'): void {
    if (!empty($items) && !$showPeerNotFound) {
      echo '<div class="ig-grid" data-grid-scope="' . h($gridScope) . '">';
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
        $snippet = sentence_snippet($snippetSource, $isMobile ? 2 : 5, $isMobile ? 110 : 160);

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
        $noMedia = (!$showVideo && !$showThumb);
        ?>
        <a class="ig-item<?php echo $noMedia ? ' no-media' : ''; ?>"
           data-post-id="<?php echo $pid; ?>"
           data-index="<?php echo $gridIndex; ?>"
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
            <div class="txtdesc">
              <div class="txtcap">
                <div class="t"><?php echo ($ttl !== '' ? h($ttl) : ''); ?></div>
                <div class="d"><?php echo h($snippet !== '' ? $snippet : ($dsc !== '' ? $dsc : '')); ?></div>
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
  foreach ($grid as $it) {
    $pid = (int)($it['post_id'] ?? 0);
    if ($pid <= 0) continue;

    $atype = strtolower(trim((string)($it['atype'] ?? '')));
    $thumb = trim((string)($it['thumb'] ?? ''));
    $filePath = trim((string)($it['file_path'] ?? ''));
    $title = trim((string)($it['title'] ?? ''));
    $description = trim((string)($it['descr'] ?? ''));
    $body = trim((string)($it['body'] ?? ''));
    $snippetSource = $description !== '' ? $description : $body;
    $snippet = sentence_snippet($snippetSource, $isMobile ? 2 : 5, $isMobile ? 110 : 160);
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
      'share_count' => (int)($it['share_count'] ?? 0),
      'save_count' => (int)($it['save_count'] ?? 0),
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
  <link rel="stylesheet" href="./css/dark-auto.css">
  <script src="./js/dark-auto.js?v=6" defer></script>

  <script defer src="assets/layout-fixed.js"></script>
  <script defer src="assets/ui_best.js"></script>

  <style>
    .ig-wrap{max-width:980px;width:100%;margin:0 auto;}
    .ig-card{background:var(--msb-palette-bg, #f5f7fb);border:1px solid var(--msb-palette-border, rgba(15,23,42,.08));}
    body.profile-page .ig-card.ig-profile-shell{border:none;}
    body.profile-page{
      overflow:hidden !important;
      height:100vh;
      background-color:var(--msb-palette-bg, #f5f7fb) !important;
      color:var(--msb-palette-text, #0b1220);
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
      overflow:visible;
      background-color:var(--msb-palette-bg, #f5f7fb);
    }
    .ig-profile-head{
      flex:0 0 auto;
      z-index:40;
      background:var(--msb-palette-bg, #f5f7fb);
      border-bottom:none;
      box-shadow:none;
    }
    .ig-profile-head-rule{
      flex:0 0 auto;
      height:1px;
      border:0;
      margin:0;
      padding:0;
      background:var(--profile-post-divider, var(--msb-palette-border-strong, rgba(177,188,206,.42)));
      width:calc(100vw - var(--feedRailW, 84px) - 16px);
      max-width:none;
      margin-left:calc((100% - (100vw - var(--feedRailW, 84px) - 16px)) / 2);
      position:relative;
      z-index:45;
      pointer-events:none;
    }
    .ig-profile-scroll{
      flex:1 1 auto;
      min-height:0;
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
    .ig-username{font-size:22px;font-weight:500;color:var(--msb-palette-text, #0b1220);margin:0;line-height:1.2;}
    .ig-btn{display:inline-flex;align-items:center;justify-content:center;height:32px;padding:0 14px;border-radius:8px;border:1px solid var(--msb-palette-border-strong, rgba(15,23,42,.12));background:var(--msb-palette-bg, #f5f7fb);color:var(--msb-palette-text, #0b1220);font-weight:700;font-size:13px;}
    .ig-btn.back{margin-right:8px;}
    .ig-btn.icon{width:34px;padding:0;}
    .ig-btn i{font-size:16px}
    .ig-btn.publisher-follow-btn{cursor:pointer;border-radius:999px}
    .ig-btn.publisher-follow-btn.is-following{background:#111827;border-color:#111827;color:#fff}
    .ig-profile-love-wrap{
      display:inline-flex;
      align-items:center;
      gap:6px;
      flex:0 0 auto;
    }
    .ig-profile-love-count{
      font-size:14px;
      color:var(--msb-palette-text, #0b1220);
      line-height:1;
    }
    .ig-profile-love-count b{font-weight:800;}
    .ig-stats .ig-profile-love-wrap{
      gap:8px;
    }
    .ig-profile-love-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:34px;
      height:34px;
      padding:0;
      border:0;
      border-radius:50%;
      background:transparent;
      color:#ea445a;
      cursor:pointer;
      line-height:1;
      flex:0 0 auto;
    }
    .ig-profile-love-btn i{
      font-size:22px;
      line-height:1;
    }
    .ig-profile-love-btn:hover,
    .ig-profile-love-btn:focus{
      opacity:.82;
      outline:none;
    }
    .ig-profile-love-btn.is-loved,
    .ig-profile-love-btn.is-loved i{
      color:var(--msb-love-color, #7c3aed);
    }
    .ig-stats{
      display:flex;
      align-items:center;
      gap:26px;
      margin:14px 0 10px;
      flex-wrap:wrap;
    }
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

    .ig-tabs{border-top:1px solid var(--msb-palette-border, rgba(15,23,42,.08));display:flex;justify-content:center;gap:18px;padding:12px 10px 0;flex-wrap:wrap;background:var(--msb-palette-bg, #f5f7fb);}
    .ig-tab{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:900;letter-spacing:.08em;color:var(--msb-palette-text-muted, #667085);padding:10px 2px;border-top:2px solid transparent;text-transform:uppercase;cursor:pointer;user-select:none;border-radius:15px;padding: 8px;}
    .ig-tab.active{color:var(--msb-palette-text, #0b1220);border-top-color:var(--msb-palette-text, #0b1220);}
    .ig-tab i{font-size:14px}
    .ig-gallery-filter{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 26px 12px;flex-wrap:wrap;}
    .ig-gallery-search{display:flex;align-items:center;gap:10px;flex:1 1 320px;min-width:260px;}
    .ig-gallery-search input{width:100%;height:40px;border:1px solid var(--msb-palette-border-strong, rgba(15,23,42,.14));background:var(--msb-palette-bg, #f5f7fb);color:var(--msb-palette-text, #0b1220);padding:0 12px;font-weight:700;}
    .ig-gallery-search button{height:40px;padding:0 14px;border:1px solid rgba(15,23,42,.14);background:#111827;color:#fff;font-weight:800;cursor:pointer;}
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
    .ig-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:16px 26px 26px;--post-media-radius:18px;}
    @media (max-width: 992px){ .ig-grid{grid-template-columns:repeat(2,1fr);} }

    .ig-item{position:relative;width:100%;aspect-ratio:1/1;background:#eef2f7;overflow:hidden;border:1px solid rgba(15,23,42,.06);text-decoration:none;border-radius:var(--post-media-radius);}
    .ig-item .ph{position:absolute;inset:0;background-size:cover;background-position:center;border-radius:var(--post-media-radius);}
    .ig-item video.ig-vid{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;background:#000;border-radius:var(--post-media-radius);}

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

    .ig-highlights{padding-top:22px;background-color:var(--msb-palette-bg, #f5f7fb);}
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

    .profile-shop-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;padding:16px;max-width:980px;margin:0 auto;}
    .profile-shop-card{background:var(--msb-palette-panel, #fff);border:1px solid var(--msb-palette-border, rgba(15,23,42,.08));border-radius:16px;overflow:hidden;display:flex;flex-direction:column;}
    .profile-shop-cover{aspect-ratio:1/1;background:rgba(15,23,42,.04);display:flex;align-items:center;justify-content:center;}
    .profile-shop-cover img{width:100%;height:100%;object-fit:cover;display:block;}
    .profile-shop-cover-fallback{font-size:42px;color:#9ca3af;}
    .profile-shop-body{padding:12px 14px 14px;display:flex;flex-direction:column;gap:8px;flex:1;}
    .profile-shop-body h3{margin:0;font-size:16px;line-height:1.3;}
    .profile-shop-body p{margin:0;font-size:13px;color:#6b7280;line-height:1.4;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;}
    .profile-shop-meta{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:13px;color:#6b7280;}
    .profile-shop-meta strong{font-size:16px;color:#111827;}
    .profile-shop-buy-btn{margin-top:auto;border:0;border-radius:10px;background:#2563eb;color:#fff;font-weight:700;padding:10px 12px;cursor:pointer;}
    .profile-shop-buy-btn:disabled{opacity:.55;cursor:not-allowed;}
    .profile-shop-empty{text-align:center;padding:48px 16px;color:#6b7280;}
    .profile-shop-empty i{font-size:42px;display:block;margin-bottom:10px;}

    .shop-buy-modal{position:fixed;inset:0;z-index:12000;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.45);}
    .shop-buy-modal.is-open{display:flex;}
    .shop-buy-card{width:min(420px,100%);background:#fff;border-radius:18px;box-shadow:0 24px 60px rgba(0,0,0,.18);overflow:hidden;}
    .shop-buy-head{padding:18px 20px 8px;font-size:18px;font-weight:700;}
    .shop-buy-sub{padding:0 20px 12px;color:#6b7280;font-size:14px;}
    .shop-buy-body{padding:0 20px 16px;display:grid;gap:12px;}
    .shop-buy-body label{display:block;font-size:13px;font-weight:600;margin-bottom:4px;}
    .shop-buy-body input,.shop-buy-body textarea{width:100%;border:1px solid rgba(15,23,42,.14);border-radius:10px;padding:10px 12px;font-size:14px;}
    .shop-buy-foot{display:flex;gap:10px;padding:0 20px 18px;}
    .shop-buy-foot button{flex:1;border:0;border-radius:10px;padding:12px;font-weight:700;cursor:pointer;}
    .shop-buy-cancel{background:#f3f4f6;color:#111827;}
    .shop-buy-submit{background:#2563eb;color:#fff;}
    .shop-buy-submit:disabled{opacity:.55;cursor:not-allowed;}
    .shop-buy-msg{padding:0 20px 12px;font-size:13px;color:#b45309;}

    /* Posts tab feed — match feed.php card column (614px) and dimensions */
    #profilePostsFeed.mf-feed{
      width:100%;max-width:614px;margin:0 auto;padding:10px 10px 26px;box-sizing:border-box;
      --post-media-radius:18px;
    }
    #profilePostsFeed .mf-card{
      width:100%;max-width:100%;
      background:var(--msb-palette-bg, #f5f7fb);border:1px solid var(--msb-palette-border, rgba(15,23,42,.08));border-radius:22px;overflow:hidden;
      margin:0 auto 16px;box-shadow:none;
      --post-media-radius:18px;
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
    }
    #profilePostsFeed .media-stage.standard-video-stage,
    #profilePostsFeed .media-stage.standard-image-stage{
      padding:20px;box-sizing:border-box;
      border:0 !important;
      overflow:hidden !important;
      border-radius:var(--post-media-radius) !important;
      background:transparent !important;
    }
    #profilePostsFeed .media-stage.standard-video-stage > video,
    #profilePostsFeed .media-stage.standard-image-stage > img,
    #profilePostsFeed video.ig-smart-feed-video{
      width:100% !important;height:auto !important;display:block;
      max-height:min(78svh,960px) !important;
      object-fit:contain !important;object-position:center center !important;
      border:0 !important;padding:0 !important;
      border-radius:var(--post-media-radius) !important;
      background:transparent !important;background-color:transparent !important;
    }
    #profilePostsFeed video.ig-smart-feed-video::-webkit-media-controls-panel,
    #profilePostsFeed video.ig-smart-feed-video::-webkit-media-controls-enclosure{
      background:transparent !important;background-image:none !important;
    }
    @media (max-width:767.98px){
      #profilePostsFeed .media-stage.phone-shot{
        width:min(72vw,var(--post-phone-max,430px)) !important;
        max-width:100% !important;max-height:min(78svh,900px) !important;
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
        max-height:min(78svh,960px) !important;object-fit:contain !important;
        border-radius:var(--post-media-radius) !important;padding:0 !important;
        background:transparent !important;
      }
    }
    #profilePostsFeed .mf-head{padding:1px 22px 1px;display:flex;align-items:center;gap:12px;}
    #profilePostsFeed .mf-peer-link{display:flex;align-items:center;gap:8px;min-width:0;flex:1 1 auto;text-decoration:none;color:inherit;}
    #profilePostsFeed .mf-avatar{
      width:45px;height:45px;border-radius:999px;display:flex;align-items:center;justify-content:center;
      flex:0 0 45px;overflow:hidden;padding:2px;
      background:linear-gradient(135deg,#0ea5e9 0%,#2563eb 58%,#f8fafc 100%);
    }
    #profilePostsFeed .mf-avatar img{width:100%;height:100%;display:block;object-fit:cover;border-radius:50%;border:2px solid #fff;background:#fff;}
    #profilePostsFeed .mf-meta{min-width:0;flex:1 1 auto;margin-left:-3px;display:flex;flex-direction:column;justify-content:center;}
    #profilePostsFeed .mf-name-row{display:flex;align-items:center;gap:5px;min-width:0;flex-wrap:nowrap;}
    #profilePostsFeed .mf-name{font-size:13px;font-weight:700;line-height:1.2;margin:0;color:#111827;min-width:0;flex:1 1 auto;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    #profilePostsFeed .mf-dot,#profilePostsFeed .mf-time{font-size:12px;color:#667085;margin:0;flex:0 0 auto;white-space:nowrap;}
    #profilePostsFeed .mf-music-row{display:flex;align-items:center;gap:4px;min-width:0;max-width:100%;margin-top:1px;font-size:11px;line-height:1.2;font-weight:500;color:#667085;overflow:hidden;}
    #profilePostsFeed .mf-music-ic{flex:0 0 auto;font-size:10px;line-height:1;color:#667085;}
    #profilePostsFeed .mf-music-title,#profilePostsFeed .mf-music-artist{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    #profilePostsFeed .mf-music-title{flex:1 1 auto;}
    #profilePostsFeed .mf-music-artist{flex:0 1 auto;max-width:46%;}
    #profilePostsFeed .mf-music-dot{flex:0 0 auto;color:#98a2b3;font-size:11px;line-height:1;}
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
    #profilePostsFeed .mf-menu.open{display:block;}
    #profilePostsFeed .mf-menu a,#profilePostsFeed .mf-menu button{
      width:100%;display:flex;align-items:center;gap:10px;padding:10px 12px;border:0;background:transparent;
      border-radius:10px;font-weight:700;color:#101828;text-decoration:none;cursor:pointer;
    }
    #profilePostsFeed .mf-menu .mf-del{color:#b42318;}
    #profilePostsFeed .mf-title{padding:2px 22px 14px;font-size:22px;line-height:1.25;font-weight:900;color:var(--msb-palette-text, #101828);background:var(--msb-palette-bg, transparent);}
    #profilePostsFeed .mf-body{padding:12px 22px 6px;font-size:15px;line-height:1.75;color:var(--msb-palette-text-muted, #344054);word-break:break-word;text-align:left;background:var(--msb-palette-bg, transparent);}
    #profilePostsFeed .mf-body .mf-body-formatted{text-align:left;}
    #profilePostsFeed .mf-body .post-card-paragraph{margin:0 0 12px;text-align:left;white-space:normal;word-break:break-word;display:block;}
    #profilePostsFeed .mf-body .post-card-paragraph:last-child{margin-bottom:0;}
    #profilePostsFeed .mf-body .mf-body-formatted.is-clamped{max-height:14em;overflow:hidden;}
    #profilePostsFeed .mf-body .mf-readmore{font-weight:900;margin-left:6px;text-decoration:none;color:var(--msb-palette-action, #2563eb);white-space:nowrap;}
    #profilePostsFeed .mf-actions{padding:10px 22px 8px;display:flex;align-items:center;justify-content:space-between;gap:10px;}
    #profilePostsFeed .mf-card:has(.mf-head--on-media) > .mf-actions{padding:10px 0 8px!important;}
    #profilePostsFeed .mf-actions .mf-left{display:flex;gap:20px;align-items:center;}
    #profilePostsFeed .mf-actions .mf-right{display:flex;align-items:center;margin-left:auto;}
    #profilePostsFeed .mf-act{
      border:0;background:transparent;display:flex;align-items:center;gap:6px;padding:0;cursor:pointer;
      color:var(--msb-palette-icon, var(--msb-palette-text, #101828));text-decoration:none;
    }
    #profilePostsFeed .mf-act i{font-size:20px;color:inherit;}
    #profilePostsFeed .mf-act .mf-num{font-size:14px;font-weight:800;color:var(--msb-palette-text, #101828);}
    #profilePostsFeed .mf-act.mf-save .mf-num,#profilePostsFeed .mf-act.mf-share .mf-num{display:none;}
    #profilePostsFeed .mf-act.is-love i{color:var(--msb-love-color, #7c3aed) !important;}
    #profilePostsFeed .mf-act.is-save i{color:#f5c518 !important;}
    #profilePostsFeed .mf-act.is-share i{color:#555555 !important;}
    #profilePostsFeed .mf-feed-empty{padding:48px 24px 56px;}
    body.profile-page.profile-leftbar-open,
    body.profile-page.public-leftbar-open{overflow-x:hidden;}
    body.profile-page #ttLeftbarOverlays{
      z-index:1295;
    }
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
        width:min(100%, var(--post-media-card-width, 430px)) !important;
        max-width:100% !important;margin-inline:auto !important;
      }
      #profilePostsFeed .mf-head{padding:1px 22px 1px;gap:14px;}
      #profilePostsFeed .mf-avatar{width:45px;height:45px;flex:0 0 45px;}
      #profilePostsFeed .mf-name{font-size:13px;font-weight:700;line-height:1.2;color:#111827;}
      #profilePostsFeed .mf-title{padding:2px 22px 14px;font-size:24px;line-height:1.25;font-weight:900;}
      #profilePostsFeed .mf-body{padding:16px 22px 20px;font-size:15px;line-height:1.75;}
    }
    @media (max-width:767px){
      #profilePostsFeed.mf-feed{padding:10px 10px 80px;}
      #profilePostsFeed .mf-card.mf-card-text-only:not(.mf-card-phone-shot){
        width:100% !important;max-width:100% !important;
        margin-left:auto !important;margin-right:auto !important;
      }
      #profilePostsFeed .mf-card.mf-card-phone-shot:not(.is-multi-media-post){
        width:min(100%, var(--post-media-card-width, min(72vw, 430px))) !important;
        max-width:min(calc(100% - 20px), 430px) !important;
        margin-left:auto !important;margin-right:auto !important;
      }
      #profilePostsFeed .mf-name{font-size:13px;color:#101828;}
      #profilePostsFeed .mf-title{padding:0 22px 12px;font-size:18px;line-height:1.3;}
      #profilePostsFeed .mf-body{padding:12px 12px 6px;font-size:14px;line-height:1.7;}
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
    .gear-shell{display:flex;align-items:stretch;min-height:480px;border:1px solid var(--msb-palette-border, rgba(15,23,42,.08));background:var(--msb-palette-bg, #f5f7fb);overflow:hidden;}
    .gear-sidebar{width:min(320px, 38vw);flex:0 0 min(320px, 38vw);border-right:1px solid var(--msb-palette-border, rgba(15,23,42,.08));background:var(--msb-palette-bg, #f5f7fb);display:flex;flex-direction:column;min-height:0;overflow:hidden;}
    .gear-sidebar-head{flex:0 0 auto;padding:22px 18px 14px;border-bottom:1px solid var(--msb-palette-border, rgba(15,23,42,.08));background:var(--msb-palette-bg, #f5f7fb);}
    .gear-sidebar-title{font-size:22px;font-weight:900;color:var(--msb-palette-text, #0b1220);line-height:1.15;margin:0;}
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
    .gear-nav-section-toggle{width:100%;display:flex;align-items:center;gap:10px;padding:11px 12px;border:0;border-radius:12px;background:transparent;color:var(--msb-palette-text, #0b1220);font-size:14px;font-weight:900;text-align:left;cursor:pointer;flex:0 0 auto;}
    .gear-nav-section-toggle:hover,.gear-nav-section-toggle:focus{background:var(--msb-palette-hover-bg, rgba(15,23,42,.05));}
    .gear-nav-section-icon{width:34px;height:34px;border-radius:10px;background:var(--msb-palette-hover-bg, #eef2ff);color:var(--msb-palette-link, #4338ca);display:flex;align-items:center;justify-content:center;flex:0 0 34px;}
    .gear-nav-section-icon i{font-size:18px;}
    .gear-nav-section-label{flex:1 1 auto;min-width:0;}
    .gear-nav-section-chevron{color:var(--msb-palette-text-muted, #98a2b3);font-size:14px;transition:transform .18s ease;}
    .gear-nav-section.is-open .gear-nav-section-chevron{transform:rotate(180deg);}
    .gear-nav-items{display:none;padding:2px 0 6px 44px;position:relative;z-index:1;}
    .gear-nav-section.is-open .gear-nav-items{display:block;max-height:min(180px, 26vh);overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:rgba(79,70,229,.35) transparent;}
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
    .gear-detail-head{display:flex;align-items:flex-start;gap:14px;margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid var(--msb-palette-border, rgba(15,23,42,.08));}
    .gear-detail-icon{width:52px;height:52px;border-radius:16px;background:var(--msb-palette-hover-bg, #eef2ff);color:var(--msb-palette-link, #4338ca);display:flex;align-items:center;justify-content:center;flex:0 0 52px;}
    .gear-detail-icon i{font-size:24px;}
    .gear-detail-title{font-size:20px;font-weight:900;color:var(--msb-palette-text, #0b1220);line-height:1.2;margin:0 0 6px;}
    .gear-detail-desc{font-size:13px;color:var(--msb-palette-text-muted, #667085);font-weight:700;line-height:1.55;margin:0;}
    .gear-detail-chips{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px;}
    .gear-chip{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;background:var(--msb-palette-hover-bg, #eef2ff);color:var(--msb-palette-link, #4338ca);font-size:11px;font-weight:900;letter-spacing:.02em;}
    .gear-detail-body{max-width:640px;}
    .gear-detail-control-label{display:block;font-size:12px;font-weight:900;color:var(--msb-palette-text-muted, #667085);margin:0 0 8px;text-transform:uppercase;letter-spacing:.04em;}
    .gear-detail-open-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 18px;border-radius:12px;background:var(--msb-palette-action-soft,#eef2ff);color:var(--msb-palette-action,#4338ca);border:1px solid var(--msb-palette-border-strong,rgba(79,70,229,.22));text-decoration:none;font-size:14px;font-weight:900;}
    .gear-detail-open-btn:hover,.gear-detail-open-btn:focus{text-decoration:none;background:var(--msb-palette-nav-hover,#e0e7ff);}
    .gear-tag{display:inline-flex;align-items:center;justify-content:center;padding:5px 9px;border-radius:999px;background:var(--msb-palette-hover-bg, #f3f4f6);color:var(--msb-palette-text-muted, #475467);font-size:10px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;}
    .gear-note{margin-top:16px;padding:12px 14px;border:1px dashed var(--msb-palette-border-strong, rgba(79,70,229,.24));background:var(--msb-palette-surface-2, #f8faff);color:var(--msb-palette-link, #4338ca);font-size:13px;font-weight:700;}
    .gear-control-wrap{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
    .gear-control-wrap--detail{align-items:flex-start;flex-direction:column;}
    .gear-control{width:100%;max-width:360px;min-width:180px;height:44px;border-radius:10px;border:1px solid var(--msb-palette-border-strong, rgba(15,23,42,.14));background:var(--msb-palette-bg, #f5f7fb);color:var(--msb-palette-text, #0b1220);font-size:13px;font-weight:800;padding:0 12px;outline:none;}
    .gear-control:focus{border-color:#4f46e5;box-shadow:0 0 0 4px rgba(79,70,229,.12);}
    .gear-appearance-select{max-width:360px;}
    .gear-save-state{font-size:11px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;color:#98a2b3;min-width:46px;}
    .gear-save-state.is-saving{color:#b45309;}
    .gear-save-state.is-saved{color:#047857;}
    .gear-save-state.is-error{color:#b42318;}
    @media (max-width: 991px){
      .gear-shell{flex-direction:column;min-height:0;}
      .gear-sidebar{width:100%;flex:0 0 auto;min-height:0;max-height:none;border-right:0;border-bottom:1px solid var(--msb-palette-border, rgba(15,23,42,.08));}
      .gear-nav{max-height:min(340px, 42vh);}
      .gear-nav-section.is-open .gear-nav-items{max-height:min(160px, 22vh);}
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
    .gear-upload-btn{display:inline-flex;align-items:center;justify-content:center;height:40px;padding:0 14px;border-radius:10px;border:1px solid rgba(79,70,229,.22);background:#eef2ff;color:#4338ca;font-size:12px;font-weight:900;cursor:pointer;white-space:nowrap;margin:0;}
    .gear-upload-btn:hover{background:#e0e7ff;}
    .gear-upload-hint{font-size:11px;color:#667085;font-weight:800;max-width:180px;text-align:right;}
    .bestprofile-avatar img,.bestprofile-avatar .avatar-photo,.peerAvatar img,.avatar-circle img,.chat-item .avatar img{width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block;}

    html[data-theme="dark"] body.profile-page .ig-card,
    html[data-theme="dark"] body.profile-page .ig-profile-shell,
    html[data-theme="dark"] body.profile-page .ig-profile-head,
    html[data-theme="dark"] body.profile-page .ig-profile-scroll,
    html[data-theme="dark"] body.profile-page .ig-highlights,
    html[data-theme="dark"] body.profile-page .ig-tabs,
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
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-foot {
      background-color: var(--msb-palette-bg, #171d24) !important;
      background-image: none !important;
      color: var(--msb-palette-text, #f3f6fb) !important;
      border-color: var(--msb-palette-border, rgba(255,255,255,.12)) !important;
    }
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-body,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-time,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-dot {
      color: var(--msb-palette-text-muted, #a9b6c8) !important;
    }
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-body .mf-readmore {
      color: var(--msb-palette-action, #93c5fd) !important;
    }
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-act,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-act .mf-num,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-act i {
      color: var(--msb-palette-icon, var(--msb-palette-text, #f3f6fb)) !important;
    }
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-act.is-love,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-act.is-love i,
    html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-act.is-love .mf-num {
      color: var(--msb-love-color, #7c3aed) !important;
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
      background: #111827 !important;
      border-color: rgba(255,255,255,.12) !important;
      color: #b1bcce !important;
    }

    html.dark-auto .ig-username,
    html[data-theme="dark"] .ig-username,
    html.dark-auto .ig-stat,
    html[data-theme="dark"] .ig-stat,
    html.dark-auto .ig-bio,
    html[data-theme="dark"] .ig-bio,
    html.dark-auto .ig-tab.active,
    html[data-theme="dark"] .ig-tab.active,
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
      color: #b1bcce !important;
    }

    html.dark-auto .ig-bio .muted,
    html[data-theme="dark"] .ig-bio .muted,
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
      color: #b1bcce !important;
    }

    html.dark-auto .mf-feed-empty .mf-feed-empty-title,
    html[data-theme="dark"] .mf-feed-empty .mf-feed-empty-title {
      color: #b1bcce !important;
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
      color: #b1bcce !important;
      border-color: rgba(177, 188, 206, 0.44) !important;
    }

    html.dark-auto .ig-btn.icon,
    html[data-theme="dark"] .ig-btn.icon {
      background: #111827 !important;
      color: #b1bcce !important;
      border-color: rgba(255,255,255,.12) !important;
    }

    html.dark-auto .gear-chip,
    html[data-theme="dark"] .gear-chip,
    html.dark-auto .gear-tag,
    html[data-theme="dark"] .gear-tag {
      background: #111827 !important;
      color: #b1bcce !important;
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
    border-radius:var(--post-media-radius,18px) !important;
  }
  #profilePostsFeed .media-stage.standard-video-stage > video,
  #profilePostsFeed .media-stage.standard-image-stage > img,
  #profilePostsFeed video.ig-smart-feed-video{
    border-radius:var(--post-media-radius,18px) !important;
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
  padding:8px 40px!important;
  box-sizing:border-box!important;
}
#profilePostsFeed .mf-card:has(.mf-head--on-media) .media-stage.standard-video-stage,
#profilePostsFeed .mf-card:has(.mf-head--on-media) .media-stage.standard-image-stage{
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
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-menu-wrap{
  pointer-events:auto!important;
  background:transparent!important;
  z-index:60!important;position:relative;
  margin-top:0!important;
}
#profilePostsFeed .mf-media-shell > .mf-head--on-media > .post-card-menu-wrap,
#profilePostsFeed .mf-media-shell > .mf-head--on-media > .mf-menu-wrap.post-card-menu-wrap{
  pointer-events:auto!important;
  background:transparent!important;
  z-index:61!important;
  position:absolute!important;
  top:var(--pcm-on-media-menu-top, 18px)!important;
  right:var(--pcm-on-media-menu-right, 10px)!important;
  margin:0!important;
}
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-peer-link{
  align-items:center!important;
  gap:8px!important;
  padding-right:44px!important;
  box-sizing:border-box!important;
}
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-name-row{
  padding-right:0!important;
  max-width:100%!important;
  gap:3px!important;
  width:auto!important;
  justify-content:flex-start!important;
}
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-name{
  flex:0 1 auto!important;
  min-width:0!important;
  max-width:calc(100% - 64px)!important;
}
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-dot{
  margin-left:0!important;
  flex:0 0 auto!important;
}
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-time{
  flex:0 0 auto!important;
  white-space:nowrap!important;
  margin-left:0!important;
}
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-music-row{
  width:auto!important;
  max-width:100%!important;
  align-self:flex-start!important;
  justify-content:flex-start!important;
}
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-music-title{
  flex:0 1 auto!important;
}
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-music-artist{
  flex:0 1 auto!important;
  max-width:none!important;
}
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-meta{
  margin-left:-5px!important;
  display:flex!important;
  flex-direction:column!important;
  justify-content:center!important;
  min-height:45px!important;
}
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-name,
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-time,
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-dot,
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-music-row,
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-music-ic,
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-music-title,
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-music-artist,
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-music-dot,
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-menu-btn:not(.post-card-menu-btn),
#profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-menu-btn:not(.post-card-menu-btn) i{
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
  position:absolute!important;
  top:var(--pcm-on-media-menu-top, 18px)!important;
  right:var(--pcm-on-media-menu-right, 10px)!important;
  margin:0!important;
  z-index:61!important;
}
#profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-wrap,
#profilePostsFeed .mf-head:not(.mf-head--on-media) .mf-menu-wrap.post-card-menu-wrap{
  flex:0 0 auto!important;
  width:auto!important;
  margin-left:auto!important;
}
#profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn{
  width:auto!important;
  height:auto!important;
  min-width:var(--pcm-menu-btn-size, 28px)!important;
  min-height:var(--pcm-menu-btn-size, 28px)!important;
  padding:6px 4px!important;
  flex:0 0 auto!important;
  border:0!important;
  border-radius:0!important;
  background:transparent!important;
  color:var(--msb-palette-text, #5c3d2e)!important;
  display:inline-flex!important;
  align-items:center!important;
  justify-content:center!important;
  box-shadow:none!important;
  line-height:1!important;
}
#profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn i,
#profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn .pcm-fries-icon{
  font-size:16px!important;
  line-height:1!important;
  color:inherit!important;
  text-shadow:none!important;
}
#profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn:hover,
#profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn:focus{
  background:transparent!important;
  outline:none!important;
  box-shadow:none!important;
  opacity:.72!important;
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
          <!-- <h2 class="ig-username"><?php echo h($username !== '' ? $username : $displayName); ?></h2> -->

          <a class="ig-btn back" href="#" onclick="if(window.history.length > 1){ history.back(); return false; } window.location.href='feed.php'; return false;"><i class="icon ion-arrow-left-c"></i>&nbsp;Back</a>
          <?php if ($isOwnProfile): ?>
            <a class="ig-btn" href="dashboard.php"><i class="icon ion-edit"></i>&nbsp;Edit</a>
            <a class="ig-btn icon" href="messages.php" title="Messages"><i class="icon ion-chatboxes"></i></a>
            <a class="ig-btn icon" href="contacts.php" title="Friends"><i class="icon ion-person-stalker"></i></a>
            <a class="ig-btn" href="contact_requests.php"><i class="icon ion-person-add"></i>&nbsp;Friend Requests</a>
          <?php elseif ($isViewedPublisher && $canFollowPublishers): ?>
            <button type="button" class="ig-btn publisher-follow-btn<?= $isFollowingPublisher ? ' is-following' : '' ?>" data-publisher-id="<?= (int)$viewId ?>">
              <?= $isFollowingPublisher ? 'Following' : 'Follow' ?>
            </button>
            <?php if ($profileLoveAfterFollow): ?>
              <?= $profileLoveBtnHtml ?>
            <?php endif; ?>
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
          <div class="ig-stat"><b><?php echo (int)$statPosts; ?></b> posts</div>
          <div class="ig-stat"><b><?php echo (int)$statSocialCount; ?></b> <?php echo h($statSocialLabel); ?></div>
          <?php if ($profileLoveInStats): ?>
            <?= $profileLoveBtnHtml ?>
          <?php endif; ?>
        </div>

        <div class="ig-bio">
          <b class="ig-stat"><?php echo h($profileDisplayName); ?></b><br>
          <?php if ($profileAccountBadge !== ''): ?><span class="profile-account-badge"><?php echo h($profileAccountBadge); ?></span><br><?php endif; ?>
          <?php if (trim($me['designation']) !== ''): ?><span class="muted"><?php echo h($me['designation']); ?></span><br><?php endif; ?>
          <!-- <?php if (trim($me['role']) !== ''): ?><span class="muted"><?php echo h($me['role']); ?></span><br><?php endif; ?>
          <?php if (trim($me['status']) !== ''): ?><?php echo h($me['status']); ?><br><?php endif; ?> -->
          <span class="muted">Joined <?php echo h($joinedLabel); ?></span>
        </div>
      </div>
    </div>

    <div class="ig-profile-head-rule" aria-hidden="true"></div>

    <div class="ig-profile-scroll">

    <div class="ig-highlights" aria-label="Profile stories">
      <div class="ig-stories-track" id="profileStoriesTrack">
        <?php if ($profileStoryCatalog): ?>
          <?php
            $story = $profileStoryCatalog[0];
            $storyLabel = $username !== '' ? $username : $displayName;
            if (u_len($storyLabel) > 11) {
              $storyLabel = u_sub($storyLabel, 0, 10) . '..';
            }
            $storyThumb = trim((string)($story['avatarUrl'] ?? $avatarUrl));
          ?>
          <button type="button" class="ig-story-item" data-story-key="<?php echo h((string)($story['key'] ?? '')); ?>" data-story-index="0" aria-label="Open story for <?php echo h($displayName); ?>">
            <div class="ig-story-ring">
              <?php if ($storyThumb !== ''): ?>
                <img src="<?php echo h($storyThumb); ?>" alt="">
              <?php else: ?>
                <span class="ig-story-thumb" style="background:linear-gradient(135deg,#667eea,#764ba2);"></span>
              <?php endif; ?>
            </div>
            <!-- <span class="ig-story-name"><?php echo h($storyLabel); ?></span> -->
          </button>
        <?php elseif ($isOwnProfile): ?>
          <a class="ig-story-item ig-story-create" href="dashboard.php?modal=1&amp;story=1" data-create-post-modal="1" aria-label="Create a story">
            <div class="ig-story-ring"><i class="icon ion-plus"></i></div>
            <span class="ig-story-name">New</span>
          </a>
          <div class="ig-story-empty">Create a post in Dashboard to add your story.</div>
        <?php else: ?>
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
      <?php if ($profileIsPublisher && $profileShopOrgId > 0): ?>
        <div class="ig-tab<?php echo $selectedTab === 'shop' ? ' active' : ''; ?>" data-panel="shop" role="tab" tabindex="<?php echo $selectedTab === 'shop' ? '0' : '-1'; ?>" aria-selected="<?php echo $selectedTab === 'shop' ? 'true' : 'false'; ?>">
          <i class="icon ion-bag"></i>Shop
        </div>
      <?php endif; ?>
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
    </div>

    <div id="panel-posts" class="profile-panel<?php echo $selectedTab === 'posts' ? ' active' : ''; ?>">
      <div class="profile-posts-feed-column" id="profilePostsFeedColumn">
        <div id="profilePostsFeed" class="mf-feed" aria-live="polite"></div>
      </div>
    </div>

    <div id="panel-tags" class="profile-panel<?php echo $selectedTab === 'tags' ? ' active' : ''; ?>">
      <?php
        profile_render_post_grid(
          $tagsGrid,
          $showPeerNotFound,
          $isMobile,
          'No Tags Available',
          'ion-ios-pricetag',
          true,
          'tags'
        );
      ?>
    </div>

    <?php if ($profileIsPublisher && $profileShopOrgId > 0): ?>
    <div id="panel-shop" class="profile-panel<?php echo $selectedTab === 'shop' ? ' active' : ''; ?>">
      <?php if (!$profileShopVisible): ?>
        <div class="profile-shop-empty">
          <i class="icon ion-bag"></i>
          <p>Shop is temporarily unavailable.</p>
        </div>
      <?php elseif (!$profileShopProducts): ?>
        <div class="profile-shop-empty">
          <i class="icon ion-bag"></i>
          <p>No products listed yet.</p>
        </div>
      <?php else: ?>
        <div class="profile-shop-grid">
          <?php foreach ($profileShopProducts as $shopProduct): ?>
            <?php
              $shopCover = org_shop_cover_url((string)($shopProduct['cover_image_path'] ?? ''));
              $shopPrice = org_shop_format_price((int)($shopProduct['price_cents'] ?? 0), (string)($shopProduct['currency'] ?? 'USD'));
              $shopStock = $shopProduct['stock_qty'];
              $shopOutOfStock = ($shopStock !== null && $shopStock !== '' && (int)$shopStock <= 0);
            ?>
            <article class="profile-shop-card" data-product-id="<?php echo (int)$shopProduct['id']; ?>">
              <div class="profile-shop-cover">
                <?php if ($shopCover !== ''): ?>
                  <img src="<?php echo h($shopCover); ?>" alt="">
                <?php else: ?>
                  <span class="profile-shop-cover-fallback"><i class="icon ion-bag"></i></span>
                <?php endif; ?>
              </div>
              <div class="profile-shop-body">
                <h3><?php echo h((string)$shopProduct['title']); ?></h3>
                <?php if (trim((string)($shopProduct['description'] ?? '')) !== ''): ?>
                  <p><?php echo h((string)$shopProduct['description']); ?></p>
                <?php endif; ?>
                <div class="profile-shop-meta">
                  <strong><?php echo h($shopPrice); ?></strong>
                  <?php if ($shopStock !== null && $shopStock !== ''): ?>
                    <span><?php echo (int)$shopStock > 0 ? (int)$shopStock . ' in stock' : 'Out of stock'; ?></span>
                  <?php endif; ?>
                </div>
                <?php if (!$isOwnProfile && !$shopOutOfStock): ?>
                  <button type="button" class="profile-shop-buy-btn" data-shop-buy="<?php echo (int)$shopProduct['id']; ?>" data-shop-title="<?php echo h((string)$shopProduct['title']); ?>" data-shop-price="<?php echo h($shopPrice); ?>">Buy</button>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

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
                      <?php profile_gear_render_detail_action($row, $profileSettings, $themeAutoDefault); ?>
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
  <button type="button" class="pv-nav pv-prev" id="pvPrev" aria-label="Previous"><i class="icon ion-chevron-left"></i></button>
  <button type="button" class="pv-nav pv-next" id="pvNext" aria-label="Next"><i class="icon ion-chevron-right"></i></button>

  <div class="pv-modal" role="dialog" aria-modal="true" aria-label="Post viewer">
    <div class="pv-left">
      <div class="pv-media" id="pvMedia"></div>
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
        <button type="button" class="pv-dots" id="pvDots" aria-label="More"><i class="icon ion-android-more-horizontal"></i></button>
      </div>

      <!-- ✅ Scrollable middle (caption + comments) so footer/input never hides on mobile/tablet -->
      <div class="pv-body" id="pvBody">
        <!-- ✅ Post caption (with Read more) -->
        <div class="pv-caption" id="pvCaption" style="display:none;"></div>

        <div class="pv-comments" id="pvComments" aria-label="Comments"></div>
      </div>

      <div class="pv-actions">
        <div class="pv-actrow">
          <button type="button" class="pv-act pv-act-love" id="pvLove" title="Love" aria-label="Love">
            <i class="icon ion-heart"></i>
            <span class="pv-n" id="pvLoveN">0</span>
          </button>
          <button type="button" class="pv-act pv-act-like" id="pvLike" title="Like" aria-label="Like">
            <i class="icon ion-thumbsup"></i>
            <span class="pv-n" id="pvLikeN">0</span>
          </button>
          <button type="button" class="pv-act pv-act-comment" id="pvComment" title="Comment" aria-label="Comment">
            <i class="icon ion-chatbubble"></i>
            <span class="pv-n" id="pvComN">0</span>
          </button>
          <button type="button" class="pv-act pv-act-share" id="pvShare" title="Share" aria-label="Share">
            <i class="icon ion-forward"></i>
            <span class="pv-n" id="pvShareN">0</span>
          </button>
          <div class="pv-sp"></div>
          <button type="button" class="pv-act pv-act-save" id="pvSave" title="Save" aria-label="Save">
            <i class="icon ion-bookmark"></i>
            <span class="pv-n" id="pvSaveN">0</span>
          </button>
        </div>
        <div class="pv-likebar">
          <span id="pvLikesText">0 reactions</span>
        </div>
        <div class="pv-metabar">
          <button type="button" class="pv-meta-link" id="pvCommentsLink">View all 0 comments</button>
          <span class="pv-views" id="pvViewsText">0 views</span>
        </div>
        <div class="pv-replybar" id="pvReplyBar" style="display:none;">
          <span><span id="pvReplyLead">Replying to</span> <b id="pvReplyName">—</b></span>
          <button type="button" class="pv-replyx" id="pvReplyCancel" aria-label="Cancel reply"><i class="icon ion-close"></i></button>
        </div>
        <div class="pv-input">
          <input type="text" id="pvText" placeholder="Add a comment…" autocomplete="off" />
          <button type="button" id="pvPostBtn">Post</button>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
  /* ✅ Modal (instagram-style) */
  .pv-overlay{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.72);z-index:9999;padding:24px;overflow:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;}
  .pv-overlay.show{display:flex;}
  .pv-modal{width:min(1120px,96vw);height:min(720px,88vh);background:#fff;overflow:hidden;display:flex;box-shadow:0 30px 90px rgba(0,0,0,.45);}
  .pv-left{flex:1.15;min-width:0;background:#0b1220;display:flex;align-items:center;justify-content:center;}
  .pv-media{width:100%;height:100%;display:flex;align-items:center;justify-content:center;--post-media-radius:18px;}
  .pv-media img,.pv-media video,.pv-media iframe{max-width:100%;max-height:100%;width:auto;height:auto;border-radius:var(--post-media-radius);}
  .pv-media video{width:100%;height:100%;object-fit:contain;border-radius:var(--post-media-radius);}
  /* ✅ Mobile/Tablet: allow long LEFT description (no media) to scroll */
  @media (max-width: 900px){
    .pv-left.pv-left-scroll{align-items:stretch !important;justify-content:stretch !important;}
    .pv-left.pv-left-scroll .pv-media{overflow:auto;-webkit-overflow-scrolling:touch;align-items:flex-start !important;justify-content:flex-start !important;}
    .pv-left.pv-left-scroll .pv-media > div{height:auto !important;min-height:100%;align-items:flex-start !important;justify-content:flex-start !important;padding:22px !important;}
  }

  .pv-right{flex:.85;min-width:320px;display:flex;flex-direction:column;background:#fff;min-height:0;}
  .pv-head{padding:14px 14px;border-bottom:1px solid rgba(15,23,42,.08);display:flex;align-items:center;justify-content:space-between;gap:10px;}
  .pv-user{display:flex;align-items:center;gap:10px;min-width:0;}
  .pv-ava{width:38px;height:38px;border-radius:999px;object-fit:cover;background:#eef2ff;}
  .pv-namewrap{min-width:0;}
  .pv-name{font-weight:700;font-size:14px;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .pv-meta{font-size:12px;color:rgba(15,23,42,.55);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .pv-dots{border:0;background:transparent;width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;}
  .pv-dots:hover{background:rgba(15,23,42,.06);}

  /* ✅ Middle scroll area: prevents input/actions from being pushed off-screen on mobile/tablet */
  .pv-body{flex:1;min-height:0;overflow:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;}
  .pv-comments{padding:12px 14px;}
  /* keep space so last comment never hides behind the footer/input */
  .pv-comments{padding-bottom:160px;}

  /* ✅ Footer stays visible; input is sticky inside footer */
  .pv-actions{position:sticky;bottom:0;background:#fff;z-index:3;}
  /* make input sticky so it never gets hidden by long scroll/keyboard */
  .pv-input{position:sticky;bottom:0;background:#fff;padding:10px 0 calc(10px + env(safe-area-inset-bottom));margin-top:10px;z-index:4;}
  .pv-input::before{content:"";position:absolute;left:0;right:0;top:-10px;height:10px;background:linear-gradient(to top, rgba(255,255,255,1), rgba(255,255,255,0));}

  /* ✅ Mobile/tablet viewport fixes: avoid VH bugs + ensure comment input is visible */
  @media (max-width: 980px){
    .pv-overlay{padding:10px;align-items:stretch;}
    .pv-modal{width:100%;height:calc(var(--vh, 1vh) * 100 - 20px);max-height:none;border-radius:18px;}
  }
  @media (max-width: 640px){
    .pv-overlay{padding:0;}
    .pv-modal{width:100vw;height:calc(var(--vh, 1vh) * 100);border-radius:0;}
  }


  /* ✅ Caption (post text) inside modal right panel */
  .pv-caption{border-bottom:1px solid rgba(15,23,42,.08);padding:10px 14px;max-height:140px;overflow:auto;}
  .pv-cap{font-size:13px;line-height:1.35;color:#0f172a;word-break:break-word;}
  .pv-cap-title{font-size:15px;font-weight:800;line-height:1.25;margin-bottom:6px;}
  .pv-cap-desc{font-size:13px;line-height:1.45;}
  .pv-cap-short,.pv-cap-full{white-space:normal;word-break:break-word;}

  .pv-cap b{font-weight:800;}
  .pv-readmore{margin-left:6px;font-weight:800;color:#2563eb;cursor:pointer;white-space:nowrap;}
  .pv-readmore:hover{text-decoration:underline;}
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
  .pv-node{position:relative;--pv-avatar-size:32px;}
  .pv-node.has-children::after{content:"";position:absolute;left:calc(var(--pv-avatar-size) / 2);top:calc(var(--pv-avatar-size) + 2px);bottom:18px;width:2px;background:rgba(148,163,184,.28);border-radius:999px;}
  .pv-node.has-children.is-collapsed::after{display:none;}
  .pv-children{margin-left:calc(var(--pv-avatar-size) / 2);padding-left:28px;}
  .pv-children.depth-capped{margin-left:0;padding-left:0;}
  .pv-node.is-reply::before{content:"";position:absolute;left:-28px;top:0;width:28px;height:16px;border-left:2px solid rgba(148,163,184,.28);border-bottom:2px solid rgba(148,163,184,.28);border-bottom-left-radius:18px;}
  .pv-node.is-depth-clamped::before{display:none;}
  .pv-com{display:flex;gap:10px;margin-bottom:12px;}
  .pv-com.is-alert-focus{background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.24);box-shadow:0 14px 28px rgba(37,99,235,.12);border-radius:16px;padding:10px;}
  .pv-com .a{width:32px;height:32px;border-radius:999px;background:#eef2ff;flex:0 0 32px;overflow:hidden;}
  .pv-com .a img{width:100%;height:100%;object-fit:cover;}
  .pv-com .b{min-width:0;flex:1;}
  .pv-com .bubble{display:inline-block;max-width:min(100%,460px);background:#f3f4f6;border:1px solid rgba(15,23,42,.06);border-radius:18px;padding:10px 14px 11px;}
  .pv-com .t{font-size:13px;line-height:1.3;color:#0f172a;}
  .pv-com .t b{font-weight:700;}
  .pv-com .m{margin-top:4px;font-size:11px;color:rgba(15,23,42,.55);display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding-left:8px;}
  .pv-com .m .link{cursor:pointer;color:#2563eb;}
  .pv-com .m .link:hover{text-decoration:underline;}
  .pv-com .m .replies-toggle{border:0;background:transparent;padding:0;color:#2563eb;font:inherit;font-weight:800;cursor:pointer;}
  .pv-com .m .replies-toggle:hover{text-decoration:underline;}
  .pv-com .m .likebtn{border:0;background:transparent;padding:0;color:inherit;font:inherit;font-weight:800;cursor:pointer;}
  .pv-com .m .likebtn.is-liked{color:#2563eb;}
  .pv-likepill{display:inline-flex;align-items:center;gap:6px;padding:2px 10px;border-radius:999px;background:rgba(37,99,235,.12);color:#1d4ed8;font-weight:800;}
  .pv-likepill i{font-size:12px;}
  html.dark-auto .pv-node.has-children::after,
  html[data-theme="dark"] .pv-node.has-children::after{background:rgba(148,163,184,.38);}
  html.dark-auto .pv-node.is-reply::before,
  html[data-theme="dark"] .pv-node.is-reply::before{border-left-color:rgba(148,163,184,.38);border-bottom-color:rgba(148,163,184,.38);}
  html.dark-auto .pv-com .bubble,
  html[data-theme="dark"] .pv-com .bubble{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.08);}
  html.dark-auto .pv-likepill,
  html[data-theme="dark"] .pv-likepill{background:rgba(96,165,250,.18);color:#bfdbfe;}
  html.dark-auto .pv-com.is-alert-focus,
  html[data-theme="dark"] .pv-com.is-alert-focus{background:rgba(96,165,250,.16);border-color:rgba(147,197,253,.34);box-shadow:0 14px 28px rgba(2,6,23,.34);}
  html.dark-auto .pv-modal,
  html[data-theme="dark"] .pv-modal,
  html.dark-auto .pv-right,
  html[data-theme="dark"] .pv-right,
  html.dark-auto .pv-actions,
  html[data-theme="dark"] .pv-actions,
  html.dark-auto .pv-input,
  html[data-theme="dark"] .pv-input{background:var(--msb-palette-bg, #171d24);color:var(--msb-palette-text, #f3f6fb);}
  html.dark-auto .pv-head,
  html[data-theme="dark"] .pv-head,
  html.dark-auto .pv-caption,
  html[data-theme="dark"] .pv-caption,
  html.dark-auto .pv-actions,
  html[data-theme="dark"] .pv-actions{border-color:rgba(255,255,255,.12);}
  html.dark-auto .pv-name,
  html[data-theme="dark"] .pv-name,
  html.dark-auto .pv-cap,
  html[data-theme="dark"] .pv-cap,
  html.dark-auto .pv-act,
  html[data-theme="dark"] .pv-act,
  html.dark-auto .pv-act i,
  html[data-theme="dark"] .pv-act i,
  html.dark-auto .pv-likebar,
  html[data-theme="dark"] .pv-likebar{color:#f3f6fb;}
  html.dark-auto .pv-meta,
  html[data-theme="dark"] .pv-meta,
  html.dark-auto .pv-act .pv-n,
  html[data-theme="dark"] .pv-act .pv-n,
  html.dark-auto .pv-meta-link,
  html[data-theme="dark"] .pv-meta-link,
  html.dark-auto .pv-views,
  html[data-theme="dark"] .pv-views{color:#a9b6c8;}
  html.dark-auto .pv-dots:hover,
  html[data-theme="dark"] .pv-dots:hover{background:rgba(255,255,255,.08);}
  html.dark-auto .pv-input::before,
  html[data-theme="dark"] .pv-input::before{background:linear-gradient(to top, rgba(24,33,48,1), rgba(24,33,48,0));}
  html.dark-auto .pv-input input,
  html[data-theme="dark"] .pv-input input{background:#111827;border-color:rgba(255,255,255,.12);color:#f3f6fb;}
  html.dark-auto .pv-input input::placeholder,
  html[data-theme="dark"] .pv-input input::placeholder{color:#a9b6c8;}

  .pv-actions{border-top:1px solid rgba(15,23,42,.08);padding:10px 12px 12px;}
  .pv-actrow{display:flex;align-items:center;gap:18px;}
  .pv-act{border:0;background:transparent;padding:0;min-width:0;height:auto;border-radius:0;display:inline-flex;align-items:center;justify-content:flex-start;gap:8px;cursor:pointer;color:#111827;font-size:15px;line-height:1;}
  .pv-act i{font-size:28px;line-height:1;color:#111827;transition:color .15s ease, transform .15s ease;}
  .pv-act .pv-n{font-size:15px;font-weight:700;line-height:1;color:#6b7280;min-width:10px;}
  .pv-act:hover{background:transparent;}
  .pv-act:hover i{transform:scale(1.03);}
  .pv-sp{flex:1;}
  .pv-likebar{margin-top:10px;font-size:15px;font-weight:700;color:#111827;line-height:1.2;}
  .pv-metabar{margin-top:8px;display:flex;align-items:center;justify-content:space-between;gap:12px;}
  .pv-meta-link{border:0;background:transparent;padding:0;color:#374151;font-size:15px;line-height:1.25;cursor:pointer;text-align:left;}
  .pv-meta-link:hover{text-decoration:underline;}
  .pv-views{font-size:15px;line-height:1.25;color:#374151;white-space:nowrap;}

  /* ✅ toggled colors (icon only; counts stay neutral) */
  .pv-act.is-love i{color:var(--msb-love-color, #7c3aed);} /* purple */
  .pv-act.is-like i{color:#2563eb;} /* blue */
  .pv-act.is-save i{color:#facc15;} /* yellow */
  .pv-act.is-share i{color:#6b7280;} /* grey */

  @media (max-width: 520px){
    .pv-actrow{gap:14px;}
    .pv-act i{font-size:24px;}
    .pv-act .pv-n,.pv-likebar,.pv-meta-link,.pv-views{font-size:14px;}
  }

  .pv-input{margin-top:10px;display:flex;gap:10px;align-items:center;}
  .pv-input input{flex:1;min-width:0;height:40px;border-radius:12px;border:1px solid rgba(15,23,42,.14);padding:0 12px;outline:none;}
  .pv-input input:focus{border-color:rgba(37,99,235,.45);box-shadow:0 0 0 3px rgba(37,99,235,.12);}
  .pv-input button{height:40px;border:0;border-radius:12px;padding:0 14px;font-weight:700;background:#2563eb;color:#fff;cursor:pointer;}
  .pv-input button:disabled{opacity:.55;cursor:not-allowed;}

  .pv-replybar{margin-top:8px;display:flex;align-items:center;justify-content:space-between;background:rgba(37,99,235,.08);border:1px solid rgba(37,99,235,.18);padding:6px 10px;border-radius:12px;font-size:12px;color:#1e3a8a;}
  .pv-replyx{border:0;background:transparent;width:28px;height:28px;border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#1e3a8a;}
  .pv-replyx:hover{background:rgba(37,99,235,.12);}

  .pv-x{position:fixed;top:14px;right:14px;z-index:10000;border:0;background:rgba(255,255,255,.12);backdrop-filter: blur(8px);color:#fff;width:42px;height:42px;border-radius:999px;display:flex;align-items:center;justify-content:center;cursor:pointer;}
  .pv-x:hover{background:rgba(255,255,255,.18);}
  .pv-nav{position:fixed;top:50%;transform:translateY(-50%);z-index:10000;border:0;background:rgba(255,255,255,.12);backdrop-filter: blur(8px);color:#fff;width:44px;height:44px;border-radius:999px;display:flex;align-items:center;justify-content:center;cursor:pointer;}
  .pv-nav:hover{background:rgba(255,255,255,.18);}
  .pv-prev{left:14px;}
  .pv-next{right:14px;}

  @media (max-width: 860px){
    .pv-overlay{align-items:stretch;justify-content:flex-start;}
    .pv-modal{flex-direction:column;width:min(720px,96vw);height:min(calc(var(--vh, 1vh) * 92),860px);margin:auto;position:relative;}
    .pv-right{min-width:0;}
    .pv-left{flex:1;min-height:42vh;}

    /* prevent nav from colliding with avatar/header on small screens */
    .pv-nav{position:absolute;top:calc(22vh);transform:translateY(-50%);}
    .pv-prev{left:10px;}
    .pv-next{right:10px;}
  }
  @media (max-width: 520px){
    .pv-overlay{padding:10px;}
    .pv-modal{height:calc(var(--vh, 1vh) * 100 - 20px);}
    .pv-head{padding:12px;}
    .pv-comments{padding:10px 12px;padding-bottom:160px;}

    .pv-nav{width:40px;height:40px;}
    .pv-nav i{font-size:18px;}
  }

  body.pv-body-lock{touch-action:none;}
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

(function(){
  const modal = document.getElementById('profileShopBuyModal');
  if (!modal) return;

  const titleEl = document.getElementById('profileShopBuyTitle');
  const priceEl = document.getElementById('profileShopBuyPrice');
  const qtyEl = document.getElementById('profileShopBuyQty');
  const addrEl = document.getElementById('profileShopBuyAddress');
  const notesEl = document.getElementById('profileShopBuyNotes');
  const phoneEl = document.getElementById('profileShopBuyPhone');
  const msgEl = document.getElementById('profileShopBuyMsg');
  const submitBtn = document.getElementById('profileShopBuySubmit');
  const cancelBtn = document.getElementById('profileShopBuyCancel');
  const stripeEnabled = <?php echo $profileShopStripeEnabled ? 'true' : 'false'; ?>;
  const profileViewId = <?php echo (int)$viewId; ?>;
  let activeProductId = 0;

  function openModal(btn){
    activeProductId = parseInt(btn.getAttribute('data-shop-buy') || '0', 10);
    if (!activeProductId) return;
    if (titleEl) titleEl.textContent = btn.getAttribute('data-shop-title') || 'Product';
    if (priceEl) priceEl.textContent = btn.getAttribute('data-shop-price') || '';
    if (qtyEl) qtyEl.value = '1';
    if (addrEl) addrEl.value = '';
    if (notesEl) notesEl.value = '';
    if (phoneEl) phoneEl.value = '';
    if (msgEl) {
      msgEl.textContent = stripeEnabled
        ? 'You will be redirected to Stripe for secure payment.'
        : 'Order will be placed; the seller confirms payment manually.';
    }
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeModal(){
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    activeProductId = 0;
    if (submitBtn) submitBtn.disabled = false;
  }

  document.querySelectorAll('[data-shop-buy]').forEach((btn) => {
    btn.addEventListener('click', () => openModal(btn));
  });

  if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

  if (submitBtn) {
    submitBtn.addEventListener('click', async function(){
      if (!activeProductId) return;
      const quantity = Math.max(1, Math.min(99, parseInt(qtyEl && qtyEl.value ? qtyEl.value : '1', 10) || 1));
      submitBtn.disabled = true;
      try {
        const body = new URLSearchParams();
        body.set('product_id', String(activeProductId));
        body.set('quantity', String(quantity));
        body.set('delivery_address', addrEl ? addrEl.value.trim() : '');
        body.set('buyer_notes', notesEl ? notesEl.value.trim() : '');
        body.set('buyer_phone', phoneEl ? phoneEl.value.trim() : '');
        body.set('profile_id', String(profileViewId));

        const res = await fetch('ajax/shop_buy.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString(),
          credentials: 'same-origin'
        });
        const data = await res.json();
        if (data.ok && data.checkout_url) {
          window.location.href = data.checkout_url;
          return;
        }
        if (data.ok) {
          closeModal();
          window.alert(data.message || 'Order placed.');
          return;
        }
        window.alert(data.message || 'Order failed.');
      } catch (e) {
        window.alert('Could not place order. Please try again.');
      } finally {
        submitBtn.disabled = false;
      }
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

  function escLine(s){ return pvEsc(s).replace(/  /g, ' &nbsp;'); }
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

function pvTruncateText(s, limit){
  const txt = (s ?? '').toString().trim();
  const lim = Math.max(20, Number(limit || 160));
  if (!txt) return { short:'', full:'', truncated:false };
  if (txt.length <= lim) return { short:txt, full:txt, truncated:false };
  // ✅ Keep as "incomplete sentence" (hard cut) + add Read more
  const short = txt.slice(0, lim).trimEnd();
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

function pvGridIdsForElement(el){
  const grid = el && el.closest ? el.closest('.ig-grid[data-grid-scope]') : null;
  const scope = grid ? String(grid.getAttribute('data-grid-scope') || '') : '';
  if (scope === 'gallery') return GALLERY_GRID_IDS;
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
    if (idx >= 0) return { ids: row.ids, idx };
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

function pvOpenInGrid(postId, gridIds){
  postId = Number(postId || 0);
  gridIds = Array.isArray(gridIds) ? gridIds : [];
  if (!postId || !gridIds.length) return;
  pvActiveGridIds = gridIds;
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
    pvOpenByIndex(ctx.idx);
    return;
  }
  pvActiveGridIds = GRID_IDS;
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
function pvRenderCaption(post, atts){
  const title = (post?.title || '').toString().trim();
  const desc  = (post?.description || post?.body || '').toString().trim();
  const hasMedia = Array.isArray(atts) && atts.length > 0;

  // ✅ If there is NO media on the left, we show text on the left only.
  // So the right caption should be hidden to avoid duplicate text.
  if (!hasMedia) {
    pv.caption.style.display = 'none';
    pv.caption.innerHTML = '';
    return;
  }

  // Nothing to show
  if (!title && !desc) {
    pv.caption.style.display = 'none';
    pv.caption.innerHTML = '';
    return;
  }

  pv.caption.style.display = '';

  // ✅ Title always stays at the top (no name beside title)
  const titleHtml = title ? `<div class="pv-cap-title">${pvEsc(title)}</div>` : '';

  // ✅ Description goes under the title (Read more / Show less when long)
  if (!desc) {
    pv.caption.innerHTML = `<div class="pv-cap">${titleHtml}</div>`;
    return;
  }

  const t = pvTruncateText(desc, 170);

  if (!t.truncated) {
    pv.caption.innerHTML = `<div class="pv-cap">${titleHtml}<div class="pv-cap-desc">${pvFormatRichText(t.full)}</div></div>`;
    return;
  }

  pv.caption.innerHTML = `
    <div class="pv-cap" data-expanded="0">
      ${titleHtml}
      <div class="pv-cap-desc">
        <span class="pv-cap-short">${pvFormatRichText(t.short)}<span class="pv-rich-ellipsis">&hellip;</span></span>
        <span class="pv-cap-full" style="display:none;">${pvFormatRichText(t.full)}</span>
        <a href="#" class="pv-readmore">Read more</a>
      </div>
    </div>
  `;
}

function pvRenderMedia(post, atts){
  // Show first attachment if any; otherwise show title/body card
  const title = (post?.title || '').trim();
  const desc  = (post?.description || '').trim();
  const body  = (post?.body || '').trim();

  // ✅ Mobile/tablet: enable LEFT scroll only for "no media + has long text"
  try{
    const hasMedia = Array.isArray(atts) && atts.length > 0;
    const textOnly = !hasMedia && ((desc || body || '').trim() !== '');
    const isSmall  = window.matchMedia && window.matchMedia('(max-width: 900px)').matches;
    if (pv.left) pv.left.classList.toggle('pv-left-scroll', !!(isSmall && textOnly));
  }catch(e){}

  if (Array.isArray(atts) && atts.length > 0) {
    const a = atts[0];
    const type = (a?.type || '').toLowerCase();
    const url  = (a?.url || a?.file_path || '').toString();
    const thumb= (a?.thumb_url || a?.thumb_path || '').toString();

    if (type === 'video' || /\.(mp4|webm|ogg|mov|m4v)(\?.*)?$/i.test(url)) {
      pv.media.innerHTML = `<video src="${pvEsc(url)}" controls playsinline preload="metadata"></video>`;
      return;
    }

    // docs / pdf / pptx etc => iframe (best effort)
    if (type === 'pdf' || /\.(pdf|docx|pptx|doc)(\?.*)?$/i.test(url)) {
      pv.media.innerHTML = `<iframe src="${pvEsc(url)}" style="width:100%;height:100%;border:0;"></iframe>`;
      return;
    }

    // image/gif fallback
    const img = thumb || url;
    pv.media.innerHTML = `<img src="${pvEsc(img)}" alt="" />`;
    return;
  }

  // no attachments
  const t = (title || '').trim();
  const text = (desc || body || '').trim();

  // ✅ Title only (no description/body) => center title in the left panel
  if (t && !text) {
    pv.media.innerHTML = `
      <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;padding:26px;">
        <div style="max-width:640px;color:#fff;text-align:center;">
          <div style="font-weight:800;font-size:24px;line-height:1.25;white-space:normal;word-break:break-word;">${pvEsc(t)}</div>
        </div>
      </div>
    `;
    return;
  }

  const cut = pvTruncateText(text, 220);
  pv.media.innerHTML = `
    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;padding:26px;">
      <div style="max-width:640px;color:#fff;text-align:left;">
        ${t ? `<div style="font-weight:800;font-size:22px;line-height:1.2;">${pvEsc(t)}</div>` : ``}
        ${text ? `
          <div class="pv-media-text" data-expanded="0" style="margin-top:${t ? '10px' : '0'};white-space:normal;word-break:break-word;">
            ${cut.truncated ? `
              <span class="pv-media-short">${pvFormatRichText(cut.short)}<span class="pv-rich-ellipsis">&hellip;</span></span>
              <span class="pv-media-full" style="display:none;">${pvFormatRichText(cut.full)}</span>
              <a href="#" class="pv-readmore">Read more</a>
            ` : `<span>${pvFormatRichText(cut.full)}</span>`}
          </div>
        ` : ''}
      </div>
    </div>
  `;
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
          <div class="a"><img src="${pvEsc(ava)}" alt="" /></div>
          <div class="b">
            <div class="bubble">
              <div class="t"><b>${pvEsc(nm)}</b> ${pvEsc(txt)}</div>
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
  }

  // counts
  const loveN = Number(counts.love_count || 0);
  const likeN = Number(counts.like_count || 0);
  const commentN = Number(post.comment_count ?? pv.comN?.textContent ?? 0);
  const viewN = Number(post.views_count ?? String(pv.viewsText?.textContent || '0').split(' ')[0] ?? 0);
  const totalReactions = loveN + likeN;
  pv.loveN.textContent = String(loveN);
  pv.likeN.textContent = String(likeN);
  pv.comN.textContent = String(commentN);
  pv.likesText.textContent = `${totalReactions} ${totalReactions === 1 ? 'reaction' : 'reactions'}`;
  pv.commentsLink.textContent = `View all ${commentN} ${commentN === 1 ? 'comment' : 'comments'}`;
  pv.viewsText.textContent = `${viewN} ${viewN === 1 ? 'view' : 'views'}`;

  // my reaction
  const my = (counts.my_reaction || '').toString();
  pvCurrentReaction = my;
  if(window.MSBReactions){
    window.MSBReactions.applyReactionButton(pv.love, my !== 'like' ? my : '', 'love');
    window.MSBReactions.applyLikeButton(pv.like, my === 'like' ? my : '');
  }else{
    pv.love.classList.toggle('is-love', my !== '' && my !== 'like');
    pv.like.classList.toggle('is-like', my === 'like');
  }
}

function pvCountMeta(){
  return {
    comment_count: Number(pv.comN?.textContent || 0),
    views_count: Number(String(pv.viewsText?.textContent || '0').split(' ')[0] || 0)
  };
}

async function pvLoad(postId){
  pv.media.innerHTML = `<div style="color:#fff;opacity:.8;">Loading…</div>`;
  pv.caption.style.display = 'none';
  pv.caption.innerHTML = '';
  pv.comments.innerHTML = `<div class="t" style="color:rgba(15,23,42,.55);font-size:13px;padding:14px 4px;">Loading…</div>`;
  try {
    // ✅ count_view=1 so analytics/unique views update when user opens in modal
    const view = await pvJson(`feed_api.php?ajax=view&id=${encodeURIComponent(postId)}&count_view=1`, { credentials:'same-origin' });
    pvRenderMedia(view.post, view.attachments);
    pvRenderCaption(view.post, view.attachments);
    pvRenderComments(view.post, view.comments);
    pvApplyCounts(view);
    pv.comN.textContent = String((Array.isArray(view.comments) ? view.comments.length : 0));
    pv.commentsLink.textContent = `View all ${pv.comN.textContent} ${Number(pv.comN.textContent) === 1 ? 'comment' : 'comments'}`;

    // share/save counts + my flags
    const tc = await pvJson(`feed_api.php?ajax=track_counts&post_id=${encodeURIComponent(postId)}`, { credentials:'same-origin' });
    pv.shareN.textContent = String(Number(tc.share_count || 0));
    pv.saveN.textContent  = String(Number(tc.save_count || 0));
    pv.share.classList.toggle('is-share', Number(tc.my_shared || 0) === 1);
    pv.save.classList.toggle('is-save', Number(tc.my_saved || 0) === 1);

  } catch (e) {
    pv.media.innerHTML = `<div style="color:#fff;opacity:.85;padding:24px;">Failed to load post.</div>`;
    pv.caption.style.display = 'none';
    pv.caption.innerHTML = '';
    pv.comments.innerHTML = `<div style="color:#b91c1c;font-size:13px;padding:14px 4px;">${pvEsc(e?.message || 'Failed')}</div>`;
  }
}

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

// ✅ Preload neighbor grid tiles (fast next/prev feel)
function pvPreloadTileByPostId(postId){
  try {
    postId = Number(postId || 0);
    if (!postId) return;
    const el = document.querySelector(`.ig-item[data-post-id="${postId}"]`);
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
  pvPreloadTileByPostId(ids[pvIndex + 1]);
  pvPreloadTileByPostId(ids[pvIndex - 1]);
}

// Grid click
document.querySelectorAll('.ig-grid .ig-item').forEach(a => {
  a.addEventListener('click', (e) => {
    e.preventDefault();
    const postId = Number(a.getAttribute('data-post-id') || 0);
    if (!postId) return;
    pvOpenInGrid(postId, pvGridIdsForElement(a));
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

// Focus comment — open the same leftbar comments door as Posts tab / friend-requests rail
pv.focusComment.addEventListener('click', (e) => {
  e.preventDefault();
  e.stopPropagation();
  if (!pvPostId) return;
  if (typeof window.openProfileCommentsTray === 'function') {
    window.openProfileCommentsTray(pvPostId);
    return;
  }
  if (window.TTComments && typeof window.TTComments.toggle === 'function') {
    window.TTComments.toggle(pvPostId, pvCommentsCache || []);
    return;
  }
  pv.text.focus();
});
pv.commentsLink.addEventListener('click', (e) => {
  e.preventDefault();
  e.stopPropagation();
  if (!pvPostId) return;
  if (typeof window.openProfileCommentsTray === 'function') {
    window.openProfileCommentsTray(pvPostId);
    return;
  }
  if (window.TTComments && typeof window.TTComments.toggle === 'function') {
    window.TTComments.toggle(pvPostId, pvCommentsCache || []);
    return;
  }
  try {
    pv.comments.scrollIntoView({ block:'nearest', behavior:'smooth' });
    pv.text.focus();
  } catch (err) {
    pv.text.focus();
  }
});

// React (love/like)
pv.love.addEventListener('click', async () => {
  if (!pvPostId) return;
  if (pvCurrentReaction === 'love') return;
  try {
    const data = await pvJson('feed_api.php?ajax=react', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:`post_id=${encodeURIComponent(pvPostId)}&reaction=${encodeURIComponent('love')}`,
      credentials:'same-origin'
    });
    pvApplyCounts({ post: pvCountMeta(), counts: data.counts || {} });
  } catch (e) {}
});

pv.like.addEventListener('click', async () => {
  if (!pvPostId) return;
  if (pvCurrentReaction === 'like') return;
  try {
    const data = await pvJson('feed_api.php?ajax=react', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:`post_id=${encodeURIComponent(pvPostId)}&reaction=${encodeURIComponent('like')}`,
      credentials:'same-origin'
    });
    pvApplyCounts({ post: pvCountMeta(), counts: data.counts || {} });
  } catch (e) {}
});

if(window.MSBReactions){
  window.MSBReactions.bindLikePicker('#pvLove', async function(_btn, reaction){
    if (!pvPostId || !reaction || reaction === pvCurrentReaction) return;
    try {
      const data = await pvJson('feed_api.php?ajax=react', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body:`post_id=${encodeURIComponent(pvPostId)}&reaction=${encodeURIComponent(reaction)}`,
        credentials:'same-origin'
      });
      pvApplyCounts({ post: pvCountMeta(), counts: data.counts || {} });
    } catch (e) {}
  });
  window.MSBReactions.bindLikePicker('.pv-clike', async function(btn, reaction){
    const cid = Number(btn.getAttribute('data-cid') || 0);
    if (!pvPostId || !cid || !reaction) return;
    if (String(btn.getAttribute('data-reaction') || '') === String(reaction)) return;
    pvAlertFocusCommentId = cid;
    try {
      await pvJson('feed_api.php?ajax=comment_like', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body:`post_id=${encodeURIComponent(pvPostId)}&comment_id=${encodeURIComponent(cid)}&reaction=${encodeURIComponent(reaction)}`,
        credentials:'same-origin'
      });
      pvLoad(pvPostId);
    } catch (e) {}
  });
}

// Share / Save
pv.share.addEventListener('click', async () => {
  if (!pvPostId) return;
  try {
    await pvJson('feed_api.php?ajax=share', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:`post_id=${encodeURIComponent(pvPostId)}`,
      credentials:'same-origin'
    });
    const tc = await pvJson(`feed_api.php?ajax=track_counts&post_id=${encodeURIComponent(pvPostId)}`, { credentials:'same-origin' });
    pv.shareN.textContent = String(Number(tc.share_count || 0));
    pv.share.classList.toggle('is-share', Number(tc.my_shared || 0) === 1);
  } catch (e) {}
});

pv.save.addEventListener('click', async () => {
  if (!pvPostId) return;
  try {
    await pvJson('feed_api.php?ajax=save', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:`post_id=${encodeURIComponent(pvPostId)}`,
      credentials:'same-origin'
    });
    const tc = await pvJson(`feed_api.php?ajax=track_counts&post_id=${encodeURIComponent(pvPostId)}`, { credentials:'same-origin' });
    pv.saveN.textContent  = String(Number(tc.save_count || 0));
    pv.save.classList.toggle('is-save', Number(tc.my_saved || 0) === 1);
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
    var meta = window.__MSB_APPEARANCE_PALETTES && window.__MSB_APPEARANCE_PALETTES[mode];
    return (meta && meta.dark) ? 'dark' : 'light';
  }

  function getThemeControls(){
    return {
      autoCtrl: document.querySelector('#panel-gear .gear-control[data-local-field="theme_auto_enabled"]'),
      manualCtrl: document.querySelector('#panel-gear .gear-control[data-field="appearance_mode"]')
    };
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
      window.__MSBThemeCore.applyThemeFromPrefs(prefs);
      return prefs;
    }
    return currentThemePrefs();
  }

  function applyAppearanceMode(mode, state){
    var ctrls = getThemeControls();
    mode = String(mode || 'dark').toLowerCase();
    if (ctrls.autoCtrl) ctrls.autoCtrl.value = '0';
    if (ctrls.manualCtrl) {
      ctrls.manualCtrl.value = mode;
      ctrls.manualCtrl.disabled = false;
    }
    applyThemePrefs({
      autoEnabled: false,
      appearanceMode: mode,
      manualMode: manualModeForAppearance(mode)
    });
    return saveThemeAppearanceMode(mode, state);
  }

  function syncThemeGearControls(){
    var prefs = currentThemePrefs();
    var ctrls = getThemeControls();
    if (ctrls.autoCtrl) ctrls.autoCtrl.value = prefs.autoEnabled ? '1' : '0';
    if (ctrls.manualCtrl) {
      var mode = prefs.autoEnabled ? (prefs.appearanceMode || prefs.manualMode || 'dark') : (prefs.appearanceMode || prefs.manualMode || 'dark');
      ctrls.manualCtrl.value = mode;
      ctrls.manualCtrl.disabled = false;
    }
  }

  function saveThemeAppearanceMode(mode, state){
    var form = new FormData();
    form.append('field', 'appearance_mode');
    form.append('value', mode);
    flashState(state, 'Saving', 'is-saving');
    return fetch('save_privacy.php', {
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
      return data;
    })
    .catch(function(err){
      flashState(state, 'Error', 'is-error');
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

      if (localField === 'theme_auto_enabled') {
        var ctrls = getThemeControls();
        var appearanceMode = ctrls.manualCtrl ? (ctrls.manualCtrl.value || 'dark') : 'dark';
        var autoEnabled = (ctrl.value === '1');
        applyThemePrefs({
          autoEnabled: autoEnabled,
          appearanceMode: autoEnabled ? 'system' : appearanceMode,
          manualMode: manualModeForAppearance(appearanceMode)
        });
        if (ctrls.manualCtrl) ctrls.manualCtrl.disabled = false;
        saveThemeAppearanceMode(autoEnabled ? 'system' : appearanceMode, state).catch(function(){});
        return;
      }

      if (field === 'appearance_mode') {
        applyAppearanceMode(ctrl.value || 'dark', state).catch(function(){});
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
  var PROFILE_FRIEND_STATUS = <?php echo json_encode($isOwnProfile ? 'self' : $friendStatus); ?>;
  var PROFILE_IS_OWN = <?php echo $isOwnProfile ? 'true' : 'false'; ?>;
  var PROFILE_VIEW_ID = <?php echo (int)$viewId; ?>;
  window.msbSyncProfileLoveCountDelta = function(delta){
    delta = Number(delta || 0);
    if(!delta) return;
    document.querySelectorAll('.ig-profile-love-count b').forEach(function(el){
      var n = Math.max(0, Number(el.textContent || 0) + delta);
      el.textContent = String(n);
    });
  };
  window.msbOnPostLoveCountChange = function(detail){
    detail = detail || {};
    if(Number(detail.ownerUserId || 0) !== PROFILE_VIEW_ID) return;
    if(typeof detail.delta !== 'undefined'){
      window.msbSyncProfileLoveCountDelta(detail.delta);
      return;
    }
    if(typeof detail.loveCount !== 'undefined'){
      document.querySelectorAll('.ig-profile-love-count b').forEach(function(el){
        el.textContent = String(Math.max(0, Number(detail.loveCount || 0)));
      });
    }
  };
  var PROFILE_CAN_FOLLOW_PUBLISHERS = <?php echo $canFollowPublishers ? 'true' : 'false'; ?>;
  var PROFILE_HIDE_PRIVATE_CONTACT = <?php echo $canViewProfilePrivateContact ? 'false' : 'true'; ?>;
  var API_URL = 'feed_api.php';
  var PCM_FRIES_ICON = <?= json_encode(post_card_menu_fries_icon_html(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  var loaded = false;
  var loading = false;
  var profileCommentsCache = {};

  try { window.API_URL = API_URL; } catch(e) {}

  function openProfileCommentsTray(postId){
    postId = Number(postId || 0);
    if(!postId || !(window.TTComments && typeof window.TTComments.openForPost === 'function')) return;
    if(window.TTComments.isOpen() && window.TTComments.getPostId() === postId){
      window.TTComments.close();
      return;
    }
    var cached = Object.prototype.hasOwnProperty.call(profileCommentsCache, postId)
      ? profileCommentsCache[postId]
      : null;
    window.TTComments.openForPost(postId, cached, {
      onLoaded: function(comments){
        profileCommentsCache[postId] = comments;
        var $card = $('#profilePostsFeed .mf-card[data-id="'+postId+'"]');
        if($card.length) $card.find('.mf-cmt').text(String(comments.length));
      }
    });
  }
  window.openProfileCommentsTray = openProfileCommentsTray;

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
      if(window.TTComments && typeof window.TTComments.setPost === 'function'){
        window.TTComments.setPost(pid, comments, false);
      }
    });
  };

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
    maxSent = Number(maxSent || 4);
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
    text = formatReadMoreTextPreserve(String(text || '').trim());
    if(!text) return '';
    var sents = text.split(/[.!?]+/).map(function(s){ return s.trim(); }).filter(Boolean);
    var formatted = formatPostCardTextHtml(text);
    if(sents.length >= 4){
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
  function countsFromItem(it){
    it = it || {};
    return {
      comment_count: Number(it.comment_count || 0),
      love_count: Number(it.love_count || 0),
      share_count: Number(it.share_count || 0),
      save_count: Number(it.save_count || 0),
      my_reaction: String(it.my_reaction || ''),
      is_saved: Number(it.my_saved || 0),
      is_shared: Number(it.my_shared || 0)
    };
  }
  function applyCounts($card, counts){
    counts = counts || {};
    $card.find('.mf-cmt').text(String(counts.comment_count || 0));
    $card.find('.mf-act.mf-love .mf-num').text(String(counts.love_count || 0));
    var my = String(counts.my_reaction || '');
    $card.attr('data-my-reaction', my);
    $card.find('.mf-act').removeClass('is-love is-save is-share');
    if(my === 'love') $card.find('.mf-act.mf-love').addClass('is-love');
    if(Number(counts.is_saved || 0) === 1) $card.find('.mf-act.mf-save').addClass('is-save');
    if(Number(counts.is_shared || 0) === 1) $card.find('.mf-act.mf-share').addClass('is-share');
    $card.find('.mf-act.mf-love .msb-pact-heart').toggleClass('is-active', my === 'love');
    $card.find('.mf-act.mf-save .msb-pact-bookmark').toggleClass('is-active', Number(counts.is_saved || 0) === 1);
  }
  function cardCountsFromDom($card){
    return {
      comment_count: Number($card.find('.mf-cmt').text() || 0),
      love_count: Number($card.find('.mf-act.mf-love .mf-num').text() || 0),
      share_count: Number($card.find('.mf-act.mf-share .mf-num').text() || 0),
      save_count: Number($card.find('.mf-act.mf-save .mf-num').text() || 0),
      my_reaction: String($card.attr('data-my-reaction') || ''),
      is_saved: $card.find('.mf-act.mf-save').hasClass('is-save') ? 1 : 0,
      is_shared: $card.find('.mf-act.mf-share').hasClass('is-share') ? 1 : 0
    };
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
    var viewportH = Math.max(window.innerHeight || 0, 320);
    if(window.matchMedia('(max-width: 767.98px)').matches) return Math.max(viewportH - 220, 280);
    return Math.min(Math.round(viewportH * 0.78), 960);
  }
  function initialMediaCardStyleFromDims(dims, isPhoneShot){
    if(!dims || !Number(dims.w || 0) || !Number(dims.h || 0)) return '';
    var aspectW = Number(dims.w || 0);
    var aspectH = Number(dims.h || 0);
    var aspect = aspectW / aspectH;
    var maxVideoH = maxVideoHeight();
    var feed = document.getElementById('profilePostsFeed');
    var feedWidth = feed ? Math.floor(feed.clientWidth || 0) : Math.min(Math.max(window.innerWidth || 0, 320), PROFILE_FEED_MAX);
    var availableWidth = Math.max(280, feedWidth || PROFILE_FEED_MAX);
    var desiredWidth = Math.round(aspect * maxVideoH);
    var maxByShape = aspect < 0.8 ? 520 : (aspect > 1.15 ? 760 : 620);
    if(isPhoneShot && window.matchMedia('(max-width: 767.98px)').matches) maxByShape = 430;
    var safeWidth = Math.max(280, Math.min(desiredWidth, availableWidth, maxByShape));
    if(aspect >= 0.8 && aspect <= 1.15) safeWidth = Math.min(availableWidth, Math.max(safeWidth, 420));
    if(aspect > 1.15) safeWidth = Math.min(availableWidth, Math.max(safeWidth, 560));
    return '--post-media-card-width:'+String(safeWidth)+'px;width:min(100%,'+String(safeWidth)+'px);max-width:100%;margin-left:auto;margin-right:auto;padding:8px 40px;box-sizing:border-box;';
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
    
    try{ card.style.removeProperty('--post-media-card-width'); }catch(e){}
  }
  function resetProfileNonMediaCardWidths(scope){
    try{
      var root = (scope && scope.jquery) ? scope[0] : (scope || document.getElementById('profilePostsFeed'));
      if(!root || !root.querySelectorAll) return;
      root.querySelectorAll('.mf-card:not(.mf-card-phone-shot):not(.is-single-video-post):not(.is-single-image-post)').forEach(clearProfileDeviceCardWidth);
    }catch(e){}
  }
  function applyPublicMediaCardWidth(card, aspectW, aspectH){
    if(!card) return;
    aspectW = Number(aspectW || 0);
    aspectH = Number(aspectH || 0);
    if(!aspectW || !aspectH) return;

    var media = card.querySelector('.media-stage.standard-video-stage, .media-stage.standard-image-stage');
    var video = card.querySelector('.media-stage.standard-video-stage > video');
    var image = card.querySelector('.media-stage.standard-image-stage > img');

    var viewportH = Math.max(window.innerHeight || 0, 320);
    var maxVideoH = window.matchMedia('(max-width: 767.98px)').matches
      ? Math.max(viewportH - 220, 280)
      : Math.min(Math.round(viewportH * 0.78), 960);

    var aspect = aspectW / aspectH;
    var feed = card.closest('.mf-feed') || document.getElementById('profilePostsFeed');
    var feedWidth = feed ? Math.floor(feed.clientWidth) : Math.round(aspect * maxVideoH);
    var availableWidth = Math.max(280, feedWidth);
    var desiredWidth = Math.round(aspect * maxVideoH);
    var maxByShape = aspect < 0.8 ? 520 : (aspect > 1.15 ? 760 : 620);
    if(card.classList.contains('mf-card-phone-shot') && window.matchMedia('(max-width: 767.98px)').matches) maxByShape = 430;
    var safeWidth = Math.max(280, Math.min(desiredWidth, availableWidth, maxByShape));
    if(aspect >= 0.8 && aspect <= 1.15) safeWidth = Math.min(availableWidth, Math.max(safeWidth, 420));
    if(aspect > 1.15) safeWidth = Math.min(availableWidth, Math.max(safeWidth, 560));

    card.style.width = String(safeWidth) + 'px';
    card.style.maxWidth = '100%';
    card.style.marginLeft = 'auto';
    card.style.marginRight = 'auto';
    card.style.setProperty('box-sizing', 'border-box', 'important');
    card.style.setProperty('padding', card.querySelector('.mf-head--on-media') ? '8px 40px' : '20px', 'important');
    card.style.setProperty('--post-media-card-width', String(safeWidth) + 'px');

    if(media){
      media.style.width = '100%';
      media.style.maxWidth = '100%';
      media.style.height = 'auto';
      media.style.aspectRatio = '';
      media.style.background = 'transparent';
      media.style.removeProperty('overflow');
      media.style.marginLeft = '';
      media.style.marginRight = '';
    }
    if(video){
      video.style.width = '100%';
      video.style.height = 'auto';
      video.style.maxHeight = '';
      video.style.objectFit = 'contain';
      video.style.background = 'transparent';
      video.style.removeProperty('padding');
    }
    if(image){
      image.style.width = '100%';
      image.style.height = 'auto';
      image.style.objectFit = 'contain';
      image.style.background = 'transparent';
      image.style.removeProperty('padding');
      image.style.removeProperty('box-sizing');
      image.style.removeProperty('max-height');
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
    return classes.join(' ');
  }
  function normalActions(){
    return '<div class="mf-actions"><div class="mf-left">'+
      '<a class="mf-act mf-love" type="button" title="Love"><i class="msb-pact msb-pact-heart" aria-hidden="true"></i><span class="mf-num mf-love">0</span></a>'+
      '<button type="button" class="mf-act mf-comment js-open-profile-comments-door" title="Comment" aria-label="Comment"><i class="msb-pact msb-pact-comment" aria-hidden="true"></i><span class="mf-num mf-cmt">0</span></button>'+
      '<a class="mf-act mf-share" type="button" title="Share"><i class="msb-pact msb-pact-share" aria-hidden="true"></i><span class="mf-num mf-share">0</span></a>'+
      '</div><div class="mf-right">'+
      '<a class="mf-act mf-save" type="button" title="Save"><i class="msb-pact msb-pact-bookmark" aria-hidden="true"></i><span class="mf-num mf-save">0</span></a>'+
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
    if(st === 'self') return '';
    var extraCls = st === 'friends' ? ' is-friends' : (st === 'incoming_pending' ? ' is-accept' : (st === 'outgoing_pending' ? ' is-pending' : ' primary'));
    var inner = st === 'outgoing_pending' ? '<span class="mf-media-action-label">Sent</span>' : (st === 'incoming_pending' ? '<span class="mf-media-action-label">Accept</span>' : (st === 'friends' ? 'Friends' : '<i class="fa fa-plus" aria-hidden="true"></i>'));
    return '<button type="button" class="'+cls+' mf-media-action-circle'+extraCls+'" data-peer-id="'+esc(uid)+'" data-peer-code="'+esc(PROFILE_HIDE_PRIVATE_CONTACT ? '' : String(it.friend_code || ''))+'" data-status="'+esc(st)+'" aria-label="Add Friend" title="Add Friend">'+inner+'</button>';
  }
  function profileIsPublisherItem(it){
    it = it || {};
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
  function profileMusicRowHtml(it){
    var title = String((it && it.music_title) || '').trim();
    var artist = String((it && it.music_artist) || '').trim();
    if(!title && !artist) return '';
    var html = '<div class="mf-music-row" aria-label="Music"><i class="fa fa-music mf-music-ic" aria-hidden="true"></i>';
    if(title) html += '<span class="mf-music-title">'+esc(title)+'</span>';
    if(title && artist) html += '<span class="mf-music-dot">&middot;</span>';
    if(artist) html += '<span class="mf-music-artist">'+esc(artist)+'</span>';
    html += '</div>';
    return html;
  }
  function profileBuildHeadHtml(it, isOwner, pid, onMedia){
    var name = it.display_name || it.username || '';
    var avatarUrl = avatarUrlFor(it);
    var time = mfDeviceTimeLabel(it, postDate(it));
    var headClass = 'mf-head' + (onMedia ? ' mf-head--on-media' : '');
    return '<div class="'+headClass+'">'+
      '<a class="mf-peer-link" href="'+esc(peerProfileHref(it))+'">'+
        '<div class="mf-avatar"><img src="'+esc(avatarUrl)+'" alt="'+esc(name)+'"></div>'+
        '<div class="mf-meta"><div class="mf-name-row">'+
          '<div class="mf-name">'+esc(name)+'</div>'+
          (time ? '<span class="mf-dot">&bull;</span><div class="mf-time">'+esc(time)+'</div>' : '')+
        '</div>'+profileMusicRowHtml(it)+'</div></a>'+
      '<div class="mf-menu-wrap post-card-menu-wrap" data-post-id="'+esc(String(pid))+'" data-peer-id="'+esc(String(it.user_id || ''))+'" data-is-owner="'+(isOwner ? '1' : '0')+'" data-menu-surface="profile">'+
        '<button type="button" class="mf-menu-btn post-card-menu-btn" aria-label="Post menu" title="Menu" aria-haspopup="true" aria-expanded="false">'+PCM_FRIES_ICON+'</button>'+
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
    var hasMedia = !!psrc;
    if(!hasMedia && !title && !hasBody) return '';
    var isTextOnly = !hasMedia;
    var attCount = Number(it.attachment_count || 0);
    var isSingleMedia = attCount <= 1;

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

    var mediaHtml = '';
    if(hasMedia){
      if(pkind === 'image' || pkind === 'gif'){
        cardClass += ' is-single-image-post mf-card-single-image';
        mediaHtml = '<div class="'+buildMediaClassList({ standardImage:isSingleMedia, isSingleMedia:isSingleMedia, isPhoneShot:isPhoneShot })+'"'+mediaStyleAttr+' data-shape-ready="1">'+
          '<img src="'+esc(psrc)+'" alt=""></div>';
      } else if(pkind === 'video'){
        cardClass += ' is-single-video-post mf-card-single-video';
        var poster = pthumb ? (' poster="'+esc(pthumb)+'"') : '';
        mediaHtml = '<div class="'+buildMediaClassList({ standardVideo:true, isPhoneShot:isPhoneShot })+'"'+mediaStyleAttr+' data-shape-ready="0">'+
          '<video class="ig-smart-feed-video" src="'+esc(psrc)+'"'+poster+' controls playsinline preload="metadata" data-smart-video="1"></video></div>';
      } else {
        mediaHtml = '<div class="mf-media"><a href="'+esc(psrc)+'" target="_blank" rel="noopener">Open attachment</a></div>';
      }
    }

    var initialCardStyle = '';
    if(isTextOnly && isPhoneShot && deviceDims){
      initialCardStyle = initialMediaCardStyleFromDims(deviceDims, isPhoneShot);
    } else if(!isTextOnly && hasMedia && (pkind === 'image' || pkind === 'gif' || pkind === 'video')){
      initialCardStyle = initialMediaCardStyleFromDims(deviceDims || initialMediaAspect(it, null), isPhoneShot);
    }
    var initialCardStyleAttr = initialCardStyle ? (' style="'+esc(initialCardStyle)+'"') : '';
    var avatarText = mfAvatarInit(name);

    var useHeadOnMedia = hasMedia;
    var followHtml = profileFriendBtnHtml(it, isOwner);
    if(useHeadOnMedia){
      mediaHtml = profileWrapMediaShell(
        mediaHtml,
        profileBuildHeadHtml(it, isOwner, pid, true),
        followHtml
      );
    }

    return '<div class="'+cardClass+'" data-id="'+pid+'" data-post-id="'+pid+'" data-post-owner="'+(isOwner ? '1' : '0')+'" data-peer-id="'+esc(String(it.user_id || ''))+'" data-peer-code="'+esc(PROFILE_HIDE_PRIVATE_CONTACT ? '' : String(it.friend_code || ''))+'" data-account-kind="'+esc(String(it.account_kind || 'personal'))+'" data-is-publisher="'+(profileIsPublisherItem(it) ? '1' : '0')+'" data-is-following="'+(profilePublisherFollowingFromItem(it) ? '1' : '0')+'" data-my-saved="'+esc(String(Number(it.my_saved || 0)))+'" data-is-archived="'+esc(String(Number(it.is_archived || 0)))+'" data-friend-status="'+esc(profileFriendStatusFromItem(it))+'" data-title="'+esc(title)+'" data-author="'+esc(name)+'" data-date="'+esc(time)+'" data-avatar-url="'+esc(avatarUrl)+'" data-avatar-text="'+esc(avatarText)+'" data-full-desc="'+esc(body)+'"'+deviceDataAttrs+initialCardStyleAttr+'>'+
      (useHeadOnMedia ? '' : profileBuildHeadHtml(it, isOwner, pid, false))+
      (title ? '<div class="mf-title">'+esc(title)+'</div>' : '')+
      mediaHtml + (hasBody ? mfBuildBodyHtml(body) : '') + normalActions()+
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
      applyCounts($card, countsFromItem(it));
      try{ preflightProfileMediaCard($card[0], it); }catch(e){}
    });
    bindProfilePostsFeedSizing($wrap);
    resetProfileNonMediaCardWidths($wrap);
  }
  function currentSearch(){
    try{
      return String(new URL(window.location.href).searchParams.get('gallery_search') || '').trim();
    }catch(e){ return ''; }
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
        $('#profilePostsFeed').html('<div class="mf-feed-empty">Could not load posts.</div>');
        return;
      }
      renderItems(res.items || []);
    }).fail(function(){
      loading = false;
      $('#profilePostsFeed').html('<div class="mf-feed-empty">Could not load posts.</div>');
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

  $(document).on('click', '.ig-profile-love-btn', function(e){
    e.preventDefault();
    e.stopPropagation();
    if(PROFILE_IS_OWN) return;
    var btn = $(this);
    var loved = btn.toggleClass('is-loved').hasClass('is-loved');
    btn.attr('aria-pressed', loved ? 'true' : 'false');
    btn.find('i').toggleClass('fa-heart-o', !loved).toggleClass('fa-heart', loved);
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
  $(document).on('click', '#profilePostsFeed .mf-body .mf-readmore, #profilePostsFeed .js-open-readmore', function(e){
    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();
    var $b = $(this).closest('.mf-body');
    var $card = $(this).closest('.mf-card');
    if(!$b.length || !$card.length) return;
    mfOpenProfileReadMoreDrawer($card, String($b.attr('data-full') || ''));
  });
  $(document).on('click', '#profilePostsFeed .mf-love', function(e){
    e.preventDefault();
    var $card = $(this).closest('.mf-card');
    var pid = Number($card.data('id') || 0);
    if(!pid || String($card.attr('data-my-reaction') || '') === 'love') return;
    var loveBefore = cardCountsFromDom($card).love_count;
    mfPost('react', { post_id: pid, reaction: 'love' }, function(res){
      if(!res || !res.ok) return;
      var merged = cardCountsFromDom($card);
      if(res.counts){
        merged.love_count = Number(res.counts.love_count || merged.love_count);
        merged.my_reaction = String(res.counts.my_reaction || 'love');
      } else merged.my_reaction = 'love';
      applyCounts($card, merged);
      if(typeof window.msbSyncProfileLoveCountDelta === 'function'){
        window.msbSyncProfileLoveCountDelta(Number(merged.love_count || 0) - Number(loveBefore || 0));
      }
    });
  });
  $(document).on('click', '#profilePostsFeed .mf-save', function(e){
    e.preventDefault();
    var $card = $(this).closest('.mf-card');
    var pid = Number($card.data('id') || 0);
    if(!pid) return;
    mfPost('save', { post_id: pid }, function(res){
      if(!res || !res.ok) return;
      var merged = cardCountsFromDom($card);
      merged.save_count = Number(res.save_count || merged.save_count);
      merged.is_saved = Number((res.state && res.state.saved) || 0);
      applyCounts($card, merged);
    });
  });
  $(document).on('click', '#profilePostsFeed .mf-share', function(e){
    e.preventDefault();
    var $card = $(this).closest('.mf-card');
    var pid = Number($card.data('id') || 0);
    if(!pid) return;
    mfPost('share', { post_id: pid }, function(res){
      if(!res || !res.ok) return;
      var merged = cardCountsFromDom($card);
      merged.share_count = Number(res.share_count || merged.share_count);
      merged.is_shared = Number((res.state && res.state.shared) || 0);
      applyCounts($card, merged);
    });
  });

  window.ProfilePostsFeed = {
    ensureLoaded: function(force){ if(document.body.classList.contains('profile-posts-mode')) loadFeed(!!force); },
    reload: function(){ loaded = false; loadFeed(true); }
  };
  var ppResizeTimer = null;
  window.addEventListener('resize', function(){
    if(!document.body.classList.contains('profile-posts-mode')) return;
    if(ppResizeTimer) clearTimeout(ppResizeTimer);
    ppResizeTimer = setTimeout(function(){
      document.querySelectorAll('#profilePostsFeed .mf-card.is-single-video-post .media-stage.standard-video-stage > video, #profilePostsFeed .mf-card.is-single-image-post .media-stage.standard-image-stage > img').forEach(syncProfileMediaCard);
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

<div class="shop-buy-modal" id="profileShopBuyModal" aria-hidden="true" role="dialog" aria-labelledby="profileShopBuyTitle">
  <div class="shop-buy-card">
    <div class="shop-buy-head" id="profileShopBuyTitle">Product</div>
    <div class="shop-buy-sub" id="profileShopBuyPrice"></div>
    <div class="shop-buy-msg" id="profileShopBuyMsg"></div>
    <div class="shop-buy-body">
      <div>
        <label for="profileShopBuyQty">Quantity</label>
        <input type="number" id="profileShopBuyQty" min="1" max="99" value="1">
      </div>
      <div>
        <label for="profileShopBuyAddress">Delivery address</label>
        <textarea id="profileShopBuyAddress" rows="2" placeholder="Optional"></textarea>
      </div>
      <div>
        <label for="profileShopBuyPhone">Phone</label>
        <input type="text" id="profileShopBuyPhone" placeholder="Optional">
      </div>
      <div>
        <label for="profileShopBuyNotes">Notes for seller</label>
        <textarea id="profileShopBuyNotes" rows="2" placeholder="Optional"></textarea>
      </div>
    </div>
    <div class="shop-buy-foot">
      <button type="button" class="shop-buy-cancel" id="profileShopBuyCancel">Cancel</button>
      <button type="button" class="shop-buy-submit" id="profileShopBuySubmit">Place order</button>
    </div>
  </div>
</div>

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

  if(window.TTStories && typeof window.TTStories.setCatalog === 'function'){
    window.TTStories.setCatalog(Array.isArray(catalog) ? catalog : []);
  }

  document.addEventListener('click', function(e){
    var target = e.target;
    if(!target || !target.closest) return;
    var item = target.closest('.ig-story-item[data-story-key]');
    if(!item) return;
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
    if(target.closest('#tt-stories-wrap, .ig-story-item[data-story-key]')) return;
    if(window.TTStories && typeof window.TTStories.close === 'function'){
      window.TTStories.close();
    }
  });
})();
</script>

<?php theme_prefs_print_post_card_tail($dbh, $meId); ?>

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
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-music-row,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-music-ic,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-music-title,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-music-artist,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-music-dot,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-menu-btn:not(.post-card-menu-btn),
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head--on-media .mf-menu-btn:not(.post-card-menu-btn) i,
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
  background:transparent!important;
  border:0!important;
  color:var(--msb-palette-text)!important;
  box-shadow:none!important;
}
html[data-msb-appearance] body #profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn i,
html[data-msb-appearance] body #profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn .pcm-fries-icon{
  color:var(--msb-palette-text)!important;
}
html[data-msb-appearance] body.dark-auto #profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn,
html[data-theme="dark"] body.profile-page #profilePostsFeed .mf-head:not(.mf-head--on-media) .post-card-menu-btn{
  background:transparent!important;
  border:0!important;
  color:var(--msb-palette-text, #e6edf3)!important;
  box-shadow:none!important;
}
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head.mf-head--on-media .mf-peer-link{
  padding-right:44px !important;
  box-sizing:border-box !important;
}
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head.mf-head--on-media .mf-name-row{
  gap:3px !important;
  width:auto !important;
  justify-content:flex-start !important;
}
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head.mf-head--on-media .mf-name{
  flex:0 1 auto !important;
  max-width:calc(100% - 64px) !important;
}
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head.mf-head--on-media .mf-dot,
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head.mf-head--on-media .mf-time{
  margin-left:0 !important;
}
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head.mf-head--on-media .mf-music-row{
  width:auto !important;
  max-width:100% !important;
  align-self:flex-start !important;
  justify-content:flex-start !important;
}
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head.mf-head--on-media .mf-music-title{
  flex:0 1 auto !important;
}
html[data-msb-appearance] body #profilePostsFeed .mf-media-shell > .mf-head.mf-head--on-media .mf-music-artist{
  flex:0 1 auto !important;
  max-width:none !important;
}
</style>

<style id="profile-posts-feed-dividers-css">
/* Profile Posts tab — vertical lines only on 614px post column edges */
body.profile-page{
  --profile-post-divider:rgba(177,188,206,.42);
}
body.profile-page .ig-profile-head-rule{
  background:var(--profile-post-divider) !important;
}
body.profile-page.profile-posts-mode .profile-posts-feed-column,
body.profile-page:has(#panel-posts.active) .profile-posts-feed-column{
  width:100% !important;
  max-width:614px !important;
  margin-left:auto !important;
  margin-right:auto !important;
  box-sizing:border-box !important;
  position:relative !important;
  min-height:min(480px, calc(100vh - 420px));
  border-left:1px solid var(--profile-post-divider) !important;
  border-right:1px solid var(--profile-post-divider) !important;
}
body.profile-page.profile-posts-mode .ig-gallery-filter,
body.profile-page:has(#panel-posts.active) .ig-gallery-filter{
  border-bottom:1px solid var(--profile-post-divider) !important;
}
#profilePostsFeed.mf-feed{
  container-type:inline-size;
  box-sizing:border-box !important;
  width:100% !important;
  max-width:100% !important;
  margin:0 !important;
}
#profilePostsFeed .mf-card{
  position:relative !important;
  overflow:visible !important;
}
#profilePostsFeed .mf-card::after{
  content:'';
  position:absolute;
  left:50%;
  bottom:0;
  transform:translateX(-50%);
  width:100cqw;
  border-bottom:1px solid var(--profile-post-divider, rgba(177,188,206,.42));
  pointer-events:none;
  z-index:2;
}
html[data-msb-appearance] body.profile-page{
  --profile-post-divider:var(--msb-palette-border-strong, rgba(177,188,206,.42));
}
html[data-msb-appearance] body.profile-page .ig-profile-head-rule{
  background:var(--msb-palette-border-strong, rgba(177,188,206,.42)) !important;
}
html[data-msb-appearance] body.profile-page.profile-posts-mode{
  --profile-post-divider:var(--msb-palette-border-strong, rgba(177,188,206,.42));
}
html[data-msb-appearance] body.profile-page.profile-posts-mode .profile-posts-feed-column,
html[data-msb-appearance] body.profile-page:has(#panel-posts.active) .profile-posts-feed-column{
  border-left-color:var(--msb-palette-border-strong, rgba(177,188,206,.42)) !important;
  border-right-color:var(--msb-palette-border-strong, rgba(177,188,206,.42)) !important;
}
html[data-msb-appearance] body.profile-page.profile-posts-mode .ig-gallery-filter,
html[data-msb-appearance] body.profile-page:has(#panel-posts.active) .ig-gallery-filter{
  border-bottom-color:var(--msb-palette-border-strong, rgba(177,188,206,.42)) !important;
}
html[data-msb-appearance] body #profilePostsFeed .mf-card::after{
  border-bottom-color:var(--msb-palette-border-strong, rgba(177,188,206,.42)) !important;
}
</style>

<?php post_card_actions_menu_render_modals(); ?>
<?php post_card_actions_menu_render_js([
  'delete_mode' => 'profile',
  'staff_readonly' => false,
  'menu_surface' => 'profile',
  'api_url' => 'feed_api.php',
]); ?>

</body>
</html>
