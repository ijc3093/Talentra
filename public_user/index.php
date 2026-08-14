<?php
// /public_user/index.php — Talentra sign-in / sign-up gate
require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/includes/deleted_user_registry.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/../admin/includes/admin_linked_accounts_load.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');

$error = '';
$usernameValue = '';
$authView = strtolower(trim((string)($_GET['view'] ?? 'login')));
if ($authView !== 'register') {
    $authView = 'login';
}
$registerWelcome = null;
if (!empty($_SESSION['register_welcome']) && is_array($_SESSION['register_welcome'])) {
    $registerWelcome = $_SESSION['register_welcome'];
    unset($_SESSION['register_welcome']);
    $welcomeUsername = trim((string)($registerWelcome['username'] ?? ''));
    if ($welcomeUsername !== '') {
        $usernameValue = $welcomeUsername;
    }
}
$accountType = strtolower(trim((string)($_GET['account_type'] ?? 'personal')));
if (!in_array($accountType, ['personal', 'publisher', 'commerce'], true)) {
    $accountType = 'personal';
}

function login_user_is_publisher(array $user): bool
{
    $accountKind = strtolower(trim((string)($user['account_kind'] ?? 'personal')));
    $friendCode = strtoupper(trim((string)($user['friend_code'] ?? '')));
    return $accountKind === 'publisher' || str_starts_with($friendCode, 'PUB-');
}

function login_bump_last_seen(Controller $controller): void
{
    try {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            $stSeen = $controller->pdo()->prepare('UPDATE users SET last_seen = NOW() WHERE id = :id LIMIT 1');
            $stSeen->execute([':id' => $uid]);
        }
    } catch (Throwable $e) {
        // ignore presence update failures
    }
}

function auth_min_personal_age(): int
{
    return 21;
}

function auth_birthday_months(): array
{
    return [
        '1' => 'January', '2' => 'February', '3' => 'March', '4' => 'April',
        '5' => 'May', '6' => 'June', '7' => 'July', '8' => 'August',
        '9' => 'September', '10' => 'October', '11' => 'November', '12' => 'December',
    ];
}

function auth_birthday_years(): array
{
    $currentYear = (int)date('Y');
    $latest = $currentYear - auth_min_personal_age();
    $years = [];
    for ($y = $latest; $y >= $currentYear - 100; $y--) {
        $years[] = $y;
    }
    return $years;
}

if (isset($_GET['deactivated']) && (string)$_GET['deactivated'] === '1') {
    $error = user_account_deactivated_message();
}

if (isset($_GET['deleted']) && (string)$_GET['deleted'] === '1') {
    $error = user_account_deleted_message();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && array_key_exists('session', $_GET)) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['user_id']) || empty($_SESSION['user_login'])) {
    try {
        require_once __DIR__ . '/../admin/includes/admin_linked_bootstrap_load.php';
        if (admin_linked_verify_portal_handoff()) {
            $query = (string)($_SERVER['QUERY_STRING'] ?? '');
            header('Location: linked_portal_enter.php' . ($query !== '' ? '?' . $query : ''));
            exit;
        }
        if (admin_linked_sync_public_user_from_admin_intent((new Controller())->pdo())) {
            header('Location: home.php?tab=for-you');
            exit;
        }
    } catch (Throwable $e) {
        // show login form
    }
}

if (!empty($_SESSION['user_login']) && !empty($_SESSION['user_id'])) {
    try {
        $controller = new Controller();
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $loginId = trim((string)($_SESSION['user_login'] ?? ''));
        if ($uid > 0 && user_is_account_removed($controller->pdo(), $uid)) {
            redirectUserLoginDeleted();
        }
        if ($loginId !== '' && user_login_identifier_was_deleted($controller->pdo(), $loginId)) {
            redirectUserLoginDeleted();
        }
        if ($uid > 0 && user_is_account_deactivated($controller->pdo(), $uid)) {
            redirectUserLoginDeactivated();
        }
    } catch (Throwable $e) {
        // fall through to feed redirect
    }
    header('Location: home.php?tab=for-you');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $usernameValue = trim($_POST['username'] ?? '');
    $username = $usernameValue;
    $password = trim($_POST['password'] ?? '');
    $postedAccountType = strtolower(trim((string)($_POST['account_type'] ?? 'personal')));
    if (in_array($postedAccountType, ['personal', 'publisher', 'commerce'], true)) {
        $accountType = $postedAccountType;
    }
    $authView = 'login';

    if ($username === '' || $password === '') {
        $error = 'Please enter username or email and password.';
    } else {
        try {
            $controller = new Controller();
            $loginResult = $controller->userLoginAttempt($username, $password);
            $loginReason = (string)($loginResult['reason'] ?? '');

            if ($loginReason === 'deleted') {
                $error = user_account_deleted_message();
            } elseif ($loginReason === 'deactivated') {
                $error = user_account_deactivated_message();
            } elseif (
                $loginReason === 'not_found'
                && user_login_identifier_was_deleted($controller->pdo(), $username)
            ) {
                $error = user_account_deleted_message();
            } else {
                $user = !empty($loginResult['ok']) ? $loginResult['user'] : null;
                $isPublisherLogin = in_array($accountType, ['publisher', 'commerce'], true);

                if ($user && $isPublisherLogin && !login_user_is_publisher($user)) {
                    $error = 'This is a personal account. Switch to Personal User on the left to sign in.';
                } elseif ($user && !$isPublisherLogin && login_user_is_publisher($user)) {
                    $error = 'This is a publisher account. Switch to Publisher on the left to sign in.';
                } elseif ($user) {
                    setUserSession($user);
                    login_bump_last_seen($controller);
                    header('Location: entry.php');
                    exit;
                } elseif ($isPublisherLogin) {
                    $staffAuth = staff_pub_authenticate($controller->pdo(), $username, $password);
                    if ($staffAuth) {
                        staff_pub_set_session(
                            $controller->pdo(),
                            (array)$staffAuth['staff'],
                            (array)$staffAuth['publisher']
                        );
                        header('Location: entry.php');
                        exit;
                    }
                }

                if ($error === '') {
                    try {
                        $adminAuth = admin_linked_verify_credentials($controller->pdo(), $username, $password);
                        if ($adminAuth) {
                            $adminId = (int)($adminAuth['idadmin'] ?? 0);
                            admin_linked_ensure_provisioned($controller->pdo(), $adminId, $password);
                            $linkedUser = admin_linked_portal_user(
                                $controller->pdo(),
                                $adminId,
                                $isPublisherLogin ? 'publisher' : 'personal'
                            );
                            if ($linkedUser) {
                                setUserSession($linkedUser);
                                login_bump_last_seen($controller);
                                header('Location: entry.php');
                                exit;
                            }
                        }
                    } catch (Throwable $linkedErr) {
                        // Admin-linked portal is optional
                    }
                }

                if ($error === '') {
                    $error = $isPublisherLogin
                        ? 'Invalid publisher credentials or account inactive.'
                        : 'Invalid login credentials or account inactive.';
                }
            }
        } catch (Throwable $e) {
            $error = 'Unable to sign in right now. Please try again. [' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
        }
    }
}

