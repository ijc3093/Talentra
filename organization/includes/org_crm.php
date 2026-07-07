<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/../public_user/includes/platform_rent.php';

function org_crm_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $migration = dirname(__DIR__, 2) . '/Data/migrations/20260706_org_crm.sql';
    if (!is_file($migration)) {
        return;
    }
    try {
        $sql = (string)file_get_contents($migration);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt === '' || stripos($stmt, 'SET ') === 0) {
                continue;
            }
            $dbh->exec($stmt);
        }
    } catch (Throwable $e) {
        // tables may already exist
    }
}

function org_crm_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function org_crm_money(int $cents, string $currency = 'USD'): string
{
    return platform_rent_format_money($cents, $currency);
}

function org_crm_gen_ticket_code(int $orgId): string
{
    return 'TKT-' . $orgId . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function org_crm_dashboard_stats(PDO $dbh, int $orgId): array
{
    org_crm_ensure_schema($dbh);
    $stats = [
        'contacts' => 0,
        'leads' => 0,
        'customers' => 0,
        'open_tickets' => 0,
        'open_deals' => 0,
        'pipeline_cents' => 0,
        'forecast_cents' => 0,
        'won_mtd_cents' => 0,
    ];
    if ($orgId <= 0) {
        return $stats;
    }
    try {
        $st = $dbh->prepare("SELECT COUNT(*) FROM org_crm_contacts WHERE org_id = :org AND is_deleted = 0");
        $st->execute([':org' => $orgId]);
        $stats['contacts'] = (int)($st->fetchColumn() ?: 0);

        $st = $dbh->prepare("SELECT COUNT(*) FROM org_crm_contacts WHERE org_id = :org AND is_deleted = 0 AND lifecycle_stage IN ('lead','prospect')");
        $st->execute([':org' => $orgId]);
        $stats['leads'] = (int)($st->fetchColumn() ?: 0);

        $st = $dbh->prepare("SELECT COUNT(*) FROM org_crm_contacts WHERE org_id = :org AND is_deleted = 0 AND lifecycle_stage = 'customer'");
        $st->execute([':org' => $orgId]);
        $stats['customers'] = (int)($st->fetchColumn() ?: 0);

        $st = $dbh->prepare("SELECT COUNT(*) FROM org_crm_tickets WHERE org_id = :org AND status IN ('open','pending')");
        $st->execute([':org' => $orgId]);
        $stats['open_tickets'] = (int)($st->fetchColumn() ?: 0);

        $st = $dbh->prepare("SELECT COUNT(*) FROM org_crm_deals WHERE org_id = :org AND is_deleted = 0 AND stage NOT IN ('won','lost')");
        $st->execute([':org' => $orgId]);
        $stats['open_deals'] = (int)($st->fetchColumn() ?: 0);

        $st = $dbh->prepare("SELECT COALESCE(SUM(amount_cents),0) FROM org_crm_deals WHERE org_id = :org AND is_deleted = 0 AND stage NOT IN ('won','lost')");
        $st->execute([':org' => $orgId]);
        $stats['pipeline_cents'] = (int)($st->fetchColumn() ?: 0);

        $st = $dbh->prepare("SELECT COALESCE(SUM(amount_cents * probability / 100),0) FROM org_crm_deals WHERE org_id = :org AND is_deleted = 0 AND stage NOT IN ('won','lost')");
        $st->execute([':org' => $orgId]);
        $stats['forecast_cents'] = (int)round((float)($st->fetchColumn() ?: 0));

        $st = $dbh->prepare("SELECT COALESCE(SUM(amount_cents),0) FROM org_crm_deals WHERE org_id = :org AND is_deleted = 0 AND stage = 'won' AND closed_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
        $st->execute([':org' => $orgId]);
        $stats['won_mtd_cents'] = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        // ignore
    }
    return $stats;
}

/** @return list<array<string, mixed>> */
function org_crm_list_contacts(PDO $dbh, int $orgId, string $stage = 'all', string $q = '', int $limit = 200): array
{
    org_crm_ensure_schema($dbh);
    if ($orgId <= 0) {
        return [];
    }
    $where = ['org_id = :org', 'is_deleted = 0'];
    $params = [':org' => $orgId];
    if ($stage !== '' && $stage !== 'all') {
        $where[] = 'lifecycle_stage = :stage';
        $params[':stage'] = $stage;
    }
    $q = trim($q);
    if ($q !== '') {
        $where[] = '(full_name LIKE :q OR email LIKE :q OR phone LIKE :q OR company LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    $limit = max(1, min($limit, 500));
    $sql = 'SELECT * FROM org_crm_contacts WHERE ' . implode(' AND ', $where) . ' ORDER BY updated_at DESC, id DESC LIMIT ' . $limit;
    try {
        $st = $dbh->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function org_crm_get_contact(PDO $dbh, int $orgId, int $contactId): ?array
{
    if ($orgId <= 0 || $contactId <= 0) {
        return null;
    }
    org_crm_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT * FROM org_crm_contacts WHERE id = :id AND org_id = :org AND is_deleted = 0 LIMIT 1');
        $st->execute([':id' => $contactId, ':org' => $orgId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function org_crm_save_contact(PDO $dbh, int $orgId, array $data, ?int $contactId = null, int $memberId = 0): array
{
    org_crm_ensure_schema($dbh);
    if ($orgId <= 0) {
        return ['ok' => false, 'error' => 'Invalid organization.'];
    }
    $name = trim((string)($data['full_name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'error' => 'Name is required.'];
    }
    $stage = strtolower(trim((string)($data['lifecycle_stage'] ?? 'lead')));
    $allowedStages = ['lead', 'prospect', 'customer', 'partner', 'churned'];
    if (!in_array($stage, $allowedStages, true)) {
        $stage = 'lead';
    }
    $source = strtolower(trim((string)($data['lead_source'] ?? 'manual')));
    $allowedSources = ['manual', 'shop', 'referral', 'web', 'portal', 'phone', 'import', 'other'];
    if (!in_array($source, $allowedSources, true)) {
        $source = 'manual';
    }

    $fields = [
        ':name' => mb_substr($name, 0, 120),
        ':email' => trim((string)($data['email'] ?? '')) ?: null,
        ':phone' => trim((string)($data['phone'] ?? '')) ?: null,
        ':company' => trim((string)($data['company'] ?? '')) ?: null,
        ':title' => trim((string)($data['job_title'] ?? '')) ?: null,
        ':stage' => $stage,
        ':source' => $source,
        ':tags' => trim((string)($data['tags'] ?? '')) ?: null,
        ':notes' => trim((string)($data['notes'] ?? '')) ?: null,
        ':assigned' => (int)($data['assigned_member_id'] ?? 0) > 0 ? (int)$data['assigned_member_id'] : null,
        ':uid' => (int)($data['linked_user_id'] ?? 0) > 0 ? (int)$data['linked_user_id'] : null,
        ':org' => $orgId,
    ];

    try {
        if ($contactId > 0) {
            $fields[':id'] = $contactId;
            $st = $dbh->prepare('
                UPDATE org_crm_contacts SET
                    full_name = :name, email = :email, phone = :phone, company = :company,
                    job_title = :title, lifecycle_stage = :stage, lead_source = :source,
                    tags = :tags, notes = :notes, assigned_member_id = :assigned,
                    linked_user_id = :uid, updated_at = NOW()
                WHERE id = :id AND org_id = :org LIMIT 1
            ');
            $st->execute($fields);
            return ['ok' => true, 'contact_id' => $contactId];
        }

        $st = $dbh->prepare('
            INSERT INTO org_crm_contacts (
                org_id, linked_user_id, full_name, email, phone, company, job_title,
                lifecycle_stage, lead_source, tags, notes, assigned_member_id,
                created_by_member_id, created_at, updated_at, is_deleted
            ) VALUES (
                :org, :uid, :name, :email, :phone, :company, :title,
                :stage, :source, :tags, :notes, :assigned,
                :member, NOW(), NOW(), 0
            )
        ');
        $st->execute($fields + [':member' => $memberId > 0 ? $memberId : null]);
        return ['ok' => true, 'contact_id' => (int)$dbh->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save contact.'];
    }
}

function org_crm_log_interaction(PDO $dbh, int $orgId, int $contactId, int $memberId, string $type, string $subject, string $body, ?int $orderId = null, ?int $ticketId = null): bool
{
    org_crm_ensure_schema($dbh);
    $type = strtolower(trim($type));
    $allowed = ['note', 'call', 'email', 'meeting', 'shop_order', 'ticket', 'task'];
    if (!in_array($type, $allowed, true)) {
        $type = 'note';
    }
    try {
        $st = $dbh->prepare('
            INSERT INTO org_crm_interactions (
                org_id, contact_id, member_id, interaction_type, subject, body,
                related_order_id, related_ticket_id, created_at
            ) VALUES (
                :org, :cid, :mid, :type, :sub, :body, :oid, :tid, NOW()
            )
        ');
        $st->execute([
            ':org' => $orgId,
            ':cid' => $contactId,
            ':mid' => $memberId > 0 ? $memberId : null,
            ':type' => $type,
            ':sub' => $subject !== '' ? $subject : null,
            ':body' => $body !== '' ? $body : null,
            ':oid' => $orderId,
            ':tid' => $ticketId,
        ]);
        $dbh->prepare('UPDATE org_crm_contacts SET last_contacted_at = NOW(), updated_at = NOW() WHERE id = :id AND org_id = :org LIMIT 1')
            ->execute([':id' => $contactId, ':org' => $orgId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<array<string, mixed>> */
function org_crm_list_interactions(PDO $dbh, int $orgId, int $contactId, int $limit = 50): array
{
    org_crm_ensure_schema($dbh);
    if ($orgId <= 0 || $contactId <= 0) {
        return [];
    }
    $limit = max(1, min($limit, 100));
    try {
        $st = $dbh->prepare("SELECT * FROM org_crm_interactions WHERE org_id = :org AND contact_id = :cid ORDER BY created_at DESC, id DESC LIMIT {$limit}");
        $st->execute([':org' => $orgId, ':cid' => $contactId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array<string, mixed>> */
function org_crm_list_tickets(PDO $dbh, int $orgId, string $status = 'all', int $limit = 150): array
{
    org_crm_ensure_schema($dbh);
    if ($orgId <= 0) {
        return [];
    }
    $where = ['t.org_id = :org'];
    $params = [':org' => $orgId];
    if ($status !== '' && $status !== 'all') {
        $where[] = 't.status = :status';
        $params[':status'] = $status;
    }
    $limit = max(1, min($limit, 300));
    $sql = "
        SELECT t.*, c.full_name AS contact_name
        FROM org_crm_tickets t
        LEFT JOIN org_crm_contacts c ON c.id = t.contact_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.updated_at DESC, t.id DESC
        LIMIT {$limit}
    ";
    try {
        $st = $dbh->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function org_crm_get_ticket(PDO $dbh, int $orgId, int $ticketId): ?array
{
    if ($orgId <= 0 || $ticketId <= 0) {
        return null;
    }
    org_crm_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT * FROM org_crm_tickets WHERE id = :id AND org_id = :org LIMIT 1');
        $st->execute([':id' => $ticketId, ':org' => $orgId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function org_crm_create_ticket(PDO $dbh, int $orgId, array $data, int $memberId = 0): array
{
    org_crm_ensure_schema($dbh);
    $subject = trim((string)($data['subject'] ?? ''));
    if ($subject === '') {
        return ['ok' => false, 'error' => 'Subject is required.'];
    }
    $priority = strtolower(trim((string)($data['priority'] ?? 'normal')));
    if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
        $priority = 'normal';
    }
    $code = org_crm_gen_ticket_code($orgId);
    try {
        $st = $dbh->prepare('
            INSERT INTO org_crm_tickets (
                org_id, contact_id, ticket_code, subject, description, status, priority,
                assigned_member_id, requester_name, requester_email, created_by_member_id,
                created_at, updated_at
            ) VALUES (
                :org, :cid, :code, :sub, :desc, \'open\', :pri,
                :assigned, :rname, :remail, :member, NOW(), NOW()
            )
        ');
        $st->execute([
            ':org' => $orgId,
            ':cid' => (int)($data['contact_id'] ?? 0) > 0 ? (int)$data['contact_id'] : null,
            ':code' => $code,
            ':sub' => mb_substr($subject, 0, 200),
            ':desc' => trim((string)($data['description'] ?? '')) ?: null,
            ':pri' => $priority,
            ':assigned' => (int)($data['assigned_member_id'] ?? 0) > 0 ? (int)$data['assigned_member_id'] : null,
            ':rname' => trim((string)($data['requester_name'] ?? '')) ?: null,
            ':remail' => trim((string)($data['requester_email'] ?? '')) ?: null,
            ':member' => $memberId > 0 ? $memberId : null,
        ]);
        $ticketId = (int)$dbh->lastInsertId();
        $contactId = (int)($data['contact_id'] ?? 0);
        if ($contactId > 0) {
            org_crm_log_interaction($dbh, $orgId, $contactId, $memberId, 'ticket', $subject, (string)($data['description'] ?? ''), null, $ticketId);
        }
        return ['ok' => true, 'ticket_id' => $ticketId, 'ticket_code' => $code];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not create ticket.'];
    }
}

function org_crm_update_ticket(PDO $dbh, int $orgId, int $ticketId, string $status, string $priority = ''): bool
{
    org_crm_ensure_schema($dbh);
    $allowed = ['open', 'pending', 'resolved', 'closed'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }
    $sql = 'UPDATE org_crm_tickets SET status = :st, updated_at = NOW()';
    $params = [':st' => $status, ':id' => $ticketId, ':org' => $orgId];
    if ($priority !== '' && in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
        $sql .= ', priority = :pri';
        $params[':pri'] = $priority;
    }
    if (in_array($status, ['resolved', 'closed'], true)) {
        $sql .= ', resolved_at = NOW()';
    }
    $sql .= ' WHERE id = :id AND org_id = :org LIMIT 1';
    try {
        $st = $dbh->prepare($sql);
        $st->execute($params);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function org_crm_add_ticket_reply(PDO $dbh, int $orgId, int $ticketId, int $memberId, string $body, bool $internal = false): bool
{
    org_crm_ensure_schema($dbh);
    $body = trim($body);
    if ($body === '') {
        return false;
    }
    try {
        $st = $dbh->prepare('
            INSERT INTO org_crm_ticket_replies (org_id, ticket_id, member_id, body, is_internal, created_at)
            VALUES (:org, :tid, :mid, :body, :int, NOW())
        ');
        $st->execute([
            ':org' => $orgId,
            ':tid' => $ticketId,
            ':mid' => $memberId > 0 ? $memberId : null,
            ':body' => $body,
            ':int' => $internal ? 1 : 0,
        ]);
        $dbh->prepare('UPDATE org_crm_tickets SET updated_at = NOW() WHERE id = :id AND org_id = :org LIMIT 1')
            ->execute([':id' => $ticketId, ':org' => $orgId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<array<string, mixed>> */
function org_crm_list_ticket_replies(PDO $dbh, int $orgId, int $ticketId): array
{
    org_crm_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT * FROM org_crm_ticket_replies WHERE org_id = :org AND ticket_id = :tid ORDER BY created_at ASC, id ASC');
        $st->execute([':org' => $orgId, ':tid' => $ticketId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array<string, mixed>> */
function org_crm_list_deals(PDO $dbh, int $orgId, string $stage = 'all', int $limit = 200): array
{
    org_crm_ensure_schema($dbh);
    if ($orgId <= 0) {
        return [];
    }
    $where = ['d.org_id = :org', 'd.is_deleted = 0'];
    $params = [':org' => $orgId];
    if ($stage !== '' && $stage !== 'all') {
        $where[] = 'd.stage = :stage';
        $params[':stage'] = $stage;
    }
    $limit = max(1, min($limit, 300));
    $sql = "
        SELECT d.*, c.full_name AS contact_name
        FROM org_crm_deals d
        LEFT JOIN org_crm_contacts c ON c.id = d.contact_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY d.expected_close_date ASC, d.updated_at DESC
        LIMIT {$limit}
    ";
    try {
        $st = $dbh->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function org_crm_save_deal(PDO $dbh, int $orgId, array $data, ?int $dealId = null, int $memberId = 0): array
{
    org_crm_ensure_schema($dbh);
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') {
        return ['ok' => false, 'error' => 'Deal title is required.'];
    }
    $stage = strtolower(trim((string)($data['stage'] ?? 'lead')));
    $allowed = ['lead', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];
    if (!in_array($stage, $allowed, true)) {
        $stage = 'lead';
    }
    $amount = (int)round((float)($data['amount'] ?? 0) * 100);
    $prob = max(0, min(100, (int)($data['probability'] ?? 20)));
    $closeDate = trim((string)($data['expected_close_date'] ?? ''));
    $closeDate = $closeDate !== '' ? $closeDate : null;

    try {
        if ($dealId > 0) {
            $st = $dbh->prepare('
                UPDATE org_crm_deals SET
                    title = :title, contact_id = :cid, stage = :stage,
                    amount_cents = :amt, probability = :prob,
                    expected_close_date = :close, notes = :notes,
                    closed_at = CASE WHEN :stage IN (\'won\',\'lost\') THEN COALESCE(closed_at, NOW()) ELSE NULL END,
                    updated_at = NOW()
                WHERE id = :id AND org_id = :org AND is_deleted = 0 LIMIT 1
            ');
            $st->execute([
                ':title' => mb_substr($title, 0, 200),
                ':cid' => (int)($data['contact_id'] ?? 0) > 0 ? (int)$data['contact_id'] : null,
                ':stage' => $stage,
                ':amt' => $amount,
                ':prob' => $prob,
                ':close' => $closeDate,
                ':notes' => trim((string)($data['notes'] ?? '')) ?: null,
                ':id' => $dealId,
                ':org' => $orgId,
            ]);
            return ['ok' => true, 'deal_id' => $dealId];
        }

        $st = $dbh->prepare('
            INSERT INTO org_crm_deals (
                org_id, contact_id, title, stage, amount_cents, currency, probability,
                expected_close_date, assigned_member_id, notes, created_by_member_id,
                created_at, updated_at, is_deleted
            ) VALUES (
                :org, :cid, :title, :stage, :amt, \'USD\', :prob,
                :close, :assigned, :notes, :member, NOW(), NOW(), 0
            )
        ');
        $st->execute([
            ':org' => $orgId,
            ':cid' => (int)($data['contact_id'] ?? 0) > 0 ? (int)$data['contact_id'] : null,
            ':title' => mb_substr($title, 0, 200),
            ':stage' => $stage,
            ':amt' => $amount,
            ':prob' => $prob,
            ':close' => $closeDate,
            ':assigned' => (int)($data['assigned_member_id'] ?? 0) > 0 ? (int)$data['assigned_member_id'] : null,
            ':notes' => trim((string)($data['notes'] ?? '')) ?: null,
            ':member' => $memberId > 0 ? $memberId : null,
        ]);
        return ['ok' => true, 'deal_id' => (int)$dbh->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save deal.'];
    }
}

function org_crm_import_shop_buyers(PDO $dbh, int $orgId, int $memberId = 0): array
{
    if ($orgId <= 0) {
        return ['ok' => false, 'error' => 'Invalid org.', 'imported' => 0];
    }
    org_crm_ensure_schema($dbh);
    $imported = 0;
    try {
        $st = $dbh->prepare("
            SELECT DISTINCT buyer_user_id, buyer_name, buyer_email, buyer_phone
            FROM org_orders
            WHERE org_id = :org
              AND (buyer_email IS NOT NULL OR buyer_name IS NOT NULL OR buyer_user_id IS NOT NULL)
            ORDER BY created_at DESC
            LIMIT 500
        ");
        $st->execute([':org' => $orgId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $email = trim((string)($row['buyer_email'] ?? ''));
            $uid = (int)($row['buyer_user_id'] ?? 0);
            if ($email !== '') {
                $chk = $dbh->prepare('SELECT id FROM org_crm_contacts WHERE org_id = :org AND email = :email AND is_deleted = 0 LIMIT 1');
                $chk->execute([':org' => $orgId, ':email' => $email]);
                if ($chk->fetchColumn()) {
                    continue;
                }
            } elseif ($uid > 0) {
                $chk = $dbh->prepare('SELECT id FROM org_crm_contacts WHERE org_id = :org AND linked_user_id = :uid AND is_deleted = 0 LIMIT 1');
                $chk->execute([':org' => $orgId, ':uid' => $uid]);
                if ($chk->fetchColumn()) {
                    continue;
                }
            } else {
                continue;
            }
            $name = trim((string)($row['buyer_name'] ?? '')) ?: ($email !== '' ? $email : 'Shop buyer');
            $res = org_crm_save_contact($dbh, $orgId, [
                'full_name' => $name,
                'email' => $email,
                'phone' => trim((string)($row['buyer_phone'] ?? '')),
                'lifecycle_stage' => 'customer',
                'lead_source' => 'shop',
                'linked_user_id' => $uid,
            ], null, $memberId);
            if (!empty($res['ok'])) {
                $imported++;
            }
        }
        return ['ok' => true, 'imported' => $imported];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Import failed.', 'imported' => $imported];
    }
}

/** @return list<array<string, mixed>> */
function org_crm_recent_activity(PDO $dbh, int $orgId, int $limit = 15): array
{
    org_crm_ensure_schema($dbh);
    if ($orgId <= 0) {
        return [];
    }
    $limit = max(1, min($limit, 50));
    try {
        $st = $dbh->prepare("
            SELECT i.*, c.full_name AS contact_name
            FROM org_crm_interactions i
            INNER JOIN org_crm_contacts c ON c.id = i.contact_id
            WHERE i.org_id = :org
            ORDER BY i.created_at DESC
            LIMIT {$limit}
        ");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function org_crm_stage_badge(string $stage): string
{
    $map = [
        'lead' => 'badge-warning',
        'prospect' => 'badge-info',
        'customer' => 'badge-success',
        'partner' => 'badge-primary',
        'churned' => 'badge-secondary',
        'open' => 'badge-danger',
        'pending' => 'badge-warning',
        'resolved' => 'badge-success',
        'closed' => 'badge-secondary',
        'qualified' => 'badge-info',
        'proposal' => 'badge-primary',
        'negotiation' => 'badge-warning',
        'won' => 'badge-success',
        'lost' => 'badge-secondary',
        'draft' => 'badge-light',
        'sent' => 'badge-info',
        'pending_approval' => 'badge-warning',
        'approved' => 'badge-success',
        'rejected' => 'badge-danger',
        'expired' => 'badge-secondary',
        'scheduled' => 'badge-primary',
        'in_progress' => 'badge-info',
        'completed' => 'badge-success',
        'cancelled' => 'badge-secondary',
        'no_show' => 'badge-warning',
        'overdue' => 'badge-danger',
        'paid' => 'badge-success',
    ];
    return $map[$stage] ?? 'badge-light';
}
