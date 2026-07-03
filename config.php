<?php
// /Business_only3/config.php
declare(strict_types=1);

// Example: /Business_only3/admin/config.php
if (!defined('APP_SIGNING_KEY')) {
    define('APP_SIGNING_KEY', 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET_64+CHARS');
}

if (!class_exists('Config', false)) {
    class Config
    {
        private PDO $dbh;

        /* =========================
           DATABASE
        ========================= */
        public string $DB_HOST = "localhost";
        public string $DB_USER = "root";
        public string $DB_PASS = "root";
        public string $DB_NAME = "talentra";

        /* =========================
           SMTP (GMAIL - APP PASSWORD)
           ✅ Use App Password (16 chars, spaces OK)
           ✅ Port 587 + STARTTLS
        ========================= */
        public string $SMTP_HOST = "smtp.gmail.com";
        public int    $SMTP_PORT = 587;
        public string $SMTP_USER = "isaaccuma3093@gmail.com";
        public string $SMTP_PASS = "vjwu vqug zrty ucrz"; // Gmail App Password
        public string $SMTP_FROM = "isaaccuma3093@gmail.com";
        public string $SMTP_FROM_NAME = "Private App";

        /* =========================
           ALERT ROUTING
           If notireceiver = 'Admin', email goes to this address
        ========================= */
        public string $ADMIN_ALERT_EMAIL = "isaaccuma3093@gmail.com";

        public function __construct()
        {
            $socket = '/Applications/MAMP/tmp/mysql/mysql.sock';
            if ($socket !== '' && file_exists($socket)) {
                $dsn = "mysql:unix_socket={$socket};dbname={$this->DB_NAME};charset=utf8mb4";
            } else {
                $dsn = "mysql:host=127.0.0.1;port=8889;dbname={$this->DB_NAME};charset=utf8mb4";
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
                // In production you should log this instead of echoing it.
                die("Database could not be connected: " . $e->getMessage());
            }
        }

        public function pdo(): PDO
        {
            return $this->dbh;
        }
    }
}