$minAge = auth_min_personal_age();
$accountTypeJson = json_encode($accountType, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$authViewJson = json_encode($authView, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$publisherNameOptions = [];
$publisherCategories = [];
$commerceBrands = [];
try {
    require_once __DIR__ . '/includes/publisher_accounts.php';
    require_once __DIR__ . '/includes/publisher_authority.php';
    require_once __DIR__ . '/includes/org_commerce_brands.php';
    $authDb = (new Controller())->pdo();
    publisher_ensure_schema($authDb);
    publisher_authority_ensure_schema($authDb);
    org_commerce_brands_ensure_schema($authDb);
    $publisherNameOptions = publisher_registry_list_options($authDb);
    $publisherCategories = publisher_categories($authDb);
    $commerceBrands = org_commerce_brands_list_active($authDb);
    $authorityEntityTypes = publisher_authority_entity_types();
} catch (Throwable $e) {
    $publisherNameOptions = [];
    $publisherCategories = [];
    $commerceBrands = [];
    $authorityEntityTypes = [
        'business' => 'Business / company',
        'nonprofit' => 'Non-profit organization',
        'news_org' => 'News organization',
        'government' => 'Government entity',
        'other' => 'Other registered entity',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="robots" content="noindex,nofollow">
  <title>Talentra — Sign in</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <style>
    :root{
      --ink:#05090f;
      --ink-2:#0b1622;
      --gold:#e8c98a;
      --gold-deep:#b8924a;
      --mustard:#c9a227;
      --mustard-deep:#a88412;
      --mist:#9fd6c8;
      --paper:#edf6fa;
      --ink-text:#0f172a;
      --muted:#64748b;
    }
    *{box-sizing:border-box}
    html,body{margin:0;min-height:100%;height:100%}
    body{
      font-family:"Manrope",system-ui,-apple-system,sans-serif;
      color:var(--ink-text);
      background:#d7ebea;
      display:grid;
      place-items:center;
      padding:28px 16px;
    }
    .auth-shell{
      width:min(1080px,100%);
      min-height:min(640px,92vh);
      height:min(640px,92vh);
      display:grid;
      grid-template-columns:1.35fr .95fr;
      align-items:stretch;
      border-radius:10px;
      overflow:hidden;
      background:var(--paper);
      box-shadow:
        0 24px 60px rgba(15,23,42,.12),
        0 0 0 1px rgba(15,23,42,.08);
    }
    .auth-left{
      position:relative;
      color:#fff;
      padding:22px 22px 52px;
      background: linear-gradient(145deg, rgb(13 65 66 / 94%) 0%, rgb(21 53 60 / 90%) 48%, rgb(13 108 104) 100%), radial-gradient(700px 420px at 80% 70%, rgb(255 255 255 / 14%), transparent 55%);
      display:flex;
      flex-direction:column;
      justify-content:center;
      align-items:flex-start;
      gap:0;
      isolation:isolate;
      overflow:hidden;
      min-height:0;
      height:100%;
      border-right:0;
    }
    .auth-left::after{
      content:"";
      position:absolute;inset:0;z-index:0;pointer-events:none;
      background:
        radial-gradient(420px 280px at 85% 80%, rgba(5,9,15,.18), transparent 60%),
        linear-gradient(180deg, transparent 55%, rgba(5,9,15,.12));
      opacity:.9;
    }
    .auth-left > .auth-brand{
      position:absolute;
      left:50%;
      top:120px;
      transform:translateX(-50%);
      z-index:2;
      font-family:"Cormorant Garamond",Georgia,serif;
      font-size:1.85rem;
      font-weight:600;
      letter-spacing:.02em;
      margin:0;
      color:#fff;
      text-shadow:0 8px 24px rgba(0,0,0,.18);
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      gap:14px;
      text-align:center;
    }
    .auth-brand-orb{
      display:inline-grid;place-items:center;
      width:120px;height:120px;flex:0 0 120px;
      border-radius:50%;
      background:
        radial-gradient(circle at 35% 30%, rgba(255,255,255,.16), transparent 55%),
        linear-gradient(165deg,#05090f,#0b1622);
      box-shadow:
        0 0 0 2px rgba(255,255,255,.38),
        0 18px 40px rgba(5,9,15,.34);
    }
    .auth-brand-mark{
      font-family:"Cormorant Garamond",Georgia,serif;font-weight:700;font-size:3.5rem;line-height:1;
      background:linear-gradient(180deg,#f8e7c2 0%,#e8c98a 42%,#b8924a 100%);
      -webkit-background-clip:text;background-clip:text;color:transparent;
    }
    .auth-brand-name{
      display:block;
      line-height:1.1;
    }
    .auth-left-nav{
      position:relative;
      text-align:left;
      width:max-content;
    }
    .auth-nav-title{
      margin:0 0 10px;
      font-family:"Cormorant Garamond",Georgia,serif;
      font-size:1.15rem;
      font-weight:600;
      line-height:1;
      text-align:left;
      color:#fff;
    }
    .auth-type-list{
      list-style:none;margin:0;padding:0;
      display:flex;flex-direction:column;gap:6px;
      align-items:flex-start;
    }
    .auth-type-option{
      display:flex;align-items:center;gap:8px;
      margin:0;padding:2px 0;
      color:rgba(255,255,255,.72);
      font:inherit;font-size:13px;font-weight:600;letter-spacing:.01em;
      cursor:pointer;
      transition:color .2s ease;
      text-align:left;
    }
    .auth-type-option:hover{color:#fff}
    .auth-type-option:has(input:checked){color:#fff}
    .auth-type-option input[type="radio"]{
      appearance:auto;-webkit-appearance:radio;
      width:14px;height:14px;min-width:14px;margin:0;padding:0;
      flex:0 0 14px;accent-color:#fff;cursor:pointer;
    }
    .auth-type-hint{
      position:absolute;
      left:0;
      top:100%;
      margin:10px 0 0;
      width:280px;
      min-height:2.7em;
      font-size:12px;
      line-height:1.35;
      color:rgba(255,255,255,.82);
      font-weight:500;
      text-align:left;
      pointer-events:none;
    }
    .auth-left > .auth-left-foot{
      position:absolute;
      left:22px;
      bottom:18px;
      z-index:2;
      margin:0;
      font-size:10px;
      color:rgba(255,255,255,.7);
      letter-spacing:.04em;
      text-transform:uppercase;
      font-weight:700;
    }
    .auth-left > .auth-left-main{
      position:absolute;
      left:50%;
      top:58%;
      transform:translate(-50%, -50%);
      right:auto;
      z-index:1;
      display:flex;
      flex-direction:column;
      gap:0;
      width:max-content;
      max-width:min(320px, calc(100% - 44px));
      margin:0;
      text-align:left;
    }
    .auth-right{
      background:var(--paper);
      color:var(--ink-text);
      padding:20px 22px 52px;
      display:block;
      min-width:0;
      min-height:0;
      height:100%;
      font-size:13px;
      position:relative;
    }
    .auth-right-main{
      position:absolute;
      inset:0 0 48px;
      width:auto;
      min-width:0;
      margin:0;
      overflow:hidden;
    }
    .auth-right-head{
      position:absolute;
      left:50%;
      top:36px;
      transform:translateX(-50%);
      width:min(320px, calc(100% - 44px));
      margin:0;
      text-align:center;
      z-index:2;
    }
    body[data-auth-view="login"] .auth-right-head{
      top:160px;
    }
    .auth-right-body{
      position:absolute;
      left:50%;
      top:118px;
      bottom:12px;
      transform:translateX(-50%);
      width:min(320px, calc(100% - 44px));
      overflow:auto;
      z-index:1;
    }
    body[data-auth-view="login"] .auth-right-body{
      top:58%;
      bottom:auto;
      transform:translate(-50%, -50%);
      max-height:calc(100% - 170px);
    }
    .auth-kicker{
      margin:0 0 2px;
      font-size:10px;
      font-weight:700;
      letter-spacing:.1em;
      text-transform:uppercase;
      color:var(--muted);
    }
    .auth-title{
      margin:0;
      font-family:"Cormorant Garamond",Georgia,serif;
      font-size:1.35rem;
      font-weight:600;
      color:var(--ink-text);
      line-height:1.15;
    }
    .auth-sub{
      margin:4px 0 0;
      font-size:12px;
      color:var(--muted);
      font-weight:500;
      line-height:1.35;
    }
    .auth-alert{
      margin:0 0 10px;
      padding:7px 9px;
      border-radius:8px;
      background:#fef2f2;
      border:1px solid #fecaca;
      color:#991b1b;
      font-size:11.5px;
      font-weight:600;
      line-height:1.35;
    }
    .auth-panels{position:relative}
    .auth-panel{display:none}
    .auth-panel.is-active{display:block}
    .auth-field{
      display:flex;align-items:center;gap:6px;
      padding:5px 2px 5px;
      border-bottom:1px solid #dbe3ee;
      margin-bottom:7px;
    }
    .auth-field i,
    .auth-field .fa{
      width:12px;flex:0 0 12px;text-align:center;color:#94a3b8;
      font-size:11px !important;line-height:1;
    }
    .auth-field input,
    .auth-field select{
      flex:1;min-width:0;border:0;outline:none;background:transparent;
      font-family:inherit;font-size:12.5px;font-weight:500;color:var(--ink-text);
      padding:1px 0;line-height:1.3;
      -webkit-appearance:none;appearance:none;
    }
    .auth-field select{
      background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath fill='%2394a3b8' d='M1 1l4 4 4-4'/%3E%3C/svg%3E");
      background-repeat:no-repeat;background-position:right 2px center;padding-right:14px;
    }
    .auth-field input::placeholder{color:#94a3b8;font-weight:500;font-size:12.5px}
    .auth-field-row{
      display:grid;grid-template-columns:1fr 1fr;gap:8px;
    }
    .auth-field-stack{margin-bottom:7px}
    .auth-field-label{
      display:block;margin:0 0 4px;
      font-size:10px;font-weight:700;letter-spacing:.05em;
      text-transform:uppercase;color:var(--muted);
    }
    .auth-birthday{
      display:grid;grid-template-columns:1.2fr .7fr .9fr;gap:5px;
    }
    .auth-birthday select{
      width:100%;border:1px solid #dbe3ee;border-radius:6px;
      padding:5px 6px;background:#f8fafc;font-family:inherit;font-size:12px;font-weight:500;
      color:var(--ink-text);
    }
    .auth-check{
      display:flex;flex-direction:row;align-items:flex-start;justify-content:flex-start;gap:6px;
      margin:2px 0 8px;font-size:12px;line-height:1.35;color:#334155;font-weight:500;text-align:left;
    }
    .auth-check input[type="checkbox"]{
      appearance:auto;-webkit-appearance:checkbox;
      flex:0 0 12px;width:12px;height:12px;min-width:12px;
      margin:2px 0 0;padding:0;align-self:flex-start;accent-color:#0f172a;
    }
    .auth-policy{
      max-height:72px;overflow:auto;border:1px solid #e2e8f0;border-radius:8px;
      padding:6px 8px;background:#f8fafc;margin-bottom:6px;
      font-size:11px;line-height:1.35;color:#475569;
    }
    .auth-policy h6{margin:0 0 2px;font-size:10px;color:#0f172a;text-transform:uppercase;letter-spacing:.04em}
    .auth-policy p{margin:0 0 5px}
    .auth-policy-choice{
      display:flex;gap:10px;margin-bottom:8px;font-size:12px;font-weight:600;
      align-items:center;justify-content:flex-start;
    }
    .auth-policy-choice label{
      display:inline-flex;align-items:center;gap:5px;cursor:pointer;font-weight:600;
    }
    .auth-policy-choice input[type="radio"]{
      width:12px;height:12px;margin:0;accent-color:#0f172a;
    }
    .auth-link-row{
      display:flex;justify-content:flex-end;margin:-2px 0 10px;
    }
    .auth-link-row a{
      color:var(--muted);font-size:12px;font-weight:600;text-decoration:none;
    }
    .auth-link-row a:hover{color:var(--gold-deep)}
    .auth-continue{
      width:100%;appearance:none;border:0;cursor:pointer;
      border-radius:999px;padding:7px 12px;
      background:linear-gradient(180deg,#111827,#0f172a);
      color:#fff;font-family:inherit;font-size:12.5px;font-weight:700;
      display:inline-flex;align-items:center;justify-content:center;gap:6px;
      box-shadow:0 6px 14px rgba(15,23,42,.12);
      transition:transform .18s ease, box-shadow .18s ease;
    }
    .auth-continue:hover{transform:translateY(-1px);box-shadow:0 8px 18px rgba(15,23,42,.16)}
    .auth-continue i,
    .auth-continue .fa{font-size:10px !important;line-height:1}
    .auth-divider{
      display:flex;align-items:center;gap:8px;margin:10px 0 8px;
      color:#94a3b8;font-size:10px;font-weight:700;letter-spacing:.1em;
    }
    .auth-divider::before,.auth-divider::after{
      content:"";flex:1;height:1px;background:#e2e8f0;
    }
    .auth-switch{
      position:absolute;
      left:22px;
      right:22px;
      bottom:16px;
      padding-top:0;text-align:center;
      font-size:12px;color:var(--muted);font-weight:500;
    }
    .auth-switch button{
      appearance:none;border:0;background:none;padding:0;margin:0;
      color:var(--ink-text);font-family:inherit;font-size:12px;font-weight:700;cursor:pointer;
      text-decoration:underline;text-underline-offset:2px;
    }
    .auth-switch button:hover{color:var(--gold-deep)}
    .auth-pro-card{
      border:1px solid #e2e8f0;border-radius:10px;padding:10px;
      background:#f8fafc;margin-bottom:10px;
    }
    .auth-pro-card p{margin:0 0 10px;font-size:12px;line-height:1.35;color:#475569;font-weight:500}
    .auth-mode-block[hidden]{display:none !important}
    .auth-register-scroll{
      max-height:min(58vh,520px);
      overflow:auto;
      padding-right:4px;
      margin-bottom:4px;
    }
    .auth-name-row{
      display:grid;
      grid-template-columns:minmax(0,1fr) auto;
      gap:6px;
      align-items:end;
      margin-bottom:7px;
    }
    .auth-name-row .auth-field{margin-bottom:0}
    .auth-add-name-btn{
      appearance:none;border:1px solid #dbe3ee;background:#f8fafc;color:#0f172a;
      border-radius:999px;padding:4px 8px;font-family:inherit;font-size:11px;font-weight:600;
      cursor:pointer;white-space:nowrap;height:26px;line-height:1;
    }
    .auth-add-name-btn:hover{background:#eef2f7;border-color:#cbd5e1}
    .auth-add-note{
      margin:0 0 7px;font-size:11px;line-height:1.35;color:#64748b;font-weight:500;
    }
    .auth-custom-chosen{
      display:none;margin:0 0 7px;padding:7px 9px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;
    }
    .auth-custom-chosen.is-visible{display:block}
    .auth-custom-chosen-label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-bottom:3px}
    .auth-custom-chosen-name{font-size:12.5px;font-weight:700;color:#0f172a}
    .auth-custom-status{margin-top:4px;font-size:11px;font-weight:600;color:#475569}
    .auth-custom-status.is-pending{color:#b45309}
    .auth-custom-status.is-approved{color:#047857}
    .auth-custom-status.is-rejected{color:#b91c1c}
    .auth-modal-backdrop{
      position:fixed;inset:0;z-index:2147483600;display:flex;align-items:center;justify-content:center;
      padding:16px;background:rgba(5,9,15,.55);
    }
    .auth-modal-backdrop[hidden]{display:none !important}
    .auth-modal{
      width:min(400px,100%);max-height:min(86vh,620px);overflow:auto;
      background:#fff;border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,.35);padding:14px 14px 12px;
    }
    .auth-modal h3{margin:0 0 3px;font-size:13px;font-weight:700;line-height:1.25}
    .auth-modal > p{margin:0 0 8px;font-size:11px;line-height:1.35;color:#64748b}
    .auth-modal .auth-modal-field > label{
      display:block;margin:0 0 2px;font-size:10px;font-weight:700;color:#475569;
    }
    .auth-modal .auth-modal-field{margin-bottom:7px}
    .auth-modal input[type="text"],
    .auth-modal input[type="email"],
    .auth-modal select,
    .auth-modal textarea{
      width:100%;border:1px solid #dbe3ee;border-radius:7px;padding:5px 7px;
      font-family:inherit;font-size:12px;font-weight:500;background:#f8fafc;color:#0f172a;
      line-height:1.3;box-sizing:border-box;
    }
    .auth-modal textarea{resize:vertical;min-height:42px}
    .auth-modal-confirm{
      display:flex !important;
      flex-direction:row;
      align-items:flex-start;
      justify-content:flex-start;
      gap:6px;
      width:100%;
      margin:2px 0 8px;
      padding:0;
      border:0;
      background:transparent;
      font-size:11px;
      line-height:1.3;
      color:#334155;
      font-weight:500;
      text-align:left;
      cursor:pointer;
    }
    .auth-modal-confirm input[type="checkbox"]{
      appearance:auto;
      -webkit-appearance:checkbox;
      position:static;
      flex:0 0 12px;
      width:12px;
      height:12px;
      min-width:12px;
      margin:2px 0 0;
      padding:0;
      float:none;
      align-self:flex-start;
      accent-color:#0f172a;
      cursor:pointer;
    }
    .auth-modal-confirm > span{
      flex:1 1 auto;
      min-width:0;
      text-align:left;
    }
    .auth-modal-error{min-height:12px;margin:0 0 6px;font-size:11px;font-weight:600;color:#b91c1c}
    .auth-modal-actions{display:flex;justify-content:flex-end;gap:6px}
    .auth-modal-actions button{
      appearance:none;border:0;border-radius:999px;padding:5px 10px;font-family:inherit;font-size:11px;font-weight:700;cursor:pointer;
    }
    .auth-modal-cancel{background:#e2e8f0;color:#0f172a}
    .auth-modal-save{background:#0f172a;color:#fff}
    .sr-only{
      position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
      clip:rect(0,0,0,0);white-space:nowrap;border:0;
    }
    @media (max-width:860px){
      body{padding:14px;align-items:stretch}
      .auth-shell{
        grid-template-columns:1fr;
        height:auto;
        min-height:auto;
        border-radius:22px;
      }
      .auth-left{
        padding:20px 18px 44px;gap:0;min-height:auto;height:auto;
        justify-content:flex-start;
      }
      .auth-left > .auth-brand{
        position:static;
        transform:none;
        left:auto;top:auto;
        margin:0 auto 14px;
      }
      .auth-left > .auth-left-main{
        position:static;
        transform:none;
        left:auto;right:auto;top:auto;
        max-width:none;
        width:100%;
      }
      .auth-left > .auth-left-foot{left:18px;bottom:14px}
      .auth-nav-title{font-size:1.2rem}
      .auth-brand-orb{width:72px;height:72px;flex-basis:72px}
      .auth-brand-mark{font-size:2.1rem}
      .auth-left > .auth-brand{font-size:1.4rem;gap:8px}
      .auth-type-list{flex-direction:column;flex-wrap:nowrap;gap:6px;justify-content:flex-start;align-items:flex-start}
      .auth-type-option{font-size:.8rem;gap:6px}
      .auth-type-hint{display:none}
      .auth-right{
        padding:20px 18px 48px;height:auto;
      }
      .auth-right-main{
        position:static;
        inset:auto;
        width:100%;
        overflow:visible;
      }
      .auth-right-head,
      .auth-right-body{
        position:static;
        transform:none;
        left:auto;top:auto;
        width:100%;
        max-height:none;
        overflow:visible;
        margin-bottom:12px;
      }
      .auth-switch{left:18px;right:18px;bottom:14px}
      .auth-field-row,.auth-birthday{grid-template-columns:1fr}
    }
  </style>
</head>
<body data-auth-view="<?= htmlspecialchars($authView, ENT_QUOTES, 'UTF-8') ?>" data-login-mode="<?= htmlspecialchars($accountType, ENT_QUOTES, 'UTF-8') ?>">
  <?php require __DIR__ . '/includes/register_welcome_modal.php'; ?>

  <div class="auth-shell" id="authShell" role="dialog" aria-label="Talentra sign in">
    <aside class="auth-left" aria-label="Account type">
      <h1 class="auth-brand">
        <span class="auth-brand-orb" aria-hidden="true"><span class="auth-brand-mark">t</span></span>
        <span class="auth-brand-name">Talentra</span>
      </h1>
      <div class="auth-left-main">
        <div class="auth-left-nav">
          <h2 class="auth-nav-title">Account</h2>
          <div class="auth-type-list" role="radiogroup" aria-label="Choose account type">
            <label class="auth-type-option">
              <input type="radio" class="js-auth-type" name="auth_account_type" value="personal" data-type="personal"<?= $accountType === 'personal' ? ' checked' : '' ?>>
              <span>Personal user</span>
            </label>
            <label class="auth-type-option">
              <input type="radio" class="js-auth-type" name="auth_account_type" value="publisher" data-type="publisher"<?= $accountType === 'publisher' ? ' checked' : '' ?>>
              <span>Publisher</span>
            </label>
            <label class="auth-type-option">
              <input type="radio" class="js-auth-type" name="auth_account_type" value="commerce" data-type="commerce"<?= $accountType === 'commerce' ? ' checked' : '' ?>>
              <span>Commerce</span>
            </label>
          </div>
          <p class="auth-type-hint" id="authTypeHint">Friends &amp; family — your personal story space.</p>
        </div>
      </div>
      <div class="auth-left-foot">Sign in · Create account</div>
    </aside>

    <section class="auth-right">
      <div class="auth-right-main">
      <div class="auth-right-head">
        <p class="auth-kicker" id="authKicker"><?= $authView === 'register' ? 'New member' : 'Existing member' ?></p>
        <h2 class="auth-title" id="authTitle">Welcome Back!</h2>
        <p class="auth-sub" id="authSub">Sign in to your personal account.</p>
      </div>

      <div class="auth-right-body">
      <?php if ($error !== ''): ?>
        <div class="auth-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <div class="auth-panels">
        <div class="auth-panel<?= $authView === 'login' ? ' is-active' : '' ?>" id="authLoginPanel" data-panel="login">
          <form method="post" autocomplete="off" id="authLoginForm">
            <?= csrfInput() ?>
            <input type="hidden" name="account_type" id="loginAccountType" value="<?= htmlspecialchars($accountType, ENT_QUOTES, 'UTF-8') ?>">
            <div class="auth-field">
              <i class="fa fa-envelope-o" aria-hidden="true"></i>
              <input type="text" name="username" id="loginUsernameInput" value="<?= htmlspecialchars($usernameValue, ENT_QUOTES, 'UTF-8') ?>" placeholder="Username or email" required>
            </div>
            <div class="auth-field">
              <i class="fa fa-lock" aria-hidden="true"></i>
              <input type="password" name="password" placeholder="Enter password" required>
            </div>
            <div class="auth-link-row">
              <a href="forget.php">Forgot password?</a>
            </div>
            <button class="auth-continue" name="login" type="submit" value="1" id="loginSubmitBtn">
              <span id="loginSubmitLabel">Continue</span>
              <i class="fa fa-arrow-right" aria-hidden="true"></i>
            </button>
          </form>
        </div>

        <div class="auth-panel<?= $authView === 'register' ? ' is-active' : '' ?>" id="authRegisterPanel" data-panel="register">
          <form method="post" action="register.php" autocomplete="off" id="authRegisterForm">
            <?= csrfInput() ?>
            <input type="hidden" name="account_type" id="registerAccountType" value="<?= $accountType === 'personal' ? 'personal' : 'publisher' ?>">
            <input type="hidden" name="publisher_mode" id="registerPublisherMode" value="<?= $accountType === 'commerce' ? 'commerce' : 'media' ?>"<?= $accountType === 'personal' ? ' disabled' : '' ?>>

            <div class="auth-register-scroll">
              <div class="auth-mode-block" data-reg-mode="personal" id="regPersonalFields"<?= $accountType !== 'personal' ? ' hidden' : '' ?>>
                <div class="auth-field">
                  <i class="fa fa-user-o" aria-hidden="true"></i>
                  <input name="name" type="text" class="js-reg-personal-name" placeholder="Full name" autocomplete="name"<?= $accountType === 'personal' ? ' required' : ' disabled' ?>>
                </div>
              </div>

              <div class="auth-mode-block" data-reg-mode="publisher" id="regPublisherFields"<?= $accountType !== 'publisher' ? ' hidden' : '' ?>>
                <div class="auth-name-row">
                  <div class="auth-field">
                    <i class="fa fa-building-o" aria-hidden="true"></i>
                    <select name="name" id="regPublisherNameSelect" class="js-reg-publisher-name" aria-label="Publisher name"<?= $accountType === 'publisher' ? ' required' : ' disabled' ?>>
                      <option value="">Select publisher name</option>
                      <?php foreach ($publisherNameOptions as $opt): ?>
                        <?php $optName = (string)($opt['name'] ?? ''); if ($optName === '') continue; ?>
                        <option value="<?= htmlspecialchars($optName, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($optName, ENT_QUOTES, 'UTF-8') ?></option>
                      <?php endforeach; ?>
                      <option value="__add_new__">+ Add publisher name…</option>
                    </select>
                  </div>
                  <button type="button" class="auth-add-name-btn" id="authPublisherAddNameBtn">Add name</button>
                </div>
                <input type="hidden" name="name" id="regPublisherCustomName" class="js-reg-publisher-custom-name" value="" disabled>
                <div class="auth-custom-chosen" id="regPublisherCustomChosen">
                  <span class="auth-custom-chosen-label">Your publisher name</span>
                  <div class="auth-custom-chosen-name" id="regPublisherCustomChosenName"></div>
                  <div class="auth-custom-status" id="regPublisherCustomStatus"></div>
                  <button type="button" class="auth-add-name-btn" id="regPublisherCustomClear" style="margin-top:8px">Choose from list instead</button>
                </div>
                <p class="auth-add-note">Choose a name from the list or click <strong>Add name</strong>. New names need admin approval before signup.</p>
                <div class="auth-name-row">
                  <div class="auth-field">
                    <i class="fa fa-folder-o" aria-hidden="true"></i>
                    <select name="publisher_category" id="regPublisherCategory" class="js-reg-publisher" aria-label="Category"<?= $accountType === 'publisher' ? ' required' : ' disabled' ?>>
                      <?php foreach ($publisherCategories as $key => $label): ?>
                        <?php if ((string)$key === 'commerce') { continue; } ?>
                        <option value="<?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <button type="button" class="auth-add-name-btn" id="authPublisherAddCategoryBtn">Add category</button>
                </div>
                <p class="auth-add-note">Pick the category that matches your publisher. If it is missing, click <strong>Add category</strong>.</p>
                <div class="auth-field">
                  <i class="fa fa-quote-left" aria-hidden="true"></i>
                  <input type="text" name="publisher_tagline" class="js-reg-publisher" placeholder="Tagline (optional)"<?= $accountType === 'publisher' ? '' : ' disabled' ?>>
                </div>
              </div>

              <div class="auth-mode-block" data-reg-mode="commerce" id="regCommerceFields"<?= $accountType !== 'commerce' ? ' hidden' : '' ?>>
                <div class="auth-name-row">
                  <div class="auth-field">
                    <i class="fa fa-shopping-bag" aria-hidden="true"></i>
                    <select name="commerce_brand_id" id="regCommerceBrandSelect" class="js-reg-commerce" aria-label="Commerce brand"<?= $accountType === 'commerce' ? ' required' : ' disabled' ?>>
                      <option value="">Select commerce brand</option>
                      <?php foreach ($commerceBrands as $brand): ?>
                        <?php $bid = (int)($brand['id'] ?? 0); if ($bid <= 0) continue; ?>
                        <option value="<?= $bid ?>"><?= htmlspecialchars((string)($brand['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                      <?php endforeach; ?>
                      <option value="__add_new__">+ Add brand name…</option>
                    </select>
                  </div>
                  <button type="button" class="auth-add-name-btn" id="authCommerceAddNameBtn">Add name</button>
                </div>
                <input type="hidden" name="commerce_brand_name" id="regCommerceCustomName" value="" disabled>
                <div class="auth-custom-chosen" id="regCommerceCustomChosen">
                  <span class="auth-custom-chosen-label">Your commerce brand</span>
                  <div class="auth-custom-chosen-name" id="regCommerceCustomChosenName"></div>
                  <div class="auth-custom-status" id="regCommerceCustomStatus"></div>
                  <button type="button" class="auth-add-name-btn" id="regCommerceCustomClear" style="margin-top:8px">Choose from list instead</button>
                </div>
                <p class="auth-add-note">Choose a brand from the list or click <strong>Add name</strong> if yours is missing. Admin approval is required.</p>
                <input type="hidden" name="publisher_category" value="commerce" class="js-reg-commerce-cat"<?= $accountType === 'commerce' ? '' : ' disabled' ?>>
              </div>

              <div class="auth-field-row">
                <div class="auth-field">
                  <i class="fa fa-at" aria-hidden="true"></i>
                  <input type="text" name="username" placeholder="Username" required>
                </div>
                <div class="auth-field">
                  <i class="fa fa-envelope-o" aria-hidden="true"></i>
                  <input name="email" type="email" placeholder="Email" required>
                </div>
              </div>

              <div class="auth-mode-block" data-reg-mode="personal" id="regPersonalExtra"<?= $accountType !== 'personal' ? ' hidden' : '' ?>>
                <div class="auth-field-row">
                  <div class="auth-field">
                    <i class="fa fa-venus-mars" aria-hidden="true"></i>
                    <select name="gender" class="js-reg-personal"<?= $accountType === 'personal' ? ' required' : ' disabled' ?>>
                      <option value="">Gender</option>
                      <option value="Male">Male</option>
                      <option value="Female">Female</option>
                    </select>
                  </div>
                  <div class="auth-field">
                    <i class="fa fa-phone" aria-hidden="true"></i>
                    <input name="mobile" type="tel" class="js-reg-personal" placeholder="Phone number" autocomplete="tel" inputmode="tel" pattern="[0-9+\-\s()]{7,20}"<?= $accountType === 'personal' ? ' required' : ' disabled' ?>>
                  </div>
                </div>
                <div class="auth-field">
                  <i class="fa fa-lock" aria-hidden="true"></i>
                  <input type="password" name="password" class="js-reg-password-personal" placeholder="Create password" autocomplete="new-password"<?= $accountType === 'personal' ? ' required' : ' disabled' ?>>
                </div>
                <div class="auth-field-stack">
                  <span class="auth-field-label">Birthday</span>
                  <div class="auth-birthday">
                    <select name="birth_month" class="js-reg-personal" aria-label="Birth month"<?= $accountType === 'personal' ? ' required' : ' disabled' ?>>
                      <option value="">Month</option>
                      <?php foreach (auth_birthday_months() as $monthValue => $monthLabel): ?>
                        <option value="<?= (int)$monthValue ?>"><?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?></option>
                      <?php endforeach; ?>
                    </select>
                    <select name="birth_day" class="js-reg-personal" aria-label="Birth day"<?= $accountType === 'personal' ? ' required' : ' disabled' ?>>
                      <option value="">Day</option>
                      <?php for ($day = 1; $day <= 31; $day++): ?>
                        <option value="<?= $day ?>"><?= $day ?></option>
                      <?php endfor; ?>
                    </select>
                    <select name="birth_year" class="js-reg-personal" aria-label="Birth year"<?= $accountType === 'personal' ? ' required' : ' disabled' ?>>
                      <option value="">Year</option>
                      <?php foreach (auth_birthday_years() as $year): ?>
                        <option value="<?= (int)$year ?>"><?= (int)$year ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <label class="auth-check">
                  <input type="checkbox" name="age_confirm" value="1" class="js-reg-personal"<?= $accountType === 'personal' ? ' required' : ' disabled' ?>>
                  <span>I confirm I am at least <?= (int)$minAge ?> years old and my birthday is accurate.</span>
                </label>
              </div>

              <div class="auth-mode-block" data-reg-mode="publisher commerce" id="regProPassword"<?= $accountType === 'personal' ? ' hidden' : '' ?>>
                <div class="auth-field">
                  <i class="fa fa-lock" aria-hidden="true"></i>
                  <input type="password" name="password" class="js-reg-password-pro" placeholder="Create password" autocomplete="new-password"<?= $accountType !== 'personal' ? ' required' : ' disabled' ?>>
                </div>
              </div>

              <div class="auth-field-stack">
                <span class="auth-field-label">Terms &amp; Policy</span>
                <div class="auth-policy" tabindex="0">
                  <h6>Eligibility</h6>
                  <p>Provide accurate information. Personal accounts require age <?= (int)$minAge ?>+. Publisher and commerce accounts may need admin approval for brand names.</p>
                  <h6>Acceptable use</h6>
                  <p>Do not post illegal, abusive, or harmful content. Do not impersonate others or misuse the service.</p>
                  <h6>Your account</h6>
                  <p>You are responsible for activity on your account and for keeping your login credentials secure.</p>
                </div>
                <div class="auth-policy-choice" role="radiogroup" aria-label="Policy agreement">
                  <label><input type="radio" name="policy_agreement" value="agree" required> I Agree</label>
                  <label><input type="radio" name="policy_agreement" value="disagree"> I Disagree</label>
                </div>
              </div>
            </div>

            <button class="auth-continue" name="submit" type="submit" value="1" id="registerSubmitBtn">
              <span id="registerSubmitLabel">Create account</span>
              <i class="fa fa-arrow-right" aria-hidden="true"></i>
            </button>
          </form>
        </div>
      </div>
      </div>
      </div>

      <div class="auth-switch" id="authSwitch">
        <span id="authSwitchLead">Don't have account?</span>
        <button type="button" id="authSwitchBtn">Register Now</button>
      </div>
    </section>
  </div>

  <div class="auth-modal-backdrop" id="authPublisherAddModal" hidden>
    <div class="auth-modal" role="dialog" aria-modal="true" aria-labelledby="authPublisherAddTitle">
      <h3 id="authPublisherAddTitle">Request publisher name</h3>
      <p>All publisher names are reviewed by an admin before you can create your account.</p>
      <div class="auth-modal-field">
        <label for="authPublisherAddNameInput">Publisher name</label>
        <input type="text" id="authPublisherAddNameInput" maxlength="120" placeholder="e.g. CBS News" autocomplete="off">
      </div>
      <div class="auth-modal-field">
        <label for="authPublisherEntityType">Organization type</label>
        <select id="authPublisherEntityType">
          <?php foreach ($authorityEntityTypes as $typeKey => $typeLabel): ?>
            <option value="<?= htmlspecialchars((string)$typeKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$typeLabel, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="auth-modal-field">
        <label for="authPublisherLegalName">Organization name (optional)</label>
        <input type="text" id="authPublisherLegalName" maxlength="200" placeholder="Registered company or organization name">
      </div>
      <div class="auth-modal-field">
        <label for="authPublisherContactName">Authorized representative</label>
        <input type="text" id="authPublisherContactName" maxlength="120" placeholder="Full name">
      </div>
      <div class="auth-modal-field">
        <label for="authPublisherContactEmail">Representative email</label>
        <input type="email" id="authPublisherContactEmail" maxlength="120" placeholder="name@company.com">
      </div>
      <div class="auth-modal-field">
        <label for="authPublisherRequestNote">Note for admin (optional)</label>
        <textarea id="authPublisherRequestNote" maxlength="500" placeholder="Why you need this publisher name"></textarea>
      </div>
      <label class="auth-modal-confirm">
        <input type="checkbox" id="authPublisherAuthorityConfirm" value="1">
        <span>I confirm I am authorized to request this publisher name on behalf of the organization above.</span>
      </label>
      <div class="auth-modal-error" id="authPublisherAddError"></div>
      <div class="auth-modal-actions">
        <button type="button" class="auth-modal-cancel" data-close-modal="authPublisherAddModal">Cancel</button>
        <button type="button" class="auth-modal-save" id="authPublisherAddSaveBtn">Submit request</button>
      </div>
    </div>
  </div>

  <div class="auth-modal-backdrop" id="authCommerceAddModal" hidden>
    <div class="auth-modal" role="dialog" aria-modal="true" aria-labelledby="authCommerceAddTitle">
      <h3 id="authCommerceAddTitle">Request commerce brand</h3>
      <p>Submit a brand name request. Admin must approve it before you can create your seller account.</p>
      <div class="auth-modal-field">
        <label for="authCommerceAddNameInput">Brand / company name</label>
        <input type="text" id="authCommerceAddNameInput" maxlength="120" placeholder="e.g. Chipotle, Target, Nike" autocomplete="off">
      </div>
      <div class="auth-modal-field">
        <label for="authCommerceContactName">Authorized representative</label>
        <input type="text" id="authCommerceContactName" maxlength="120" placeholder="Full name">
      </div>
      <div class="auth-modal-field">
        <label for="authCommerceContactEmail">Representative email</label>
        <input type="email" id="authCommerceContactEmail" maxlength="120" placeholder="name@company.com">
      </div>
      <div class="auth-modal-field">
        <label for="authCommerceEntityType">Organization type</label>
        <select id="authCommerceEntityType">
          <?php foreach ($authorityEntityTypes as $typeKey => $typeLabel): ?>
            <option value="<?= htmlspecialchars((string)$typeKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$typeLabel, ENT_QUOTES, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="auth-modal-field">
        <label for="authCommerceRequestNote">Note for admin (optional)</label>
        <textarea id="authCommerceRequestNote" maxlength="500" placeholder="Briefly describe your brand request"></textarea>
      </div>
      <label class="auth-modal-confirm">
        <input type="checkbox" id="authCommerceAuthorityConfirm" value="1">
        <span>I confirm I am authorized to request this commerce brand.</span>
      </label>
      <div class="auth-modal-error" id="authCommerceAddError"></div>
      <div class="auth-modal-actions">
        <button type="button" class="auth-modal-cancel" data-close-modal="authCommerceAddModal">Cancel</button>
        <button type="button" class="auth-modal-save" id="authCommerceAddSaveBtn">Submit request</button>
      </div>
    </div>
  </div>

  <div class="auth-modal-backdrop" id="authCategoryAddModal" hidden>
    <div class="auth-modal" role="dialog" aria-modal="true" aria-labelledby="authCategoryAddTitle">
      <h3 id="authCategoryAddTitle">Add category</h3>
      <p>Use the category that matches your publisher. Example: Father → Family, Tom Cruise → actor.</p>
      <div class="auth-modal-field">
        <label for="authCategoryAddInput">Category name</label>
        <input type="text" id="authCategoryAddInput" maxlength="80" placeholder="e.g. Family, actor" autocomplete="off">
      </div>
      <div class="auth-modal-error" id="authCategoryAddError"></div>
      <div class="auth-modal-actions">
        <button type="button" class="auth-modal-cancel" data-close-modal="authCategoryAddModal">Cancel</button>
        <button type="button" class="auth-modal-save" id="authCategoryAddSaveBtn">Add category</button>
      </div>
    </div>
  </div>

  <script>
  (function () {
    var mode = <?= $accountTypeJson ?: '"personal"' ?>;
    var view = <?= $authViewJson ?: '"login"' ?>;
    var typeBtns = document.querySelectorAll('.js-auth-type');
    var hint = document.getElementById('authTypeHint');
    var kicker = document.getElementById('authKicker');
    var title = document.getElementById('authTitle');
    var sub = document.getElementById('authSub');
    var loginType = document.getElementById('loginAccountType');
    var usernameInput = document.getElementById('loginUsernameInput');
    var loginLabel = document.getElementById('loginSubmitLabel');
    var loginPanel = document.getElementById('authLoginPanel');
    var registerPanel = document.getElementById('authRegisterPanel');
    var switchLead = document.getElementById('authSwitchLead');
    var switchBtn = document.getElementById('authSwitchBtn');
    var loginForm = document.getElementById('authLoginForm');
    var registerForm = document.getElementById('authRegisterForm');
    var registerAccountType = document.getElementById('registerAccountType');
    var registerPublisherMode = document.getElementById('registerPublisherMode');
    var registerSubmitLabel = document.getElementById('registerSubmitLabel');

    var copy = {
      personal: {
        hint: 'Friends & family — your personal story space.',
        loginSub: 'Sign in to your personal account.',
        registerSub: 'Create your personal Talentra account.',
        placeholder: 'Username or email',
        continueLabel: 'Continue',
        registerCta: 'Create personal account'
      },
      publisher: {
        hint: 'News & media brands — CNN, Fox, and more.',
        loginSub: 'Sign in as a publisher brand or staff.',
        registerSub: 'Start your publisher brand account.',
        placeholder: 'Publisher username, email, or staff login',
        continueLabel: 'Continue as Publisher',
        registerCta: 'Create publisher account'
      },
      commerce: {
        hint: 'Brand stores and seller accounts',
        loginSub: 'Sign in to your commerce seller account.',
        registerSub: 'Start your commerce seller access.',
        placeholder: 'Commerce username or email',
        continueLabel: 'Continue as Commerce',
        registerCta: 'Create commerce account'
      }
    };

    function setView(next) {
      view = next === 'register' ? 'register' : 'login';
      document.body.setAttribute('data-auth-view', view);
      if (loginPanel) loginPanel.classList.toggle('is-active', view === 'login');
      if (registerPanel) registerPanel.classList.toggle('is-active', view === 'register');
      if (kicker) kicker.textContent = view === 'register' ? 'New member' : 'Existing member';
      if (title) title.textContent = view === 'register' ? 'Join Talentra' : 'Welcome Back!';
      if (switchLead) switchLead.textContent = view === 'register' ? 'Already a member?' : "Don't have account?";
      if (switchBtn) switchBtn.textContent = view === 'register' ? 'Sign In' : 'Register Now';
      syncMode();
      try {
        var u = new URL(window.location.href);
        if (view === 'register') u.searchParams.set('view', 'register');
        else u.searchParams.delete('view');
        u.searchParams.set('account_type', mode);
        history.replaceState(null, '', u.pathname + '?' + u.searchParams.toString());
      } catch (e) {}
    }

    function syncMode() {
      var cfg = copy[mode] || copy.personal;
      document.body.setAttribute('data-login-mode', mode);
      typeBtns.forEach(function (btn) {
        btn.checked = btn.getAttribute('data-type') === mode;
      });
      if (hint) hint.textContent = cfg.hint;
      if (sub) sub.textContent = view === 'register' ? cfg.registerSub : cfg.loginSub;
      if (loginType) loginType.value = mode;
      if (usernameInput) usernameInput.placeholder = cfg.placeholder;
      if (loginLabel) loginLabel.textContent = cfg.continueLabel;

      syncRegisterFields();
    }

    function setEnabled(el, on, required) {
      if (!el) return;
      el.disabled = !on;
      if (required === true) el.required = !!on;
      else if (required === false) el.required = false;
    }

    function syncRegisterFields() {
      if (!registerForm) return;
      var isPersonal = mode === 'personal';
      var isPublisher = mode === 'publisher';
      var isCommerce = mode === 'commerce';
      var cfg = copy[mode] || copy.personal;

      if (registerAccountType) registerAccountType.value = isPersonal ? 'personal' : 'publisher';
      if (registerPublisherMode) {
        registerPublisherMode.disabled = isPersonal;
        registerPublisherMode.value = isCommerce ? 'commerce' : 'media';
      }
      if (registerSubmitLabel) registerSubmitLabel.textContent = cfg.registerCta || 'Create account';

      registerForm.action = isCommerce
        ? 'register.php?account_type=publisher&publisher_mode=commerce'
        : (isPublisher ? 'register.php?account_type=publisher' : 'register.php');

      var personalBlocks = registerForm.querySelectorAll('[data-reg-mode="personal"]');
      var publisherBlock = document.getElementById('regPublisherFields');
      var commerceBlock = document.getElementById('regCommerceFields');
      var proPassword = document.getElementById('regProPassword');

      personalBlocks.forEach(function (block) { block.hidden = !isPersonal; });
      if (publisherBlock) publisherBlock.hidden = !isPublisher;
      if (commerceBlock) commerceBlock.hidden = !isCommerce;
      if (proPassword) proPassword.hidden = isPersonal;

      registerForm.querySelectorAll('.js-reg-personal-name, .js-reg-personal').forEach(function (el) {
        setEnabled(el, isPersonal, el.classList.contains('js-reg-personal-name') || el.tagName === 'SELECT' || el.type === 'tel' || el.type === 'checkbox' || el.name === 'gender' || el.name.indexOf('birth_') === 0);
      });
      registerForm.querySelectorAll('.js-reg-publisher-name, .js-reg-publisher').forEach(function (el) {
        var req = el.classList.contains('js-reg-publisher-name') || el.name === 'publisher_category';
        var usingCustom = !!(publisherCustomChosen && publisherCustomChosen.classList.contains('is-visible'));
        if (el.classList.contains('js-reg-publisher-name') && usingCustom) {
          setEnabled(el, false, false);
          return;
        }
        setEnabled(el, isPublisher, req);
      });
      if (publisherCustomName) {
        var usingPubCustom = !!(publisherCustomChosen && publisherCustomChosen.classList.contains('is-visible'));
        publisherCustomName.disabled = !(isPublisher && usingPubCustom);
      }
      registerForm.querySelectorAll('.js-reg-commerce').forEach(function (el) {
        var usingCustom = !!(commerceCustomChosen && commerceCustomChosen.classList.contains('is-visible'));
        if (usingCustom) {
          setEnabled(el, false, false);
          return;
        }
        setEnabled(el, isCommerce, true);
      });
      if (commerceCustomName) {
        var usingComCustom = !!(commerceCustomChosen && commerceCustomChosen.classList.contains('is-visible'));
        commerceCustomName.disabled = !(isCommerce && usingComCustom);
      }
      registerForm.querySelectorAll('.js-reg-commerce-cat').forEach(function (el) {
        setEnabled(el, isCommerce, false);
      });
      registerForm.querySelectorAll('.js-reg-password-personal').forEach(function (el) {
        setEnabled(el, isPersonal, true);
      });
      registerForm.querySelectorAll('.js-reg-password-pro').forEach(function (el) {
        setEnabled(el, !isPersonal, true);
      });
    }

    typeBtns.forEach(function (btn) {
      btn.addEventListener('change', function () {
        if (!btn.checked) return;
        mode = btn.getAttribute('data-type') || 'personal';
        syncMode();
        try {
          var u = new URL(window.location.href);
          u.searchParams.set('account_type', mode);
          if (view === 'register') u.searchParams.set('view', 'register');
          history.replaceState(null, '', u.pathname + '?' + u.searchParams.toString());
        } catch (e) {}
      });
    });

    if (switchBtn) {
      switchBtn.addEventListener('click', function () {
        setView(view === 'register' ? 'login' : 'register');
      });
    }

    if (loginForm) {
      loginForm.addEventListener('submit', function () {
        if (typeof window.msbArmEntryBridge === 'function') window.msbArmEntryBridge();
      });
    }
    if (registerForm) {
      registerForm.addEventListener('submit', function (ev) {
        var agreed = registerForm.querySelector('input[name="policy_agreement"][value="agree"]');
        if (!agreed || !agreed.checked) {
          ev.preventDefault();
          alert('You must agree to the Terms & Policy to create an account.');
          return;
        }
        if (typeof window.msbArmEntryBridge === 'function') window.msbArmEntryBridge();
      });
    }

    /* ---- Add name (publisher / commerce) ---- */
    var publisherSelect = document.getElementById('regPublisherNameSelect');
    var publisherCustomName = document.getElementById('regPublisherCustomName');
    var publisherCustomChosen = document.getElementById('regPublisherCustomChosen');
    var publisherCustomChosenName = document.getElementById('regPublisherCustomChosenName');
    var publisherCustomStatus = document.getElementById('regPublisherCustomStatus');
    var publisherAddBtn = document.getElementById('authPublisherAddNameBtn');
    var publisherClearBtn = document.getElementById('regPublisherCustomClear');
    var publisherModal = document.getElementById('authPublisherAddModal');
    var publisherSaveBtn = document.getElementById('authPublisherAddSaveBtn');
    var publisherAddError = document.getElementById('authPublisherAddError');
    var lastPublisherSelection = '';

    var commerceSelect = document.getElementById('regCommerceBrandSelect');
    var commerceCustomName = document.getElementById('regCommerceCustomName');
    var commerceCustomChosen = document.getElementById('regCommerceCustomChosen');
    var commerceCustomChosenName = document.getElementById('regCommerceCustomChosenName');
    var commerceCustomStatus = document.getElementById('regCommerceCustomStatus');
    var commerceAddBtn = document.getElementById('authCommerceAddNameBtn');
    var commerceClearBtn = document.getElementById('regCommerceCustomClear');
    var commerceModal = document.getElementById('authCommerceAddModal');
    var commerceSaveBtn = document.getElementById('authCommerceAddSaveBtn');
    var commerceAddError = document.getElementById('authCommerceAddError');
    var lastCommerceSelection = '';

    function openModal(el) {
      if (!el) return;
      el.hidden = false;
    }
    function closeModal(el) {
      if (!el) return;
      el.hidden = true;
    }
    document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        closeModal(document.getElementById(btn.getAttribute('data-close-modal')));
      });
    });
    [publisherModal, commerceModal, document.getElementById('authCategoryAddModal')].forEach(function (modal) {
      if (!modal) return;
      modal.addEventListener('click', function (ev) {
        if (ev.target === modal) closeModal(modal);
      });
    });

    var categoryAddBtn = document.getElementById('authPublisherAddCategoryBtn');
    var categoryModal = document.getElementById('authCategoryAddModal');
    var categoryAddInput = document.getElementById('authCategoryAddInput');
    var categoryAddSaveBtn = document.getElementById('authCategoryAddSaveBtn');
    var categoryAddError = document.getElementById('authCategoryAddError');
    var categorySelect = document.getElementById('regPublisherCategory');

    function selectPublisherCategoryOption(slug, label) {
      if (!categorySelect || !slug) return;
      var existing = null;
      Array.prototype.slice.call(categorySelect.options).forEach(function (opt) {
        if (opt.value === slug) existing = opt;
      });
      if (!existing) {
        existing = document.createElement('option');
        existing.value = slug;
        categorySelect.appendChild(existing);
      }
      existing.textContent = label || slug;
      categorySelect.value = slug;
    }

    if (categoryAddBtn) {
      categoryAddBtn.addEventListener('click', function () {
        if (categoryAddError) categoryAddError.textContent = '';
        if (categoryAddInput) categoryAddInput.value = '';
        openModal(categoryModal);
        if (categoryAddInput) setTimeout(function () { categoryAddInput.focus(); }, 40);
      });
    }
    if (categoryAddSaveBtn) {
      categoryAddSaveBtn.addEventListener('click', function () {
        var label = categoryAddInput ? categoryAddInput.value.replace(/\s+/g, ' ').trim() : '';
        if (label.length < 2) {
          if (categoryAddError) categoryAddError.textContent = 'Enter a category name (at least 2 characters).';
          return;
        }
        if (categoryAddError) categoryAddError.textContent = '';
        categoryAddSaveBtn.disabled = true;
        var body = new FormData();
        body.append('label', label);
        fetch('publisher_category_save.php', { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            categoryAddSaveBtn.disabled = false;
            if (!data || !data.ok) {
              if (categoryAddError) categoryAddError.textContent = (data && data.message) ? data.message : 'Unable to add that category.';
              return;
            }
            selectPublisherCategoryOption(data.slug, data.label);
            closeModal(categoryModal);
          })
          .catch(function () {
            categoryAddSaveBtn.disabled = false;
            if (categoryAddError) categoryAddError.textContent = 'Unable to add that category right now.';
          });
      });
    }
    if (categoryAddInput) {
      categoryAddInput.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          if (categoryAddSaveBtn) categoryAddSaveBtn.click();
        }
      });
    }

    function setPublisherCustom(name, status, message) {
      if (!name) {
        if (publisherCustomChosen) publisherCustomChosen.classList.remove('is-visible');
        if (publisherCustomName) {
          publisherCustomName.value = '';
          publisherCustomName.disabled = true;
        }
        if (publisherSelect) {
          publisherSelect.disabled = mode !== 'publisher';
          publisherSelect.required = mode === 'publisher';
        }
        return;
      }
      if (publisherSelect) {
        publisherSelect.value = '';
        publisherSelect.required = false;
        publisherSelect.disabled = true;
      }
      if (publisherCustomName) {
        publisherCustomName.value = name;
        publisherCustomName.disabled = false;
      }
      if (publisherCustomChosen) publisherCustomChosen.classList.add('is-visible');
      if (publisherCustomChosenName) publisherCustomChosenName.textContent = name;
      if (publisherCustomStatus) {
        publisherCustomStatus.textContent = message || '';
        publisherCustomStatus.className = 'auth-custom-status' + (status ? (' is-' + status) : '');
      }
    }

    function setCommerceCustom(name, status, message) {
      if (!name) {
        if (commerceCustomChosen) commerceCustomChosen.classList.remove('is-visible');
        if (commerceCustomName) {
          commerceCustomName.value = '';
          commerceCustomName.disabled = true;
        }
        if (commerceSelect) {
          commerceSelect.disabled = mode !== 'commerce';
          commerceSelect.required = mode === 'commerce';
        }
        return;
      }
      if (commerceSelect) {
        commerceSelect.value = '';
        commerceSelect.required = false;
        commerceSelect.disabled = true;
      }
      if (commerceCustomName) {
        commerceCustomName.value = name;
        commerceCustomName.disabled = false;
      }
      if (commerceCustomChosen) commerceCustomChosen.classList.add('is-visible');
      if (commerceCustomChosenName) commerceCustomChosenName.textContent = name;
      if (commerceCustomStatus) {
        commerceCustomStatus.textContent = message || '';
        commerceCustomStatus.className = 'auth-custom-status' + (status ? (' is-' + status) : '');
      }
    }

    if (publisherAddBtn) {
      publisherAddBtn.addEventListener('click', function () {
        if (publisherAddError) publisherAddError.textContent = '';
        openModal(publisherModal);
        var input = document.getElementById('authPublisherAddNameInput');
        if (input) setTimeout(function () { input.focus(); }, 40);
      });
    }
    if (commerceAddBtn) {
      commerceAddBtn.addEventListener('click', function () {
        if (commerceAddError) commerceAddError.textContent = '';
        var emailField = registerForm ? registerForm.querySelector('input[name="email"]') : null;
        var contactEmail = document.getElementById('authCommerceContactEmail');
        if (contactEmail && emailField && emailField.value && !contactEmail.value) {
          contactEmail.value = emailField.value;
        }
        openModal(commerceModal);
        var input = document.getElementById('authCommerceAddNameInput');
        if (input) setTimeout(function () { input.focus(); }, 40);
      });
    }
    if (publisherClearBtn) {
      publisherClearBtn.addEventListener('click', function () {
        setPublisherCustom('');
        if (publisherSelect && mode === 'publisher') publisherSelect.focus();
      });
    }
    if (commerceClearBtn) {
      commerceClearBtn.addEventListener('click', function () {
        setCommerceCustom('');
        if (commerceSelect && mode === 'commerce') commerceSelect.focus();
      });
    }
    if (publisherSelect) {
      publisherSelect.addEventListener('change', function () {
        if (publisherSelect.value === '__add_new__') {
          publisherSelect.value = lastPublisherSelection || '';
          if (publisherAddBtn) publisherAddBtn.click();
          return;
        }
        lastPublisherSelection = publisherSelect.value;
        setPublisherCustom('');
      });
    }
    if (commerceSelect) {
      commerceSelect.addEventListener('change', function () {
        if (commerceSelect.value === '__add_new__') {
          commerceSelect.value = lastCommerceSelection || '';
          if (commerceAddBtn) commerceAddBtn.click();
          return;
        }
        lastCommerceSelection = commerceSelect.value;
        setCommerceCustom('');
      });
    }

    if (publisherSaveBtn) {
      publisherSaveBtn.addEventListener('click', function () {
        var name = (document.getElementById('authPublisherAddNameInput') || {}).value || '';
        name = String(name).replace(/\s+/g, ' ').trim();
        var contactName = (document.getElementById('authPublisherContactName') || {}).value || '';
        var contactEmail = (document.getElementById('authPublisherContactEmail') || {}).value || '';
        var confirmed = document.getElementById('authPublisherAuthorityConfirm');
        if (name.length < 2) {
          if (publisherAddError) publisherAddError.textContent = 'Enter a publisher name (at least 2 characters).';
          return;
        }
        if (!String(contactName).trim() || !String(contactEmail).trim()) {
          if (publisherAddError) publisherAddError.textContent = 'Enter representative name and email.';
          return;
        }
        if (!confirmed || !confirmed.checked) {
          if (publisherAddError) publisherAddError.textContent = 'Confirm you are authorized to request this name.';
          return;
        }
        if (publisherAddError) publisherAddError.textContent = '';
        publisherSaveBtn.disabled = true;
        var body = new FormData();
        body.append('name', name);
        var cat = document.getElementById('regPublisherCategory');
        body.append('publisher_category', cat ? (cat.value || 'news') : 'news');
        body.append('entity_type', (document.getElementById('authPublisherEntityType') || {}).value || 'business');
        body.append('legal_entity_name', (document.getElementById('authPublisherLegalName') || {}).value || '');
        body.append('authorized_contact_name', String(contactName).trim());
        body.append('authorized_contact_email', String(contactEmail).trim());
        body.append('request_note', (document.getElementById('authPublisherRequestNote') || {}).value || '');
        body.append('authority_confirmed', '1');
        fetch('publisher_name_save.php', { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            publisherSaveBtn.disabled = false;
            if (!data || !data.ok) {
              if (publisherAddError) publisherAddError.textContent = (data && data.message) ? data.message : 'Unable to submit request.';
              return;
            }
            setPublisherCustom(
              data.name || name,
              data.status || 'pending',
              data.message || 'Request submitted. Waiting for admin approval.'
            );
            closeModal(publisherModal);
          })
          .catch(function () {
            publisherSaveBtn.disabled = false;
            if (publisherAddError) publisherAddError.textContent = 'Unable to submit request right now.';
          });
      });
    }

    if (commerceSaveBtn) {
      commerceSaveBtn.addEventListener('click', function () {
        var name = (document.getElementById('authCommerceAddNameInput') || {}).value || '';
        name = String(name).replace(/\s+/g, ' ').trim();
        var contactName = (document.getElementById('authCommerceContactName') || {}).value || '';
        var contactEmail = (document.getElementById('authCommerceContactEmail') || {}).value || '';
        var confirmed = document.getElementById('authCommerceAuthorityConfirm');
        var usernameField = registerForm ? registerForm.querySelector('input[name="username"]') : null;
        var emailField = registerForm ? registerForm.querySelector('input[name="email"]') : null;
        var username = usernameField ? String(usernameField.value || '').trim() : '';
        var email = emailField ? String(emailField.value || '').trim().toLowerCase() : '';
        if (!email && contactEmail) email = String(contactEmail).trim().toLowerCase();
        if (name.length < 2) {
          if (commerceAddError) commerceAddError.textContent = 'Enter a brand name (at least 2 characters).';
          return;
        }
        if (!username || !email) {
          if (commerceAddError) commerceAddError.textContent = 'Enter account username and email in the signup form first.';
          return;
        }
        if (!String(contactName).trim() || !String(contactEmail).trim()) {
          if (commerceAddError) commerceAddError.textContent = 'Enter representative name and email.';
          return;
        }
        if (!confirmed || !confirmed.checked) {
          if (commerceAddError) commerceAddError.textContent = 'Confirm you are authorized to request this brand.';
          return;
        }
        if (commerceAddError) commerceAddError.textContent = '';
        commerceSaveBtn.disabled = true;
        var body = new FormData();
        body.append('name', name);
        body.append('username', username);
        body.append('email', email);
        body.append('entity_type', (document.getElementById('authCommerceEntityType') || {}).value || 'business');
        body.append('authorized_contact_name', String(contactName).trim());
        body.append('authorized_contact_email', String(contactEmail).trim());
        body.append('request_note', (document.getElementById('authCommerceRequestNote') || {}).value || '');
        body.append('authority_confirmed', '1');
        fetch('commerce_brand_name_request_save.php', { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (res) { return res.json(); })
          .then(function (data) {
            commerceSaveBtn.disabled = false;
            if (!data || !data.ok) {
              if (commerceAddError) commerceAddError.textContent = (data && data.message) ? data.message : 'Unable to submit brand request.';
              return;
            }
            setCommerceCustom(
              data.name || name,
              data.status || 'pending',
              data.approved
                ? 'Brand approved — you can create your account.'
                : 'Request submitted. Waiting for admin approval.'
            );
            if (data.brand_id && commerceSelect) {
              // keep custom name path; brand id may arrive after approval
            }
            closeModal(commerceModal);
          })
          .catch(function () {
            commerceSaveBtn.disabled = false;
            if (commerceAddError) commerceAddError.textContent = 'Unable to submit brand request right now.';
          });
      });
    }

    setView(view);
  })();
  </script>
  <?php require __DIR__ . '/includes/entry_bridge_handoff.php'; ?>
</body>
</html>
