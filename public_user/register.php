<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session_user.php';
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/controller.php';
require_once __DIR__ . '/includes/publisher_accounts.php';
require_once __DIR__ . '/includes/publisher_organization_bridge.php';
require_once __DIR__ . '/includes/publisher_authority.php';
require_once __DIR__ . '/includes/user_phone.php';
require_once __DIR__ . '/includes/org_commerce_brands.php';

$error = '';
$msg   = '';

function register_signup_track(string $accountType, string $publisherMode): string
{
    if ($accountType === 'publisher') {
        return $publisherMode === 'commerce' ? 'commerce' : 'publisher';
    }
    return 'personal';
}

function register_signup_track_from_request(): string
{
    $accountType = strtolower(trim((string)($_GET['account_type'] ?? 'personal')));
    if (!in_array($accountType, ['personal', 'publisher'], true)) {
        $accountType = 'personal';
    }
    $publisherMode = strtolower(trim((string)($_GET['publisher_mode'] ?? 'media')));
    if (!in_array($publisherMode, ['media', 'commerce'], true)) {
        $publisherMode = 'media';
    }
    return register_signup_track($accountType, $publisherMode);
}

function register_signup_query_string(string $track): string
{
    if ($track === 'publisher') {
        return '?account_type=publisher';
    }
    if ($track === 'commerce') {
        return '?account_type=publisher&publisher_mode=commerce';
    }
    return '';
}

$signupTrack = register_signup_track_from_request();
$defaultAccountType = ($signupTrack === 'personal') ? 'personal' : 'publisher';
$defaultPublisherMode = ($signupTrack === 'commerce') ? 'commerce' : 'media';

$controller = new Controller();
$dbh = $controller->pdo();
publisher_ensure_schema($dbh);
publisher_authority_ensure_schema($dbh);
org_commerce_brands_ensure_schema($dbh);
$categories = publisher_categories();
$commerceBrands = org_commerce_brands_list_active($dbh);
$publisherNameOptions = publisher_registry_list_options($dbh);
$selectedPublisherName = publisher_registry_normalize_name((string)($_POST['name'] ?? ''));
$selectedCustomPublisherName = '';
if ($selectedPublisherName !== '') {
    $selectedIsCatalogPublisher = false;
    foreach ($publisherNameOptions as $opt) {
        if (strcasecmp($selectedPublisherName, (string)($opt['name'] ?? '')) === 0) {
            $selectedIsCatalogPublisher = true;
            break;
        }
    }
    if (!$selectedIsCatalogPublisher) {
        $selectedCustomPublisherName = $selectedPublisherName;
    }
}
$postedBirthMonth = trim((string)($_POST['birth_month'] ?? ''));
$postedBirthDay = trim((string)($_POST['birth_day'] ?? ''));
$postedBirthYear = trim((string)($_POST['birth_year'] ?? ''));
$postedMobile = trim((string)($_POST['mobile'] ?? $_POST['mobileno'] ?? ''));
$postedPolicyAgreement = strtolower(trim((string)($_POST['policy_agreement'] ?? '')));
$postedAgeConfirm = isset($_POST['age_confirm']) && (string)$_POST['age_confirm'] === '1';
$postedPublisherMode = strtolower(trim((string)($_POST['publisher_mode'] ?? $defaultPublisherMode)));
$postedCommerceBrandId = (int)($_POST['commerce_brand_id'] ?? 0);
$postedCommerceBrandName = publisher_registry_normalize_name((string)($_POST['commerce_brand_name'] ?? ''));
$selectedCustomCommerceBrandName = '';
if ($postedCommerceBrandId <= 0 && $postedCommerceBrandName !== '') {
    $selectedCustomCommerceBrandName = $postedCommerceBrandName;
}
if (!in_array($postedPublisherMode, ['media', 'commerce'], true)) {
    $postedPublisherMode = 'media';
}
register_ensure_user_birthday_columns($dbh);
register_ensure_user_consent_columns($dbh);

function register_policy_sections(): array
{
    return [
        'Eligibility' => 'Personal accounts are for users who are at least ' . register_minimum_personal_age() . ' years old. You must provide accurate information when creating an account.',
        'Acceptable use' => 'Do not post illegal, abusive, harassing, fraudulent, or harmful content. Do not impersonate others or misuse the service.',
        'Your account' => 'You are responsible for activity on your account and for keeping your login credentials secure.',
        'Privacy' => 'We use the information you provide to operate Talentra, including your profile details and birthday for eligibility and account safety.',
        'Enforcement' => 'We may suspend or remove accounts that violate these terms or provide false information, including false age declarations.',
    ];
}

function register_birthday_month_options(): array
{
    return [
        '1' => 'January',
        '2' => 'February',
        '3' => 'March',
        '4' => 'April',
        '5' => 'May',
        '6' => 'June',
        '7' => 'July',
        '8' => 'August',
        '9' => 'September',
        '10' => 'October',
        '11' => 'November',
        '12' => 'December',
    ];
}

function register_birthday_year_options(): array
{
    $currentYear = (int)date('Y');
    $latestBirthYear = $currentYear - register_minimum_personal_age();
    $years = [];
    for ($year = $latestBirthYear; $year >= $currentYear - 100; $year--) {
        $years[] = $year;
    }
    return $years;
}

function register_minimum_personal_age(): int
{
    return 21;
}

function register_birthday_meets_minimum_age(string $birthdayIso, int $minimumAge = 0): bool
{
    if ($birthdayIso === '') {
        return false;
    }
    if ($minimumAge <= 0) {
        $minimumAge = register_minimum_personal_age();
    }

    try {
        $birthday = new DateTimeImmutable($birthdayIso);
        $today = new DateTimeImmutable('today');
        $latestAllowedBirthday = $today->sub(new DateInterval('P' . $minimumAge . 'Y'));
        return $birthday <= $latestAllowedBirthday;
    } catch (Throwable $e) {
        return false;
    }
}

