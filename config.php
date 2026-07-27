<?php
// /config.php — shared by admin, public_user, organization
// Local MAMP only (Hostinger credentials will be set later)
declare(strict_types=1);

if (!defined('APP_SIGNING_KEY')) {
    define('APP_SIGNING_KEY', 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET_64+CHARS');
}

if (!class_exists('Config', false)) {
    class Config
    {
        private PDO $dbh;

        /* =========================
           DATABASE (local MAMP)
        ========================= */
        public string $DB_HOST = 'localhost';
        public string $DB_USER = 'root';
        public string $DB_PASS = 'root';
        public string $DB_NAME = 'talentra';
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
            $mampSocket = '/Applications/MAMP/tmp/mysql/mysql.sock';

            if (file_exists($mampSocket)) {
                $dsn = "mysql:unix_socket={$mampSocket};dbname={$this->DB_NAME};charset=utf8mb4";
            } else {
                $dsn = "mysql:host=127.0.0.1;port={$this->DB_PORT};dbname={$this->DB_NAME};charset=utf8mb4";
            }

            try {
                $this->dbh = new PDO(
                    $dsn,
                    $this->DB_USER,
                    $this->DB_PASS,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]
                );
            } catch (PDOException $e) {
                http_response_code(500);
                die('Database could not be connected: ' . $e->getMessage());
            }
        }

        public function pdo(): PDO
        {
            return $this->dbh;
        }
    }
}
