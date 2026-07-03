<?php
/**
 * /Business_only3/admin/controller.php
 * Bulletproof Controller
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/request.php'; // clientIp(), clientUserAgent()

if (!class_exists('Controller', false)) {
class Controller
{
    private PDO $dbh;

    public function __construct()
    {
        $cfg = new Config();
        $this->dbh = $cfg->pdo();
    }

    public function pdo(): PDO
    {
        return $this->dbh;
    }

    /* =========================
       SECURITY AUDIT LOG
    ========================= */
    public function logSecurity(
        string $action,
        bool $success,
        ?string $email = null,
        ?string $username = null,
        ?int $adminId = null,
        array $meta = []
    ): void {
        try {
            $stmt = $this->dbh->prepare("
                INSERT INTO security_audit_log
                (email, username, admin_id, action, success, ip, user_agent, meta)
                VALUES (:email, :username, :admin_id, :action, :success, :ip, :ua, :meta)
            ");
            $stmt->execute([
                ':email'    => $email,
                ':username' => $username,
                ':admin_id' => $adminId,
                ':action'   => $action,
                ':success'  => $success ? 1 : 0,
                ':ip'       => clientIp(),
                ':ua'       => clientUserAgent(),
                ':meta'     => $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null,
            ]);
        } catch (Throwable $e) {
            // Don't block app if logging fails
        }
    }

    /* =========================
       PASSWORD HELPERS
    ========================= */
    private function hashMatches(string $plain, string $dbHash): bool
    {
        if ($dbHash === '') return false;

        if (password_get_info($dbHash)['algo'] !== 0) {
            return password_verify($plain, $dbHash);
        }
        if (hash('sha256', $plain) === $dbHash) return true;
        if (hash('sha384', $plain) === $dbHash) return true;
        if (md5($plain) === $dbHash) return true;
        return false;
    }

    private function upgradeAdminPasswordIfNeeded(
        int $adminId,
        string $plain,
        string $dbHash
    ): void {
        if ($adminId <= 0) return;
        if (password_get_info($dbHash)['algo'] !== 0) return;

        $newHash = password_hash($plain, PASSWORD_DEFAULT);

        $stmt = $this->dbh->prepare(
            "UPDATE admin SET password = :p WHERE idadmin = :id LIMIT 1"
        );
        $stmt->execute([
            'p'  => $newHash,
            'id' => $adminId
        ]);
    }

    private function upgradeUserPasswordIfNeeded(
        int $userId,
        string $plain,
        string $dbHash
    ): void {
        if ($userId <= 0) return;
        if (password_get_info($dbHash)['algo'] !== 0) return;

        $newHash = password_hash($plain, PASSWORD_DEFAULT);

        $stmt = $this->dbh->prepare(
            "UPDATE users SET password = :p WHERE id = :id LIMIT 1"
        );
        $stmt->execute([
            'p'  => $newHash,
            'id' => $userId
        ]);
    }

    private function mailerFilePath(): string
    {
        return __DIR__ . '/includes/mailer.php';
    }

    private function appBaseUrl(): string
    {
        $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $https = (
            (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
            strtolower($forwardedProto) === 'https'
        );

        $scheme = $https ? 'https' : 'http';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

        if ($host === '') {
            $serverName = trim((string)($_SERVER['SERVER_NAME'] ?? 'localhost'));
            $serverPort = trim((string)($_SERVER['SERVER_PORT'] ?? ''));
            $isDefaultPort = $serverPort === '' || $serverPort === '80' || $serverPort === '443';
            $host = $isDefaultPort ? $serverName : ($serverName . ':' . $serverPort);
        }

        $scriptDir = dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptDir === DIRECTORY_SEPARATOR || $scriptDir === '.' || $scriptDir === '\\') {
            $scriptDir = '';
        }
        $scriptDir = rtrim(str_replace('\\', '/', $scriptDir), '/');

        return $scheme . '://' . $host . $scriptDir;
    }

    public function sendPasswordResetEmail(
        string $accountType,
        string $email,
        string $username,
        string $token
    ): bool {
        $mailerFile = $this->mailerFilePath();
        if (!file_exists($mailerFile)) {
            return false;
        }

        require_once $mailerFile;
        if (!function_exists('sendNotificationEmail')) {
            return false;
        }

        $resetUrl = $this->appBaseUrl() . '/reset.php?token=' . rawurlencode($token);
        $title = $accountType === 'admin' ? 'Admin Password Reset' : 'Password Reset';
        $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
        $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');

        $html = "
            <h3>{$title}</h3>
            <p>Hello {$safeUser},</p>
            <p>We received a request to reset your password. This link expires in 30 minutes.</p>
            <p><a href=\"{$safeUrl}\">Reset your password</a></p>
            <p>If the button does not work, copy and paste this link into your browser:</p>
            <p>{$safeUrl}</p>
            <p>If you did not request this change, you can ignore this email.</p>
        ";

        return sendNotificationEmail($email, $title, $html);
    }



    /* =========================
       ADMIN LOGIN
       (supports username/email)
    ========================= */
public function adminLogin(string $username, string $password): ?array
{
    $username = trim($username);
    if ($username === '' || $password === '') return null;

    $sql = "
        SELECT idadmin, username, email, password, role, status, image, friend_code
        FROM admin
        WHERE username = :username OR email = :email
        LIMIT 1
    ";
    $stmt = $this->dbh->prepare($sql);
    $stmt->execute([
        ':username' => $username,
        ':email' => $username,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if ((int)$row['status'] !== 1) return null;

    $dbHash = (string)$row['password'];
    if (!$this->hashMatches($password, $dbHash)) return null;

    $this->upgradeAdminPasswordIfNeeded((int)$row['idadmin'], $password, $dbHash);

    return [
        'idadmin'     => (int)$row['idadmin'],
        'username'    => (string)$row['username'],
        'email'       => (string)$row['email'],
        'role'        => (int)$row['role'],
        'image'       => (string)($row['image'] ?? 'default.jpg'),
        'friend_code' => (string)($row['friend_code'] ?? ''),
    ];
}



    /* =========================
       USER LOGIN (supports username/email)
    ========================= */
    public function userLoginAttempt(string $username, string $password): array
    {
        $login = trim($username);
        $lowerLogin = mb_strtolower($login);
        $upperLogin = strtoupper($login);
        $digitLogin = preg_replace('/\D+/', '', $login) ?? '';

        $stmt = $this->dbh->prepare("
            SELECT id, name, username, email, password, image, role, status, friend_code,
                   COALESCE(account_kind, 'personal') AS account_kind
            FROM users
            WHERE username = :exact_user
               OR email = :exact_email
               OR LOWER(username) = :lower_user
               OR LOWER(email) = :lower_email
               OR UPPER(friend_code) = :upper
               OR LOWER(name) = :lower_name
               OR (:digits <> '' AND REPLACE(REPLACE(REPLACE(REPLACE(mobile, ' ', ''), '-', ''), '(', ''), ')', '') = :digits_match)
            LIMIT 1
        ");
        $stmt->execute([
            ':exact_user' => $login,
            ':exact_email' => $login,
            ':lower_user' => $lowerLogin,
            ':lower_email' => $lowerLogin,
            ':upper' => $upperLogin,
            ':lower_name' => $lowerLogin,
            ':digits' => $digitLogin,
            ':digits_match' => $digitLogin,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            require_once __DIR__ . '/includes/deleted_user_registry.php';
            if (user_login_identifier_was_deleted($this->dbh, $username)) {
                $this->logSecurity('user_login_failed', false, null, $username, null, ['reason' => 'deleted']);
                return ['ok' => false, 'reason' => 'deleted'];
            }
            $this->logSecurity('user_login_failed', false, null, $username, null, ['reason' => 'not_found']);
            return ['ok' => false, 'reason' => 'not_found'];
        }

        if ((int)$row['status'] !== 1) {
            $this->logSecurity('user_login_failed', false, (string)$row['email'], (string)$row['username'], null, ['reason' => 'deactivated']);
            return ['ok' => false, 'reason' => 'deactivated'];
        }

        $dbHash = (string)$row['password'];
        if (!$this->hashMatches($password, $dbHash)) {
            $this->logSecurity('user_login_failed', false, (string)$row['email'], (string)$row['username'], null, ['reason' => 'bad_password']);
            return ['ok' => false, 'reason' => 'invalid'];
        }

        $this->upgradeUserPasswordIfNeeded((int)$row['id'], $password, $dbHash);

        $this->logSecurity('user_login', true, (string)$row['email'], (string)$row['username']);

        return [
            'ok' => true,
            'user' => [
                'id'          => (int)$row['id'],
                'name'        => (string)$row['name'],
                'username'    => (string)$row['username'],
                'email'       => (string)$row['email'],
                'role'        => (int)($row['role'] ?? 4),
                'status'      => (int)$row['status'],
                'image'       => (string)($row['image'] ?? 'default.jpg'),
                'friend_code' => (string)($row['friend_code'] ?? ''),
                'account_kind' => (string)($row['account_kind'] ?? 'personal'),
            ],
        ];
    }

    public function userLogin(string $username, string $password): ?array
    {
        $result = $this->userLoginAttempt($username, $password);
        return !empty($result['ok']) ? $result['user'] : null;
    }

    /* =========================
       PASSWORD RESET TOKENS
    ========================= */

    private function makeResetToken(): array
    {
        $token = bin2hex(random_bytes(32)); // raw token
        $hash  = hash('sha256', $token);    // store hash only
        return [$token, $hash];
    }

    private function storeResetToken(
        string $accountType,
        string $username,
        ?int $userId,
        ?int $adminId,
        string $tokenHash,
        int $minutes = 30
    ): void {
        // invalidate old unused tokens
        $del = $this->dbh->prepare("
            UPDATE password_reset_tokens
            SET used_at = NOW()
            WHERE account_type = :t AND username = :u AND used_at IS NULL
        ");
        $del->execute([':t' => $accountType, ':u' => $username]);

        $expiresAt = date('Y-m-d H:i:s', time() + ($minutes * 60));

        $stmt = $this->dbh->prepare("
            INSERT INTO password_reset_tokens
            (account_type, user_id, admin_id, username, token_hash, expires_at, ip, user_agent)
            VALUES (:t, :uid, :aid, :u, :h, :expires_at, :ip, :ua)
        ");
        $stmt->execute([
            ':t'    => $accountType,
            ':uid'  => $userId,
            ':aid'  => $adminId,
            ':u'    => $username,
            ':h'    => $tokenHash,
            ':expires_at' => $expiresAt,
            ':ip'   => clientIp(),
            ':ua'   => clientUserAgent(),
        ]);
    }

    public function createUserReset(string $username): array
    {
        $st = $this->dbh->prepare("
            SELECT id, username, email, status
            FROM users
            WHERE username = :username OR email = :email
            LIMIT 1
        ");
        $st->execute([
            ':username' => $username,
            ':email' => $username,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        // Always return ok=true to avoid account enumeration
        if (!$row || (int)$row['status'] !== 1) {
            $this->logSecurity('forgot_password_user', false, null, $username, null, ['reason' => 'not_found_or_inactive']);
            return ['ok' => true];
        }

        [$token, $hash] = $this->makeResetToken();
        $this->storeResetToken('user', (string)$row['username'], (int)$row['id'], null, $hash, 30);

        $this->logSecurity('forgot_password_user', true, (string)$row['email'], (string)$row['username']);

        return [
            'ok' => true,
            'email' => (string)$row['email'],
            'username' => (string)$row['username'],
            'token' => $token,
        ];
    }

    public function createAdminReset(string $username): array
    {
        $st = $this->dbh->prepare("
            SELECT idadmin, username, email, status
            FROM admin
            WHERE username = :username OR email = :email
            LIMIT 1
        ");
        $st->execute([
            ':username' => $username,
            ':email' => $username,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row || (int)$row['status'] !== 1) {
            $this->logSecurity('forgot_password_admin', false, null, $username, null, ['reason' => 'not_found_or_inactive']);
            return ['ok' => true];
        }

        [$token, $hash] = $this->makeResetToken();
        $this->storeResetToken('admin', (string)$row['username'], null, (int)$row['idadmin'], $hash, 30);

        $this->logSecurity('forgot_password_admin', true, (string)$row['email'], (string)$row['username'], (int)$row['idadmin']);

        return [
            'ok' => true,
            'email' => (string)$row['email'],
            'username' => (string)$row['username'],
            'token' => $token,
        ];
    }

    public function resetPasswordWithToken(string $accountType, string $token, string $newPassword): array
    {
        $tokenHash = hash('sha256', $token);

        $st = $this->dbh->prepare("
            SELECT *
            FROM password_reset_tokens
            WHERE account_type = :t
              AND token_hash = :h
              AND used_at IS NULL
              AND expires_at > NOW()
            LIMIT 1
        ");
        $st->execute([':t' => $accountType, ':h' => $tokenHash]);
        $tok = $st->fetch(PDO::FETCH_ASSOC);

        if (!$tok) {
            $this->logSecurity('reset_password', false, null, null, null, ['type'=>$accountType, 'reason'=>'invalid_or_expired']);
            return ['ok'=>false, 'error'=>'Invalid or expired reset link.'];
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        if ($accountType === 'user') {
            $uid = (int)($tok['user_id'] ?? 0);
            if ($uid <= 0) return ['ok'=>false, 'error'=>'Reset token invalid (no user).'];

            $up = $this->dbh->prepare("UPDATE users SET password = :p WHERE id = :id LIMIT 1");
            $up->execute([':p'=>$hash, ':id'=>$uid]);

            $this->logSecurity('reset_password_user', true, null, (string)$tok['username']);
        } else {
            $aid = (int)($tok['admin_id'] ?? 0);
            if ($aid <= 0) return ['ok'=>false, 'error'=>'Reset token invalid (no admin).'];

            $up = $this->dbh->prepare("
                UPDATE admin
                SET password = :p, force_password_change = 0
                WHERE idadmin = :id
                LIMIT 1
            ");
            $up->execute([':p'=>$hash, ':id'=>$aid]);

            $this->logSecurity('reset_password_admin', true, null, (string)$tok['username'], $aid);
        }

        // mark token used
        $mk = $this->dbh->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id LIMIT 1");
        $mk->execute([':id' => (int)$tok['id']]);

        return ['ok'=>true];
    }

    /* =========================
       ADMIN RESET USER PASSWORD
       (Admin-only: sets temp pass, emails user)
    ========================= */
    public function adminResetUserPassword(int $adminId, string $targetUsername): array
    {
        // Find user
        $st = $this->dbh->prepare("SELECT id, username, email, status FROM users WHERE username = :u LIMIT 1");
        $st->execute([':u' => $targetUsername]);
        $u = $st->fetch(PDO::FETCH_ASSOC);

        if (!$u) return ['ok'=>false, 'error'=>'User not found.'];
        if ((int)$u['status'] !== 1) return ['ok'=>false, 'error'=>'User is inactive.'];

        // temp password
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*?';
        $temp = '';
        for ($i=0; $i<12; $i++) $temp .= $chars[random_int(0, strlen($chars)-1)];

        $hash = password_hash($temp, PASSWORD_DEFAULT);

        $up = $this->dbh->prepare("UPDATE users SET password = :p WHERE id = :id LIMIT 1");
        $up->execute([':p'=>$hash, ':id'=>(int)$u['id']]);

        $this->logSecurity('admin_reset_user_password', true, (string)$u['email'], (string)$u['username'], $adminId);

        // email user
        $mailerFile = $this->mailerFilePath();
        if (file_exists($mailerFile)) {
            require_once $mailerFile;
            if (function_exists('sendNotificationEmail')) {
                $subject = "Your password has been reset";
                $html = "
                  <h3>Password Reset</h3>
                  <p>An administrator reset your password.</p>
                  <p><b>Username:</b> ".htmlspecialchars((string)$u['username'])."</p>
                  <p><b>Temporary Password:</b> ".htmlspecialchars($temp)."</p>
                  <p>Please login and change it immediately.</p>
                ";
                sendNotificationEmail((string)$u['email'], $subject, $html);
            }
        }

        return ['ok'=>true, 'temp_password'=>$temp];
    }

public function addNotification(string $notiuser, string $notireceiver, string $notitype): bool
{
    // If your table doesn't have is_read, remove it from query + values
    $stmt = $this->dbh->prepare("
        INSERT INTO notification (notiuser, notireceiver, notitype, is_read)
        VALUES (:u, :r, :t, 0)
    ");

    $ok = $stmt->execute([
        'u' => $notiuser,
        'r' => $notireceiver,
        't' => $notitype,
    ]);

    if (!$ok) return false;

    // Optional email alert (works if includes/mailer.php exists)
    $mailerFile = $this->mailerFilePath();
    if (file_exists($mailerFile)) {
        require_once $mailerFile;

        // Send to receiver if it's an email, otherwise to Admin email
        $to = '';
        if (filter_var($notireceiver, FILTER_VALIDATE_EMAIL)) {
            $to = $notireceiver;
        } elseif ($notireceiver === 'Admin') {
            $cfg = new Config();
            $to = $cfg->ADMIN_ALERT_EMAIL;
        }

        if ($to !== '' && function_exists('sendNotificationEmail')) {
            $subject = "New Notification";
            $html = "
                <h3>New Notification</h3>
                <p><b>From:</b> " . htmlspecialchars($notiuser) . "</p>
                <p><b>Type:</b> " . htmlspecialchars($notitype) . "</p>
                <p>Please login to view it.</p>
            ";
            sendNotificationEmail($to, $subject, $html);
        }
    }

    return true;
}


    /**
     * Create internal account + invite email + force password change
     */
    public function createInternalAccountWithInvite(array $data): array
    {
        // required: fullname, username, email, role, status
        $fullname = trim($data['fullname'] ?? '');
        $username = trim($data['username'] ?? '');
        $email    = trim($data['email'] ?? '');
        $role     = (int)($data['role'] ?? 0);
        $status   = (int)($data['status'] ?? 1);

        if ($fullname === '' || $username === '' || $email === '' || $role <= 0) {
            return ['ok'=>false, 'error'=>'Missing required fields'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok'=>false, 'error'=>'Invalid email'];
        }

        // prevent duplicates
        $st = $this->dbh->prepare("SELECT 1 FROM admin WHERE email=:e OR username=:u LIMIT 1");
        $st->execute([':e'=>$email, ':u'=>$username]);
        if ($st->fetchColumn()) {
            return ['ok'=>false, 'error'=>'Email or username already exists'];
        }

        // friend code generator: ADM-XXXX-XXXX
        $makeCode = function(string $prefix='ADM'): string {
            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $part = function() use ($chars){
                $s=''; for($i=0;$i<4;$i++) $s.=$chars[random_int(0, strlen($chars)-1)];
                return $s;
            };
            return strtoupper($prefix.'-'.$part().'-'.$part());
        };

        $genUniqueCode = function() use ($makeCode): string {
            for ($i=0; $i<120; $i++) {
                $code = $makeCode('ADM');
                $q = $this->dbh->prepare("SELECT 1 FROM admin WHERE friend_code=:c LIMIT 1");
                $q->execute([':c'=>$code]);
                if (!$q->fetchColumn()) return $code;
            }
            throw new RuntimeException("Unable to generate unique friend code");
        };

        // temp password
        $makeTempPassword = function(int $len=12): string {
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*?';
            $out=''; for($i=0;$i<$len;$i++) $out.=$chars[random_int(0, strlen($chars)-1)];
            return $out;
        };

        $friendCode = $genUniqueCode();
        $tempPass   = $makeTempPassword(12);
        $hash       = password_hash($tempPass, PASSWORD_DEFAULT);

        // ✅ CODE DEFAULTS (Option A)
        $gender      = 'N/A';
        $mobile      = 'N/A';
        $designation = 'Internal';

        $image = 'default.jpg';

        try {
            $this->dbh->beginTransaction();

            // ✅ matches your table: gender/mobile/designation are NOT NULL
            $ins = $this->dbh->prepare("
                INSERT INTO admin
                (fullname, username, friend_code, email, password,
                gender, mobile, designation,
                role, image, status, force_password_change,
                failed_login_attempts, locked_until, last_login_at)
                VALUES
                (:fullname, :username, :friend_code, :email, :password,
                :gender, :mobile, :designation,
                :role, :image, :status, 1,
                0, NULL, NULL)
            ");

            $ins->execute([
                ':fullname'    => $fullname,
                ':username'    => $username,
                ':friend_code' => $friendCode,
                ':email'       => $email,
                ':password'    => $hash,
                ':gender'      => $gender,
                ':mobile'      => $mobile,
                ':designation' => $designation,
                ':role'        => $role,
                ':image'       => $image,
                ':status'      => $status
            ]);

            // notify admin panel (optional)
            $this->addNotification($email, 'Admin', 'Create Internal Account');

            // send invite email
            $mailerFile = $this->mailerFilePath();
            if (file_exists($mailerFile)) {
                require_once $mailerFile;

                $subject = "You're invited: Internal Account Created";
                $html = "
                <h3>Welcome!</h3>
                <p>An internal account has been created for you.</p>
                <p><b>Login URL:</b> http://localhost:8888/Business_only3/admin/</p>
                <p><b>Username:</b> ".htmlspecialchars($username)."</p>
                <p><b>Email:</b> ".htmlspecialchars($email)."</p>
                <p><b>Friend Code:</b> ".htmlspecialchars($friendCode)."</p>
                <p><b>Temporary Password:</b> ".htmlspecialchars($tempPass)."</p>
                <p><b>Important:</b> You will be forced to change password after login.</p>
                ";

                if (function_exists('sendNotificationEmail')) {
                    sendNotificationEmail($email, $subject, $html);
                }
            }

            $this->dbh->commit();

            return [
                'ok'=>true,
                'friend_code'=>$friendCode,
                'temp_password'=>$tempPass
            ];

        } catch (Throwable $e) {
            if ($this->dbh->inTransaction()) $this->dbh->rollBack();
            return ['ok'=>false, 'error'=>$e->getMessage()];
        }
    }
}
}
