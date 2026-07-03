<?php
// /Business_only3/organization/includes/org_context.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors','1');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/session_org_login.php';
orgRequireLoginOnly();

require_once __DIR__ . '/../../admin/controller.php';
$controller = new Controller();
$dbh = $controller->pdo();

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('orgAccountId')) {
    function orgAccountId(): int { return (int)($_SESSION['org_account_id'] ?? 0); }
}
if (!function_exists('orgAccountType')) {
    function orgAccountType(): string { return (string)($_SESSION['org_account_type'] ?? ''); }
}
if (!function_exists('isOrgManager')) {
    function isOrgManager(): bool { return orgAccountType() === 'manager'; }
}

$orgId = (int)($_SESSION['org_active_org_id'] ?? 0);

/**
 * ✅ IMPORTANT:
 * When manager is creating/selecting org, org_active_org_id is not set yet.
 * Header/leftbar must still render WITHOUT redirecting or returning early.
 */
$ORG = [
    'id' => 0,
    'org_code' => '',
    'name' => 'Organization',
    'status' => 1,
];
$ORG_SETTINGS = [
    'logo_type' => 'text',
    'logo_text' => null,
    'logo_image_path' => null,
    'theme_json' => null,
    'a11y_json' => null,
];

$theme = [];
$a11y  = [];
$ORG_THEME_BG = '';
$ORG_THEME_ACCENT = '';
$ORG_THEME_HEADER = '';
$ORG_THEME_LEFTBAR = '';
$ORG_THEME_PANEL = '';
$ORG_THEME_FOOTER = '';
$ORG_THEME_TEXT = '';
$ORG_THEME_INPUT_BG = '';
$ORG_THEME_INPUT_TEXT = '';
$ORG_THEME_INPUT_BORDER = '';
$ORG_THEME_MEMBERS = '';
$ORG_THEME_MSGBOX = '';
$ORG_FONT_SIZE = '';

if ($orgId <= 0) {
    // Safe defaults for create_org/select_org pages
    $ORG['name'] = isOrgManager() ? 'Create / Select Org' : 'Organization';
    $ORG_SETTINGS['logo_text'] = $ORG['name'];
    return; // ✅ OK here: it returns from org_context only (NOT from create_org.php)
}

// If org is selected, load org + settings
$stOrg = $dbh->prepare("SELECT id, org_code, name, status FROM organizations WHERE id = :id LIMIT 1");
$stOrg->execute([':id' => $orgId]);
$rowOrg = $stOrg->fetch(PDO::FETCH_ASSOC);

if ($rowOrg) {
    $ORG['id'] = (int)$rowOrg['id'];
    $ORG['org_code'] = (string)($rowOrg['org_code'] ?? '');
    $ORG['name'] = (string)($rowOrg['name'] ?? 'Organization');
    $ORG['status'] = (int)($rowOrg['status'] ?? 1);
}

$stSet = $dbh->prepare("SELECT * FROM org_settings WHERE org_id = :id LIMIT 1");
$stSet->execute([':id' => $orgId]);
$rowSet = $stSet->fetch(PDO::FETCH_ASSOC);
if ($rowSet) $ORG_SETTINGS = array_merge($ORG_SETTINGS, $rowSet);

if (!empty($ORG_SETTINGS['theme_json'])) $theme = json_decode((string)$ORG_SETTINGS['theme_json'], true) ?: [];
if (!empty($ORG_SETTINGS['a11y_json'])) $a11y = json_decode((string)$ORG_SETTINGS['a11y_json'], true) ?: [];

$ORG_THEME_BG     = (string)($theme['bg'] ?? '');
$ORG_THEME_ACCENT = (string)($theme['accent'] ?? '');
$ORG_THEME_HEADER = (string)($theme['headerBg'] ?? '');
$ORG_THEME_LEFTBAR= (string)($theme['leftbarBg'] ?? '');
$ORG_THEME_PANEL  = (string)($theme['panelBg'] ?? '');
$ORG_THEME_FOOTER = (string)($theme['footerBg'] ?? '');
$ORG_THEME_TEXT   = (string)($theme['text'] ?? '');
$ORG_THEME_INPUT_BG    = (string)($theme['inputBg'] ?? '');
$ORG_THEME_INPUT_TEXT  = (string)($theme['inputText'] ?? '');
$ORG_THEME_INPUT_BORDER= (string)($theme['inputBorder'] ?? '');
$ORG_THEME_MEMBERS = (string)($theme['membersBg'] ?? '');
$ORG_THEME_MSGBOX  = (string)($theme['msgBoxBg'] ?? '');
$ORG_FONT_SIZE    = (string)($a11y['fontSize'] ?? '');


$ORG_THEME_BG     = (string)($theme['bg'] ?? '');
$ORG_THEME_ACCENT = (string)($theme['accent'] ?? '');

$ORG_THEME_HEADER = (string)($theme['headerBg'] ?? '');     // fallback done in header.php
$ORG_THEME_LEFTBAR= (string)($theme['leftbarBg'] ?? '');
$ORG_THEME_PANEL  = (string)($theme['panelBg'] ?? '');
$ORG_THEME_FOOTER = (string)($theme['footerBg'] ?? '');

$ORG_THEME_MEMBERS  = (string)($theme['membersBg'] ?? '');
$ORG_THEME_MSGBOX   = (string)($theme['msgBoxBg'] ?? '');
$ORG_THEME_PAGETITLE= (string)($theme['pagetitleBg'] ?? '');

// Add these theme vars (SAFE defaults)
$ORG_THEME_TEXT        = (string)($theme['textColor']   ?? '');
$ORG_THEME_INPUT_BG    = (string)($theme['inputBg']     ?? '');
$ORG_THEME_INPUT_TEXT  = (string)($theme['inputText']   ?? '');
$ORG_THEME_PAGETITLE   = (string)($theme['pagetitleBg'] ?? '');
$ORG_THEME_MEMBERS     = (string)($theme['membersBg']   ?? '');
$ORG_THEME_MSGBOX      = (string)($theme['msgBoxBg']    ?? '');


$ORG_FONT_SIZE    = (string)($a11y['fontSize'] ?? '');


// ---- TEXT + INPUT THEME DEFAULTS ----
$ORG_TEXT_COLOR   = (string)($theme['textColor']   ?? '#111827'); // normal dark text
$ORG_INPUT_BG     = (string)($theme['inputBg']     ?? '#ffffff');
$ORG_INPUT_TEXT   = (string)($theme['inputText']   ?? '#111827');
$ORG_INPUT_BORDER = (string)($theme['inputBorder'] ?? '#d1d5db');

// ---- PAGE TITLE DEFAULT (WHITE) ----
$ORG_PAGETITLE_BG = (string)($theme['pagetitleBg'] ?? '#ffffff');


