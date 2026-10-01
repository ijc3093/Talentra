<?php
// Upload this file to Hostinger as: public_html/config.php
// (Keep the local MAMP config.php on your computer unchanged.)
//
// Hostinger account u825834874:
//   Database  = u825834874_talsora_
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
        private static ?PDO $shared = null;
        private PDO $dbh;

        /* =========================
           DATABASE (Hostinger)
        ========================= */
        public string $DB_HOST = '127.0.0.1';
        public string $DB_USER = 'u825834874_root';
        public string $DB_PASS = 'u825834874_Pass';
        public string $DB_NAME = 'u825834874_talsora_';
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
            if (self::$shared instanceof PDO) {
                $this->dbh = self::$shared;
                return;
            }

            $this->dbh = self::connectPdo(
                $this->DB_NAME,
                $this->DB_USER,
                $this->DB_PASS,
                $this->DB_PORT,
                $this->DB_HOST,
                true
            );
            self::$shared = $this->dbh;
        }

        /**
         * Hostinger shared hosting:
         * - unix socket via host=localhost can fail with SQLSTATE 2002
         *   "Operation not permitted"
         * - new connections are capped (~20/sec); reuse one PDO and persist it
         */
        public static function connectPdo(
            string $dbName,
            string $dbUser,
            string $dbPass,
            int $dbPort,
            string $preferredHost,
            bool $persistent
        ): PDO {
            $hosts = [];
            foreach (['127.0.0.1', $preferredHost, 'localhost'] as $host) {
                $host = trim($host);
                if ($host !== '' && !in_array($host, $hosts, true)) {
                    $hosts[] = $host;
                }
            }

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
                PDO::ATTR_PERSISTENT         => $persistent,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            ];

            $lastError = null;
            foreach ($hosts as $host) {
                $dsn = "mysql:host={$host};port={$dbPort};dbname={$dbName};charset=utf8mb4";
                try {
                    return new PDO($dsn, $dbUser, $dbPass, $options);
                } catch (PDOException $e) {
                    $lastError = $e;
                    $sqlState = (string)$e->getCode();
                    $msg = strtolower($e->getMessage());
                    // Wrong user/password/database will not succeed on another host.
                    if ($sqlState === '1045' || $sqlState === '1044' || str_contains($msg, 'access denied')) {
                        break;
                    }
                }
            }

            http_response_code(503);
            $detail = $lastError ? $lastError->getMessage() : 'unknown error';
            die('Database could not be connected: ' . $detail);
        }

        public function pdo(): PDO
        {
            return $this->dbh;
        }
    }
}
