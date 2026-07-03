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
  $_SESSION['org_role_id']
);

header("Location: login.php");
exit;
