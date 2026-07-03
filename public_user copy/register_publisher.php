<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
header('Location: register.php?account_type=publisher');
exit;