function register_birthday_from_parts(string $month, string $day, string $year): string
{
    $month = trim($month);
    $day = trim($day);
    $year = trim($year);
    if ($month === '' || $day === '' || $year === '') {
        return '';
    }
    $m = (int)$month;
    $d = (int)$day;
    $y = (int)$year;
    if ($m < 1 || $m > 12 || $d < 1 || $d > 31 || $y < 1900 || $y > (int)date('Y')) {
        return '';
    }
    if (!checkdate($m, $d, $y)) {
        return '';
    }

    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

function register_users_has_publisher_columns(PDO $dbh): bool
{
    return publisher_db_column_exists($dbh, 'users', 'account_kind')
        && publisher_db_column_exists($dbh, 'users', 'publisher_category')
        && publisher_db_column_exists($dbh, 'users', 'publisher_tagline');
}

function register_ensure_base_roles(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $st = $dbh->prepare('SELECT idrole FROM role WHERE idrole = 4 AND status = 1 LIMIT 1');
        $st->execute();
        if ((int)($st->fetchColumn() ?: 0) === 4) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    $baseRoles = [
        [1, 'Admin', null],
        [2, 'Manager', null],
        [3, 'Gospel', null],
        [4, 'Staff', null],
    ];

    try {
        $insert = $dbh->prepare('
            INSERT IGNORE INTO role (idrole, name, inherits_from, status)
            VALUES (:id, :name, :inherits, 1)
        ');
        foreach ($baseRoles as [$id, $name, $inherits]) {
            $insert->execute([
                ':id' => $id,
                ':name' => $name,
                ':inherits' => $inherits,
            ]);
        }
    } catch (Throwable $e) {
        // registration will surface FK errors if seeding fails
    }
}

function register_public_user_role_id(PDO $dbh): int
{
    register_ensure_base_roles($dbh);

    try {
        $st = $dbh->prepare('SELECT idrole FROM role WHERE idrole = 4 AND status = 1 LIMIT 1');
        $st->execute();
        $staffRoleId = (int)($st->fetchColumn() ?: 0);
        if ($staffRoleId > 0) {
            return $staffRoleId;
        }

        $fallback = (int)($dbh->query('SELECT idrole FROM role WHERE status = 1 ORDER BY idrole ASC LIMIT 1')->fetchColumn() ?: 0);
        if ($fallback > 0) {
            return $fallback;
        }
    } catch (Throwable $e) {
        // fall through
    }

    return 4;
}

function register_users_has_age_column(PDO $dbh): bool
{
    return publisher_db_column_exists($dbh, 'users', 'age');
}

function register_users_has_birthday_column(PDO $dbh): bool
{
    return publisher_db_column_exists($dbh, 'users', 'birthday');
}

function register_users_has_policy_agreed_column(PDO $dbh): bool
{
    return publisher_db_column_exists($dbh, 'users', 'policy_agreed');
}

function register_users_has_policy_agreed_at_column(PDO $dbh): bool
{
    return publisher_db_column_exists($dbh, 'users', 'policy_agreed_at');
}

function register_users_has_age_confirmed_column(PDO $dbh): bool
{
    return publisher_db_column_exists($dbh, 'users', 'age_confirmed');
}

function register_users_has_age_confirmed_at_column(PDO $dbh): bool
{
    return publisher_db_column_exists($dbh, 'users', 'age_confirmed_at');
}

function register_ensure_user_consent_columns(PDO $dbh): void
{
    if (!register_users_has_policy_agreed_column($dbh)) {
        try {
            $dbh->exec('ALTER TABLE users ADD COLUMN policy_agreed TINYINT(1) NOT NULL DEFAULT 0');
        } catch (Throwable $e) {
            // Non-fatal.
        }
    }
    if (!register_users_has_policy_agreed_at_column($dbh)) {
        try {
            $dbh->exec('ALTER TABLE users ADD COLUMN policy_agreed_at DATETIME NULL DEFAULT NULL');
        } catch (Throwable $e) {
            // Non-fatal.
        }
    }
    if (!register_users_has_age_confirmed_column($dbh)) {
        try {
            $dbh->exec('ALTER TABLE users ADD COLUMN age_confirmed TINYINT(1) NOT NULL DEFAULT 0');
        } catch (Throwable $e) {
            // Non-fatal.
        }
    }
    if (!register_users_has_age_confirmed_at_column($dbh)) {
        try {
            $dbh->exec('ALTER TABLE users ADD COLUMN age_confirmed_at DATETIME NULL DEFAULT NULL');
        } catch (Throwable $e) {
            // Non-fatal.
        }
    }
}

/** Persist terms/policy agreement and age confirmation after insert. */
function register_persist_user_consent(PDO $dbh, int $userId, bool $policyAgreed, bool $ageConfirmed): void
{
    if ($userId <= 0) {
        return;
    }

    register_ensure_user_consent_columns($dbh);

    $now = date('Y-m-d H:i:s');

    if (register_users_has_policy_agreed_column($dbh)) {
        try {
            $st = $dbh->prepare('UPDATE users SET policy_agreed = :policy_agreed WHERE id = :id LIMIT 1');
            $st->execute([
                ':policy_agreed' => $policyAgreed ? 1 : 0,
                ':id' => $userId,
            ]);
        } catch (Throwable $e) {
            // Try again below with timestamp column if needed.
        }
    }

    if (register_users_has_policy_agreed_at_column($dbh) && $policyAgreed) {
        try {
            $st = $dbh->prepare('UPDATE users SET policy_agreed_at = :policy_agreed_at WHERE id = :id LIMIT 1');
            $st->execute([
                ':policy_agreed_at' => $now,
                ':id' => $userId,
            ]);
        } catch (Throwable $e) {
            // Non-fatal.
        }
    }

    if (register_users_has_age_confirmed_column($dbh)) {
        try {
            $st = $dbh->prepare('UPDATE users SET age_confirmed = :age_confirmed WHERE id = :id LIMIT 1');
            $st->execute([
                ':age_confirmed' => $ageConfirmed ? 1 : 0,
                ':id' => $userId,
            ]);
        } catch (Throwable $e) {
            // Non-fatal.
        }
    }

    if (register_users_has_age_confirmed_at_column($dbh) && $ageConfirmed) {
        try {
            $st = $dbh->prepare('UPDATE users SET age_confirmed_at = :age_confirmed_at WHERE id = :id LIMIT 1');
            $st->execute([
                ':age_confirmed_at' => $now,
                ':id' => $userId,
            ]);
        } catch (Throwable $e) {
            // Non-fatal.
        }
    }
}

function register_ensure_user_birthday_columns(PDO $dbh): void
{
    if (!register_users_has_birthday_column($dbh)) {
        try {
            $dbh->exec('ALTER TABLE users ADD COLUMN birthday DATE NULL DEFAULT NULL');
        } catch (Throwable $e) {
            // Non-fatal — fallback UPDATE may still use age column.
        }
    }
    if (!register_users_has_age_column($dbh)) {
        try {
            $dbh->exec("ALTER TABLE users ADD COLUMN age VARCHAR(255) NOT NULL DEFAULT ''");
        } catch (Throwable $e) {
            // Non-fatal.
        }
    }
}

/** Persist birthday after insert (covers missing column at INSERT time). */
function register_persist_user_birthday(PDO $dbh, int $userId, string $birthdayIso): void
{
    if ($userId <= 0 || $birthdayIso === '') {
        return;
    }

    register_ensure_user_birthday_columns($dbh);

    if (register_users_has_birthday_column($dbh)) {
        try {
            $st = $dbh->prepare('UPDATE users SET birthday = :birthday WHERE id = :id LIMIT 1');
            $st->execute([
                ':birthday' => $birthdayIso,
                ':id' => $userId,
            ]);
        } catch (Throwable $e) {
            // Try age column below.
        }
    }

    if (register_users_has_age_column($dbh)) {
        try {
            $st = $dbh->prepare('UPDATE users SET age = :age WHERE id = :id LIMIT 1');
            $st->execute([
                ':age' => $birthdayIso,
                ':id' => $userId,
            ]);
        } catch (Throwable $e) {
            // Non-fatal.
        }
    }
}

/** Persist mobile after insert (covers missing column at INSERT time). */
function register_persist_user_mobile(PDO $dbh, int $userId, string $mobile): void
{
    if ($userId <= 0 || $mobile === '' || strcasecmp($mobile, 'N/A') === 0 || !user_phone_is_valid($mobile)) {
        return;
    }

    if (!publisher_db_column_exists($dbh, 'users', 'mobile')) {
        return;
    }

    try {
        $st = $dbh->prepare('UPDATE users SET mobile = :mobile WHERE id = :id LIMIT 1');
        $st->execute([
            ':mobile' => user_phone_normalize($mobile),
            ':id' => $userId,
        ]);
    } catch (Throwable $e) {
        // Non-fatal.
    }
}

function register_ensure_age_column(PDO $dbh): void
{
    register_ensure_user_birthday_columns($dbh);
}

function register_insert_user_row(
    PDO $dbh,
    string $name,
    string $username,
    string $friendCode,
    string $email,
    string $passwordHash,
    string $gender,
    string $mobileno,
    string $age,
    string $designation,
    bool $isPublisher,
    string $publisherCategory,
    string $publisherTagline,
    string $image
): int {
    publisher_ensure_schema($dbh);
    register_ensure_user_birthday_columns($dbh);
    register_ensure_base_roles($dbh);

    $birthdayValue = $isPublisher ? '' : mb_substr(trim($age), 0, 255);
    $publicRoleId = register_public_user_role_id($dbh);

    if (register_users_has_publisher_columns($dbh)) {
        $sql = "INSERT INTO users
            (name, username, friend_code, email, password, gender, mobile, designation, role, account_kind, publisher_category, publisher_tagline, image, status, created_at)
            VALUES
            (:name, :username, :friend_code, :email, :password, :gender, :mobile, :designation, :role, :account_kind, :publisher_category, :publisher_tagline, :image, 1, NOW())";
        $st = $dbh->prepare($sql);
        $st->execute([
            ':name' => $name,
            ':username' => $username,
            ':friend_code' => $friendCode,
            ':email' => $email,
            ':password' => $passwordHash,
            ':gender' => $gender,
            ':mobile' => $mobileno,
            ':designation' => $designation,
            ':role' => $publicRoleId,
            ':account_kind' => $isPublisher ? 'publisher' : 'personal',
            ':publisher_category' => $isPublisher ? $publisherCategory : '',
            ':publisher_tagline' => $isPublisher ? mb_substr($publisherTagline, 0, 250) : '',
            ':image' => $image,
        ]);
    } else {
        $sql = "INSERT INTO users
            (name, username, friend_code, email, password, gender, mobile, designation, role, image, status, created_at)
            VALUES
            (:name, :username, :friend_code, :email, :password, :gender, :mobile, :designation, :role, :image, 1, NOW())";
        $st = $dbh->prepare($sql);
        $st->execute([
            ':name' => $name,
            ':username' => $username,
            ':friend_code' => $friendCode,
            ':email' => $email,
            ':password' => $passwordHash,
            ':gender' => $gender,
            ':mobile' => $mobileno,
            ':designation' => $designation,
            ':role' => $publicRoleId,
            ':image' => $image,
        ]);
    }

    $newUserId = (int)$dbh->lastInsertId();
    if ($newUserId > 0 && !$isPublisher && $birthdayValue !== '') {
        register_persist_user_birthday($dbh, $newUserId, $birthdayValue);
    }
    if ($newUserId > 0 && $isPublisher) {
        publisher_repair_user_as_publisher($dbh, $newUserId, $publisherCategory);
    }

    return $newUserId;
}

function register_notify_admin(PDO $dbh, string $email, bool $isPublisher): void
{
    try {
        $noti = $dbh->prepare("
            INSERT INTO notification (notiuser, notireceiver, notitype, is_read)
            VALUES (:u, 'Admin', :type, 0)
        ");
        $noti->execute([
            ':u' => $email,
            ':type' => $isPublisher ? 'Create Publisher Account' : 'Create Account',
        ]);
    } catch (Throwable $e) {
        // Registration should succeed even if notification row fails.
    }
}

function register_is_local_dev(): bool
{
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    return $host === 'localhost'
        || str_starts_with($host, 'localhost:')
        || $host === '127.0.0.1'
        || str_starts_with($host, '127.0.0.1:');
}

function makeFriendCode(string $prefix = 'USR'): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $part = static function () use ($chars): string {
        $s = '';
        for ($i = 0; $i < 4; $i++) {
            $s .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $s;
    };
    return strtoupper($prefix . '-' . $part() . '-' . $part());
}

function generateUniqueFriendCode(PDO $dbh, string $prefix = 'USR', int $maxTries = 60): string
{
    for ($i = 0; $i < $maxTries; $i++) {
        $code = makeFriendCode($prefix);
        $st = $dbh->prepare('SELECT 1 FROM users WHERE friend_code = :c LIMIT 1');
        $st->execute([':c' => $code]);
        if (!$st->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('Unable to generate unique friend code. Try again.');
}

if (isset($_POST['submit'])) {
    $lockedTrack = register_signup_track_from_request();
    $accountType = strtolower(trim((string)($_POST['account_type'] ?? 'personal')));
    if (!in_array($accountType, ['personal', 'publisher'], true)) {
        $accountType = 'personal';
    }
    $publisherMode = strtolower(trim((string)($_POST['publisher_mode'] ?? 'media')));
    if (!in_array($publisherMode, ['media', 'commerce'], true)) {
        $publisherMode = 'media';
    }
    if ($lockedTrack === 'personal') {
        $accountType = 'personal';
        $publisherMode = 'media';
    } elseif ($lockedTrack === 'publisher') {
        $accountType = 'publisher';
        $publisherMode = 'media';
    } else {
        $accountType = 'publisher';
        $publisherMode = 'commerce';
    }
    $isPublisher = ($accountType === 'publisher');

    $name = trim((string)($_POST['name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $passwordRaw = (string)($_POST['password'] ?? '');
    $gender = trim((string)($_POST['gender'] ?? ''));
    $mobileno = trim((string)($_POST['mobile'] ?? $_POST['mobileno'] ?? ''));
    $birthMonth = trim((string)($_POST['birth_month'] ?? ''));
    $birthDay = trim((string)($_POST['birth_day'] ?? ''));
    $birthYear = trim((string)($_POST['birth_year'] ?? ''));
    $age = register_birthday_from_parts($birthMonth, $birthDay, $birthYear);
    $postedBirthMonth = $birthMonth;
    $postedBirthDay = $birthDay;
    $postedBirthYear = $birthYear;
    $postedMobile = $mobileno;
    $publisherCategory = strtolower(trim((string)($_POST['publisher_category'] ?? 'news')));
    $publisherTagline = trim((string)($_POST['publisher_tagline'] ?? ''));
    $commerceBrandId = (int)($_POST['commerce_brand_id'] ?? 0);
    $postedCommerceBrandName = publisher_registry_normalize_name((string)($_POST['commerce_brand_name'] ?? ''));
    if ($publisherMode === 'commerce' && $commerceBrandId <= 0 && $postedCommerceBrandName !== '' && $email !== '') {
        $brandMeta = publisher_authority_commerce_brand_name_request_meta($dbh, $postedCommerceBrandName, $email);
        if ((int)($brandMeta['brand_id'] ?? 0) > 0) {
            $commerceBrandId = (int)$brandMeta['brand_id'];
        }
    }
    if ($publisherMode === 'commerce') {
        $publisherCategory = 'commerce';
        $commerceBrandId = org_commerce_brands_resolve_for_registration($dbh, $commerceBrandId, $name);
        if ($commerceBrandId > 0) {
            $brandRow = org_commerce_brands_get($dbh, $commerceBrandId);
            if ($brandRow) {
                $brandName = trim((string)($brandRow['name'] ?? ''));
                if ($brandName !== '') {
                    $name = $brandName;
                    if (publisher_registry_name_is_registered($dbh, $name)) {
                        $name = $brandName . ' — ' . $username;
                    }
                }
            }
        }
    }
    $policyAgreement = strtolower(trim((string)($_POST['policy_agreement'] ?? '')));
    $ageConfirmed = isset($_POST['age_confirm']) && (string)$_POST['age_confirm'] === '1';
    $postedPolicyAgreement = $policyAgreement;
    $postedAgeConfirm = $ageConfirmed;

    if ($policyAgreement !== 'agree') {
        $error = 'You must agree to the Terms and Policy to create an account.';
    } elseif ($username === '' || $email === '' || $passwordRaw === '') {
        $error = 'Please fill all required fields.';
    } elseif (!$isPublisher && $name === '') {
        $error = 'Please fill all required fields.';
    } elseif (!$isPublisher && ($gender === '' || $mobileno === '' || $birthMonth === '' || $birthDay === '' || $birthYear === '')) {
        $error = 'Please fill all required fields.';
    } elseif (!$isPublisher && !user_phone_is_valid($mobileno)) {
        $error = 'Please enter a valid phone number (digits only, 7–15 numbers).';
    } elseif (!$isPublisher && $age === '') {
        $error = 'Please enter a valid birthday.';
    } elseif (!$isPublisher && !register_birthday_meets_minimum_age($age)) {
        $error = 'You must be at least ' . register_minimum_personal_age() . ' years old to create a personal account.';
    } elseif (!$isPublisher && !$ageConfirmed) {
        $error = 'Please confirm you are at least ' . register_minimum_personal_age() . ' years old.';
    } elseif ($isPublisher && $publisherMode === 'commerce' && $commerceBrandId <= 0) {
        if ($postedCommerceBrandName !== '' && publisher_authority_commerce_brand_name_request_status($dbh, $postedCommerceBrandName, $email) === 'pending') {
            $error = 'Your commerce brand request is waiting for admin approval. Stay on this page until it is approved.';
        } elseif ($postedCommerceBrandName !== '') {
            $error = 'Submit your commerce brand request with Add name and wait for admin approval before creating your account.';
        } else {
            $error = 'Please choose a commerce brand system or click Add name to request a new one.';
        }
    } elseif ($isPublisher && $publisherMode === 'commerce' && !org_commerce_brands_get($dbh, $commerceBrandId)) {
        $error = 'Please choose a valid commerce brand system.';
    } elseif ($isPublisher && $publisherMode === 'commerce'
        && !publisher_authority_commerce_is_approved($dbh, $commerceBrandId, $email)
        && !($postedCommerceBrandName !== '' && publisher_authority_commerce_brand_name_is_approved($dbh, $postedCommerceBrandName, $email))) {
        $reqStatus = publisher_authority_commerce_request_status($dbh, $commerceBrandId, $email);
        if ($reqStatus === 'pending') {
            $error = 'Your commerce seller request is waiting for admin approval. Submit the request below and check back once approved.';
        } elseif ($reqStatus === 'rejected') {
            $error = 'Your commerce seller request was rejected. Submit a new request or contact support.';
        } else {
            $error = 'Submit a commerce seller request and wait for admin approval before creating your account.';
        }
    } elseif ($isPublisher && $publisherMode === 'commerce' && $name === '') {
        $error = 'Could not resolve a display name from the selected commerce brand.';
    } elseif ($isPublisher && $publisherMode !== 'commerce' && $name === '') {
        $error = 'Please choose a publisher name.';
    } elseif ($isPublisher && publisher_registry_name_is_registered($dbh, $name)) {
        $error = 'That publisher name is already registered. Choose another or sign in.';
    } elseif ($isPublisher && $publisherMode !== 'commerce' && !publisher_authority_is_approved($dbh, $name)) {
        $reqStatus = publisher_authority_request_status($dbh, $name);
        if ($reqStatus === 'pending') {
            $error = 'This publisher name is waiting for admin approval. You can sign up once it is approved.';
        } elseif ($reqStatus === 'rejected') {
            $error = 'This publisher name request was rejected. Choose another name or submit a new request.';
        } else {
            $error = 'Submit a publisher name request and wait for admin approval before signing up.';
        }
    } elseif ($isPublisher && !isset($categories[$publisherCategory])) {
        $error = 'Please choose a publisher category.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        try {
            $check = $dbh->prepare('SELECT 1 FROM users WHERE email = :e OR username = :u LIMIT 1');
            $check->execute([':e' => $email, ':u' => $username]);
            if ($check->fetchColumn()) {
                $error = 'Email or Username already exists. Please login.';
            } elseif ($isPublisher && publisher_org_manager_username_taken($dbh, $username, $email)) {
                $error = 'Email or Username already exists in organization accounts. Please login or choose another.';
            } else {
                $friendCode = $isPublisher
                    ? publisher_make_friend_code($dbh)
                    : generateUniqueFriendCode($dbh, 'USR');
                $password = password_hash($passwordRaw, PASSWORD_DEFAULT);
                $designation = $isPublisher
                    ? ($publisherTagline !== '' ? $publisherTagline : ('Official ' . $name . ' on Talentra'))
                    : '';
                $image = 'default.jpg';

                if ($isPublisher) {
                    $gender = 'N/A';
                    $mobileno = 'N/A';
                    $age = '';
                } else {
                    $mobileno = user_phone_normalize($mobileno);
                }

                $dbh->beginTransaction();

                $newUserId = register_insert_user_row(
                    $dbh,
                    $name,
                    $username,
                    $friendCode,
                    $email,
                    $password,
                    $gender,
                    $mobileno,
                    $age,
                    $designation,
                    $isPublisher,
                    $publisherCategory,
                    $publisherTagline,
                    $image
                );

                if ($newUserId <= 0) {
                    throw new RuntimeException('User account was not created.');
                }

                register_notify_admin($dbh, $email, $isPublisher);

                if (!$isPublisher && $age !== '') {
                    register_persist_user_birthday($dbh, $newUserId, $age);
                }

                if (!$isPublisher && $mobileno !== '') {
                    register_persist_user_mobile($dbh, $newUserId, $mobileno);
                }

                register_persist_user_consent(
                    $dbh,
                    $newUserId,
                    $policyAgreement === 'agree',
                    !$isPublisher && $ageConfirmed
                );

                $dbh->commit();

                if ($isPublisher) {
                    publisher_repair_user_as_publisher($dbh, $newUserId, $publisherCategory);
                    publisher_registry_mark_registered($dbh, $name, $newUserId);

                    $orgId = publisher_org_link_publisher_user($dbh, $name, $newUserId, $publisherCategory, $commerceBrandId);
                    if ($orgId <= 0) {
                        publisher_org_sync_public_user_orgs($dbh, $newUserId);
                        $orgId = (int)(publisher_org_fetch_public_user_orgs($dbh, $newUserId)[0]['id'] ?? 0);
                    }
                    if ($orgId > 0 && $commerceBrandId > 0) {
                        org_commerce_brands_assign_org($dbh, $orgId, $commerceBrandId);
                    } elseif ($orgId > 0 && $publisherMode !== 'commerce') {
                        $matchedBrandId = org_commerce_brands_resolve_for_registration($dbh, 0, $name);
                        if ($matchedBrandId > 0) {
                            org_commerce_brands_assign_org($dbh, $orgId, $matchedBrandId);
                            $commerceBrandId = $matchedBrandId;
                            $publisherCategory = 'commerce';
                            publisher_repair_user_as_publisher($dbh, $newUserId, 'commerce');
                        }
                    }
                    if ($orgId <= 0) {
                        error_log('publisher_org_link_publisher_user failed for user ' . $newUserId);
                    }

                    if ($publisherMode === 'commerce' && $commerceBrandId > 0) {
                        $brandRow = org_commerce_brands_get($dbh, $commerceBrandId);
                        if ($brandRow) {
                            $system = org_commerce_brands_parse_system($brandRow);
                            $hint = trim((string)($system['order_hint'] ?? $brandRow['tagline'] ?? ''));
                            if ($publisherTagline === '' && $hint !== '') {
                                $publisherTagline = $hint;
                            }
                        }
                    }

                    setUserSession([
                        'id' => $newUserId,
                        'name' => $name,
                        'username' => $username,
                        'email' => $email,
                        'role' => register_public_user_role_id($dbh),
                        'status' => 1,
                        'image' => $image,
                        'friend_code' => $friendCode,
                        'account_kind' => 'publisher',
                    ]);
                    header('Location: feed.php');
                    exit;
                }

                echo "<script>alert('Registration Successful! Your Friend Code is: " . addslashes($friendCode) . "');</script>";
                echo "<script>window.location.href='index.php';</script>";
                exit;
            }
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            $error = 'Unable to complete registration right now. Please try again.';
            if (register_is_local_dev()) {
                $error .= ' (' . $e->getMessage() . ')';
            }
        }
    }

    $postedPublisherMode = $publisherMode;
}
?>
<?php
$isPublisherReg = ($signupTrack !== 'personal');
$isPublisherCommerce = ($signupTrack === 'commerce');
$registerBodyClasses = 'bg-gray-900 register-signup signup-track-' . $signupTrack;
if ($isPublisherReg) {
    $registerBodyClasses .= ' is-publisher-reg';
}
if ($isPublisherCommerce) {
    $registerBodyClasses .= ' is-publisher-commerce';
}
$registerHtmlClasses = 'register-signup';
if ($isPublisherReg) {
    $registerHtmlClasses .= ' is-publisher-reg';
}
?>
<!DOCTYPE html>
<html lang="en" class="<?= htmlspecialchars($registerHtmlClasses, ENT_QUOTES, 'UTF-8') ?>">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Create account — Talentra</title>
    <link href="./lib/font-awesome/css/font-awesome.css" rel="stylesheet">
    <link href="./lib/Ionicons/css/ionicons.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/shamcey.css">
    <link rel="stylesheet" href="assets/ui_best.css">
    <script src="assets/ui_best.js" defer></script>
    <script src="./lib/jquery/jquery.js"></script>
    <script src="./lib/popper.js/popper.js"></script>
    <script src="./lib/bootstrap/bootstrap.js"></script>
    <script src="./js/shamcey.js"></script>
    <style>
      .acct-type-row{display:flex;gap:10px;margin-bottom:14px}
      .acct-type-row label{flex:1;border:1px solid #ddd;border-radius:10px;padding:12px;cursor:pointer;text-align:center;font-weight:700}
      .acct-type-row input{position:absolute;opacity:0;pointer-events:none}
      .acct-type-row input:checked + span{display:block}
      .acct-type-row label:has(input:checked){border-color:#2563eb;background:#eff6ff}
      .signup-track-topic-card{
        flex:1;
        border:1px solid #2563eb;
        border-radius:10px;
        padding:12px;
        text-align:center;
        font-weight:700;
        background:#eff6ff;
      }
      body.signup-track-personal .account-type-publisher{display:none!important}
      body.signup-track-publisher .account-type-personal{display:none!important}
      body.signup-track-commerce .account-type-personal,
      body.signup-track-commerce .account-type-publisher{display:none!important}
      body.signup-track-personal .publisher-mode-row{display:none!important}
      body.signup-track-publisher .publisher-mode-commerce{display:none!important}
      body.signup-track-commerce .publisher-mode-row{display:none!important}
      .publisher-only{display:none}
      body.is-publisher-reg .publisher-only{display:block}
      body.is-publisher-reg .personal-only{display:none}
      body.is-publisher-commerce .publisher-media-only{display:none}
      body.is-publisher-commerce .publisher-commerce-only{display:block}
      body.is-publisher-reg:not(.is-publisher-commerce) .publisher-commerce-only{display:none}
      body.is-publisher-reg:not(.is-publisher-commerce) .publisher-media-only{display:block}
      .publisher-name-wrap{display:none}
      body.is-publisher-reg .publisher-name-wrap{display:block}
      body.is-publisher-commerce .publisher-name-wrap{display:none !important}
      body.is-publisher-reg .personal-name-wrap{display:none}
      .publisher-add-modal .modal-content{border-radius:14px;overflow:hidden}
      .publisher-add-modal .modal-header{border-bottom:1px solid #eee}
      .publisher-add-modal .modal-footer{border-top:1px solid #eee}
      .publisher-add-modal .modal-dialog{max-width:560px}
      .publisher-authority-box{margin-top:14px;padding-top:14px;border-top:1px solid #e5e7eb}
      .publisher-authority-title{font-size:13px;font-weight:800;color:#111827;margin-bottom:8px}
      .publisher-authority-note{font-size:12px;color:#64748b;line-height:1.45;margin-bottom:10px}
      .publisher-authority-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
      .publisher-authority-grid .form-group{margin-bottom:0}
      .publisher-authority-grid .form-group.full{grid-column:1/-1}
      .publisher-authority-confirm{display:flex;align-items:flex-start;gap:8px;margin-top:12px;font-size:12px;line-height:1.4;font-weight:600;color:#111827}
      .publisher-authority-confirm input{margin-top:2px}
      @media (max-width: 575px){.publisher-authority-grid{grid-template-columns:1fr}}
      .publisher-add-error{color:#b91c1c;font-size:13px;margin-top:8px;display:none}
      .publisher-add-error.is-visible{display:block}
      .publisher-custom-chosen{display:none;margin-top:10px;padding:10px 12px;border:1px solid #bfdbfe;border-radius:10px;background:#eff6ff}
      .publisher-custom-chosen.is-visible{display:block}
      .publisher-custom-chosen.is-waiting{
        border-color:#93c5fd;
        background:#eff6ff;
        box-shadow:0 0 0 1px rgba(37,99,235,.08);
      }
      .publisher-custom-chosen.is-approved-state{
        border-color:#86efac;
        background:#f0fdf4;
      }
      .publisher-custom-chosen-label{display:block;font-size:12px;color:#475569;margin-bottom:4px}
      .publisher-custom-chosen-name{font-size:15px;font-weight:800;color:#0f172a}
      .publisher-custom-chosen-change{margin-top:6px;padding:0;border:0;background:transparent;color:#2563eb;font-size:12px;font-weight:700;cursor:pointer}
      .publisher-custom-chosen-change:hover{text-decoration:underline}
      .publisher-custom-status{margin-top:8px;font-size:12px;font-weight:700;line-height:1.4}
      .publisher-custom-status.is-pending{color:#b45309}
      .publisher-custom-status.is-approved{color:#15803d}
      .publisher-custom-status.is-rejected{color:#b91c1c}
      .publisher-custom-status.is-checking{color:#2563eb}
      .publisher-custom-status-actions{margin-top:6px}
      .publisher-check-status-btn{
        padding:0;
        border:0;
        background:transparent;
        color:#2563eb;
        font-size:12px;
        font-weight:800;
        cursor:pointer;
      }
      .publisher-check-status-btn:hover{text-decoration:underline}
      .publisher-wait-note{
        margin-top:6px;
        padding:8px 10px;
        border-radius:10px;
        border:1px solid #fde68a;
        background:#fffbeb;
        color:#92400e;
        font-size:12px;
        line-height:1.4;
        font-weight:600;
      }
      body.is-publisher-reg .register-submit-row .btn-success.is-ready-flash{
        box-shadow:0 0 0 3px rgba(34,197,94,.25);
      }
      html.register-signup,
      body.register-signup{
        height:100%;
        overflow:hidden;
      }
      body.register-signup .signpanel-wrapper{
        min-height:100vh;
        max-height:100vh;
        height:100vh;
        padding:0;
        overflow:hidden;
        box-sizing:border-box;
      }
      body.register-signup .signbox.signup{
        max-height:100vh;
        overflow:hidden;
        display:flex;
        flex-direction:column;
      }
      body.register-signup .signbox-body{
        padding:16px 22px 14px;
        flex:1 1 auto;
        min-height:0;
        overflow-y:auto;
        -webkit-overflow-scrolling:touch;
      }
      body.register-signup .register-submit-row{
        position:sticky;
        bottom:0;
        z-index:3;
        margin-top:8px;
        padding-top:8px;
        background:linear-gradient(to top,#fff 78%,rgba(255,255,255,0));
      }
      body.register-signup .register-submit-row .register-footer-link{
        margin-top:8px !important;
        padding:4px 8px !important;
      }
      body.register-signup .form-group{
        margin-bottom:9px;
      }
      body.register-signup .acct-type-row{
        margin-bottom:9px;
      }
      body.register-signup .acct-type-row label{
        padding:8px 10px;
      }
      body.register-signup .acct-type-row .tx-12{
        margin-top:2px !important;
        font-size:11px;
      }
      body.register-signup .form-control-label{
        margin-bottom:3px;
        font-size:13px;
      }
      body.register-signup .form-control{
        min-height:34px;
        height:34px;
        padding:4px 10px;
        font-size:14px;
      }
      body.register-signup select.form-control{
        padding-top:4px;
        padding-bottom:4px;
      }
      body.register-signup .register-birthday-row{
        display:flex;
        gap:8px;
        align-items:stretch;
      }
      body.register-signup .register-birthday-row select.form-control{
        flex:1 1 0;
        min-width:0;
        border-radius:999px;
        border:1px solid #d1d5db;
        background:#fff;
        color:#374151;
        font-size:14px;
        padding-right:28px;
        appearance:none;
        -webkit-appearance:none;
        background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M3 4.5 6 7.5 9 4.5'/%3E%3C/svg%3E");
        background-repeat:no-repeat;
        background-position:right 12px center;
        background-size:12px 12px;
      }
      body.register-signup .register-birthday-row select.form-control:invalid,
      body.register-signup .register-birthday-row select.form-control option[value='']{
        color:#9ca3af;
      }
      body.register-signup .register-birthday-label{
        font-weight:700;
        color:#111827;
      }
      body.register-signup .register-policy-box{
        max-height:88px;
        overflow:auto;
        padding:10px 12px;
        border:1px solid #d1d5db;
        border-radius:10px;
        background:#fff;
        font-size:12px;
        line-height:1.45;
        color:#374151;
      }
      body.register-signup .register-policy-box h6{
        margin:0 0 4px;
        font-size:12px;
        font-weight:800;
        color:#111827;
      }
      body.register-signup .register-policy-box p{
        margin:0 0 8px;
      }
      body.register-signup .register-policy-box p:last-child{
        margin-bottom:0;
      }
      body.register-signup .register-policy-choice{
        display:flex;
        gap:10px;
        margin-top:8px;
      }
      body.register-signup .register-policy-option{
        flex:1;
        display:flex;
        align-items:center;
        justify-content:center;
        gap:8px;
        margin:0;
        padding:8px 10px;
        border:1px solid #d1d5db;
        border-radius:999px;
        background:#fff;
        font-size:13px;
        font-weight:700;
        cursor:pointer;
      }
      body.register-signup .register-policy-option:has(input:checked){
        border-color:#2563eb;
        background:#eff6ff;
        color:#1d4ed8;
      }
      body.register-signup .register-policy-option input{
        margin:0;
      }
      body.register-signup .register-policy-note{
        color:#b91c1c;
      }
      body.register-signup .register-age-confirm{
        display:flex;
        align-items:flex-start;
        gap:8px;
        margin:0;
        font-size:13px;
        line-height:1.35;
        font-weight:600;
        color:#111827;
        cursor:pointer;
      }
      body.register-signup .register-age-confirm input{
        margin-top:2px;
      }
      body.register-signup .btn-success:disabled{
        opacity:.55;
        cursor:not-allowed;
      }
      body.register-signup .btn-success{
        margin-top:2px;
        padding:8px 12px;
      }
      body.register-signup .register-footer-link{
        margin-top:10px !important;
        padding:6px 8px !important;
        font-size:13px;
      }
      body.register-signup .errorWrap,
      body.register-signup .succWrap{
        margin:0;
        padding:8px 12px;
        font-size:12px;
        line-height:1.3;
      }
      body.is-publisher-reg .publisher-name-wrap .tx-12,
      body.is-publisher-reg .publisher-only .tx-12{
        font-size:11px;
        line-height:1.25;
        margin-top:4px !important;
      }
      body.is-publisher-reg .publisher-only .publisher-commerce-note{display:none}
      body.is-publisher-commerce .publisher-media-note{display:none}
      body.is-publisher-commerce .publisher-only .publisher-commerce-note{display:block}
      .commerce-seller-authority{margin-top:14px;padding-top:14px;border-top:1px solid #e5e7eb}
      .commerce-submit-request-btn{margin-top:12px;border-radius:999px;font-weight:800}
      body.is-publisher-reg .publisher-custom-chosen{
        margin-top:6px;
        padding:7px 10px;
      }
      body.is-publisher-reg .publisher-custom-chosen-name{
        font-size:14px;
      }
      body.is-publisher-reg .register-policy-box{
        max-height:64px;
      }
      body.is-publisher-reg .publisher-name-actions{
        display:flex;
        gap:8px;
        align-items:stretch;
      }
      body.is-publisher-reg .publisher-name-actions select{
        flex:1 1 auto;
        min-width:0;
      }
      body.is-publisher-reg .publisher-add-name-btn{
        flex:0 0 auto;
        white-space:nowrap;
        padding:0 12px;
        font-size:12px;
        font-weight:800;
        border-radius:999px;
      }
      body.is-publisher-commerce .commerce-brand-actions{
        display:flex;
        gap:8px;
        align-items:stretch;
      }
      body.is-publisher-commerce .commerce-brand-actions select{
        flex:1 1 auto;
        min-width:0;
      }
      body.is-publisher-commerce .commerce-add-name-btn{
        flex:0 0 auto;
        white-space:nowrap;
        padding:0 12px;
        font-size:12px;
        font-weight:800;
        border-radius:999px;
      }
      body.is-publisher-commerce .commerce-brand-wrap.commerce-custom-active .commerce-brand-actions,
      body.is-publisher-commerce .commerce-brand-wrap.commerce-custom-active .commerce-brand-catalog-note,
      body.is-publisher-commerce .commerce-brand-wrap.commerce-custom-active .commerce-catalog-status{
        display:none;
      }
      body.is-publisher-commerce .commerce-brand-wrap:not(.commerce-custom-active) #commerceCustomChosen{
        display:none !important;
      }
      @media (max-height:760px){
        body.register-signup .signbox-body{padding:12px 18px 10px}
        body.register-signup .form-group{margin-bottom:7px}
        body.register-signup .acct-type-row label{padding:6px 8px}
        body.register-signup .register-footer-link{margin-top:6px !important}
      }
    </style>
  </head>
  <body class="<?= htmlspecialchars($registerBodyClasses, ENT_QUOTES, 'UTF-8') ?>" data-signup-track="<?= htmlspecialchars($signupTrack, ENT_QUOTES, 'UTF-8') ?>">
    <div class="signpanel-wrapper">
      <div class="signbox signup">
          <?php if ($error): ?>
          <div class="errorWrap"><strong>ERROR</strong>: <?php echo htmlentities($error); ?></div>
          <?php elseif ($msg): ?>
          <div class="succWrap"><strong>SUCCESS</strong>: <?php echo htmlentities($msg); ?></div>
          <?php endif; ?>
        <div class="signbox-body">
          <form method="post" autocomplete="off" id="registerForm" action="register.php<?= htmlspecialchars(register_signup_query_string($signupTrack), ENT_QUOTES, 'UTF-8') ?>">
              <?php echo csrfInput(); ?>
              <?php if ($signupTrack === 'commerce'): ?>
              <input type="hidden" name="account_type" value="publisher">
              <input type="hidden" name="publisher_mode" value="commerce">
              <?php endif; ?>
              <div class="form-group account-type-group">
                <label class="form-control-label d-block">Account type</label>
                <div class="acct-type-row">
                  <?php if ($signupTrack === 'commerce'): ?>
                  <div class="signup-track-topic-card account-type-commerce-topic"><span>Commerce</span><div class="tx-12 mg-t-5">Brand stores &amp; seller accounts</div></div>
                  <?php else: ?>
                  <label class="account-type-personal"><input type="radio" name="account_type" value="personal"<?= $defaultAccountType === 'personal' ? ' checked' : '' ?>><span>Personal User</span><div class="tx-12 mg-t-5">Friends &amp; family</div></label>
                  <label class="account-type-publisher"><input type="radio" name="account_type" value="publisher"<?= $defaultAccountType === 'publisher' ? ' checked' : '' ?>><span>News Media Organization</span></label>
                  <?php endif; ?>
                </div>
              </div>
              <div class="form-group personal-name-wrap">
                <!-- <label class="form-control-label">Full Name</label> -->
                <input name="name" type="text" class="form-control personal-name personal-req" placeholder="Your Full Name"<?= $signupTrack === 'personal' ? ' required' : ' disabled' ?> autocomplete="name">
              </div>
              <div class="form-group publisher-name-wrap">
                <!-- <label class="form-control-label">Publisher name</label> -->
                <div class="publisher-name-actions">
                  <select id="publisherNameSelect" class="form-control publisher-name publisher-req">
                    <option value="">Select publisher name</option>
                    <?php foreach ($publisherNameOptions as $opt): ?>
                      <?php
                        $optName = (string)($opt['name'] ?? '');
                        $optCategory = (string)($opt['category'] ?? 'news');
                        $selected = ($selectedPublisherName !== '' && strcasecmp($selectedPublisherName, $optName) === 0) ? ' selected' : '';
                      ?>
                      <option value="<?= htmlspecialchars($optName, ENT_QUOTES, 'UTF-8') ?>" data-category="<?= htmlspecialchars($optCategory, ENT_QUOTES, 'UTF-8') ?>"<?= $selected ?>><?= htmlspecialchars($optName, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                    <option value="__add_new__">+ Add publisher name…</option>
                  </select>
                  <button type="button" class="btn btn-primary btn-sm publisher-add-name-btn" id="publisherAddNameBtn">Add name</button>
                </div>
                <div id="publisherCustomChosen" class="publisher-custom-chosen<?= $selectedCustomPublisherName !== '' ? ' is-visible' : '' ?>" aria-live="polite">
                  <span class="publisher-custom-chosen-label">Your publisher name</span>
                  <div id="publisherCustomChosenName" class="publisher-custom-chosen-name"><?= htmlspecialchars($selectedCustomPublisherName, ENT_QUOTES, 'UTF-8') ?></div>
                  <div id="publisherCustomStatus" class="publisher-custom-status" hidden></div>
                  <div id="publisherCustomStatusActions" class="publisher-custom-status-actions" hidden>
                    <button type="button" class="publisher-check-status-btn" id="publisherCheckStatusBtn">Check approval now</button>
                  </div>
                  <div id="publisherWaitNote" class="publisher-wait-note" hidden>
                    Stay on this page while you wait. We check for admin approval automatically every few seconds and will enable <strong>Create account</strong> when your request is approved.
                  </div>
                  <button type="button" id="publisherCustomChosenChange" class="publisher-custom-chosen-change">Choose from list instead</button>
                </div>
                <input type="hidden" id="publisherNameHidden" class="publisher-name-hidden" value="<?= htmlspecialchars($selectedPublisherName, ENT_QUOTES, 'UTF-8') ?>" disabled>
                <div class="tx-12 mg-t-5 publisher-media-note">Choose a name from the list or click <strong>Add name</strong>. Both open the same request form and require admin approval before you can create your account.</div>
              </div>
              <div class="row row-xs">
                <div class="col-sm">
                  <div class="form-group">
                    <!-- <label class="form-control-label">Username</label> -->
                    <input type="text" name="username" class="form-control" placeholder="Username" required>
                  </div>
                </div>
                <div class="col-sm">
                  <div class="form-group">
                    <!-- <label class="form-control-label">Email</label> -->
                    <input name="email" type="email" class="form-control" placeholder="Email" required>
                  </div>
                </div>
              </div>
              <div class="row row-xs personal-only">
                <div class="col-sm">
                  <div class="form-group">
                    <!-- <label class="form-control-label">Gender</label> -->
                    <select name="gender" class="form-control personal-req">
                      <option value="">Select</option>
                      <option value="Male">Male</option>
                      <option value="Female">Female</option>
                    </select>
                  </div>
                </div>
                <div class="col-sm">
                  <div class="form-group">
                    <!-- <label class="form-control-label">Phone Number</label> -->
                    <input name="mobile" type="tel" class="form-control personal-req" placeholder="Phone number" autocomplete="tel" inputmode="tel" pattern="[0-9+\-\s()]{7,20}" value="<?= htmlspecialchars($postedMobile, ENT_QUOTES, 'UTF-8') ?>" required>
                  </div>
                </div>
              </div>
              <div class="form-group">
                <!-- <label class="form-control-label">Password</label> -->
                <input type="password" name="password" class="form-control" placeholder="Password" autocomplete="new-password" required>
              </div>
              <div class="form-group personal-only">
                <label class="form-control-label register-birthday-label">Birthday</label>
                <div class="register-birthday-row">
                  <select name="birth_month" class="form-control personal-req personal-birth-part" aria-label="Birth month" required>
                    <option value=""<?= $postedBirthMonth === '' ? ' selected' : '' ?>>Month</option>
                    <?php foreach (register_birthday_month_options() as $monthValue => $monthLabel): ?>
                      <option value="<?= (int)$monthValue ?>"<?= $postedBirthMonth !== '' && (int)$postedBirthMonth === (int)$monthValue ? ' selected' : '' ?>><?= htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                  <select name="birth_day" class="form-control personal-req personal-birth-part" aria-label="Birth day" required>
                    <option value=""<?= $postedBirthDay === '' ? ' selected' : '' ?>>Day</option>
                    <?php for ($day = 1; $day <= 31; $day++): ?>
                      <option value="<?= $day ?>"<?= $postedBirthDay !== '' && (int)$postedBirthDay === $day ? ' selected' : '' ?>><?= $day ?></option>
                    <?php endfor; ?>
                  </select>
                  <select name="birth_year" class="form-control personal-req personal-birth-part" aria-label="Birth year" required>
                    <option value=""<?= $postedBirthYear === '' ? ' selected' : '' ?>>Year</option>
                    <?php foreach (register_birthday_year_options() as $year): ?>
                      <option value="<?= $year ?>"<?= $postedBirthYear !== '' && (int)$postedBirthYear === $year ? ' selected' : '' ?>><?= $year ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="tx-12 mg-t-5">Personal accounts require you to be at least <?= register_minimum_personal_age() ?> years old.</div>
              </div>
              <div class="form-group personal-only">
                <label class="register-age-confirm">
                  <input type="checkbox" name="age_confirm" value="1" class="personal-req register-age-confirm-input"<?= $postedAgeConfirm ? ' checked' : '' ?>>
                  <span>I confirm I am at least <?= register_minimum_personal_age() ?> years old and that my birthday is accurate.</span>
                </label>
              </div>
              <?php if ($signupTrack !== 'commerce'): ?>
              <div class="form-group publisher-only">
                <label class="form-control-label d-block">Publisher type</label>
                <div class="acct-type-row publisher-mode-row">
                  <label class="publisher-mode-media"><input type="radio" name="publisher_mode" value="media"<?= $postedPublisherMode === 'media' ? ' checked' : '' ?>><div class="tx-12 mg-t-5">CNN, Fox, ABC…</div></label>
                  <label class="publisher-mode-commerce"><input type="radio" name="publisher_mode" value="commerce"<?= $postedPublisherMode === 'commerce' ? ' checked' : '' ?>><span>Commerce</span><div class="tx-12 mg-t-5">McDonald's, Wendy's…</div></label>
                </div>
              </div>
              <?php endif; ?>
              <div class="form-group publisher-only publisher-commerce-only commerce-brand-wrap<?= $selectedCustomCommerceBrandName !== '' ? ' commerce-custom-active' : '' ?>" id="commerceBrandWrap">
                <label class="form-control-label" for="commerceBrandSelect">Commerce brand system</label>
                <div class="commerce-brand-actions">
                  <select name="commerce_brand_id" id="commerceBrandSelect" class="form-control">
                    <option value="">Select brand system</option>
                    <?php foreach ($commerceBrands as $brand): ?>
                      <?php $bid = (int)($brand['id'] ?? 0); ?>
                      <option value="<?= $bid ?>"<?= $postedCommerceBrandId === $bid ? ' selected' : '' ?>><?= htmlspecialchars((string)($brand['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars((string)($brand['tagline'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                    <option value="__add_new__">+ Add brand name…</option>
                  </select>
                  <button type="button" class="btn btn-primary btn-sm commerce-add-name-btn" id="commerceAddNameBtn">Add name</button>
                </div>
                <div id="commerceCustomChosen" class="publisher-custom-chosen<?= $selectedCustomCommerceBrandName !== '' ? ' is-visible' : '' ?>" aria-live="polite">
                  <span class="publisher-custom-chosen-label">Your commerce brand</span>
                  <div id="commerceCustomChosenName" class="publisher-custom-chosen-name"><?= htmlspecialchars($selectedCustomCommerceBrandName, ENT_QUOTES, 'UTF-8') ?></div>
                  <div id="commerceBrandCustomStatus" class="publisher-custom-status" hidden></div>
                  <div id="commerceBrandCustomStatusActions" class="publisher-custom-status-actions" hidden>
                    <button type="button" class="publisher-check-status-btn" id="commerceBrandCheckStatusBtn">Check approval now</button>
                  </div>
                  <div id="commerceBrandWaitNote" class="publisher-wait-note" hidden>
                    Stay on this page while you wait. We check for admin approval automatically every few seconds and will enable <strong>Create account</strong> when your brand request is approved.
                  </div>
                  <button type="button" id="commerceCustomChosenChange" class="publisher-custom-chosen-change">Choose from list instead</button>
                </div>
                <input type="hidden" id="commerceBrandNameHidden" name="commerce_brand_name" value="<?= htmlspecialchars($selectedCustomCommerceBrandName, ENT_QUOTES, 'UTF-8') ?>"<?= $selectedCustomCommerceBrandName === '' ? ' disabled' : '' ?>>
                <div class="tx-12 mg-t-5 commerce-brand-catalog-note">Choose a brand from the list or click <strong>Add name</strong> if your company is not listed. Both require admin approval before you can create your account.</div>
                <div id="commerceCatalogStatus" class="commerce-catalog-status">
                  <div id="commerceSellerStatus" class="publisher-custom-status" hidden></div>
                  <div id="commerceSellerStatusActions" class="publisher-custom-status-actions" hidden>
                    <button type="button" class="publisher-check-status-btn" id="commerceCheckStatusBtn">Check approval now</button>
                  </div>
                  <div id="commerceWaitNote" class="publisher-wait-note" hidden>
                    Stay on this page while you wait. We check for admin approval automatically every few seconds and will enable <strong>Create account</strong> when your commerce seller request is approved.
                  </div>
                  <div class="publisher-add-error" id="commerceRequestError" role="alert"></div>
                </div>
              </div>
              <div class="form-group publisher-only publisher-media-only">
                <label class="form-control-label">Category</label>
                <select name="publisher_category" class="form-control" id="publisherCategorySelect">
                  <?php foreach ($categories as $key => $label): ?>
                    <?php if ($key === 'commerce') { continue; } ?>
                    <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <input type="hidden" name="publisher_category" value="commerce" class="publisher-commerce-category-hidden" disabled>
              <div class="form-group publisher-only publisher-media-only">
                <label class="form-control-label">Tagline (optional)</label>
                <input type="text" name="publisher_tagline" class="form-control" placeholder="Breaking news and daily updates">
                <div class="tx-12 mg-t-5 publisher-tagline-note">After signup you go straight to <strong>feed.php</strong> to post. Users find you on <strong>public.php</strong> and tap Follow.</div>
              </div>
              <div class="form-group register-policy-block">
                <label class="form-control-label">Terms &amp; Policy</label>
                <div class="register-policy-box" tabindex="0" aria-label="Terms and Policy">
                  <?php foreach (register_policy_sections() as $title => $body): ?>
                    <h6><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h6>
                    <p><?= htmlspecialchars($body, ENT_QUOTES, 'UTF-8') ?></p>
                  <?php endforeach; ?>
                </div>
                <div class="register-policy-choice" role="radiogroup" aria-label="Policy agreement">
                  <label class="register-policy-option">
                    <input type="radio" name="policy_agreement" value="agree" class="register-policy-radio"<?= $postedPolicyAgreement === 'agree' ? ' checked' : '' ?> required>
                    <span>I Agree</span>
                  </label>
                  <label class="register-policy-option">
                    <input type="radio" name="policy_agreement" value="disagree" class="register-policy-radio"<?= $postedPolicyAgreement === 'disagree' ? ' checked' : '' ?>>
                    <span>I Disagree</span>
                  </label>
                </div>
                <div class="register-policy-note tx-12 mg-t-5" id="registerPolicyNote"<?= $postedPolicyAgreement === 'disagree' ? '' : ' hidden' ?>>You must agree to the Terms &amp; Policy to create an account.</div>
              </div>
              <div class="register-submit-row">
                <button name="submit" type="submit" class="btn btn-success btn-block" id="registerSubmitBtn">Sign Up</button>
                <div class="tx-center bd pd-10 mg-t-40 register-footer-link">Already a member? <a href="index.php">Sign In</a></div>
              </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade publisher-add-modal" id="publisherAddModal" tabindex="-1" role="dialog" aria-labelledby="publisherAddModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="publisherAddModalLabel">Publisher name request</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <!-- <label class="form-control-label" for="publisherAddNameInput">Publisher name</label> -->
            <input type="text" id="publisherAddNameInput" class="form-control" placeholder="e.g. CBS News" maxlength="120" autocomplete="off">
            <div class="publisher-authority-box">
              <div class="publisher-authority-title" id="publisherAuthorityTitle">Publisher name request</div>
              <div class="publisher-authority-note" id="publisherAuthorityNote">All publisher names — listed or new — are reviewed by an admin before you can create your account.</div>
              <div class="publisher-authority-grid">
                <div class="form-group full">
                  <label class="form-control-label" for="publisherAuthorityEntityType">Organization type</label>
                  <select id="publisherAuthorityEntityType" class="form-control">
                    <?php foreach (publisher_authority_entity_types() as $typeKey => $typeLabel): ?>
                      <option value="<?= htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group full">
                  <label class="form-control-label" for="publisherAuthorityLegalName">Organization name (optional)</label>
                  <input type="text" id="publisherAuthorityLegalName" class="form-control" placeholder="Registered company or organization name" maxlength="200" autocomplete="organization">
                </div>
                <div class="form-group">
                  <label class="form-control-label" for="publisherAuthorityContactName">Authorized representative</label>
                  <input type="text" id="publisherAuthorityContactName" class="form-control" placeholder="Full name" maxlength="120" autocomplete="name" required>
                </div>
                <div class="form-group">
                  <label class="form-control-label" for="publisherAuthorityContactEmail">Representative email</label>
                  <input type="email" id="publisherAuthorityContactEmail" class="form-control" placeholder="name@company.com" maxlength="120" autocomplete="email" required>
                </div>
                <div class="form-group full">
                  <label class="form-control-label" for="publisherAuthorityRequestNote">Note for admin (optional)</label>
                  <textarea id="publisherAuthorityRequestNote" class="form-control" rows="2" maxlength="500" placeholder="Briefly describe why you need this publisher name"></textarea>
                </div>
              </div>
              <label class="publisher-authority-confirm">
                <input type="checkbox" id="publisherAuthorityConfirm" value="1">
                <span>I confirm I am authorized to request this publisher name on behalf of the organization above.</span>
              </label>
            </div>
            <div class="publisher-add-error" id="publisherAddError" role="alert"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="publisherAddSaveBtn">Submit request</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade publisher-add-modal" id="commerceBrandAddModal" tabindex="-1" role="dialog" aria-labelledby="commerceBrandAddModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="commerceBrandAddModalLabel">Commerce brand request</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <input type="text" id="commerceBrandAddNameInput" class="form-control" placeholder="e.g. Chipotle, Target, Nike" maxlength="120" autocomplete="off">
            <div class="row row-xs mg-t-10">
              <div class="col-sm">
                <label class="form-control-label" for="commerceBrandAccountUsername">Account username</label>
                <input type="text" id="commerceBrandAccountUsername" class="form-control" placeholder="Username for signup" maxlength="120" autocomplete="username">
              </div>
              <div class="col-sm">
                <label class="form-control-label" for="commerceBrandAccountEmail">Account email</label>
                <input type="email" id="commerceBrandAccountEmail" class="form-control" placeholder="Email for signup" maxlength="120" autocomplete="email">
              </div>
            </div>
            <div class="tx-12 mg-t-5">Use the username and email you will use to create your seller account.</div>
            <div class="publisher-authority-box">
              <div class="publisher-authority-title">Commerce brand request</div>
              <div class="publisher-authority-note">New brand systems are reviewed by an admin before you can create your seller account.</div>
              <div class="publisher-authority-grid">
                <div class="form-group full">
                  <label class="form-control-label" for="commerceBrandAuthorityEntityType">Organization type</label>
                  <select id="commerceBrandAuthorityEntityType" class="form-control">
                    <?php foreach (publisher_authority_entity_types() as $typeKey => $typeLabel): ?>
                      <option value="<?= htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group full">
                  <label class="form-control-label" for="commerceBrandAuthorityLegalName">Store / franchise name (optional)</label>
                  <input type="text" id="commerceBrandAuthorityLegalName" class="form-control" placeholder="e.g. Downtown location" maxlength="200" autocomplete="organization">
                </div>
                <div class="form-group">
                  <label class="form-control-label" for="commerceBrandAuthorityContactName">Authorized representative</label>
                  <input type="text" id="commerceBrandAuthorityContactName" class="form-control" placeholder="Full name" maxlength="120" autocomplete="name" required>
                </div>
                <div class="form-group">
                  <label class="form-control-label" for="commerceBrandAuthorityContactEmail">Representative email</label>
                  <input type="email" id="commerceBrandAuthorityContactEmail" class="form-control" placeholder="name@company.com" maxlength="120" autocomplete="email" required>
                </div>
                <div class="form-group full">
                  <label class="form-control-label" for="commerceBrandAuthorityRequestNote">Note for admin (optional)</label>
                  <textarea id="commerceBrandAuthorityRequestNote" class="form-control" rows="2" maxlength="500" placeholder="Why this brand should be added to Commerce brand system"></textarea>
                </div>
              </div>
              <label class="publisher-authority-confirm">
                <input type="checkbox" id="commerceBrandAuthorityConfirm" value="1">
                <span>I confirm I am authorized to request this commerce brand on behalf of the organization above.</span>
              </label>
            </div>
            <div class="publisher-add-error" id="commerceBrandAddError" role="alert"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="commerceBrandAddSaveBtn">Submit request</button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade publisher-add-modal" id="commerceSellerRequestModal" tabindex="-1" role="dialog" aria-labelledby="commerceSellerRequestModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="commerceSellerRequestModalLabel">Commerce seller request</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <div id="commerceSellerBrandName" class="publisher-custom-chosen-name mg-b-10"></div>
            <div class="row row-xs mg-b-10">
              <div class="col-sm">
                <label class="form-control-label" for="commerceSellerAccountUsername">Account username</label>
                <input type="text" id="commerceSellerAccountUsername" class="form-control" placeholder="Username for signup" maxlength="120" autocomplete="username">
              </div>
              <div class="col-sm">
                <label class="form-control-label" for="commerceSellerAccountEmail">Account email</label>
                <input type="email" id="commerceSellerAccountEmail" class="form-control" placeholder="Email for signup" maxlength="120" autocomplete="email">
              </div>
            </div>
            <div class="publisher-authority-box">
              <div class="publisher-authority-title">Commerce seller approval</div>
              <div class="publisher-authority-note">Admin must approve your request to sell under this brand before you can create your account.</div>
              <div class="publisher-authority-grid">
                <div class="form-group full">
                  <label class="form-control-label" for="commerceAuthorityEntityType">Organization type</label>
                  <select id="commerceAuthorityEntityType" class="form-control">
                    <?php foreach (publisher_authority_entity_types() as $typeKey => $typeLabel): ?>
                      <option value="<?= htmlspecialchars($typeKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group full">
                  <label class="form-control-label" for="commerceAuthorityLegalName">Store / franchise name (optional)</label>
                  <input type="text" id="commerceAuthorityLegalName" class="form-control" placeholder="e.g. Downtown McDonald's" maxlength="200" autocomplete="organization">
                </div>
                <div class="form-group">
                  <label class="form-control-label" for="commerceAuthorityContactName">Authorized representative</label>
                  <input type="text" id="commerceAuthorityContactName" class="form-control" placeholder="Full name" maxlength="120" autocomplete="name" required>
                </div>
                <div class="form-group">
                  <label class="form-control-label" for="commerceAuthorityContactEmail">Representative email</label>
                  <input type="email" id="commerceAuthorityContactEmail" class="form-control" placeholder="name@company.com" maxlength="120" autocomplete="email" required>
                </div>
                <div class="form-group full">
                  <label class="form-control-label" for="commerceAuthorityRequestNote">Note for admin (optional)</label>
                  <textarea id="commerceAuthorityRequestNote" class="form-control" rows="2" maxlength="500" placeholder="Store location, franchise details, or why you should sell under this brand"></textarea>
                </div>
              </div>
              <label class="publisher-authority-confirm">
                <input type="checkbox" id="commerceAuthorityConfirm" value="1">
                <span>I confirm I am authorized to sell under this brand system.</span>
              </label>
            </div>
            <div class="publisher-add-error" id="commerceSellerModalError" role="alert"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="commerceSubmitRequestBtn">Submit seller request</button>
          </div>
        </div>
      </div>
    </div>

    <script>
    (function(){
      var signupTrack = document.body.getAttribute('data-signup-track') || 'personal';
      var publisherSelect = document.getElementById('publisherNameSelect');
      var publisherHidden = document.getElementById('publisherNameHidden');
      var personalNameInput = document.querySelector('.personal-name');
      var categorySelect = document.querySelector('select[name="publisher_category"]');
      var addModal = window.jQuery ? window.jQuery('#publisherAddModal') : null;
      var addInput = document.getElementById('publisherAddNameInput');
      var addError = document.getElementById('publisherAddError');
      var addSaveBtn = document.getElementById('publisherAddSaveBtn');
      var publisherAddNameBtn = document.getElementById('publisherAddNameBtn');
      var authorityEntityType = document.getElementById('publisherAuthorityEntityType');
      var authorityLegalName = document.getElementById('publisherAuthorityLegalName');
      var authorityContactName = document.getElementById('publisherAuthorityContactName');
      var authorityContactEmail = document.getElementById('publisherAuthorityContactEmail');
      var authorityRequestNote = document.getElementById('publisherAuthorityRequestNote');
      var authorityConfirm = document.getElementById('publisherAuthorityConfirm');
      var customChosen = document.getElementById('publisherCustomChosen');
      var customChosenName = document.getElementById('publisherCustomChosenName');
      var customChosenStatus = document.getElementById('publisherCustomStatus');
      var customChosenStatusActions = document.getElementById('publisherCustomStatusActions');
      var publisherCheckStatusBtn = document.getElementById('publisherCheckStatusBtn');
      var publisherWaitNote = document.getElementById('publisherWaitNote');
      var customChosenChange = document.getElementById('publisherCustomChosenChange');
      var commerceBrandSelect = document.getElementById('commerceBrandSelect');
      var commerceAuthorityEntityType = document.getElementById('commerceAuthorityEntityType');
      var commerceAuthorityLegalName = document.getElementById('commerceAuthorityLegalName');
      var commerceAuthorityContactName = document.getElementById('commerceAuthorityContactName');
      var commerceAuthorityContactEmail = document.getElementById('commerceAuthorityContactEmail');
      var commerceAuthorityRequestNote = document.getElementById('commerceAuthorityRequestNote');
      var commerceAuthorityConfirm = document.getElementById('commerceAuthorityConfirm');
      var commerceSellerStatus = document.getElementById('commerceSellerStatus');
      var commerceSellerStatusActions = document.getElementById('commerceSellerStatusActions');
      var commerceCheckStatusBtn = document.getElementById('commerceCheckStatusBtn');
      var commerceWaitNote = document.getElementById('commerceWaitNote');
      var commerceSubmitRequestBtn = document.getElementById('commerceSubmitRequestBtn');
      var commerceRequestError = document.getElementById('commerceRequestError');
      var commerceSellerRequestModal = window.jQuery ? window.jQuery('#commerceSellerRequestModal') : null;
      var commerceSellerBrandName = document.getElementById('commerceSellerBrandName');
      var commerceSellerAccountUsername = document.getElementById('commerceSellerAccountUsername');
      var commerceSellerAccountEmail = document.getElementById('commerceSellerAccountEmail');
      var commerceSellerModalError = document.getElementById('commerceSellerModalError');
      var pendingCommerceBrandId = 0;
      var commerceBrandWrap = document.getElementById('commerceBrandWrap');
      var commerceAddNameBtn = document.getElementById('commerceAddNameBtn');
      var commerceBrandAddModal = window.jQuery ? window.jQuery('#commerceBrandAddModal') : null;
      var commerceBrandAddInput = document.getElementById('commerceBrandAddNameInput');
      var commerceBrandAccountUsername = document.getElementById('commerceBrandAccountUsername');
      var commerceBrandAccountEmail = document.getElementById('commerceBrandAccountEmail');
      var commerceBrandAddSaveBtn = document.getElementById('commerceBrandAddSaveBtn');
      var commerceBrandAddError = document.getElementById('commerceBrandAddError');
      var commerceBrandAuthorityEntityType = document.getElementById('commerceBrandAuthorityEntityType');
      var commerceBrandAuthorityLegalName = document.getElementById('commerceBrandAuthorityLegalName');
      var commerceBrandAuthorityContactName = document.getElementById('commerceBrandAuthorityContactName');
      var commerceBrandAuthorityContactEmail = document.getElementById('commerceBrandAuthorityContactEmail');
      var commerceBrandAuthorityRequestNote = document.getElementById('commerceBrandAuthorityRequestNote');
      var commerceBrandAuthorityConfirm = document.getElementById('commerceBrandAuthorityConfirm');
      var commerceCustomChosen = document.getElementById('commerceCustomChosen');
      var commerceCustomChosenName = document.getElementById('commerceCustomChosenName');
      var commerceCustomChosenChange = document.getElementById('commerceCustomChosenChange');
      var commerceBrandNameHidden = document.getElementById('commerceBrandNameHidden');
      var commerceBrandCustomStatus = document.getElementById('commerceBrandCustomStatus');
      var commerceBrandCustomStatusActions = document.getElementById('commerceBrandCustomStatusActions');
      var commerceBrandCheckStatusBtn = document.getElementById('commerceBrandCheckStatusBtn');
      var commerceBrandWaitNote = document.getElementById('commerceBrandWaitNote');
      var customCommerceBrandApproved = false;
      var commerceBrandStatusPollTimer = null;
      var commerceBrandStatusPollName = '';
      var lastCommerceBrandSelection = '';
      var registerUsernameInput = document.querySelector('input[name="username"]');
      var registerEmailInput = document.querySelector('input[name="email"]');
      var commerceSellerApproved = false;
      var commerceStatusPollTimer = null;
      var lastPublisherSelection = '';
      var pendingPublisherSelection = '';
      var customPublisherApproved = false;
      var publisherStatusPollTimer = null;
      var publisherStatusPollName = '';
      var PUBLISHER_STATUS_POLL_MS = 8000;

      function isPublisherSignup(){
        if (signupTrack === 'publisher' || signupTrack === 'commerce') {
          return true;
        }
        if (signupTrack === 'personal') {
          return false;
        }
        var pub = document.querySelector('input[name="account_type"][value="publisher"]');
        return !!(pub && pub.checked);
      }

      function isCommerceSignup(){
        if (signupTrack === 'commerce') {
          return true;
        }
        if (signupTrack === 'publisher' || signupTrack === 'personal') {
          return false;
        }
        var commerceMode = document.querySelector('input[name="publisher_mode"][value="commerce"]');
        return !!(commerceMode && commerceMode.checked);
      }

      function registerCoreFieldsReady(){
        var username = registerUsernameInput ? registerUsernameInput.value.trim() : '';
        var email = registerEmailInput ? registerEmailInput.value.trim() : '';
        var passwordInput = document.querySelector('#registerForm input[name="password"]');
        var password = passwordInput ? passwordInput.value : '';
        return username !== '' && email !== '' && password !== '';
      }

      function syncCommerceBrandFieldRequirements(){
        var brandSelect = document.getElementById('commerceBrandSelect');
        if (!brandSelect) {
          return;
        }
        var usingCustomBrand = !!(commerceCustomChosen && commerceCustomChosen.classList.contains('is-visible'));
        if (isCommerceSignup() && !usingCustomBrand) {
          brandSelect.setAttribute('required', 'required');
        } else {
          brandSelect.removeAttribute('required');
        }
      }

      function syncSubmitButtonState(){
        var selectedPolicy = document.querySelector('input[name="policy_agreement"]:checked');
        var agree = !!(selectedPolicy && selectedPolicy.value === 'agree');
        var isPublisher = isPublisherSignup();
        var isCommerce = isCommerceSignup();
        var commerceBrandSelect = document.getElementById('commerceBrandSelect');
        var publisherReady = true;
        var coreReady = registerCoreFieldsReady();

        if (isPublisher) {
          if (isCommerce) {
            if (commerceCustomChosen && commerceCustomChosen.classList.contains('is-visible')) {
              publisherReady = customCommerceBrandApproved;
            } else {
              publisherReady = !!(commerceBrandSelect && commerceBrandSelect.value && commerceSellerApproved);
            }
          } else if (publisherHidden && !publisherHidden.value.trim()) {
            publisherReady = false;
          } else if (!customPublisherApproved) {
            publisherReady = false;
          }
        }

        if (registerSubmitBtn) {
          registerSubmitBtn.disabled = !agree || !coreReady || (isPublisher && !publisherReady);
          if (isPublisher && isCommerce && commerceCustomChosen && commerceCustomChosen.classList.contains('is-visible') && !customCommerceBrandApproved) {
            registerSubmitBtn.title = 'Waiting for admin approval of your commerce brand request.';
          } else if (isPublisher && isCommerce && commerceBrandSelect && !commerceBrandSelect.value) {
            registerSubmitBtn.title = 'Choose a commerce brand system to continue.';
          } else if (isPublisher && isCommerce && !commerceSellerApproved) {
            registerSubmitBtn.title = 'Submit your commerce seller request and wait for admin approval.';
          } else if (isPublisher && !isCommerce && customChosen && customChosen.classList.contains('is-visible') && !customPublisherApproved) {
            registerSubmitBtn.title = 'Waiting for admin approval of your publisher name request.';
          } else if (!agree) {
            registerSubmitBtn.title = 'Agree to the Terms & Policy to continue.';
          } else {
            registerSubmitBtn.removeAttribute('title');
          }
        }
      }

      function stopPublisherStatusPoll(){
        if (publisherStatusPollTimer) {
          clearInterval(publisherStatusPollTimer);
          publisherStatusPollTimer = null;
        }
        publisherStatusPollName = '';
      }

      function startPublisherStatusPoll(name){
        stopPublisherStatusPoll();
        if (!name) return;
        publisherStatusPollName = name;
        publisherStatusPollTimer = setInterval(function(){
          if (publisherStatusPollName) {
            refreshCustomPublisherStatus(publisherStatusPollName, false);
          }
        }, PUBLISHER_STATUS_POLL_MS);
      }

      function setPublisherWaitNoteVisible(visible){
        if (publisherWaitNote) {
          publisherWaitNote.hidden = !visible;
        }
      }

      function flashCreateButtonReady(){
        if (!registerSubmitBtn) return;
        registerSubmitBtn.classList.add('is-ready-flash');
        setTimeout(function(){
          registerSubmitBtn.classList.remove('is-ready-flash');
        }, 4000);
      }

      function setPublisherHidden(value){
        if (!publisherHidden) return;
        publisherHidden.value = value || '';
      }

      function showCustomStatus(status, message){
        if (!customChosenStatus) return;
        customChosenStatus.hidden = !message;
        customChosenStatus.textContent = message || '';
        customChosenStatus.classList.remove('is-pending', 'is-approved', 'is-rejected', 'is-checking');
        if (status === 'pending') customChosenStatus.classList.add('is-pending');
        if (status === 'approved') customChosenStatus.classList.add('is-approved');
        if (status === 'rejected') customChosenStatus.classList.add('is-rejected');
        if (status === 'checking') customChosenStatus.classList.add('is-checking');
        if (customChosen) {
          customChosen.classList.toggle('is-waiting', status === 'pending');
          customChosen.classList.toggle('is-approved-state', status === 'approved');
        }
        if (customChosenStatusActions) {
          customChosenStatusActions.hidden = status !== 'pending';
        }
        setPublisherWaitNoteVisible(status === 'pending');
      }

      function applyPublisherRequestResult(data){
        if (!data || !data.name) return;
        setCustomPublisherName(data.name);
        lastPublisherSelection = '';
        pendingPublisherSelection = '';
        if (publisherSelect) {
          publisherSelect.value = '';
        }
        if (data.status === 'approved') {
          showCustomStatus('approved', 'Admin approved your request! Click Create account to finish signup.');
          setCustomApprovalState(true);
          return;
        }
        showCustomStatus('pending', 'Waiting for admin approval… we check automatically every few seconds.');
        setCustomApprovalState(false);
        startPublisherStatusPoll(data.name);
      }

      function setCustomApprovalState(approved){
        var wasApproved = customPublisherApproved;
        customPublisherApproved = !!approved;
        syncSubmitButtonState();
        if (approved && !wasApproved) {
          flashCreateButtonReady();
        }
        if (approved || !publisherStatusPollName) {
          stopPublisherStatusPoll();
        }
      }

      function refreshCustomPublisherStatus(name, manualCheck){
        if (!name) {
          showCustomStatus('', '');
          setCustomApprovalState(false);
          setPublisherWaitNoteVisible(false);
          stopPublisherStatusPoll();
          return;
        }

        if (manualCheck) {
          showCustomStatus('checking', 'Checking approval status…');
        }

        fetch('publisher_request_status.php?name=' + encodeURIComponent(name), { credentials: 'same-origin' })
          .then(function(res){ return res.json(); })
          .then(function(data){
            if (!data || !data.ok) {
              if (manualCheck) {
                showCustomStatus('pending', 'Could not check status right now. Trying again automatically…');
              }
              return;
            }
            if (data.status === 'approved') {
              showCustomStatus('approved', 'Admin approved your request! Click Create account to finish signup.');
              setCustomApprovalState(true);
            } else if (data.status === 'pending') {
              showCustomStatus(
                'pending',
                manualCheck
                  ? 'Still waiting for admin approval. This page will keep checking automatically.'
                  : 'Waiting for admin approval… we check automatically every few seconds.'
              );
              setCustomApprovalState(false);
              startPublisherStatusPoll(name);
            } else if (data.status === 'rejected') {
              showCustomStatus('rejected', 'Admin rejected this request. Submit a new request or choose another publisher name.');
              setCustomApprovalState(false);
              stopPublisherStatusPoll();
            } else {
              showCustomStatus('', 'Submit a request for this publisher name before signing up.');
              setCustomApprovalState(false);
              stopPublisherStatusPoll();
            }
          })
          .catch(function(){
            if (manualCheck) {
              showCustomStatus('pending', 'Could not check status right now. Trying again automatically…');
            }
          });
      }

      function showCustomChosen(name){
        if (!customChosen || !customChosenName) return;
        if (!name) {
          customChosen.classList.remove('is-visible');
          customChosenName.textContent = '';
          showCustomStatus('', '');
          setCustomApprovalState(false);
          return;
        }
        customChosenName.textContent = name;
        customChosen.classList.add('is-visible');
        refreshCustomPublisherStatus(name);
      }

      function clearCustomChosen(){
        stopPublisherStatusPoll();
        showCustomChosen('');
        setPublisherHidden('');
        if (publisherSelect) {
          publisherSelect.value = '';
        }
        lastPublisherSelection = '';
      }

      function setCustomPublisherName(name){
        if (!name) return;
        setPublisherHidden(name);
        if (publisherSelect) {
          publisherSelect.value = '';
        }
        lastPublisherSelection = '';
        showCustomChosen(name);
      }

      function syncPublisherSelectFromHidden(){
        if (!publisherHidden) return;
        var value = publisherHidden.value || '';
        if (!value) {
          clearCustomChosen();
          return;
        }
        if (publisherSelect) {
          publisherSelect.value = '';
        }
        showCustomChosen(value);
        lastPublisherSelection = '';
      }

      function openPublisherAddModal(options){
        options = options || {};
        var presetName = (options.name || '').replace(/\s+/g, ' ').trim();
        var presetCategory = (options.category || '').trim();
        var lockName = !!options.lockName;

        showAddError('');
        clearAuthorityForm();

        if (addInput) {
          addInput.value = presetName;
          addInput.readOnly = lockName;
        }

        if (presetCategory && categorySelect) {
          categorySelect.value = presetCategory;
        }

        var modalTitle = document.getElementById('publisherAddModalLabel');
        var authorityTitle = document.getElementById('publisherAuthorityTitle');
        var authorityNote = document.getElementById('publisherAuthorityNote');
        if (modalTitle) {
          modalTitle.textContent = lockName ? 'Confirm publisher name' : 'Request publisher name';
        }
        if (authorityTitle) {
          authorityTitle.textContent = lockName ? 'Confirm your publisher name' : 'Publisher name request';
        }
        if (authorityNote) {
          authorityNote.textContent = lockName
            ? 'Confirm you are authorized to register under this publisher name. Admin must approve your request before you can create your account.'
            : 'All publisher names — listed or new — are reviewed by an admin before you can create your account.';
        }

        if (addModal) {
          addModal.modal('show');
          setTimeout(function(){
            if (addInput && !lockName) {
              addInput.focus();
            } else if (authorityContactName) {
              authorityContactName.focus();
            }
          }, 180);
        }
      }

      function stopCommerceStatusPoll(){
        if (commerceStatusPollTimer) {
          clearInterval(commerceStatusPollTimer);
          commerceStatusPollTimer = null;
        }
      }

      function stopCommerceBrandStatusPoll(){
        if (commerceBrandStatusPollTimer) {
          clearInterval(commerceBrandStatusPollTimer);
          commerceBrandStatusPollTimer = null;
        }
        commerceBrandStatusPollName = '';
      }

      function setCommerceBrandWaitNoteVisible(visible){
        if (commerceBrandWaitNote) {
          commerceBrandWaitNote.hidden = !visible;
        }
      }

      function showCommerceBrandAddError(message){
        if (!commerceBrandAddError) return;
        commerceBrandAddError.textContent = message || '';
        commerceBrandAddError.classList.toggle('is-visible', !!message);
      }

      function showCommerceBrandCustomStatus(status, message){
        if (!commerceBrandCustomStatus) return;
        commerceBrandCustomStatus.hidden = !message;
        commerceBrandCustomStatus.textContent = message || '';
        commerceBrandCustomStatus.classList.remove('is-pending', 'is-approved', 'is-rejected', 'is-checking');
        if (status === 'pending') commerceBrandCustomStatus.classList.add('is-pending');
        if (status === 'approved') commerceBrandCustomStatus.classList.add('is-approved');
        if (status === 'rejected') commerceBrandCustomStatus.classList.add('is-rejected');
        if (status === 'checking') commerceBrandCustomStatus.classList.add('is-checking');
        if (commerceBrandCustomStatusActions) {
          commerceBrandCustomStatusActions.hidden = status !== 'pending';
        }
        setCommerceBrandWaitNoteVisible(status === 'pending');
      }

      function setCustomCommerceBrandApproved(approved){
        customCommerceBrandApproved = !!approved;
        syncSubmitButtonState();
        if (approved) {
          stopCommerceBrandStatusPoll();
        }
      }

      function ensureCommerceBrandOption(brandId, brandName, tagline){
        if (!commerceBrandSelect || brandId <= 0) return;
        var exists = false;
        Array.prototype.forEach.call(commerceBrandSelect.options, function(opt){
          if (parseInt(opt.value || '0', 10) === brandId) {
            exists = true;
          }
        });
        if (!exists) {
          var opt = document.createElement('option');
          opt.value = String(brandId);
          opt.textContent = brandName + (tagline ? ' — ' + tagline : '');
          commerceBrandSelect.insertBefore(opt, commerceBrandSelect.querySelector('option[value="__add_new__"]'));
        }
        commerceBrandSelect.value = String(brandId);
      }

      function clearCommerceCustomChosen(){
        stopCommerceBrandStatusPoll();
        setCustomCommerceBrandApproved(false);
        if (commerceCustomChosen) {
          commerceCustomChosen.classList.remove('is-visible', 'is-waiting', 'is-approved-state');
        }
        if (commerceCustomChosenName) commerceCustomChosenName.textContent = '';
        if (commerceBrandNameHidden) {
          commerceBrandNameHidden.value = '';
          commerceBrandNameHidden.disabled = true;
        }
        if (commerceBrandWrap) {
          commerceBrandWrap.classList.remove('commerce-custom-active');
        }
        syncCommerceBrandFieldRequirements();
        showCommerceBrandCustomStatus('', '');
        setCommerceBrandWaitNoteVisible(false);
        if (commerceBrandSelect) {
          commerceBrandSelect.value = lastCommerceBrandSelection || '';
        }
        syncSubmitButtonState();
      }

      function applyCommerceBrandRequestResult(data){
        var name = data && data.name ? String(data.name) : '';
        if (!name) return;
        if (commerceBrandSelect) {
          lastCommerceBrandSelection = commerceBrandSelect.value || '';
          commerceBrandSelect.value = '';
        }
        if (commerceCustomChosen) {
          commerceCustomChosen.classList.add('is-visible');
          commerceCustomChosen.classList.toggle('is-waiting', data.status !== 'approved');
          commerceCustomChosen.classList.toggle('is-approved-state', data.status === 'approved');
        }
        if (commerceCustomChosenName) commerceCustomChosenName.textContent = name;
        if (commerceBrandNameHidden) {
          commerceBrandNameHidden.value = name;
          commerceBrandNameHidden.disabled = false;
        }
        if (commerceBrandWrap) {
          commerceBrandWrap.classList.add('commerce-custom-active');
        }
        syncCommerceBrandFieldRequirements();
        if (data.brand_id) {
          ensureCommerceBrandOption(parseInt(data.brand_id, 10), name, '');
        }
        if (data.status === 'approved') {
          showCommerceBrandCustomStatus('approved', 'Admin approved your commerce brand request! Click Create account to finish signup.');
          setCustomCommerceBrandApproved(true);
          if (data.brand_id) {
            setCommerceApprovedState(true);
          }
        } else {
          showCommerceBrandCustomStatus('pending', 'Waiting for admin approval… we check automatically every few seconds.');
          setCustomCommerceBrandApproved(false);
          startCommerceBrandStatusPoll(name);
        }
        syncSubmitButtonState();
      }

      function refreshCustomCommerceBrandStatus(brandName, manualCheck){
        var email = registerEmailInput ? registerEmailInput.value.trim() : '';
        if (!brandName || email === '') {
          showCommerceBrandCustomStatus('', '');
          setCustomCommerceBrandApproved(false);
          stopCommerceBrandStatusPoll();
          return;
        }

        if (manualCheck) {
          showCommerceBrandCustomStatus('checking', 'Checking approval status…');
        }

        fetch('commerce_brand_name_request_status.php?name=' + encodeURIComponent(brandName) + '&email=' + encodeURIComponent(email), { credentials: 'same-origin' })
          .then(function(res){ return res.json(); })
          .then(function(data){
            if (!data || !data.ok) {
              if (manualCheck) {
                showCommerceBrandCustomStatus('pending', 'Could not check status right now. Trying again automatically…');
              }
              return;
            }
            if (data.brand_id) {
              ensureCommerceBrandOption(parseInt(data.brand_id, 10), data.brand_name || brandName, '');
            }
            if (data.status === 'approved') {
              showCommerceBrandCustomStatus('approved', 'Admin approved your commerce brand request! Click Create account to finish signup.');
              setCustomCommerceBrandApproved(true);
              setCommerceApprovedState(true);
            } else if (data.status === 'pending') {
              showCommerceBrandCustomStatus('pending', manualCheck ? 'Still waiting for admin approval. This page will keep checking automatically.' : 'Waiting for admin approval… we check automatically every few seconds.');
              setCustomCommerceBrandApproved(false);
              startCommerceBrandStatusPoll(brandName);
            } else if (data.status === 'rejected') {
              showCommerceBrandCustomStatus('rejected', 'Admin rejected this request. Update your details and submit a new request.');
              setCustomCommerceBrandApproved(false);
              stopCommerceBrandStatusPoll();
            } else {
              showCommerceBrandCustomStatus('', 'Submit your brand request with Add name. Admin must approve it before you can create your account.');
              setCustomCommerceBrandApproved(false);
              stopCommerceBrandStatusPoll();
            }
          })
          .catch(function(){
            if (manualCheck) {
              showCommerceBrandCustomStatus('pending', 'Could not check status right now. Trying again automatically…');
            }
          });
      }

      function startCommerceBrandStatusPoll(brandName){
        stopCommerceBrandStatusPoll();
        commerceBrandStatusPollName = brandName || '';
        if (!commerceBrandStatusPollName) return;
        commerceBrandStatusPollTimer = setInterval(function(){
          refreshCustomCommerceBrandStatus(commerceBrandStatusPollName, false);
        }, 8000);
      }

      function syncCommerceAccountFieldsToRegisterForm(username, email){
        if (registerUsernameInput && username && !registerUsernameInput.value.trim()) {
          registerUsernameInput.value = username;
        }
        if (registerEmailInput && email && !registerEmailInput.value.trim()) {
          registerEmailInput.value = email;
        }
        syncSubmitButtonState();
      }

      function prefillCommerceAccountFields(usernameField, emailField){
        if (usernameField && registerUsernameInput && registerUsernameInput.value.trim() && !usernameField.value.trim()) {
          usernameField.value = registerUsernameInput.value.trim();
        }
        if (emailField && registerEmailInput && registerEmailInput.value.trim() && !emailField.value.trim()) {
          emailField.value = registerEmailInput.value.trim();
        }
      }

      function resolveCommerceBrandRequestCredentials(){
        var username = commerceBrandAccountUsername ? commerceBrandAccountUsername.value.trim() : '';
        var email = commerceBrandAccountEmail ? commerceBrandAccountEmail.value.trim() : '';
        if (username === '' && registerUsernameInput) {
          username = registerUsernameInput.value.trim();
        }
        if (email === '' && registerEmailInput) {
          email = registerEmailInput.value.trim();
        }
        if (email === '' && commerceBrandAuthorityContactEmail) {
          email = commerceBrandAuthorityContactEmail.value.trim();
        }
        if (username === '' && email !== '') {
          username = email.split('@')[0].replace(/[^a-zA-Z0-9_]/g, '').slice(0, 40);
        }
        return { username: username, email: email };
      }

      function resolveCommerceSellerRequestCredentials(){
        var username = commerceSellerAccountUsername ? commerceSellerAccountUsername.value.trim() : '';
        var email = commerceSellerAccountEmail ? commerceSellerAccountEmail.value.trim() : '';
        if (username === '' && registerUsernameInput) {
          username = registerUsernameInput.value.trim();
        }
        if (email === '' && registerEmailInput) {
          email = registerEmailInput.value.trim();
        }
        if (email === '' && commerceAuthorityContactEmail) {
          email = commerceAuthorityContactEmail.value.trim();
        }
        if (username === '' && email !== '') {
          username = email.split('@')[0].replace(/[^a-zA-Z0-9_]/g, '').slice(0, 40);
        }
        return { username: username, email: email };
      }

      function openCommerceBrandAddModal(){
        showCommerceBrandAddError('');
        if (commerceBrandAuthorityEntityType) commerceBrandAuthorityEntityType.selectedIndex = 0;
        if (commerceBrandAuthorityLegalName) commerceBrandAuthorityLegalName.value = '';
        if (commerceBrandAuthorityContactName) commerceBrandAuthorityContactName.value = '';
        if (commerceBrandAuthorityContactEmail) commerceBrandAuthorityContactEmail.value = '';
        if (commerceBrandAuthorityRequestNote) commerceBrandAuthorityRequestNote.value = '';
        if (commerceBrandAuthorityConfirm) commerceBrandAuthorityConfirm.checked = false;
        if (commerceBrandAddInput) {
          commerceBrandAddInput.value = '';
          commerceBrandAddInput.readOnly = false;
        }
        if (commerceBrandAccountUsername) commerceBrandAccountUsername.value = '';
        if (commerceBrandAccountEmail) commerceBrandAccountEmail.value = '';
        prefillCommerceAccountFields(commerceBrandAccountUsername, commerceBrandAccountEmail);
        if (commerceBrandAddModal) commerceBrandAddModal.modal('show');
      }

      function validateCommerceBrandAuthorityForm(){
        var contactName = commerceBrandAuthorityContactName ? commerceBrandAuthorityContactName.value.replace(/\s+/g, ' ').trim() : '';
        var contactEmail = commerceBrandAuthorityContactEmail ? commerceBrandAuthorityContactEmail.value.trim() : '';
        if (!contactName) return 'Enter the authorized representative name.';
        if (!contactEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contactEmail)) return 'Enter a valid authorized representative email.';
        if (!commerceBrandAuthorityConfirm || !commerceBrandAuthorityConfirm.checked) return 'Confirm that you are authorized to request this commerce brand.';
        return '';
      }

      function appendCommerceBrandAuthorityForm(body){
        if (commerceBrandAuthorityEntityType) body.append('entity_type', commerceBrandAuthorityEntityType.value || 'business');
        if (commerceBrandAuthorityLegalName) body.append('legal_entity_name', commerceBrandAuthorityLegalName.value.trim());
        if (commerceBrandAuthorityContactName) body.append('authorized_contact_name', commerceBrandAuthorityContactName.value.trim());
        if (commerceBrandAuthorityContactEmail) body.append('authorized_contact_email', commerceBrandAuthorityContactEmail.value.trim());
        if (commerceBrandAuthorityRequestNote) body.append('request_note', commerceBrandAuthorityRequestNote.value.trim());
        body.append('authority_confirmed', (commerceBrandAuthorityConfirm && commerceBrandAuthorityConfirm.checked) ? '1' : '0');
      }

      function showCommerceRequestError(message){
        if (!commerceRequestError) return;
        commerceRequestError.textContent = message || '';
        commerceRequestError.classList.toggle('is-visible', !!message);
      }

      function showCommerceSellerModalError(message){
        if (!commerceSellerModalError) return;
        commerceSellerModalError.textContent = message || '';
        commerceSellerModalError.classList.toggle('is-visible', !!message);
      }

      function clearCommerceSellerAuthorityForm(){
        if (commerceAuthorityEntityType) commerceAuthorityEntityType.selectedIndex = 0;
        if (commerceAuthorityLegalName) commerceAuthorityLegalName.value = '';
        if (commerceAuthorityContactName) commerceAuthorityContactName.value = '';
        if (commerceAuthorityContactEmail) commerceAuthorityContactEmail.value = '';
        if (commerceAuthorityRequestNote) commerceAuthorityRequestNote.value = '';
        if (commerceAuthorityConfirm) commerceAuthorityConfirm.checked = false;
      }

      function openCommerceSellerRequestModal(brandId){
        pendingCommerceBrandId = brandId > 0 ? brandId : 0;
        showCommerceSellerModalError('');
        clearCommerceSellerAuthorityForm();
        if (commerceSellerBrandName && commerceBrandSelect) {
          var label = '';
          Array.prototype.forEach.call(commerceBrandSelect.options, function(opt){
            if (parseInt(opt.value || '0', 10) === pendingCommerceBrandId) {
              label = (opt.textContent || '').split(' — ')[0].trim();
            }
          });
          commerceSellerBrandName.textContent = label || 'Selected brand';
        }
        if (commerceSellerAccountUsername) commerceSellerAccountUsername.value = '';
        if (commerceSellerAccountEmail) commerceSellerAccountEmail.value = '';
        prefillCommerceAccountFields(commerceSellerAccountUsername, commerceSellerAccountEmail);
        if (commerceSellerRequestModal) commerceSellerRequestModal.modal('show');
      }

      function applyCommerceSellerSelection(brandId){
        if (!commerceBrandSelect || brandId <= 0) return;
        commerceBrandSelect.value = String(brandId);
        lastCommerceBrandSelection = String(brandId);
      }

      function setCommerceWaitNoteVisible(visible){
        if (commerceWaitNote) {
          commerceWaitNote.hidden = !visible;
        }
      }

      function showCommerceSellerStatus(status, message){
        if (!commerceSellerStatus) return;
        commerceSellerStatus.hidden = !message;
        commerceSellerStatus.textContent = message || '';
        commerceSellerStatus.classList.remove('is-pending', 'is-approved', 'is-rejected', 'is-checking');
        if (status === 'pending') commerceSellerStatus.classList.add('is-pending');
        if (status === 'approved') commerceSellerStatus.classList.add('is-approved');
        if (status === 'rejected') commerceSellerStatus.classList.add('is-rejected');
        if (status === 'checking') commerceSellerStatus.classList.add('is-checking');
        if (commerceSellerStatusActions) {
          commerceSellerStatusActions.hidden = status !== 'pending';
        }
        setCommerceWaitNoteVisible(status === 'pending');
      }

      function setCommerceApprovedState(approved){
        var wasApproved = commerceSellerApproved;
        commerceSellerApproved = !!approved;
        syncSubmitButtonState();
        if (approved && !wasApproved && registerSubmitBtn) {
          registerSubmitBtn.classList.add('is-ready-flash');
          setTimeout(function(){ registerSubmitBtn.classList.remove('is-ready-flash'); }, 4000);
        }
        if (approved) {
          stopCommerceStatusPoll();
        }
      }

      function commerceRequestParams(){
        return {
          brandId: commerceBrandSelect ? parseInt(commerceBrandSelect.value || '0', 10) : 0,
          username: registerUsernameInput ? registerUsernameInput.value.trim() : '',
          email: registerEmailInput ? registerEmailInput.value.trim() : ''
        };
      }

      function refreshCommerceSellerStatus(manualCheck){
        var params = commerceRequestParams();
        if (params.brandId <= 0 || params.email === '') {
          showCommerceSellerStatus('', '');
          setCommerceApprovedState(false);
          stopCommerceStatusPoll();
          return;
        }

        if (manualCheck) {
          showCommerceSellerStatus('checking', 'Checking approval status…');
        }

        fetch('commerce_seller_request_status.php?commerce_brand_id=' + encodeURIComponent(params.brandId) + '&email=' + encodeURIComponent(params.email), { credentials: 'same-origin' })
          .then(function(res){ return res.json(); })
          .then(function(data){
            if (!data || !data.ok) {
              if (manualCheck) {
                showCommerceSellerStatus('pending', 'Could not check status right now. Trying again automatically…');
              }
              return;
            }
            if (data.status === 'approved') {
              showCommerceSellerStatus('approved', 'Admin approved your commerce seller request! Click Create account to finish signup.');
              setCommerceApprovedState(true);
            } else if (data.status === 'pending') {
              showCommerceSellerStatus('pending', manualCheck ? 'Still waiting for admin approval. This page will keep checking automatically.' : 'Waiting for admin approval… we check automatically every few seconds.');
              setCommerceApprovedState(false);
              startCommerceStatusPoll();
            } else if (data.status === 'rejected') {
              showCommerceSellerStatus('rejected', 'Admin rejected this request. Update your details and submit a new request.');
              setCommerceApprovedState(false);
              stopCommerceStatusPoll();
            } else {
              showCommerceSellerStatus('', 'Submit your seller request below. Admin must approve it before you can create your account.');
              setCommerceApprovedState(false);
              stopCommerceStatusPoll();
            }
          })
          .catch(function(){
            if (manualCheck) {
              showCommerceSellerStatus('pending', 'Could not check status right now. Trying again automatically…');
            }
          });
      }

      function startCommerceStatusPoll(){
        stopCommerceStatusPoll();
        commerceStatusPollTimer = setInterval(function(){
          refreshCommerceSellerStatus(false);
        }, PUBLISHER_STATUS_POLL_MS);
      }

      function validateCommerceAuthorityForm(){
        var contactName = commerceAuthorityContactName ? commerceAuthorityContactName.value.replace(/\s+/g, ' ').trim() : '';
        var contactEmail = commerceAuthorityContactEmail ? commerceAuthorityContactEmail.value.trim() : '';
        if (!contactName) return 'Enter the authorized representative name.';
        if (!contactEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contactEmail)) return 'Enter a valid authorized representative email.';
        if (!commerceAuthorityConfirm || !commerceAuthorityConfirm.checked) return 'Confirm that you are authorized to sell under this brand.';
        return '';
      }

      function appendCommerceAuthorityForm(body){
        if (commerceAuthorityEntityType) body.append('entity_type', commerceAuthorityEntityType.value || 'business');
        if (commerceAuthorityLegalName) body.append('legal_entity_name', commerceAuthorityLegalName.value.trim());
        if (commerceAuthorityContactName) body.append('authorized_contact_name', commerceAuthorityContactName.value.trim());
        if (commerceAuthorityContactEmail) body.append('authorized_contact_email', commerceAuthorityContactEmail.value.trim());
        if (commerceAuthorityRequestNote) body.append('request_note', commerceAuthorityRequestNote.value.trim());
        body.append('authority_confirmed', (commerceAuthorityConfirm && commerceAuthorityConfirm.checked) ? '1' : '0');
      }

      function syncPublisherMode(){
        var isCommerce = (signupTrack === 'commerce');
        if (signupTrack === 'publisher') {
          isCommerce = false;
        } else if (signupTrack === 'personal') {
          isCommerce = false;
        } else if (signupTrack !== 'commerce') {
          var commerceModeInput = document.querySelector('input[name="publisher_mode"][value="commerce"]');
          isCommerce = !!(commerceModeInput && commerceModeInput.checked);
        }
        document.body.classList.toggle('is-publisher-commerce', isCommerce);
        var mediaCategory = document.getElementById('publisherCategorySelect');
        var commerceCategoryHidden = document.querySelector('.publisher-commerce-category-hidden');
        var commerceBrandSelect = document.getElementById('commerceBrandSelect');
        if (mediaCategory) {
          mediaCategory.disabled = isCommerce;
          mediaCategory.required = !isCommerce;
        }
        if (commerceCategoryHidden) {
          commerceCategoryHidden.disabled = !isCommerce;
        }
        if (commerceBrandSelect) {
          syncCommerceBrandFieldRequirements();
        }
        if (publisherHidden) {
          if (isCommerce) {
            publisherHidden.removeAttribute('name');
            publisherHidden.required = false;
            publisherHidden.disabled = true;
          } else if (signupTrack === 'publisher' || document.querySelector('input[name="account_type"][value="publisher"]')?.checked) {
            publisherHidden.disabled = false;
            publisherHidden.required = true;
            publisherHidden.setAttribute('name', 'name');
          }
        }
        if (isCommerce) {
          stopPublisherStatusPoll();
          setPublisherWaitNoteVisible(false);
          refreshCommerceSellerStatus(false);
        } else {
          stopCommerceStatusPoll();
          setCommerceWaitNoteVisible(false);
          setCommerceApprovedState(false);
          showCommerceSellerStatus('', '');
        }
        syncSubmitButtonState();
      }

      function syncType(){
        var isPublisher = (signupTrack === 'publisher' || signupTrack === 'commerce');
        if (signupTrack === 'personal') {
          isPublisher = false;
        } else if (signupTrack !== 'publisher' && signupTrack !== 'commerce') {
          var pub = document.querySelector('input[name="account_type"][value="publisher"]');
          isPublisher = !!(pub && pub.checked);
        }
        document.body.classList.toggle('is-publisher-reg', isPublisher);
        document.documentElement.classList.toggle('is-publisher-reg', isPublisher);
        if (!isPublisher) {
          document.body.classList.remove('is-publisher-commerce');
        }
        if (registerSubmitBtn) {
          registerSubmitBtn.textContent = isPublisher ? 'Create account' : 'Sign Up';
        }
        document.querySelectorAll('.personal-req').forEach(function(el){
          el.required = !isPublisher;
          if (el.name === 'name') {
            el.disabled = isPublisher;
          }
        });
        document.querySelectorAll('.personal-birth-part').forEach(function(el){
          el.disabled = isPublisher;
        });
        document.querySelectorAll('.publisher-req').forEach(function(el){
          el.required = false;
        });
        if (publisherHidden) {
          publisherHidden.disabled = !isPublisher;
          var isCommerce = (signupTrack === 'commerce');
          if (signupTrack === 'publisher' || signupTrack === 'personal') {
            isCommerce = false;
          } else if (signupTrack !== 'commerce') {
            var commerceMode = document.querySelector('input[name="publisher_mode"][value="commerce"]');
            isCommerce = !!(commerceMode && commerceMode.checked);
          }
          publisherHidden.required = isPublisher && !isCommerce;
          if (isPublisher && !isCommerce) {
            publisherHidden.setAttribute('name', 'name');
          } else {
            publisherHidden.removeAttribute('name');
          }
        }
        if (personalNameInput) {
          personalNameInput.disabled = isPublisher;
          if (isPublisher) {
            personalNameInput.removeAttribute('name');
          } else {
            personalNameInput.setAttribute('name', 'name');
          }
        }
        if (isPublisher) {
          syncPublisherSelectFromHidden();
          syncPublisherMode();
        }
        syncSubmitButtonState();
      }

      function showAddError(message){
        if (!addError) return;
        addError.textContent = message || '';
        addError.classList.toggle('is-visible', !!message);
      }

      function clearAuthorityForm(){
        if (authorityEntityType) authorityEntityType.selectedIndex = 0;
        if (authorityLegalName) authorityLegalName.value = '';
        if (authorityContactName) authorityContactName.value = '';
        if (authorityContactEmail) authorityContactEmail.value = '';
        if (authorityRequestNote) authorityRequestNote.value = '';
        if (authorityConfirm) authorityConfirm.checked = false;
      }

      function validateAuthorityForm(){
        var contactName = authorityContactName ? authorityContactName.value.replace(/\s+/g, ' ').trim() : '';
        var contactEmail = authorityContactEmail ? authorityContactEmail.value.trim() : '';

        if (!contactName) {
          return 'Enter the authorized representative name.';
        }
        if (!contactEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contactEmail)) {
          return 'Enter a valid authorized representative email.';
        }
        if (!authorityConfirm || !authorityConfirm.checked) {
          return 'Confirm that you are authorized to request this publisher name.';
        }
        return '';
      }

      function appendAuthorityForm(body){
        if (authorityEntityType) body.append('entity_type', authorityEntityType.value || 'business');
        if (authorityLegalName) body.append('legal_entity_name', authorityLegalName.value.trim());
        if (authorityContactName) body.append('authorized_contact_name', authorityContactName.value.trim());
        if (authorityContactEmail) body.append('authorized_contact_email', authorityContactEmail.value.trim());
        if (authorityRequestNote) body.append('request_note', authorityRequestNote.value.trim());
        body.append('authority_confirmed', (authorityConfirm && authorityConfirm.checked) ? '1' : '0');
      }

      if (publisherSelect) {
        publisherSelect.addEventListener('change', function(){
          var value = publisherSelect.value;
          if (value === '__add_new__') {
            publisherSelect.value = lastPublisherSelection || '';
            pendingPublisherSelection = '';
            openPublisherAddModal({ name: '', lockName: false });
            return;
          }
          if (value) {
            var selectedOpt = publisherSelect.options[publisherSelect.selectedIndex];
            var category = selectedOpt ? (selectedOpt.getAttribute('data-category') || '') : '';
            pendingPublisherSelection = value;
            publisherSelect.value = lastPublisherSelection || '';
            openPublisherAddModal({ name: value, category: category, lockName: true });
            return;
          }
          clearCustomChosen();
        });
      }

      if (publisherAddNameBtn) {
        publisherAddNameBtn.addEventListener('click', function(){
          pendingPublisherSelection = '';
          openPublisherAddModal({ name: '', lockName: false });
        });
      }

      if (addModal) {
        addModal.on('hidden.bs.modal', function(){
          pendingPublisherSelection = '';
          if (addInput) {
            addInput.readOnly = false;
          }
        });
      }

      if (customChosenChange) {
        customChosenChange.addEventListener('click', function(){
          clearCustomChosen();
          if (publisherSelect) {
            publisherSelect.focus();
          }
        });
      }

      if (addSaveBtn) {
        addSaveBtn.addEventListener('click', function(){
          var name = addInput ? addInput.value.replace(/\s+/g, ' ').trim() : '';
          if (name.length < 2) {
            showAddError('Enter a publisher name (at least 2 characters).');
            return;
          }
          var authorityError = validateAuthorityForm();
          if (authorityError) {
            showAddError(authorityError);
            return;
          }
          showAddError('');
          addSaveBtn.disabled = true;

          var body = new FormData();
          body.append('name', name);
          if (categorySelect) {
            body.append('publisher_category', categorySelect.value || 'news');
          }
          appendAuthorityForm(body);

          fetch('publisher_name_save.php', {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
          })
            .then(function(res){ return res.json(); })
            .then(function(data){
              addSaveBtn.disabled = false;
              if (!data || !data.ok) {
                showAddError((data && data.message) ? data.message : 'Unable to submit publisher name request.');
                return;
              }
              applyPublisherRequestResult(data);
              if (addModal) addModal.modal('hide');
            })
            .catch(function(){
              addSaveBtn.disabled = false;
              showAddError('Unable to submit publisher name request right now.');
            });
        });
      }

      if (addInput) {
        addInput.addEventListener('keydown', function(ev){
          if (ev.key === 'Enter') {
            ev.preventDefault();
            if (addSaveBtn) addSaveBtn.click();
          }
        });
      }

      if (publisherCheckStatusBtn) {
        publisherCheckStatusBtn.addEventListener('click', function(){
          var name = publisherHidden ? publisherHidden.value.trim() : '';
          if (name) {
            refreshCustomPublisherStatus(name, true);
          }
        });
      }

      document.querySelectorAll('input[name="account_type"]').forEach(function(r){
        r.addEventListener('change', function(){
          if (!(r.checked && r.value === 'publisher')) {
            stopPublisherStatusPoll();
            setPublisherWaitNoteVisible(false);
          }
          syncType();
        });
      });

      if (commerceBrandSelect) {
        commerceBrandSelect.addEventListener('change', function(){
          var value = commerceBrandSelect.value;
          if (value === '__add_new__') {
            commerceBrandSelect.value = lastCommerceBrandSelection || '';
            openCommerceBrandAddModal();
            return;
          }
          if (value) {
            var brandId = parseInt(value, 10);
            commerceBrandSelect.value = lastCommerceBrandSelection || '';
            clearCommerceCustomChosen();
            openCommerceSellerRequestModal(brandId);
            return;
          }
          clearCommerceCustomChosen();
          lastCommerceBrandSelection = '';
          setCommerceApprovedState(false);
          refreshCommerceSellerStatus(false);
          syncSubmitButtonState();
        });
      }

      if (commerceAddNameBtn) {
        commerceAddNameBtn.addEventListener('click', function(){
          openCommerceBrandAddModal();
        });
      }

      if (commerceCustomChosenChange) {
        commerceCustomChosenChange.addEventListener('click', function(){
          clearCommerceCustomChosen();
          if (commerceBrandSelect) {
            commerceBrandSelect.focus();
          }
        });
      }

      if (commerceBrandAddSaveBtn) {
        commerceBrandAddSaveBtn.addEventListener('click', function(){
          var name = commerceBrandAddInput ? commerceBrandAddInput.value.replace(/\s+/g, ' ').trim() : '';
          var credentials = resolveCommerceBrandRequestCredentials();
          var username = credentials.username;
          var email = credentials.email;
          if (name.length < 2) {
            showCommerceBrandAddError('Enter a company or brand name (at least 2 characters).');
            return;
          }
          if (username === '' || email === '') {
            showCommerceBrandAddError('Enter account username and email in this form, or fill representative email below.');
            return;
          }
          if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showCommerceBrandAddError('Enter a valid account email.');
            return;
          }
          var authorityError = validateCommerceBrandAuthorityForm();
          if (authorityError) {
            showCommerceBrandAddError(authorityError);
            return;
          }
          showCommerceBrandAddError('');
          syncCommerceAccountFieldsToRegisterForm(username, email);
          commerceBrandAddSaveBtn.disabled = true;

          var body = new FormData();
          body.append('name', name);
          body.append('username', username);
          body.append('email', email);
          appendCommerceBrandAuthorityForm(body);

          fetch('commerce_brand_name_request_save.php', {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
          })
            .then(function(res){ return res.json(); })
            .then(function(data){
              commerceBrandAddSaveBtn.disabled = false;
              if (!data || !data.ok) {
                showCommerceBrandAddError((data && data.message) ? data.message : 'Unable to submit commerce brand request.');
                return;
              }
              applyCommerceBrandRequestResult(data);
              if (commerceBrandAddModal) commerceBrandAddModal.modal('hide');
            })
            .catch(function(){
              commerceBrandAddSaveBtn.disabled = false;
              showCommerceBrandAddError('Unable to submit commerce brand request right now.');
            });
        });
      }

      if (commerceBrandCheckStatusBtn) {
        commerceBrandCheckStatusBtn.addEventListener('click', function(){
          var name = commerceBrandNameHidden ? commerceBrandNameHidden.value.trim() : '';
          if (name) {
            refreshCustomCommerceBrandStatus(name, true);
          }
        });
      }

      if (commerceSubmitRequestBtn) {
        commerceSubmitRequestBtn.addEventListener('click', function(){
          var brandId = pendingCommerceBrandId > 0 ? pendingCommerceBrandId : (commerceBrandSelect ? parseInt(commerceBrandSelect.value || '0', 10) : 0);
          var credentials = resolveCommerceSellerRequestCredentials();
          var username = credentials.username;
          var email = credentials.email;
          if (brandId <= 0) {
            showCommerceSellerModalError('Choose a commerce brand system first.');
            return;
          }
          if (username === '' || email === '') {
            showCommerceSellerModalError('Enter account username and email in this form, or fill representative email below.');
            return;
          }
          if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showCommerceSellerModalError('Enter a valid account email.');
            return;
          }
          var authorityError = validateCommerceAuthorityForm();
          if (authorityError) {
            showCommerceSellerModalError(authorityError);
            return;
          }
          showCommerceSellerModalError('');
          showCommerceRequestError('');
          syncCommerceAccountFieldsToRegisterForm(username, email);
          commerceSubmitRequestBtn.disabled = true;

          var body = new FormData();
          body.append('commerce_brand_id', String(brandId));
          body.append('username', username);
          body.append('email', email);
          appendCommerceAuthorityForm(body);

          fetch('commerce_seller_request_save.php', {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
          })
            .then(function(res){ return res.json(); })
            .then(function(data){
              commerceSubmitRequestBtn.disabled = false;
              if (!data || !data.ok) {
                showCommerceSellerModalError((data && data.message) ? data.message : 'Unable to submit commerce seller request.');
                return;
              }
              applyCommerceSellerSelection(brandId);
              if (commerceSellerRequestModal) commerceSellerRequestModal.modal('hide');
              if (data.status === 'approved') {
                showCommerceSellerStatus('approved', 'Admin approved your commerce seller request! Click Create account to finish signup.');
                setCommerceApprovedState(true);
              } else {
                showCommerceSellerStatus('pending', 'Waiting for admin approval… we check automatically every few seconds.');
                setCommerceApprovedState(false);
                startCommerceStatusPoll();
              }
            })
            .catch(function(){
              commerceSubmitRequestBtn.disabled = false;
              showCommerceSellerModalError('Unable to submit commerce seller request right now.');
            });
        });
      }

      if (commerceCheckStatusBtn) {
        commerceCheckStatusBtn.addEventListener('click', function(){
          refreshCommerceSellerStatus(true);
        });
      }

      if (registerEmailInput) {
        registerEmailInput.addEventListener('input', function(){
          if (document.body.classList.contains('is-publisher-commerce')) {
            setCommerceApprovedState(false);
            if (commerceCustomChosen && commerceCustomChosen.classList.contains('is-visible')) {
              setCustomCommerceBrandApproved(false);
              var customName = commerceBrandNameHidden ? commerceBrandNameHidden.value.trim() : '';
              if (customName) {
                refreshCustomCommerceBrandStatus(customName, false);
              }
            } else {
              refreshCommerceSellerStatus(false);
            }
          }
        });
      }

      document.querySelectorAll('input[name="publisher_mode"]').forEach(function(r){
        r.addEventListener('change', syncPublisherMode);
      });

      var registerForm = document.getElementById('registerForm');
      var registerSubmitBtn = document.getElementById('registerSubmitBtn');
      var registerPolicyNote = document.getElementById('registerPolicyNote');

      function syncPolicyChoice(){
        var selected = document.querySelector('input[name="policy_agreement"]:checked');
        if (registerPolicyNote) {
          registerPolicyNote.hidden = !(selected && selected.value === 'disagree');
        }
        syncSubmitButtonState();
      }

      document.querySelectorAll('input[name="policy_agreement"]').forEach(function(radio){
        radio.addEventListener('change', syncPolicyChoice);
      });
      document.querySelectorAll('#registerForm input[name="username"], #registerForm input[name="email"], #registerForm input[name="password"]').forEach(function(input){
        input.addEventListener('input', syncSubmitButtonState);
      });
      syncPolicyChoice();

      if (registerForm) {
        registerForm.addEventListener('submit', function(ev){
          var selectedPolicy = document.querySelector('input[name="policy_agreement"]:checked');
          if (!selectedPolicy || selectedPolicy.value !== 'agree') {
            ev.preventDefault();
            if (registerPolicyNote) {
              registerPolicyNote.hidden = false;
            }
            return;
          }

          var isPublisherSubmit = (signupTrack === 'publisher' || signupTrack === 'commerce');
          if (!isPublisherSubmit) {
            var pub = document.querySelector('input[name="account_type"][value="publisher"]');
            isPublisherSubmit = !!(pub && pub.checked);
          }
          if (!isPublisherSubmit) {
            var ageConfirm = document.querySelector('input[name="age_confirm"]');
            if (ageConfirm && !ageConfirm.checked) {
              ev.preventDefault();
              alert('Please confirm you are at least <?= register_minimum_personal_age() ?> years old.');
              ageConfirm.focus();
              return;
            }
          }

          if (isPublisherSubmit) {
            var isCommerceSubmit = (signupTrack === 'commerce');
            if (!isCommerceSubmit) {
              var commerceMode = document.querySelector('input[name="publisher_mode"][value="commerce"]');
              isCommerceSubmit = !!(commerceMode && commerceMode.checked);
            }
            var commerceBrandSelect = document.getElementById('commerceBrandSelect');
            if (isCommerceSubmit) {
              var usingCustomCommerceBrand = commerceCustomChosen && commerceCustomChosen.classList.contains('is-visible');
              if (usingCustomCommerceBrand) {
                if (!customCommerceBrandApproved) {
                  ev.preventDefault();
                  alert('Your commerce brand request must be approved by admin before you can create your account.');
                  return;
                }
                return;
              }
              if (commerceBrandSelect && !commerceBrandSelect.value) {
                ev.preventDefault();
                commerceBrandSelect.focus();
                alert('Please choose a commerce brand system or click Add name to request a new one.');
                return;
              }
              if (!commerceSellerApproved) {
                ev.preventDefault();
                alert('Your commerce seller request must be approved by admin before you can create your account.');
                return;
              }
              return;
            }
            if (publisherHidden && !publisherHidden.value.trim()) {
              ev.preventDefault();
              if (publisherSelect) publisherSelect.focus();
              alert('Please select or add a publisher name.');
              return;
            }

            if (publisherHidden && publisherHidden.value.trim() && !customPublisherApproved) {
              ev.preventDefault();
              alert('This publisher name is not approved yet. Wait for admin approval before signing up.');
              return;
            }
          }
        });
      }

      syncType();
      syncPublisherMode();
      syncPublisherSelectFromHidden();
      syncCommerceBrandFieldRequirements();
      if (commerceCustomChosen && commerceCustomChosen.classList.contains('is-visible') && commerceBrandNameHidden && commerceBrandNameHidden.value.trim()) {
        refreshCustomCommerceBrandStatus(commerceBrandNameHidden.value.trim(), false);
      }
    })();
    </script>
  </body>
</html>
