<?php
declare(strict_types=1);

require_once __DIR__ . '/account_display_helpers.php';

if (!isset($feedTopChromePart)) {
    $feedTopChromePart = 'lead';
}

$rawName = '';
if (function_exists('myUserName')) {
    $rawName = trim((string)myUserName());
}
if ($rawName === '') {
    $rawName = trim((string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? ''));
}
if ($rawName === '') {
    $rawName = 'My Account';
}

$isPublisher = strtolower(trim((string)($_SESSION['user_account_kind'] ?? ''))) === 'publisher';
$feedTopDbh = null;
if (isset($GLOBALS['feedTopDbh']) && $GLOBALS['feedTopDbh'] instanceof PDO) {
    $feedTopDbh = $GLOBALS['feedTopDbh'];
} elseif (isset($dbh) && $dbh instanceof PDO) {
    $feedTopDbh = $dbh;
}

$feedTopChrome = account_display_name_parts($rawName, $isPublisher, $feedTopDbh);

$profileHref = 'profile.php';
$meCode = $GLOBALS['feedTopMeCode'] ?? null;
$feedTopMeId = (int)($GLOBALS['feedTopMeId'] ?? ($_SESSION['user_id'] ?? 0));
if (!empty($meCode)) {
    $profileHref .= '?friend_code=' . rawurlencode((string)$meCode);
} elseif ($feedTopMeId > 0) {
    $profileHref .= '?id=' . $feedTopMeId;
}
$feedTopChrome['profile_href'] = $profileHref;

if ($feedTopChromePart === 'badge') {
    if (($feedTopChrome['badge'] ?? '') === '') {
        return;
    }
    ?>
    <span class="ig-feed-account-badge" aria-label="Account type"><?= htmlspecialchars((string)$feedTopChrome['badge'], ENT_QUOTES, 'UTF-8') ?></span>
    <?php
    return;
}

?>
<div class="ig-feed-top-lead">
  <a href="<?= htmlspecialchars((string)$feedTopChrome['profile_href'], ENT_QUOTES, 'UTF-8') ?>" class="ig-feed-user-name" aria-label="Your profile"><?= htmlspecialchars((string)$feedTopChrome['display_name'], ENT_QUOTES, 'UTF-8') ?></a>
</div>
