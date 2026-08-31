<?php
// /public_user/index.php — Talsora sign-in / sign-up gate
require_once __DIR__ . '/includes/session_user.php';
require_once __DIR__ . '/includes/deleted_user_registry.php';
require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/staff_publisher_access.php';
require_once __DIR__ . '/includes/account_switch.php';
require_once __DIR__ . '/includes/index_footer_tabs.php';
require_once __DIR__ . '/includes/appearance_bridge.php';
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
$addingAccount = account_switch_is_add_request();
$accountSwitchFromId = $addingAccount ? account_switch_pending_owner_id() : 0;

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

$indexTab = index_footer_tab_from_request();
$indexLoggedIn = !empty($_SESSION['user_login']) && !empty($_SESSION['user_id']);

if ($indexLoggedIn) {
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
        // fall through
    }
    if (!$addingAccount && $indexTab === '') {
        header('Location: home.php?tab=for-you');
        exit;
    }
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
                    $error = 'This is a personal account. Switch to Personal User to sign in.';
                } elseif ($user && !$isPublisherLogin && login_user_is_publisher($user)) {
                    $error = 'This is a publisher account. Switch to Publisher to sign in.';
                } elseif ($user) {
                    setUserSession($user);
                    login_bump_last_seen($controller);
                    if ($accountSwitchFromId > 0) {
                        account_switch_complete_after_auth(
                            $controller->pdo(),
                            $accountSwitchFromId,
                            (int)($user['id'] ?? 0)
                        );
                    }
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
                                if ($accountSwitchFromId > 0) {
                                    account_switch_complete_after_auth(
                                        $controller->pdo(),
                                        $accountSwitchFromId,
                                        (int)($linkedUser['id'] ?? 0)
                                    );
                                }
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
  <title><?= $indexTab !== '' ? htmlspecialchars(index_help_tab_title($indexTab) . ' · Talsora', ENT_QUOTES, 'UTF-8') : 'Talsora — Sign in' ?></title>
  <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
  <link href="./css/auth-gate.css?v=80" rel="stylesheet">
  <?php
  $themeUserId = $indexLoggedIn ? (int)($_SESSION['user_id'] ?? 0) : 0;
  $themeDbh = null;
  try {
      $themeDbh = (new Controller())->pdo();
  } catch (Throwable $e) {
      $themeDbh = null;
  }
  if ($themeDbh instanceof PDO && $themeUserId > 0) {
      appearance_bridge_print_theme_stack($themeDbh, $themeUserId);
      appearance_bridge_print_index_gate_critical($themeDbh, $themeUserId);
  } else {
      $guestMode = appearance_bridge_read_cookie_mode();
      if (appearance_bridge_is_named_palette($guestMode)) {
          appearance_bridge_print_guest_index_theme($guestMode);
      } else {
          appearance_bridge_print_early_dark_auto_class(true);
          appearance_bridge_print_index_daylight_critical();
          if (!defined('MSB_THEME_DARK_CSS')) {
              define('MSB_THEME_DARK_CSS', true);
              echo '<link rel="stylesheet" href="./css/dark-auto.css?v=51">' . "\n";
          }
      }
  }
  ?>

</head>
<body class="ig-auth<?= $indexTab !== '' ? ' is-index-tab' : '' ?>" data-auth-view="<?= htmlspecialchars($authView, ENT_QUOTES, 'UTF-8') ?>" data-login-mode="<?= htmlspecialchars($accountType, ENT_QUOTES, 'UTF-8') ?>" data-index-tab="<?= htmlspecialchars($indexTab, ENT_QUOTES, 'UTF-8') ?>">
  <?php require __DIR__ . '/includes/register_welcome_modal.php'; ?>

  <div class="auth-page">
  <div class="auth-gear-wrap">
    <button type="button" class="auth-gear" id="authSettingsBtn" aria-label="Settings" aria-haspopup="true" aria-expanded="false" aria-controls="authSettingsMenu">
      <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
        <path fill="currentColor" d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 00.12-.64l-1.92-3.32a.5.5 0 00-.6-.22l-2.39.96c-.5-.39-1.04-.7-1.63-.94l-.36-2.54A.5.5 0 0014.4 2h-4.8a.5.5 0 00-.5.42l-.36 2.54c-.59.24-1.13.55-1.63.94l-2.39-.96a.5.5 0 00-.6.22L1.7 8.48a.5.5 0 00.12.64l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94L1.82 14.16a.5.5 0 00-.12.64l1.92 3.32c.14.23.4.32.64.22l2.39-.96c.5.39 1.04.7 1.63.94l.36 2.54c.05.24.26.42.5.42h4.8c.24 0 .45-.18.5-.42l.36-2.54c.59-.24 1.13-.55 1.63-.94l2.39.96c.24.1.51 0 .64-.22l1.92-3.32a.5.5 0 00-.12-.64l-2.03-1.58zM12 15.6A3.6 3.6 0 1112 8.4a3.6 3.6 0 010 7.2z"/>
      </svg>
    </button>
    <div class="auth-gear-menu" id="authSettingsMenu" hidden role="dialog" aria-labelledby="authSettingsBtn">
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
    </div>
  </div>
  <div class="auth-shell" id="authShell" aria-label="Talsora sign in">
    <aside class="auth-left" aria-label="Talsora">
      <a class="ig-logo" href="index.php">
        <span class="auth-brand-orb" aria-hidden="true"><span class="auth-brand-mark">t</span></span>
        <span class="ig-logo-word">Talsora</span>
      </a>
      <h2 class="ig-headline" id="igHeadline">See everyday moments from your <span id="igHeadlineAccent">close friends.</span></h2>
      <div class="ig-phones" aria-hidden="true">
        <span class="ig-heart">♥</span>
        <div class="ig-phone">
          <img src="https://images.unsplash.com/photo-1469474968028-56623f02e42e?auto=format&fit=crop&w=400&h=720&q=80" alt="">
          <div class="ig-phone-ui"><span class="ig-progress"><i class="is-on"></i><i></i><i></i></span></div>
        </div>
        <div class="ig-phone">
          <img src="https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=400&h=720&q=80" alt="">
          <div class="ig-phone-ui"><span class="ig-progress"><i class="is-on"></i><i></i><i></i></span></div>
        </div>
        <div class="ig-phone">
          <img src="https://images.unsplash.com/photo-1470071459604-3b5ec3a7fe05?auto=format&fit=crop&w=400&h=720&q=80" alt="">
          <div class="ig-phone-ui"><span class="ig-progress"><i class="is-on"></i><i></i><i></i></span></div>
        </div>
        <div class="ig-phone">
          <img src="https://images.unsplash.com/photo-1441974231531-c6227db76b6e?auto=format&fit=crop&w=400&h=720&q=80" alt="">
          <div class="ig-phone-ui"><span class="ig-progress"><i class="is-on"></i><i></i><i></i></span></div>
        </div>
      </div>
    </aside>

    <div class="auth-divider-col" aria-hidden="true"></div>

    <section class="auth-right">
      <div class="auth-right-main">
      <div class="auth-right-head">
        <p class="auth-kicker" id="authKicker"><?php
          if ($addingAccount) {
              echo $authView === 'register' ? 'Create another account' : 'Add another account';
          } else {
              echo $authView === 'register' ? 'Create an account' : 'Log into Talsora';
          }
        ?></p>
        <h2 class="auth-title sr-only" id="authTitle"><?= $authView === 'register' ? 'Join Talsora' : 'Log into Talsora' ?></h2>
        <p class="auth-sub" id="authSub">Sign in to your personal account.</p>
      </div>

      <div class="auth-right-spacer" aria-hidden="true"></div>

      <div class="auth-right-stack">
      <div class="auth-right-body">
      <?php if ($error !== ''): ?>
        <div class="auth-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php elseif ($addingAccount): ?>
        <div class="auth-alert" role="status">Sign in or create another account. It will be linked so you can switch later.</div>
      <?php endif; ?>

      <div class="auth-panels">
        <div class="auth-panel<?= $authView === 'login' ? ' is-active' : '' ?>" id="authLoginPanel" data-panel="login">
          <form method="post" autocomplete="off" id="authLoginForm">
            <?= csrfInput() ?>
            <?php if ($addingAccount): ?>
            <input type="hidden" name="add_account" value="1">
            <?php endif; ?>
            <input type="hidden" name="account_type" id="loginAccountType" value="<?= htmlspecialchars($accountType, ENT_QUOTES, 'UTF-8') ?>">
            <div class="auth-field">
              <input type="text" name="username" id="loginUsernameInput" value="<?= htmlspecialchars($usernameValue, ENT_QUOTES, 'UTF-8') ?>" placeholder="Username or email" required>
            </div>
            <div class="auth-field">
              <input type="password" name="password" placeholder="Password" required>
            </div>
            <button class="auth-continue" name="login" type="submit" value="1" id="loginSubmitBtn">
              <span id="loginSubmitLabel">Log in</span>
            </button>
            <div class="auth-link-row">
              <a href="forget.php">Forgot password?</a>
            </div>
          </form>
        </div>

        <div class="auth-panel<?= $authView === 'register' ? ' is-active' : '' ?>" id="authRegisterPanel" data-panel="register">
          <form method="post" action="register.php" autocomplete="off" id="authRegisterForm">
            <?= csrfInput() ?>
            <?php if ($addingAccount): ?>
            <input type="hidden" name="add_account" value="1">
            <?php endif; ?>
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

      <div class="auth-switch" id="authSwitch">
        <span id="authSwitchLead">Don't have an account?</span>
        <button type="button" id="authSwitchBtn">Create new account</button>
      </div>
      <div class="auth-meta-mark" aria-hidden="true">Talsora</div>
      </div>
      </div>
    </section>
  </div>
  <?php index_render_legal_panels($indexTab, $indexLoggedIn, $addingAccount); ?>
  <footer class="auth-page-foot">
    <?php index_render_footer_tab_nav($indexTab, $addingAccount); ?>
    <p class="auth-page-copy">English · © <?= (int)date('Y') ?> Talsora</p>
  </footer>
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
    var addingAccount = <?= $addingAccount ? 'true' : 'false' ?>;
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

    var igHeadline = document.getElementById('igHeadline');
    var igHeadlineAccent = document.getElementById('igHeadlineAccent');

    var copy = {
      personal: {
        hint: 'Friends & family — your personal story space.',
        loginSub: 'Sign in to your personal account.',
        registerSub: 'Create your personal Talsora account.',
        placeholder: 'Mobile number, username or email',
        continueLabel: 'Log in',
        registerCta: 'Create personal account',
        headline: 'See everyday moments from your ',
        accent: 'close friends.'
      },
      publisher: {
        hint: 'News & media brands — CNN, Fox, and more.',
        loginSub: 'Sign in as a publisher brand or staff.',
        registerSub: 'Start your publisher brand account.',
        placeholder: 'Publisher username, email, or staff login',
        continueLabel: 'Log in',
        registerCta: 'Create publisher account',
        headline: 'Share the story as it happens with your ',
        accent: 'audience.'
      },
      commerce: {
        hint: 'Brand stores and seller accounts',
        loginSub: 'Sign in to your commerce seller account.',
        registerSub: 'Start your commerce seller access.',
        placeholder: 'Commerce username or email',
        continueLabel: 'Log in',
        registerCta: 'Create commerce account',
        headline: 'Bring your shop into everyday ',
        accent: 'moments.'
      }
    };

    function setView(next) {
      view = next === 'register' ? 'register' : 'login';
      document.body.setAttribute('data-auth-view', view);
      if (loginPanel) loginPanel.classList.toggle('is-active', view === 'login');
      if (registerPanel) registerPanel.classList.toggle('is-active', view === 'register');
      if (kicker) {
        if (addingAccount) kicker.textContent = view === 'register' ? 'Create another account' : 'Add another account';
        else kicker.textContent = view === 'register' ? 'Create an account' : 'Log into Talsora';
      }
      if (title) title.textContent = view === 'register' ? 'Create an account' : 'Log into Talsora';
      if (switchLead) switchLead.textContent = view === 'register' ? 'Already have an account?' : "Don't have an account?";
      if (switchBtn) switchBtn.textContent = view === 'register' ? 'Log in' : 'Create new account';
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
      if (igHeadline && igHeadlineAccent) {
        igHeadline.innerHTML = (cfg.headline || '') + '<span id="igHeadlineAccent">' + (cfg.accent || '') + '</span>';
        igHeadlineAccent = document.getElementById('igHeadlineAccent');
      }
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

    var gearBtn = document.getElementById('authSettingsBtn');
    var gearMenu = document.getElementById('authSettingsMenu');
    if (gearBtn && gearMenu) {
      function closeGear() {
        gearMenu.hidden = true;
        gearBtn.setAttribute('aria-expanded', 'false');
      }
      gearBtn.addEventListener('click', function (ev) {
        ev.stopPropagation();
        var open = gearMenu.hidden;
        gearMenu.hidden = !open;
        gearBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      document.addEventListener('click', function () { closeGear(); });
      gearMenu.addEventListener('click', function (ev) { ev.stopPropagation(); });
      document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') closeGear();
      });
    }
  })();
  </script>
  <script>
  (function () {
    var tabs = <?= json_encode(index_help_all_tab_keys(), JSON_UNESCAPED_SLASHES) ?>;
    var titles = <?= json_encode(index_help_all_tab_titles(), JSON_UNESCAPED_SLASHES) ?>;
    var adding = <?= $addingAccount ? 'true' : 'false' ?>;
    var groupLabels = <?= json_encode(index_help_crumb_map(), JSON_UNESCAPED_SLASHES) ?>;
    var crumb = document.getElementById('hcCrumb');
    var search = document.getElementById('hcSearch');
    var legal = document.getElementById('authLegal');
    var articles = legal ? legal.querySelectorAll('[data-legal-panel]') : [];
    var links = document.querySelectorAll('.js-index-tab');
    var helpInited = false;
    var hcMain = document.querySelector('.hc-main');
    function updateAboutProgress() {
      var nav = document.querySelector('.hc-story-progress');
      if (!nav || !hcMain) return;
      var prog = nav.querySelectorAll('a');
      if (!prog.length) return;
      if (document.body.getAttribute('data-index-tab') !== 'about') return;
      var marker = hcMain.getBoundingClientRect().top + 140;
      var current = prog[0].getAttribute('href');
      Array.prototype.forEach.call(prog, function (a) {
        var id = String(a.getAttribute('href') || '').replace('#', '');
        var el = document.getElementById(id);
        if (!el) return;
        if (el.getBoundingClientRect().top <= marker) current = a.getAttribute('href');
      });
      Array.prototype.forEach.call(prog, function (a) {
        a.classList.toggle('is-active', a.getAttribute('href') === current);
      });
    }
    function syncAboutReel() {
      var onAbout = document.body.getAttribute('data-index-tab') === 'about';
      document.querySelectorAll('.hc-about-reel-video').forEach(function (v) {
        if (!onAbout) {
          try { v.pause(); } catch (ePause) {}
          return;
        }
        var r = v.getBoundingClientRect();
        var vis = r.bottom > 80 && r.top < (window.innerHeight - 40);
        if (vis) {
          v.muted = true;
          var play = v.play();
          if (play && play.catch) play.catch(function () {});
        } else {
          try { v.pause(); } catch (eOff) {}
        }
      });
    }
    document.addEventListener('click', function (e) {
      var reel = e.target && e.target.closest ? e.target.closest('.hc-about-reel') : null;
      if (!reel) return;
      var v = reel.querySelector('.hc-about-reel-video');
      if (!v) return;
      if (v.paused) {
        var play = v.play();
        if (play && play.catch) play.catch(function () {});
      } else {
        v.pause();
      }
    });
    if (hcMain) hcMain.addEventListener('scroll', function () {
      updateAboutProgress();
      syncAboutReel();
    }, { passive: true });
    document.querySelectorAll('.hc-story-progress a, .hc-story-scroll, .hc-dest-card').forEach(function (a) {
      a.addEventListener('click', function (e) {
        var id = String(a.getAttribute('href') || '').replace('#', '');
        var el = document.getElementById(id);
        if (!el || !hcMain) return;
        e.preventDefault();
        var next = hcMain.scrollTop + el.getBoundingClientRect().top - hcMain.getBoundingClientRect().top;
        hcMain.scrollTo({ top: next, behavior: 'smooth' });
      });
    });

    function hrefFor(tab) {
      var q = 'index.php?tab=' + encodeURIComponent(tab);
      if (adding) q += '&add_account=1';
      return q;
    }
    function initHelp() {
      if (helpInited) return;
      var root = document.getElementById('indexHelpRoot');
      if (!root) return;
      helpInited = true;
      var endpoint = String(root.getAttribute('data-endpoint') || 'ajax/admin_support_chat.php');
      var thread = document.getElementById('indexHelpThread');
      var input = document.getElementById('indexHelpInput');
      var sendBtn = document.getElementById('indexHelpSend');
      var errEl = document.getElementById('indexHelpErr');
      var lastId = 0;
      var polling = false;
      function setErr(msg) {
        if (!errEl) return;
        if (!msg) { errEl.hidden = true; errEl.textContent = ''; return; }
        errEl.hidden = false;
        errEl.textContent = msg;
      }
      function esc(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
      }
      function appendItems(items, replace) {
        if (!thread) return;
        if (replace) thread.innerHTML = '';
        (items || []).forEach(function (item) {
          var id = parseInt(item.id || 0, 10);
          if (id > lastId) lastId = id;
          var div = document.createElement('div');
          div.className = 'auth-help-bubble ' + (item.is_me ? 'me' : 'them');
          div.innerHTML = esc(item.text || '') + '<div class="auth-help-meta">' + esc(item.from || '') + ' · ' + esc(item.time_label || '') + '</div>';
          thread.appendChild(div);
        });
        thread.scrollTop = thread.scrollHeight;
      }
      async function loadHistory() {
        try {
          var res = await fetch(endpoint + '?mode=history&after=0&mark=1', { credentials: 'same-origin' });
          var data = await res.json();
          if (data && data.ok) {
            lastId = 0;
            appendItems(data.items || [], true);
            if (!(data.items || []).length) {
              thread.innerHTML = '<div class="auth-help-empty">No Admin messages yet. Describe what you need help with.</div>';
            }
          }
        } catch (e) { /* ignore */ }
      }
      async function pollNew() {
        if (polling) return;
        polling = true;
        try {
          var res = await fetch(endpoint + '?mode=history&after=' + lastId + '&mark=1', { credentials: 'same-origin' });
          var data = await res.json();
          if (data && data.ok && (data.items || []).length) {
            if (thread && thread.querySelector('.auth-help-empty')) thread.innerHTML = '';
            appendItems(data.items, false);
          }
        } catch (e) { /* ignore */ }
        polling = false;
      }
      async function sendMessage() {
        setErr('');
        var text = input ? String(input.value || '').trim() : '';
        if (!text) { setErr('Type a message for Admin.'); return; }
        if (sendBtn) sendBtn.disabled = true;
        try {
          var body = new URLSearchParams();
          body.set('mode', 'send');
          body.set('topic', 'help');
          body.set('message', text);
          var res = await fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
          });
          var data = await res.json();
          if (!data || !data.ok) {
            setErr((data && (data.error || data.message)) || 'Could not send.');
            return;
          }
          if (input) input.value = '';
          if (data.item) {
            if (thread && thread.querySelector('.auth-help-empty')) thread.innerHTML = '';
            appendItems([data.item], false);
          } else {
            await pollNew();
          }
        } catch (e) {
          setErr('Could not send message.');
        } finally {
          if (sendBtn) sendBtn.disabled = false;
        }
      }
      if (sendBtn) sendBtn.addEventListener('click', sendMessage);
      if (input) {
        input.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
        });
      }
      loadHistory();
      setInterval(pollNew, 5000);
    }
    function showTab(tab) {
      var ok = tabs.indexOf(tab) !== -1;
      document.body.classList.toggle('is-index-tab', ok);
      document.body.setAttribute('data-index-tab', ok ? tab : '');
      if (legal) legal.hidden = !ok;
      Array.prototype.forEach.call(articles, function (el) {
        el.hidden = !ok || el.getAttribute('data-legal-panel') !== tab;
      });
      Array.prototype.forEach.call(links, function (a) {
        var on = ok && a.getAttribute('data-index-tab') === tab && !a.classList.contains('hc-brand');
        a.classList.toggle('is-active', on);
      });
      document.querySelectorAll('.hc-nav-group').forEach(function (group) {
        var has = group.querySelector('a[data-index-tab="' + tab + '"]');
        if (has) group.open = true;
      });
      document.querySelectorAll('.hc-nav-topic').forEach(function (topic) {
        var has = topic.querySelector('a[data-index-tab="' + tab + '"]');
        if (has) topic.open = true;
      });
      if (crumb) crumb.textContent = ok ? (groupLabels[tab] || 'Talsora') : 'Talsora';
      document.title = ok ? ((titles[tab] || 'Talsora') + ' · Talsora') : 'Talsora — Sign in';
      if (history.replaceState) {
        history.replaceState(null, '', ok ? hrefFor(tab) : (adding ? 'index.php?add_account=1' : 'index.php'));
      }
      if (ok && tab === 'help') initHelp();
      if (ok && tab === 'about') {
        var mainReset = document.querySelector('.hc-main');
        if (mainReset) mainReset.scrollTop = 0;
        updateAboutProgress();
        syncAboutReel();
      } else {
        if (hcMain) hcMain.scrollTop = 0;
        syncAboutReel();
      }
      var hashId = String(window.location.hash || '').replace('#', '');
      if (ok && hashId) openHelpAcc(hashId);
    }
    Array.prototype.forEach.call(links, function (a) {
      a.addEventListener('click', function (e) {
        var tab = a.getAttribute('data-index-tab');
        if (!tab) return;
        if (a.classList.contains('hc-topic-link')) e.stopPropagation();
        e.preventDefault();
        showTab(tab);
        window.scrollTo(0, 0);
      });
    });
    function openHelpAcc(id) {
      var acc = id ? document.getElementById(id) : null;
      if (!acc || acc.tagName !== 'DETAILS') return false;
      var panel = acc.closest('[data-legal-panel]');
      if (panel && panel.hidden) return false;
      document.querySelectorAll('.hc-acc').forEach(function (d) { d.open = d === acc; });
      document.querySelectorAll('.hc-pill').forEach(function (p) {
        p.classList.toggle('is-on', p.getAttribute('href') === '#' + id);
      });
      if (hcMain) {
        hcMain.scrollTop = hcMain.scrollTop + acc.getBoundingClientRect().top - hcMain.getBoundingClientRect().top - 8;
      }
      return true;
    }
    document.addEventListener('click', function (e) {
      var helpBtn = e.target && e.target.closest ? e.target.closest('.hc-helpful-btn') : null;
      if (helpBtn) {
        var box = helpBtn.closest('.hc-helpful');
        if (!box) return;
        var id = String(box.getAttribute('data-helpful-id') || '');
        var val = String(helpBtn.getAttribute('data-helpful') || '');
        try { if (id) localStorage.setItem('hc-helpful-' + id, val); } catch (err) {}
        box.querySelectorAll('.hc-helpful-btn').forEach(function (b) {
          b.classList.toggle('is-on', b === helpBtn);
        });
        var done = box.querySelector('.hc-helpful-done');
        if (done) done.hidden = false;
        return;
      }
      var copyHash = e.target && e.target.closest ? e.target.closest('.js-hc-copy-hash') : null;
      if (copyHash) {
        var hash = String(copyHash.getAttribute('data-hash') || '');
        var url = window.location.href.split('#')[0] + (hash ? '#' + hash : '');
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(function () {
            var label = copyHash.querySelector('.hc-acc-copy-label');
            if (!label) return;
            label.textContent = 'Copied';
            setTimeout(function () { label.textContent = 'Copy link'; }, 1600);
          }).catch(function () {});
        }
        e.preventDefault();
        return;
      }
      var jump = e.target && e.target.closest ? e.target.closest('.hc-pill, .js-hc-acc-jump, a.hc-acc-tool') : null;
      if (jump) {
        var pid = String(jump.getAttribute('href') || '').split('#')[1] || '';
        if (pid && openHelpAcc(pid)) e.preventDefault();
      }
    });
    document.querySelectorAll('.hc-acc').forEach(function (acc) {
      acc.addEventListener('toggle', function () {
        if (!acc.open) return;
        document.querySelectorAll('.hc-acc').forEach(function (d) {
          if (d !== acc) d.open = false;
        });
        document.querySelectorAll('.hc-pill').forEach(function (p) {
          p.classList.toggle('is-on', p.getAttribute('href') === '#' + acc.id);
        });
      });
    });
    document.querySelectorAll('.js-hc-login').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        showTab('');
      });
    });
    document.querySelectorAll('.js-hc-copy').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var url = window.location.href;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(function () {
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = 'Copy link'; }, 1600);
          }).catch(function () {});
        }
      });
    });
    var navToggle = document.getElementById('hcNavToggle');
    var helpCenter = document.getElementById('authLegal');
    function setNavOpen(open) {
      if (!helpCenter || !navToggle) return;
      helpCenter.classList.toggle('is-nav-closed', !open);
      navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      navToggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    }
    if (navToggle) {
      setNavOpen(false);
      navToggle.addEventListener('click', function () {
        setNavOpen(helpCenter && helpCenter.classList.contains('is-nav-closed'));
      });
    }
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        if (helpCenter) helpCenter.classList.add('is-nav-anim');
      });
    });
    if (search) {
      search.addEventListener('input', function () {
        var q = String(search.value || '').trim().toLowerCase();
        document.querySelectorAll('.hc-nav-group a.js-index-tab').forEach(function (a) {
          var hit = !q || String(a.textContent || '').toLowerCase().indexOf(q) !== -1;
          a.classList.toggle('hc-nav-hidden', !hit);
        });
        document.querySelectorAll('.hc-nav-topic').forEach(function (topic) {
          var summary = topic.querySelector('summary');
          var topicHit = !q || String((summary && summary.textContent) || '').toLowerCase().indexOf(q) !== -1;
          var any = false;
          topic.querySelectorAll('a.js-index-tab').forEach(function (a) {
            var hit = topicHit || !q || String(a.textContent || '').toLowerCase().indexOf(q) !== -1;
            a.classList.toggle('hc-nav-hidden', !hit);
            if (hit) any = true;
          });
          topic.classList.toggle('hc-nav-hidden', !!q && !any);
          if (q && any) topic.open = true;
        });
      });
    }
    var current = String(document.body.getAttribute('data-index-tab') || '');
    if (current === 'help') initHelp();
    if (current === 'about') {
      updateAboutProgress();
      syncAboutReel();
    }
    openHelpAcc(String(window.location.hash || '').replace('#', ''));
  })();
  </script>
  <?php
  appearance_bridge_print_index_help_ink_tail();
  appearance_bridge_print_index_chrome_lines(
      ($themeDbh instanceof PDO) ? $themeDbh : null,
      (int)($themeUserId ?? 0)
  );
  require __DIR__ . '/includes/entry_bridge_handoff.php';
  appearance_bridge_print_index_help_borders();
  ?>
</body>
</html>
