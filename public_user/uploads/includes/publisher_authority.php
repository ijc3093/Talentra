<?php
declare(strict_types=1);

require_once __DIR__ . '/publisher_accounts_load.php';

function publisher_authority_entity_types(): array
{
    return [
        'business' => 'Business / company',
        'nonprofit' => 'Non-profit organization',
        'news_org' => 'News organization',
        'government' => 'Government entity',
        'other' => 'Other registered entity',
    ];
}

function publisher_authority_ensure_schema(PDO $dbh): void
{
    publisher_registry_ensure_schema($dbh);

    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $dbh->exec("
            CREATE TABLE IF NOT EXISTS publisher_name_authority (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                publisher_name_option_id INT UNSIGNED NULL DEFAULT NULL,
                publisher_name VARCHAR(120) NOT NULL,
                publisher_category VARCHAR(40) NOT NULL DEFAULT 'news',
                entity_type VARCHAR(40) NOT NULL DEFAULT 'business',
                legal_entity_name VARCHAR(200) NOT NULL DEFAULT '',
                registration_id VARCHAR(40) NOT NULL DEFAULT '',
                registration_country VARCHAR(80) NOT NULL DEFAULT 'US',
                authorized_contact_name VARCHAR(120) NOT NULL DEFAULT '',
                authorized_contact_email VARCHAR(120) NOT NULL DEFAULT '',
                request_note VARCHAR(500) NOT NULL DEFAULT '',
                authority_confirmed TINYINT(1) NOT NULL DEFAULT 0,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                reviewed_by_admin_id INT UNSIGNED NULL DEFAULT NULL,
                reviewed_at DATETIME NULL DEFAULT NULL,
                review_note VARCHAR(255) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_publisher_name_authority_name (publisher_name),
                KEY idx_publisher_name_authority_status (status),
                KEY idx_publisher_name_authority_option (publisher_name_option_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        foreach ([
            'publisher_category' => "ALTER TABLE publisher_name_authority ADD COLUMN publisher_category VARCHAR(40) NOT NULL DEFAULT 'news' AFTER publisher_name",
            'commerce_brand_id' => 'ALTER TABLE publisher_name_authority ADD COLUMN commerce_brand_id INT UNSIGNED NULL DEFAULT NULL AFTER publisher_category',
            'applicant_username' => "ALTER TABLE publisher_name_authority ADD COLUMN applicant_username VARCHAR(120) NOT NULL DEFAULT '' AFTER commerce_brand_id",
            'applicant_email' => "ALTER TABLE publisher_name_authority ADD COLUMN applicant_email VARCHAR(120) NOT NULL DEFAULT '' AFTER applicant_username",
            'registration_id' => "ALTER TABLE publisher_name_authority ADD COLUMN registration_id VARCHAR(40) NOT NULL DEFAULT '' AFTER legal_entity_name",
            'registration_country' => "ALTER TABLE publisher_name_authority ADD COLUMN registration_country VARCHAR(80) NOT NULL DEFAULT 'US' AFTER registration_id",
            'request_note' => 'ALTER TABLE publisher_name_authority ADD COLUMN request_note VARCHAR(500) NOT NULL DEFAULT \'\' AFTER authorized_contact_email',
            'reviewed_by_admin_id' => 'ALTER TABLE publisher_name_authority ADD COLUMN reviewed_by_admin_id INT UNSIGNED NULL DEFAULT NULL AFTER status',
            'reviewed_at' => 'ALTER TABLE publisher_name_authority ADD COLUMN reviewed_at DATETIME NULL DEFAULT NULL AFTER reviewed_by_admin_id',
            'review_note' => 'ALTER TABLE publisher_name_authority ADD COLUMN review_note VARCHAR(255) NOT NULL DEFAULT \'\' AFTER reviewed_at',
        ] as $column => $sql) {
            if (!publisher_db_column_exists($dbh, 'publisher_name_authority', $column)) {
                try {
                    $dbh->exec($sql);
                } catch (Throwable $e) {
                    // Non-fatal.
                }
            }
        }
    } catch (Throwable $e) {
        // Non-fatal — registration falls back to catalog-only names.
    }

    $migration = dirname(__DIR__, 2) . '/Data/migrations/20260709_commerce_seller_authority.sql';
    if (is_file($migration)) {
        require_once __DIR__ . '/msb_migrations.php';
        msb_run_sql_migration_file($dbh, $migration);
    }
}

function publisher_authority_payload_from_request(array $input): array
{
    return [
        'entity_type' => strtolower(trim((string)($input['entity_type'] ?? ''))),
        'legal_entity_name' => mb_substr(trim((string)($input['legal_entity_name'] ?? '')), 0, 200),
        'authorized_contact_name' => mb_substr(trim((string)($input['authorized_contact_name'] ?? '')), 0, 120),
        'authorized_contact_email' => mb_substr(trim((string)($input['authorized_contact_email'] ?? '')), 0, 120),
        'request_note' => mb_substr(trim((string)($input['request_note'] ?? '')), 0, 500),
        'authority_confirmed' => !empty($input['authority_confirmed']) && (string)$input['authority_confirmed'] === '1',
    ];
}

function publisher_authority_validate_payload(array $payload): array
{
    $errors = [];
    $types = publisher_authority_entity_types();

    if (!isset($types[$payload['entity_type'] ?? ''])) {
        $errors[] = 'Choose the type of organization you represent.';
    }

    if (trim((string)($payload['authorized_contact_name'] ?? '')) === '') {
        $errors[] = 'Enter the authorized representative name.';
    }

    $email = trim((string)($payload['authorized_contact_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid authorized representative email.';
    }

    if (empty($payload['authority_confirmed'])) {
        $errors[] = 'You must confirm that you are authorized to register this publisher name.';
    }

    return $errors;
}

function publisher_registry_is_catalog_name(string $name): bool
{
    $name = publisher_registry_normalize_name($name);
    if ($name === '') {
        return false;
    }

    foreach (publisher_registry_catalog_names() as $row) {
        if (mb_strtolower((string)($row['name'] ?? '')) === mb_strtolower($name)) {
            return true;
        }
    }

    return false;
}

function publisher_registry_requires_authority(PDO $dbh, string $name): bool
{
    return publisher_registry_normalize_name($name) !== '';
}

function publisher_authority_is_commerce_request(array $request): bool
{
    if ((int)($request['commerce_brand_id'] ?? 0) > 0) {
        return true;
    }
    return strtolower(trim((string)($request['publisher_category'] ?? ''))) === 'commerce';
}

function publisher_authority_is_commerce_seller_request(array $request): bool
{
    return (int)($request['commerce_brand_id'] ?? 0) > 0
        && strtolower(trim((string)($request['publisher_category'] ?? ''))) === 'commerce';
}

function publisher_authority_is_commerce_brand_name_request(array $request): bool
{
    return (int)($request['commerce_brand_id'] ?? 0) <= 0
        && strtolower(trim((string)($request['publisher_category'] ?? ''))) === 'commerce'
        && trim((string)($request['publisher_name'] ?? '')) !== '';
}

function publisher_authority_commerce_request_status(PDO $dbh, int $commerceBrandId, string $applicantEmail): string
{
    publisher_authority_ensure_schema($dbh);

    $commerceBrandId = max(0, $commerceBrandId);
    $applicantEmail = strtolower(trim($applicantEmail));
    if ($commerceBrandId <= 0 || $applicantEmail === '') {
        return 'none';
    }

    try {
        $st = $dbh->prepare('
            SELECT status
            FROM publisher_name_authority
            WHERE commerce_brand_id = :brand_id
              AND LOWER(applicant_email) = :email
            ORDER BY id DESC
            LIMIT 1
        ');
        $st->execute([
            ':brand_id' => $commerceBrandId,
            ':email' => $applicantEmail,
        ]);
        $status = strtolower(trim((string)($st->fetchColumn() ?: '')));
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            return $status;
        }
    } catch (Throwable $e) {
        // Non-fatal.
    }

    return 'none';
}

function publisher_authority_commerce_is_approved(PDO $dbh, int $commerceBrandId, string $applicantEmail): bool
{
    return publisher_authority_commerce_request_status($dbh, $commerceBrandId, $applicantEmail) === 'approved';
}

function publisher_authority_commerce_brand_name_request_status(PDO $dbh, string $brandName, string $applicantEmail): string
{
    $meta = publisher_authority_commerce_brand_name_request_meta($dbh, $brandName, $applicantEmail);
    return (string)($meta['status'] ?? 'none');
}

/** @return array{brand_id:int,status:string} */
function publisher_authority_commerce_brand_name_request_meta(PDO $dbh, string $brandName, string $applicantEmail): array
{
    publisher_authority_ensure_schema($dbh);

    $brandName = publisher_registry_normalize_name($brandName);
    $applicantEmail = strtolower(trim($applicantEmail));
    if ($brandName === '' || $applicantEmail === '') {
        return ['brand_id' => 0, 'status' => 'none'];
    }

    try {
        $st = $dbh->prepare('
            SELECT status, commerce_brand_id
            FROM publisher_name_authority
            WHERE LOWER(publisher_name) = LOWER(:name)
              AND publisher_category = \'commerce\'
              AND LOWER(applicant_email) = :email
            ORDER BY id DESC
            LIMIT 1
        ');
        $st->execute([
            ':name' => $brandName,
            ':email' => $applicantEmail,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $status = strtolower(trim((string)($row['status'] ?? '')));
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $status = 'none';
        }
        return [
            'brand_id' => (int)($row['commerce_brand_id'] ?? 0),
            'status' => $status,
        ];
    } catch (Throwable $e) {
        return ['brand_id' => 0, 'status' => 'none'];
    }
}

function publisher_authority_commerce_brand_name_is_approved(PDO $dbh, string $brandName, string $applicantEmail): bool
{
    return publisher_authority_commerce_brand_name_request_status($dbh, $brandName, $applicantEmail) === 'approved';
}

/** @return array{ok:bool,error?:string,messages?:list<string>,status?:string,name?:string,brand_id?:int,request_id?:int} */
function publisher_authority_submit_commerce_brand_name_request(
    PDO $dbh,
    string $brandName,
    string $username,
    string $email,
    array $payload
): array {
    publisher_authority_ensure_schema($dbh);
    require_once __DIR__ . '/org_commerce_brands.php';

    org_commerce_brands_ensure_schema($dbh);

    $brandName = publisher_registry_normalize_name($brandName);
    if ($brandName === '' || mb_strlen($brandName) < 2) {
        return ['ok' => false, 'error' => 'name_too_short', 'messages' => ['Enter a company or brand name (at least 2 characters).']];
    }

    if (org_commerce_brands_get_by_name($dbh, $brandName)) {
        return ['ok' => false, 'error' => 'already_exists', 'messages' => ['That brand is already in the list. Choose it from Commerce brand system instead.']];
    }

    $username = trim($username);
    $email = strtolower(trim($email));
    if ($username === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'invalid_account', 'messages' => ['Enter a valid username and email before submitting your brand request.']];
    }

    $authorityErrors = publisher_authority_validate_payload($payload);
    if ($authorityErrors !== []) {
        return ['ok' => false, 'error' => 'authority_invalid', 'messages' => $authorityErrors];
    }

    try {
        $stUser = $dbh->prepare('SELECT 1 FROM users WHERE LOWER(email) = :email OR LOWER(username) = :username LIMIT 1');
        $stUser->execute([':email' => $email, ':username' => strtolower($username)]);
        if ($stUser->fetchColumn()) {
            return ['ok' => false, 'error' => 'already_registered', 'messages' => ['That email or username is already registered. Sign in instead.']];
        }
    } catch (Throwable $e) {
        // continue
    }

    $existingStatus = publisher_authority_commerce_brand_name_request_status($dbh, $brandName, $email);
    if ($existingStatus === 'pending') {
        $meta = publisher_authority_commerce_brand_name_request_meta($dbh, $brandName, $email);
        return [
            'ok' => true,
            'name' => $brandName,
            'brand_id' => (int)($meta['brand_id'] ?? 0),
            'status' => 'pending',
        ];
    }
    if ($existingStatus === 'approved') {
        $meta = publisher_authority_commerce_brand_name_request_meta($dbh, $brandName, $email);
        return [
            'ok' => true,
            'name' => $brandName,
            'brand_id' => (int)($meta['brand_id'] ?? 0),
            'status' => 'approved',
        ];
    }

    try {
        $st = $dbh->prepare('
            INSERT INTO publisher_name_authority (
                publisher_name_option_id,
                publisher_name,
                publisher_category,
                commerce_brand_id,
                applicant_username,
                applicant_email,
                entity_type,
                legal_entity_name,
                authorized_contact_name,
                authorized_contact_email,
                request_note,
                authority_confirmed,
                status,
                created_at
            ) VALUES (
                NULL,
                :publisher_name,
                :publisher_category,
                NULL,
                :applicant_username,
                :applicant_email,
                :entity_type,
                :legal_entity_name,
                :authorized_contact_name,
                :authorized_contact_email,
                :request_note,
                :authority_confirmed,
                :status,
                NOW()
            )
        ');
        $st->execute([
            ':publisher_name' => $brandName,
            ':publisher_category' => 'commerce',
            ':applicant_username' => mb_substr($username, 0, 120),
            ':applicant_email' => mb_substr($email, 0, 120),
            ':entity_type' => (string)($payload['entity_type'] ?? 'business'),
            ':legal_entity_name' => (string)($payload['legal_entity_name'] ?? ''),
            ':authorized_contact_name' => (string)($payload['authorized_contact_name'] ?? ''),
            ':authorized_contact_email' => (string)($payload['authorized_contact_email'] ?? ''),
            ':request_note' => (string)($payload['request_note'] ?? ''),
            ':authority_confirmed' => !empty($payload['authority_confirmed']) ? 1 : 0,
            ':status' => 'pending',
        ]);

        return [
            'ok' => true,
            'name' => $brandName,
            'brand_id' => 0,
            'status' => 'pending',
            'request_id' => (int)$dbh->lastInsertId(),
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'save_failed'];
    }
}

/** @return array{ok:bool,error?:string,messages?:list<string>,status?:string,name?:string,brand_id?:int,request_id?:int} */
function publisher_authority_submit_commerce_request(
    PDO $dbh,
    int $commerceBrandId,
    string $username,
    string $email,
    array $payload
): array {
    publisher_authority_ensure_schema($dbh);
    require_once __DIR__ . '/org_commerce_brands.php';

    org_commerce_brands_ensure_schema($dbh);
    $brand = org_commerce_brands_get($dbh, $commerceBrandId);
    if (!$brand) {
        return ['ok' => false, 'error' => 'invalid_brand'];
    }

    $username = trim($username);
    $email = strtolower(trim($email));
    if ($username === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'invalid_account', 'messages' => ['Enter a valid username and email before submitting your seller request.']];
    }

    $authorityErrors = publisher_authority_validate_payload($payload);
    if ($authorityErrors !== []) {
        return ['ok' => false, 'error' => 'authority_invalid', 'messages' => $authorityErrors];
    }

    try {
        $stUser = $dbh->prepare('SELECT 1 FROM users WHERE LOWER(email) = :email OR LOWER(username) = :username LIMIT 1');
        $stUser->execute([':email' => $email, ':username' => strtolower($username)]);
        if ($stUser->fetchColumn()) {
            return ['ok' => false, 'error' => 'already_registered', 'messages' => ['That email or username is already registered. Sign in instead.']];
        }
    } catch (Throwable $e) {
        // continue
    }

    $brandName = publisher_registry_normalize_name((string)($brand['name'] ?? ''));
    $existingStatus = publisher_authority_commerce_request_status($dbh, $commerceBrandId, $email);
    if ($existingStatus === 'pending') {
        return [
            'ok' => true,
            'name' => $brandName,
            'brand_id' => $commerceBrandId,
            'status' => 'pending',
        ];
    }
    if ($existingStatus === 'approved') {
        return [
            'ok' => true,
            'name' => $brandName,
            'brand_id' => $commerceBrandId,
            'status' => 'approved',
        ];
    }

    try {
        $st = $dbh->prepare('
            INSERT INTO publisher_name_authority (
                publisher_name_option_id,
                publisher_name,
                publisher_category,
                commerce_brand_id,
                applicant_username,
                applicant_email,
                entity_type,
                legal_entity_name,
                authorized_contact_name,
                authorized_contact_email,
                request_note,
                authority_confirmed,
                status,
                created_at
            ) VALUES (
                NULL,
                :publisher_name,
                :publisher_category,
                :commerce_brand_id,
                :applicant_username,
                :applicant_email,
                :entity_type,
                :legal_entity_name,
                :authorized_contact_name,
                :authorized_contact_email,
                :request_note,
                :authority_confirmed,
                :status,
                NOW()
            )
        ');
        $st->execute([
            ':publisher_name' => $brandName,
            ':publisher_category' => 'commerce',
            ':commerce_brand_id' => $commerceBrandId,
            ':applicant_username' => mb_substr($username, 0, 120),
            ':applicant_email' => mb_substr($email, 0, 120),
            ':entity_type' => (string)($payload['entity_type'] ?? 'business'),
            ':legal_entity_name' => (string)($payload['legal_entity_name'] ?? ''),
            ':authorized_contact_name' => (string)($payload['authorized_contact_name'] ?? ''),
            ':authorized_contact_email' => (string)($payload['authorized_contact_email'] ?? ''),
            ':request_note' => (string)($payload['request_note'] ?? ''),
            ':authority_confirmed' => !empty($payload['authority_confirmed']) ? 1 : 0,
            ':status' => 'pending',
        ]);

        return [
            'ok' => true,
            'name' => $brandName,
            'brand_id' => $commerceBrandId,
            'status' => 'pending',
            'request_id' => (int)$dbh->lastInsertId(),
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'save_failed'];
    }
}

function publisher_authority_request_status(PDO $dbh, string $publisherName): string
{
    publisher_authority_ensure_schema($dbh);

    $publisherName = publisher_registry_normalize_name($publisherName);
    if ($publisherName === '') {
        return 'none';
    }

    try {
        $st = $dbh->prepare('
            SELECT status
            FROM publisher_name_authority
            WHERE LOWER(publisher_name) = LOWER(:name)
            ORDER BY id DESC
            LIMIT 1
        ');
        $st->execute([':name' => $publisherName]);
        $status = strtolower(trim((string)($st->fetchColumn() ?: '')));
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            return $status;
        }
    } catch (Throwable $e) {
        // Non-fatal.
    }

    return 'none';
}

function publisher_authority_is_approved(PDO $dbh, string $publisherName): bool
{
    return publisher_authority_request_status($dbh, $publisherName) === 'approved';
}

/** @deprecated Use publisher_authority_is_approved() */
function publisher_authority_has_proof(PDO $dbh, string $publisherName): bool
{
    return publisher_authority_is_approved($dbh, $publisherName);
}

function publisher_authority_pending_count(PDO $dbh): int
{
    publisher_authority_ensure_schema($dbh);

    try {
        $st = $dbh->query("SELECT COUNT(*) FROM publisher_name_authority WHERE status = 'pending'");
        return (int)($st ? $st->fetchColumn() : 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function publisher_authority_submit_request(PDO $dbh, string $publisherName, string $category, array $payload): array
{
    publisher_authority_ensure_schema($dbh);

    $publisherName = publisher_registry_normalize_name($publisherName);
    if ($publisherName === '') {
        return ['ok' => false, 'error' => 'empty_name'];
    }
    if (mb_strlen($publisherName) < 2) {
        return ['ok' => false, 'error' => 'name_too_short'];
    }

    $category = strtolower(trim($category));
    if (!isset(publisher_categories()[$category])) {
        $category = 'news';
    }

    if (publisher_registry_name_is_registered($dbh, $publisherName)) {
        return ['ok' => false, 'error' => 'already_registered'];
    }

    $authorityErrors = publisher_authority_validate_payload($payload);
    if ($authorityErrors !== []) {
        return ['ok' => false, 'error' => 'authority_invalid', 'messages' => $authorityErrors];
    }

    $existingStatus = publisher_authority_request_status($dbh, $publisherName);
    if ($existingStatus === 'pending') {
        return [
            'ok' => true,
            'name' => $publisherName,
            'category' => $category,
            'source' => publisher_registry_is_catalog_name($publisherName) ? 'catalog' : 'custom',
            'status' => 'pending',
        ];
    }
    if ($existingStatus === 'approved') {
        return [
            'ok' => true,
            'name' => $publisherName,
            'category' => $category,
            'source' => publisher_registry_is_catalog_name($publisherName) ? 'catalog' : 'custom',
            'status' => 'approved',
        ];
    }

    try {
        $st = $dbh->prepare('
            INSERT INTO publisher_name_authority (
                publisher_name_option_id,
                publisher_name,
                publisher_category,
                entity_type,
                legal_entity_name,
                authorized_contact_name,
                authorized_contact_email,
                request_note,
                authority_confirmed,
                status,
                created_at
            ) VALUES (
                NULL,
                :publisher_name,
                :publisher_category,
                :entity_type,
                :legal_entity_name,
                :authorized_contact_name,
                :authorized_contact_email,
                :request_note,
                :authority_confirmed,
                :status,
                NOW()
            )
        ');
        $st->execute([
            ':publisher_name' => $publisherName,
            ':publisher_category' => $category,
            ':entity_type' => (string)($payload['entity_type'] ?? 'business'),
            ':legal_entity_name' => (string)($payload['legal_entity_name'] ?? ''),
            ':authorized_contact_name' => (string)($payload['authorized_contact_name'] ?? ''),
            ':authorized_contact_email' => (string)($payload['authorized_contact_email'] ?? ''),
            ':request_note' => (string)($payload['request_note'] ?? ''),
            ':authority_confirmed' => !empty($payload['authority_confirmed']) ? 1 : 0,
            ':status' => 'pending',
        ]);

        return [
            'ok' => true,
            'name' => $publisherName,
            'category' => $category,
            'source' => publisher_registry_is_catalog_name($publisherName) ? 'catalog' : 'custom',
            'status' => 'pending',
            'request_id' => (int)$dbh->lastInsertId(),
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'save_failed'];
    }
}

function publisher_authority_fetch_latest_for_name(PDO $dbh, string $publisherName): ?array
{
    publisher_authority_ensure_schema($dbh);

    $publisherName = publisher_registry_normalize_name($publisherName);
    if ($publisherName === '') {
        return null;
    }

    try {
        $st = $dbh->prepare('
            SELECT *
            FROM publisher_name_authority
            WHERE LOWER(publisher_name) = LOWER(:name)
            ORDER BY id DESC
            LIMIT 1
        ');
        $st->execute([':name' => $publisherName]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function publisher_authority_fetch_request(PDO $dbh, int $requestId): ?array
{
    publisher_authority_ensure_schema($dbh);
    if ($requestId <= 0) {
        return null;
    }

    try {
        $st = $dbh->prepare('SELECT * FROM publisher_name_authority WHERE id = :id LIMIT 1');
        $st->execute([':id' => $requestId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function publisher_authority_admin_list(PDO $dbh, string $status = 'pending'): array
{
    publisher_authority_ensure_schema($dbh);

    $status = strtolower(trim($status));
    if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
        $status = 'pending';
    }

    try {
        $sql = 'SELECT * FROM publisher_name_authority';
        $params = [];
        if ($status !== 'all') {
            $sql .= ' WHERE status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC';

        $st = $dbh->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function publisher_authority_create_option_for_request(PDO $dbh, array $request, bool $linkOrganization = false): int
{
    $name = publisher_registry_normalize_name((string)($request['publisher_name'] ?? ''));
    $category = strtolower(trim((string)($request['publisher_category'] ?? 'news')));
    if (!isset(publisher_categories()[$category])) {
        $category = 'news';
    }
    if ($name === '') {
        return 0;
    }

    $st = $dbh->prepare('SELECT id FROM publisher_name_options WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $st->execute([':name' => $name]);
    $optionId = (int)($st->fetchColumn() ?: 0);
    if ($optionId > 0) {
        if ($linkOrganization) {
            require_once __DIR__ . '/publisher_organization_bridge.php';
            publisher_org_ensure_for_publisher_name($dbh, $name, $category);
        }
        return $optionId;
    }

    $ins = $dbh->prepare('
        INSERT INTO publisher_name_options (name, category, created_at)
        VALUES (:name, :category, NOW())
    ');
    $ins->execute([
        ':name' => $name,
        ':category' => $category,
    ]);

    $optionId = (int)$dbh->lastInsertId();
    if ($optionId > 0 && $linkOrganization) {
        require_once __DIR__ . '/publisher_organization_bridge.php';
        publisher_org_ensure_for_publisher_name($dbh, $name, $category);
    }

    return $optionId;
}

/** Approve a commerce seller request — no shared publisher name option row. */
function publisher_authority_admin_approve_commerce_brand_name(PDO $dbh, array $request, int $adminId, string $reviewNote = ''): array
{
    require_once __DIR__ . '/org_commerce_brands.php';

    $currentStatus = strtolower((string)($request['status'] ?? ''));
    $requestId = (int)($request['id'] ?? 0);
    $brandName = publisher_registry_normalize_name((string)($request['publisher_name'] ?? ''));

    if ($requestId <= 0 || $brandName === '') {
        return ['ok' => false, 'error' => 'not_found', 'message' => 'Request not found.'];
    }

    if ($currentStatus === 'approved') {
        $brandId = (int)($request['commerce_brand_id'] ?? 0);
        if ($brandId <= 0) {
            $brandId = (int)(org_commerce_brands_get_by_name($dbh, $brandName)['id'] ?? 0);
        }
        return [
            'ok' => true,
            'name' => $brandName,
            'brand_id' => $brandId,
            'repaired' => true,
            'message' => 'Commerce brand request already approved.',
        ];
    }

    if ($currentStatus !== 'pending') {
        return ['ok' => false, 'error' => 'not_pending', 'message' => 'Only pending requests can be approved.'];
    }

    $email = strtolower(trim((string)($request['applicant_email'] ?? '')));
    if ($email !== '') {
        try {
            $st = $dbh->prepare('SELECT 1 FROM users WHERE LOWER(email) = :email LIMIT 1');
            $st->execute([':email' => $email]);
            if ($st->fetchColumn()) {
                return ['ok' => false, 'error' => 'already_registered', 'message' => 'This applicant email already has an account.'];
            }
        } catch (Throwable $e) {
            // continue
        }
    }

    $tagline = trim((string)($request['request_note'] ?? ''));
    $brandId = org_commerce_brands_create($dbh, $brandName, $tagline);
    if ($brandId <= 0) {
        return ['ok' => false, 'error' => 'brand_create_failed', 'message' => 'Could not create the commerce brand system.'];
    }

    try {
        $st = $dbh->prepare('
            UPDATE publisher_name_authority
            SET status = :status,
                commerce_brand_id = :brand_id,
                reviewed_by_admin_id = :admin_id,
                reviewed_at = NOW(),
                review_note = :review_note
            WHERE id = :id
              AND status = \'pending\'
            LIMIT 1
        ');
        $st->execute([
            ':status' => 'approved',
            ':brand_id' => $brandId,
            ':admin_id' => $adminId > 0 ? $adminId : null,
            ':review_note' => mb_substr(trim($reviewNote), 0, 255),
            ':id' => $requestId,
        ]);

        if ($st->rowCount() <= 0) {
            return ['ok' => false, 'error' => 'approve_failed', 'message' => 'Could not approve commerce brand request.'];
        }

        return ['ok' => true, 'name' => $brandName, 'brand_id' => $brandId, 'message' => 'Approved commerce brand: ' . $brandName];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'approve_failed', 'message' => 'Could not approve commerce brand request.'];
    }
}

/** Approve a commerce seller request — no shared publisher name option row. */
function publisher_authority_admin_approve_commerce(PDO $dbh, array $request, int $adminId, string $reviewNote = ''): array
{
    $currentStatus = strtolower((string)($request['status'] ?? ''));
    $requestId = (int)($request['id'] ?? 0);
    $brandName = publisher_registry_normalize_name((string)($request['publisher_name'] ?? ''));

    if ($requestId <= 0) {
        return ['ok' => false, 'error' => 'not_found', 'message' => 'Request not found.'];
    }

    if ($currentStatus === 'approved') {
        return ['ok' => true, 'name' => $brandName, 'repaired' => true, 'message' => 'Commerce seller request already approved.'];
    }

    if ($currentStatus !== 'pending') {
        return ['ok' => false, 'error' => 'not_pending', 'message' => 'Only pending requests can be approved.'];
    }

    $email = strtolower(trim((string)($request['applicant_email'] ?? '')));
    if ($email !== '') {
        try {
            $st = $dbh->prepare('SELECT 1 FROM users WHERE LOWER(email) = :email LIMIT 1');
            $st->execute([':email' => $email]);
            if ($st->fetchColumn()) {
                return ['ok' => false, 'error' => 'already_registered', 'message' => 'This applicant email already has an account.'];
            }
        } catch (Throwable $e) {
            // continue
        }
    }

    try {
        $st = $dbh->prepare('
            UPDATE publisher_name_authority
            SET status = :status,
                reviewed_by_admin_id = :admin_id,
                reviewed_at = NOW(),
                review_note = :review_note
            WHERE id = :id
              AND status = \'pending\'
            LIMIT 1
        ');
        $st->execute([
            ':status' => 'approved',
            ':admin_id' => $adminId > 0 ? $adminId : null,
            ':review_note' => mb_substr(trim($reviewNote), 0, 255),
            ':id' => $requestId,
        ]);

        if ($st->rowCount() <= 0) {
            return ['ok' => false, 'error' => 'approve_failed', 'message' => 'Could not approve commerce seller request.'];
        }

        return ['ok' => true, 'name' => $brandName];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'approve_failed', 'message' => 'Could not approve commerce seller request.'];
    }
}

function publisher_authority_admin_approve(PDO $dbh, int $requestId, int $adminId, string $reviewNote = ''): array
{
    publisher_authority_ensure_schema($dbh);

    $request = publisher_authority_fetch_request($dbh, $requestId);
    if (!$request) {
        return ['ok' => false, 'error' => 'not_found', 'message' => 'Request not found.'];
    }

    if (publisher_authority_is_commerce_seller_request($request)) {
        return publisher_authority_admin_approve_commerce($dbh, $request, $adminId, $reviewNote);
    }

    if (publisher_authority_is_commerce_brand_name_request($request)) {
        return publisher_authority_admin_approve_commerce_brand_name($dbh, $request, $adminId, $reviewNote);
    }

    $currentStatus = strtolower((string)($request['status'] ?? ''));
    $name = publisher_registry_normalize_name((string)($request['publisher_name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'error' => 'invalid_name', 'message' => 'Invalid publisher name.'];
    }

    if ($currentStatus === 'approved') {
        $optionId = publisher_authority_create_option_for_request($dbh, $request, true);
        if ($optionId > 0) {
            return ['ok' => true, 'name' => $name, 'option_id' => $optionId, 'repaired' => true];
        }
        return ['ok' => false, 'error' => 'repair_failed', 'message' => 'Request is approved but the publisher name could not be restored.'];
    }

    if ($currentStatus !== 'pending') {
        return ['ok' => false, 'error' => 'not_pending', 'message' => 'Only pending requests can be approved.'];
    }

    if (publisher_registry_name_is_registered($dbh, $name)) {
        return ['ok' => false, 'error' => 'already_registered', 'message' => 'A publisher account already uses this name.'];
    }

    try {
        $dbh->beginTransaction();

        $optionId = publisher_authority_create_option_for_request($dbh, $request, false);
        if ($optionId <= 0) {
            throw new RuntimeException('option_create_failed');
        }

        $st = $dbh->prepare('
            UPDATE publisher_name_authority
            SET status = :status,
                publisher_name_option_id = :option_id,
                reviewed_by_admin_id = :admin_id,
                reviewed_at = NOW(),
                review_note = :review_note
            WHERE id = :id
              AND status = \'pending\'
            LIMIT 1
        ');
        $st->execute([
            ':status' => 'approved',
            ':option_id' => $optionId,
            ':admin_id' => $adminId > 0 ? $adminId : null,
            ':review_note' => mb_substr(trim($reviewNote), 0, 255),
            ':id' => $requestId,
        ]);

        if ($st->rowCount() <= 0) {
            throw new RuntimeException('request_update_failed');
        }

        $dbh->commit();

        $category = strtolower(trim((string)($request['publisher_category'] ?? 'news')));
        if (!isset(publisher_categories()[$category])) {
            $category = 'news';
        }
        publisher_authority_create_option_for_request($dbh, $request, true);

        return [
            'ok' => true,
            'name' => $name,
            'option_id' => $optionId,
        ];
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        return ['ok' => false, 'error' => 'approve_failed', 'message' => $e->getMessage()];
    }
}

function publisher_authority_admin_reject(PDO $dbh, int $requestId, int $adminId, string $reviewNote = ''): array
{
    publisher_authority_ensure_schema($dbh);

    $request = publisher_authority_fetch_request($dbh, $requestId);
    if (!$request) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    if (strtolower((string)($request['status'] ?? '')) !== 'pending') {
        return ['ok' => false, 'error' => 'not_pending'];
    }

    try {
        $st = $dbh->prepare('
            UPDATE publisher_name_authority
            SET status = :status,
                reviewed_by_admin_id = :admin_id,
                reviewed_at = NOW(),
                review_note = :review_note
            WHERE id = :id
              AND status = \'pending\'
            LIMIT 1
        ');
        $st->execute([
            ':status' => 'rejected',
            ':admin_id' => $adminId > 0 ? $adminId : null,
            ':review_note' => mb_substr(trim($reviewNote), 0, 255),
            ':id' => $requestId,
        ]);

        return ['ok' => $st->rowCount() > 0];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'reject_failed'];
    }
}
