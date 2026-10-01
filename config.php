<?php
// /config.php — shared by admin, public_user, organization
// Local MAMP uses the socket/port below.
// On Hostinger the same file switches to TCP + a reused/persistent PDO
// (Hostinger rate-limits new MySQL connections).
declare(strict_types=1);

if (!defined('APP_SIGNING_KEY')) {
    define('APP_SIGNING_KEY', 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET_64+CHARS');
}

if (!class_exists('Config', false)) {
    class Config
    {
        private static ?PDO $shared = null;
        private PDO $dbh;
        private bool $isHostinger = false;

        /* =========================
           DATABASE (overwritten in constructor)
        ========================= */
        public string $DB_HOST = 'localhost';
        public string $DB_USER = 'root';
        public string $DB_PASS = 'root';
        public string $DB_NAME = 'talsora';
        public int    $DB_PORT = 8889;

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

            $this->isHostinger = self::detectHostinger();
            if ($this->isHostinger) {
                $this->DB_HOST = '127.0.0.1';
                $this->DB_USER = 'u825834874_root';
                $this->DB_PASS = 'u825834874_Pass';
                $this->DB_NAME = 'u825834874_talsora_';
                $this->DB_PORT = 3306;
                if (!defined('APP_CANONICAL_HOST')) {
                    define('APP_CANONICAL_HOST', 'talsora.com');
                }
            }

            $this->dbh = $this->isHostinger
                ? self::connectRemote()
                : self::connectLocalMamp();
            self::$shared = $this->dbh;
        }

        public static function detectHostinger(): bool
        {
            $flag = strtolower((string)(getenv('TALSORA_HOSTING') ?: getenv('TALORA_HOSTING') ?: ''));
            if ($flag === 'hostinger' || $flag === '1') {
                return true;
            }
            $root = str_replace('\\', '/', (string)__DIR__);
            if (str_contains($root, '/domains/') || str_contains($root, '/u825834874')) {
                return true;
            }
            if (!empty($_SERVER['H_PLATFORM']) || !empty($_SERVER['HOSTINGER'])) {
                return true;
            }
            $mampSocket = '/Applications/MAMP/tmp/mysql/mysql.sock';
            return !is_file($mampSocket) && PHP_OS_FAMILY !== 'Darwin';
        }

        private static function connectLocalMamp(): PDO
        {
            $dbName = 'talsora';
            $user = 'root';
            $pass = 'root';
            $port = 8889;
            $mampSocket = '/Applications/MAMP/tmp/mysql/mysql.sock';
            $dsn = is_file($mampSocket)
                ? "mysql:unix_socket={$mampSocket};dbname={$dbName};charset=utf8mb4"
                : "mysql:host=127.0.0.1;port={$port};dbname={$dbName};charset=utf8mb4";

            try {
                return new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                http_response_code(500);
                die('Database could not be connected: ' . $e->getMessage());
            }
        }

        private static function connectRemote(): PDO
        {
            $dbName = 'u825834874_talsora_';
            $user = 'u825834874_root';
            $pass = 'u825834874_Pass';
            $port = 3306;
            $hosts = [];
            foreach (['127.0.0.1', 'localhost'] as $host) {
                if (!in_array($host, $hosts, true)) {
                    $hosts[] = $host;
                }
            }

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
                PDO::ATTR_PERSISTENT         => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            ];

            $lastError = null;
            foreach ($hosts as $host) {
                $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
                try {
                    return new PDO($dsn, $user, $pass, $options);
                } catch (PDOException $e) {
                    $lastError = $e;
                    $msg = strtolower($e->getMessage());
                    if (str_contains($msg, 'access denied')) {
                        break;
                    }
                }
            }

            http_response_code(503);
            die('Database could not be connected: ' . ($lastError ? $lastError->getMessage() : 'unknown error'));
        }

        public function pdo(): PDO
        {
            return $this->dbh;
        }
    }
}
