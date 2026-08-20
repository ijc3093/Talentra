<?php
// /Business_only3/organization/logout.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

unset(
  $_SESSION['org_auth'],
  $_SESSION['org_account_type'],
  $_SESSION['org_account_id'],
  $_SESSION['org_active_org_id'],
  $_SESSION['org_member_id'],
  $_SESSION['org_role_id'],
  $_SESSION['org_publisher_user_id']
);

// The organization portal and public account portal use separate session
// cookies. Close the organization session first, then clear the linked public
// publisher/commerce session so the personal sign-in screen is actually shown.
session_write_close();

require_once __DIR__ . '/../public_user/includes/session_user.php';
clearUserSession();

header('Location: ../public_user/index.php?account_type=personal');
exit;
