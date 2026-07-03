<?php
// public_html/admin/includes/admin_guard.php

require_once __DIR__ . '/session_admin.php'; // your admin session starter

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (empty($_SESSION['admin_id']) || empty($_SESSION['userRole'])) {
  header("Location: /public_user/index.php?err=unauthorized");
  exit;
}

// Optional: if your userRole is a STRING like "admin"
if ((string)$_SESSION['userRole'] === 'admin') {
  return;
}

// Optional: if your userRole is a numeric role id (like 1=admin)
// Uncomment and adjust if you know the admin role id:
// if ((int)$_SESSION['userRole'] !== 1) {
//   header("Location: /public_user/index.php?err=unauthorized");
//   exit;
// }