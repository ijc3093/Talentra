<?php
declare(strict_types=1);

/**
 * Opens the standard org HTML shell. Include from a page that already loaded org_context.php.
 * Set $pageTitle (required) and $orgPageExtraHead (optional) before including.
 */
if (!isset($pageTitle)) {
    $pageTitle = (string)($GLOBALS['pageTitle'] ?? 'Organization');
}
if ($pageTitle === '') {
    $pageTitle = 'Organization';
}
if (!isset($orgPageExtraHead)) {
    $orgPageExtraHead = (string)($GLOBALS['orgPageExtraHead'] ?? '');
}

// When loaded via org_page_shell_open(), inherit org vars from the page scope.
global $dbh, $ORG, $ORG_SETTINGS, $orgId, $theme, $a11y,
       $ORG_THEME_BG, $ORG_THEME_ACCENT, $ORG_THEME_HEADER, $ORG_THEME_LEFTBAR,
       $ORG_THEME_PANEL, $ORG_THEME_FOOTER, $ORG_THEME_TEXT,
       $ORG_THEME_INPUT_BG, $ORG_THEME_INPUT_TEXT, $ORG_THEME_INPUT_BORDER,
       $ORG_THEME_MEMBERS, $ORG_THEME_MSGBOX, $ORG_FONT_SIZE;

if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

$orgName = h((string)($ORG['name'] ?? 'Organization'));
$titleEsc = h((string)$pageTitle);
$pageSlug = preg_replace('/\.php$/i', '', basename((string)($_SERVER['PHP_SELF'] ?? 'page')));
$pageSlug = preg_replace('/[^a-z0-9_-]+/i', '', (string)$pageSlug);
if ($pageSlug === '') {
    $pageSlug = 'page';
}
$bodyPageClass = 'org-page-' . $pageSlug;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require_once __DIR__ . '/org_theme_head.php'; ?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= $orgName ?> — <?= $titleEsc ?></title>

  <link href="../lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="../lib/Ionicons/css/ionicons.css" rel="stylesheet">
  <link href="../lib/perfect-scrollbar/css/perfect-scrollbar.css" rel="stylesheet">
  <link href="../lib/bootstrap/bootstrap.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/shamcey.css">
  <?php require_once __DIR__ . '/org_layout.php'; org_layout_head_assets(); ?>
  <?= $orgPageExtraHead ?>
  <style>
    html, body { min-height: 100%; }
    .sh-mainpanel { min-height: 100vh; display: flex; flex-direction: column; }
    .sh-pagebody { flex: 1 1 auto; }
  </style>
</head>
<body class="org-app <?= h($bodyPageClass) ?>">
<?php include __DIR__ . '/header.php'; ?>
<?php include __DIR__ . '/leftbar.php'; ?>
<div class="sh-mainpanel">
