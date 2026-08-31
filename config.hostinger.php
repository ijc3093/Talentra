<?php
// Upload this file to Hostinger as: public_html/config.php
// (Keep the local MAMP config.php on your computer unchanged.)
//
// Hostinger account u825834874:
//   Database  = u825834874_talsora
//   App files = domains/mystorybook.pro/public_html  (this repo)
//   Public domain to use: talsora.io (Namecheap). Do not use talsora.pro
//   (that name is a different live marketing site).
declare(strict_types=1);

if (!defined('APP_CANONICAL_HOST')) {
    define('APP_CANONICAL_HOST', 'talsora.com');
}

if (!defined('APP_SIGNING_KEY')) {
    define('APP_SIGNING_KEY', 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET_64+CHARS');
}

if (!class_exists('Config', false)) {
    class Config
    {
        private PDO $dbh;

        /* =========================
           DATABASE (Hostinger)
        ========================= */
        public string $DB_HOST = '127.0.0.1';
        public string $DB_USER = 'u825834874_root';
        public string $DB_PASS = 'u825834874_Pass';
        public string $DB_NAME = 'u825834874_talsora';
        public int    $DB_PORT = 3306;

        /* =========================
           SMTP (GMAIL - APP PASSWORD)
        ========================= */
        public string $SMTP_HOST = 'smtp.gmail.com';
        public int    $SMTP_PORT = 587;
        public string $SMTP_USER = 'isaaccuma3093@gmail.com';
        public string $SMTP_PASS = 'vjwu vqug zrty ucrz';
        public string $SMTP_FROM = 'isaaccuma3093@gmail.com';
        public string $SMTP_FROM_NAME = 'Private App';

        public string $ADMIN_ALERT_EMAIL = 'isaaccuma3093@gmail.com';

        public string $STRIPE_SECRET_KEY = '';
        public string $STRIPE_PUBLISHABLE_KEY = '';
        public string $STRIPE_WEBHOOK_SECRET = '';

        public function __construct()
        {
            // Hostinger often blocks the MySQL unix socket that PHP uses for
            // host=localhost (SQLSTATE 2002 "Operation not permitted").
            // Prefer TCP to 127.0.0.1, then fall back to the configured host.
            $hosts = [];
            foreach (['127.0.0.1', $this->DB_HOST, 'localhost'] as $host) {
                $host = trim((string)$host);
                if ($host !== '' && !in_array($host, $hosts, true)) {
                    $hosts[] = $host;
                }
            }

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 8,
            ];

            $lastError = null;
            foreach ($hosts as $host) {
                $dsn = "mysql:host={$host};port={$this->DB_PORT};dbname={$this->DB_NAME};charset=utf8mb4";
                try {
                    $this->dbh = new PDO($dsn, $this->DB_USER, $this->DB_PASS, $options);
                    return;
                } catch (PDOException $e) {
                    $lastError = $e;
                }
            }

            http_response_code(500);
            die('Database could not be connected: ' . ($lastError ? $lastError->getMessage() : 'unknown error'));
        }

        public function pdo(): PDO
        {
            return $this->dbh;
        }
    }
}
