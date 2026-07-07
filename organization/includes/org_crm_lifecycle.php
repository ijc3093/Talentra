<?php
declare(strict_types=1);

require_once __DIR__ . '/org_crm.php';

function org_crm_lifecycle_ensure_schema(PDO $dbh): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    org_crm_ensure_schema($dbh);
    $migration = dirname(__DIR__, 2) . '/Data/migrations/20260706_org_crm_lifecycle.sql';
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

function org_crm_gen_code(string $prefix, int $orgId): string
{
    return $prefix . '-' . $orgId . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

/** @return array<string, int> */
function org_crm_lifecycle_stats(PDO $dbh, int $orgId): array
{
    org_crm_lifecycle_ensure_schema($dbh);
    $stats = [
        'capture_mtd' => 0,
        'quotes_open' => 0,
        'reminders_due' => 0,
        'bookings_upcoming' => 0,
        'invoices_unpaid' => 0,
        'feedback_avg' => 0,
        'campaigns_active' => 0,
    ];
    if ($orgId <= 0) {
        return $stats;
    }
    try {
        $st = $dbh->prepare("
            SELECT COUNT(*) FROM org_crm_contacts
            WHERE org_id = :org AND is_deleted = 0
              AND lead_source IN ('web','portal','phone')
              AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
        ");
        $st->execute([':org' => $orgId]);
        $stats['capture_mtd'] = (int)($st->fetchColumn() ?: 0);

        $st = $dbh->prepare("SELECT COUNT(*) FROM org_crm_quotes WHERE org_id = :org AND status IN ('draft','sent','pending_approval')");
        $st->execute([':org' => $orgId]);
        $stats['quotes_open'] = (int)($st->fetchColumn() ?: 0);

        $st = $dbh->prepare("SELECT COUNT(*) FROM org_crm_reminders WHERE org_id = :org AND status = 'pending' AND due_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)");
        $st->execute([':org' => $orgId]);
        $stats['reminders_due'] = (int)($st->fetchColumn() ?: 0);

        $st = $dbh->prepare("SELECT COUNT(*) FROM org_crm_bookings WHERE org_id = :org AND status = 'scheduled' AND scheduled_at >= NOW()");
        $st->execute([':org' => $orgId]);
        $stats['bookings_upcoming'] = (int)($st->fetchColumn() ?: 0);

        $st = $dbh->prepare("SELECT COUNT(*) FROM org_crm_invoices WHERE org_id = :org AND status IN ('sent','overdue')");
        $st->execute([':org' => $orgId]);
        $stats['invoices_unpaid'] = (int)($st->fetchColumn() ?: 0);

        $st = $dbh->prepare("SELECT COALESCE(AVG(rating),0) FROM org_crm_feedback WHERE org_id = :org");
        $st->execute([':org' => $orgId]);
        $stats['feedback_avg'] = round((float)($st->fetchColumn() ?: 0), 1);

        $st = $dbh->prepare("SELECT COUNT(*) FROM org_crm_campaigns WHERE org_id = :org AND status IN ('draft','scheduled')");
        $st->execute([':org' => $orgId]);
        $stats['campaigns_active'] = (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        // ignore
    }
    return $stats;
}

/** @return list<array<string, mixed>> */
function org_crm_list_quotes(PDO $dbh, int $orgId, string $status = 'all', int $limit = 100): array
{
    org_crm_lifecycle_ensure_schema($dbh);
    if ($orgId <= 0) {
        return [];
    }
    $where = ['q.org_id = :org'];
    $params = [':org' => $orgId];
    if ($status !== '' && $status !== 'all') {
        $where[] = 'q.status = :st';
        $params[':st'] = $status;
    }
    $limit = max(1, min($limit, 200));
    $sql = "
        SELECT q.*, c.full_name AS contact_name
        FROM org_crm_quotes q
        LEFT JOIN org_crm_contacts c ON c.id = q.contact_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY q.updated_at DESC, q.id DESC
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

function org_crm_save_quote(PDO $dbh, int $orgId, array $data, ?int $quoteId = null, int $memberId = 0): array
{
    org_crm_lifecycle_ensure_schema($dbh);
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') {
        return ['ok' => false, 'error' => 'Quote title is required.'];
    }
    $status = strtolower(trim((string)($data['status'] ?? 'draft')));
    $allowed = ['draft', 'sent', 'pending_approval', 'approved', 'rejected', 'expired'];
    if (!in_array($status, $allowed, true)) {
        $status = 'draft';
    }
    $amount = (int)round((float)($data['amount'] ?? 0) * 100);
    $validUntil = trim((string)($data['valid_until'] ?? '')) ?: null;

    try {
        if ($quoteId > 0) {
            $extra = '';
            $params = [
                ':title' => mb_substr($title, 0, 200),
                ':cid' => (int)($data['contact_id'] ?? 0) > 0 ? (int)$data['contact_id'] : null,
                ':amt' => $amount,
                ':st' => $status,
                ':valid' => $validUntil,
                ':notes' => trim((string)($data['notes'] ?? '')) ?: null,
                ':id' => $quoteId,
                ':org' => $orgId,
            ];
            if ($status === 'approved') {
                $extra = ', approved_by_member_id = :mid, approved_at = NOW()';
                $params[':mid'] = $memberId > 0 ? $memberId : null;
            }
            $st = $dbh->prepare("
                UPDATE org_crm_quotes SET
                    title = :title, contact_id = :cid, amount_cents = :amt,
                    status = :st, valid_until = :valid, notes = :notes,
                    updated_at = NOW(){$extra}
                WHERE id = :id AND org_id = :org LIMIT 1
            ");
            $st->execute($params);
            return ['ok' => true, 'quote_id' => $quoteId];
        }

        $code = org_crm_gen_code('QTE', $orgId);
        $st = $dbh->prepare('
            INSERT INTO org_crm_quotes (
                org_id, contact_id, quote_code, title, amount_cents, status,
                valid_until, notes, created_by_member_id, created_at, updated_at
            ) VALUES (
                :org, :cid, :code, :title, :amt, :st,
                :valid, :notes, :member, NOW(), NOW()
            )
        ');
        $st->execute([
            ':org' => $orgId,
            ':cid' => (int)($data['contact_id'] ?? 0) > 0 ? (int)$data['contact_id'] : null,
            ':code' => $code,
            ':title' => mb_substr($title, 0, 200),
            ':amt' => $amount,
            ':st' => $status,
            ':valid' => $validUntil,
            ':notes' => trim((string)($data['notes'] ?? '')) ?: null,
            ':member' => $memberId > 0 ? $memberId : null,
        ]);
        return ['ok' => true, 'quote_id' => (int)$dbh->lastInsertId(), 'quote_code' => $code];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save quote.'];
    }
}

/** @return list<array<string, mixed>> */
function org_crm_list_reminders(PDO $dbh, int $orgId, string $status = 'pending', int $limit = 100): array
{
    org_crm_lifecycle_ensure_schema($dbh);
    if ($orgId <= 0) {
        return [];
    }
    $where = ['r.org_id = :org'];
    $params = [':org' => $orgId];
    if ($status !== '' && $status !== 'all') {
        $where[] = 'r.status = :st';
        $params[':st'] = $status;
    }
    $limit = max(1, min($limit, 200));
    $sql = "
        SELECT r.*, c.full_name AS contact_name
        FROM org_crm_reminders r
        LEFT JOIN org_crm_contacts c ON c.id = r.contact_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.due_at ASC, r.id ASC
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

function org_crm_save_reminder(PDO $dbh, int $orgId, array $data, int $memberId = 0): array
{
    org_crm_lifecycle_ensure_schema($dbh);
    $title = trim((string)($data['title'] ?? ''));
    $dueAt = trim((string)($data['due_at'] ?? ''));
    if ($title === '' || $dueAt === '') {
        return ['ok' => false, 'error' => 'Title and due date are required.'];
    }
    try {
        $st = $dbh->prepare('
            INSERT INTO org_crm_reminders (
                org_id, contact_id, deal_id, quote_id, title, body, due_at,
                assigned_member_id, created_by_member_id, created_at
            ) VALUES (
                :org, :cid, :did, :qid, :title, :body, :due,
                :assigned, :member, NOW()
            )
        ');
        $st->execute([
            ':org' => $orgId,
            ':cid' => (int)($data['contact_id'] ?? 0) > 0 ? (int)$data['contact_id'] : null,
            ':did' => (int)($data['deal_id'] ?? 0) > 0 ? (int)$data['deal_id'] : null,
            ':qid' => (int)($data['quote_id'] ?? 0) > 0 ? (int)$data['quote_id'] : null,
            ':title' => mb_substr($title, 0, 200),
            ':body' => trim((string)($data['body'] ?? '')) ?: null,
            ':due' => $dueAt,
            ':assigned' => (int)($data['assigned_member_id'] ?? 0) > 0 ? (int)$data['assigned_member_id'] : null,
            ':member' => $memberId > 0 ? $memberId : null,
        ]);
        return ['ok' => true, 'reminder_id' => (int)$dbh->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save reminder.'];
    }
}

function org_crm_complete_reminder(PDO $dbh, int $orgId, int $reminderId, string $status = 'done'): bool
{
    if (!in_array($status, ['done', 'dismissed'], true)) {
        return false;
    }
    try {
        $st = $dbh->prepare('UPDATE org_crm_reminders SET status = :st WHERE id = :id AND org_id = :org LIMIT 1');
        $st->execute([':st' => $status, ':id' => $reminderId, ':org' => $orgId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<array<string, mixed>> */
function org_crm_list_addresses(PDO $dbh, int $orgId, int $contactId): array
{
    org_crm_lifecycle_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT * FROM org_crm_contact_addresses WHERE org_id = :org AND contact_id = :cid ORDER BY is_primary DESC, id ASC');
        $st->execute([':org' => $orgId, ':cid' => $contactId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function org_crm_save_address(PDO $dbh, int $orgId, int $contactId, array $data): bool
{
    org_crm_lifecycle_ensure_schema($dbh);
    $line1 = trim((string)($data['line1'] ?? ''));
    if ($line1 === '') {
        return false;
    }
    try {
        $st = $dbh->prepare('
            INSERT INTO org_crm_contact_addresses (
                org_id, contact_id, label, line1, line2, city, state, postal_code, country, is_primary, created_at
            ) VALUES (
                :org, :cid, :label, :l1, :l2, :city, :state, :postal, :country, :pri, NOW()
            )
        ');
        $st->execute([
            ':org' => $orgId,
            ':cid' => $contactId,
            ':label' => trim((string)($data['label'] ?? 'Primary')) ?: 'Primary',
            ':l1' => $line1,
            ':l2' => trim((string)($data['line2'] ?? '')) ?: null,
            ':city' => trim((string)($data['city'] ?? '')) ?: null,
            ':state' => trim((string)($data['state'] ?? '')) ?: null,
            ':postal' => trim((string)($data['postal_code'] ?? '')) ?: null,
            ':country' => trim((string)($data['country'] ?? '')) ?: null,
            ':pri' => !empty($data['is_primary']) ? 1 : 0,
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<array<string, mixed>> */
function org_crm_list_files(PDO $dbh, int $orgId, int $contactId): array
{
    org_crm_lifecycle_ensure_schema($dbh);
    try {
        $st = $dbh->prepare('SELECT * FROM org_crm_contact_files WHERE org_id = :org AND contact_id = :cid ORDER BY created_at DESC');
        $st->execute([':org' => $orgId, ':cid' => $contactId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function org_crm_upload_contact_file(PDO $dbh, int $orgId, int $contactId, int $memberId): bool
{
    org_crm_lifecycle_ensure_schema($dbh);
    if (empty($_FILES['contact_file']) || !is_array($_FILES['contact_file'])) {
        return false;
    }
    if ((int)($_FILES['contact_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return false;
    }
    $tmp = (string)($_FILES['contact_file']['tmp_name'] ?? '');
    $name = basename((string)($_FILES['contact_file']['name'] ?? 'file'));
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return false;
    }
    $dir = dirname(__DIR__, 2) . '/uploads/org_crm/' . $orgId . '/' . $contactId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return false;
    }
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name) ?? 'file';
    $dest = $dir . '/' . time() . '_' . $safe;
    if (!move_uploaded_file($tmp, $dest)) {
        return false;
    }
    $rel = 'uploads/org_crm/' . $orgId . '/' . $contactId . '/' . basename($dest);
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$fi->file($dest);
    try {
        $st = $dbh->prepare('
            INSERT INTO org_crm_contact_files (org_id, contact_id, file_name, file_path, mime_type, uploaded_by_member_id, created_at)
            VALUES (:org, :cid, :name, :path, :mime, :mid, NOW())
        ');
        $st->execute([
            ':org' => $orgId,
            ':cid' => $contactId,
            ':name' => $name,
            ':path' => $rel,
            ':mime' => $mime,
            ':mid' => $memberId > 0 ? $memberId : null,
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<array<string, mixed>> */
function org_crm_list_bookings(PDO $dbh, int $orgId, string $status = 'all', int $limit = 100): array
{
    org_crm_lifecycle_ensure_schema($dbh);
    if ($orgId <= 0) {
        return [];
    }
    $where = ['b.org_id = :org'];
    $params = [':org' => $orgId];
    if ($status !== '' && $status !== 'all') {
        $where[] = 'b.status = :st';
        $params[':st'] = $status;
    }
    $limit = max(1, min($limit, 200));
    $sql = "
        SELECT b.*, c.full_name AS contact_name
        FROM org_crm_bookings b
        LEFT JOIN org_crm_contacts c ON c.id = b.contact_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY b.scheduled_at ASC, b.id ASC
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

function org_crm_save_booking(PDO $dbh, int $orgId, array $data, ?int $bookingId = null, int $memberId = 0): array
{
    org_crm_lifecycle_ensure_schema($dbh);
    $title = trim((string)($data['title'] ?? ''));
    $scheduled = trim((string)($data['scheduled_at'] ?? ''));
    if ($title === '' || $scheduled === '') {
        return ['ok' => false, 'error' => 'Title and schedule are required.'];
    }
    $status = strtolower(trim((string)($data['status'] ?? 'scheduled')));
    $allowed = ['scheduled', 'in_progress', 'completed', 'cancelled', 'no_show'];
    if (!in_array($status, $allowed, true)) {
        $status = 'scheduled';
    }
    try {
        if ($bookingId > 0) {
            $st = $dbh->prepare('
                UPDATE org_crm_bookings SET
                    title = :title, contact_id = :cid, scheduled_at = :sched,
                    duration_minutes = :dur, status = :st, location = :loc,
                    fieldworker_member_id = :fw, notes = :notes, is_repeat = :rep,
                    updated_at = NOW()
                WHERE id = :id AND org_id = :org LIMIT 1
            ');
            $st->execute([
                ':title' => mb_substr($title, 0, 200),
                ':cid' => (int)($data['contact_id'] ?? 0) > 0 ? (int)$data['contact_id'] : null,
                ':sched' => $scheduled,
                ':dur' => max(15, (int)($data['duration_minutes'] ?? 60)),
                ':st' => $status,
                ':loc' => trim((string)($data['location'] ?? '')) ?: null,
                ':fw' => (int)($data['fieldworker_member_id'] ?? 0) > 0 ? (int)$data['fieldworker_member_id'] : null,
                ':notes' => trim((string)($data['notes'] ?? '')) ?: null,
                ':rep' => !empty($data['is_repeat']) ? 1 : 0,
                ':id' => $bookingId,
                ':org' => $orgId,
            ]);
            return ['ok' => true, 'booking_id' => $bookingId];
        }

        $st = $dbh->prepare('
            INSERT INTO org_crm_bookings (
                org_id, contact_id, title, scheduled_at, duration_minutes, status,
                location, fieldworker_member_id, notes, is_repeat,
                created_by_member_id, created_at, updated_at
            ) VALUES (
                :org, :cid, :title, :sched, :dur, :st,
                :loc, :fw, :notes, :rep,
                :member, NOW(), NOW()
            )
        ');
        $st->execute([
            ':org' => $orgId,
            ':cid' => (int)($data['contact_id'] ?? 0) > 0 ? (int)$data['contact_id'] : null,
            ':title' => mb_substr($title, 0, 200),
            ':sched' => $scheduled,
            ':dur' => max(15, (int)($data['duration_minutes'] ?? 60)),
            ':st' => $status,
            ':loc' => trim((string)($data['location'] ?? '')) ?: null,
            ':fw' => (int)($data['fieldworker_member_id'] ?? 0) > 0 ? (int)$data['fieldworker_member_id'] : null,
            ':notes' => trim((string)($data['notes'] ?? '')) ?: null,
            ':rep' => !empty($data['is_repeat']) ? 1 : 0,
            ':member' => $memberId > 0 ? $memberId : null,
        ]);
        return ['ok' => true, 'booking_id' => (int)$dbh->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save booking.'];
    }
}

/** @return list<array<string, mixed>> */
function org_crm_list_invoices(PDO $dbh, int $orgId, string $status = 'all', int $limit = 100): array
{
    org_crm_lifecycle_ensure_schema($dbh);
    if ($orgId <= 0) {
        return [];
    }
    $where = ['i.org_id = :org'];
    $params = [':org' => $orgId];
    if ($status !== '' && $status !== 'all') {
        $where[] = 'i.status = :st';
        $params[':st'] = $status;
    }
    $limit = max(1, min($limit, 200));
    $sql = "
        SELECT i.*, c.full_name AS contact_name
        FROM org_crm_invoices i
        LEFT JOIN org_crm_contacts c ON c.id = i.contact_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY i.due_date ASC, i.id DESC
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

function org_crm_save_invoice(PDO $dbh, int $orgId, array $data, ?int $invoiceId = null, int $memberId = 0): array
{
    org_crm_lifecycle_ensure_schema($dbh);
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') {
        return ['ok' => false, 'error' => 'Invoice title is required.'];
    }
    $status = strtolower(trim((string)($data['status'] ?? 'draft')));
    $allowed = ['draft', 'sent', 'paid', 'overdue', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        $status = 'draft';
    }
    $amount = (int)round((float)($data['amount'] ?? 0) * 100);
    $dueDate = trim((string)($data['due_date'] ?? '')) ?: null;

    try {
        if ($invoiceId > 0) {
            $extra = $status === 'paid' ? ', paid_at = COALESCE(paid_at, NOW())' : '';
            $st = $dbh->prepare("
                UPDATE org_crm_invoices SET
                    title = :title, contact_id = :cid, amount_cents = :amt,
                    status = :st, due_date = :due, notes = :notes,
                    updated_at = NOW(){$extra}
                WHERE id = :id AND org_id = :org LIMIT 1
            ");
            $st->execute([
                ':title' => mb_substr($title, 0, 200),
                ':cid' => (int)($data['contact_id'] ?? 0) > 0 ? (int)$data['contact_id'] : null,
                ':amt' => $amount,
                ':st' => $status,
                ':due' => $dueDate,
                ':notes' => trim((string)($data['notes'] ?? '')) ?: null,
                ':id' => $invoiceId,
                ':org' => $orgId,
            ]);
            return ['ok' => true, 'invoice_id' => $invoiceId];
        }

        $code = org_crm_gen_code('INV', $orgId);
        $st = $dbh->prepare('
            INSERT INTO org_crm_invoices (
                org_id, contact_id, invoice_code, title, amount_cents, status,
                due_date, related_quote_id, notes, created_by_member_id, created_at, updated_at
            ) VALUES (
                :org, :cid, :code, :title, :amt, :st,
                :due, :qid, :notes, :member, NOW(), NOW()
            )
        ');
        $st->execute([
            ':org' => $orgId,
            ':cid' => (int)($data['contact_id'] ?? 0) > 0 ? (int)$data['contact_id'] : null,
            ':code' => $code,
            ':title' => mb_substr($title, 0, 200),
            ':amt' => $amount,
            ':st' => $status,
            ':due' => $dueDate,
            ':qid' => (int)($data['quote_id'] ?? 0) > 0 ? (int)$data['quote_id'] : null,
            ':notes' => trim((string)($data['notes'] ?? '')) ?: null,
            ':member' => $memberId > 0 ? $memberId : null,
        ]);
        return ['ok' => true, 'invoice_id' => (int)$dbh->lastInsertId(), 'invoice_code' => $code];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save invoice.'];
    }
}

/** @return list<array<string, mixed>> */
function org_crm_list_feedback(PDO $dbh, int $orgId, int $limit = 50): array
{
    org_crm_lifecycle_ensure_schema($dbh);
    $limit = max(1, min($limit, 100));
    try {
        $st = $dbh->prepare("
            SELECT f.*, c.full_name AS contact_name
            FROM org_crm_feedback f
            LEFT JOIN org_crm_contacts c ON c.id = f.contact_id
            WHERE f.org_id = :org
            ORDER BY f.created_at DESC
            LIMIT {$limit}
        ");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function org_crm_save_feedback(PDO $dbh, int $orgId, array $data): array
{
    org_crm_lifecycle_ensure_schema($dbh);
    $rating = max(1, min(5, (int)($data['rating'] ?? 5)));
    try {
        $st = $dbh->prepare('
            INSERT INTO org_crm_feedback (org_id, contact_id, booking_id, rating, comment, created_at)
            VALUES (:org, :cid, :bid, :rating, :comment, NOW())
        ');
        $st->execute([
            ':org' => $orgId,
            ':cid' => (int)($data['contact_id'] ?? 0) > 0 ? (int)$data['contact_id'] : null,
            ':bid' => (int)($data['booking_id'] ?? 0) > 0 ? (int)$data['booking_id'] : null,
            ':rating' => $rating,
            ':comment' => trim((string)($data['comment'] ?? '')) ?: null,
        ]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save feedback.'];
    }
}

/** @return list<array<string, mixed>> */
function org_crm_list_campaigns(PDO $dbh, int $orgId, int $limit = 50): array
{
    org_crm_lifecycle_ensure_schema($dbh);
    $limit = max(1, min($limit, 100));
    try {
        $st = $dbh->prepare("SELECT * FROM org_crm_campaigns WHERE org_id = :org ORDER BY created_at DESC LIMIT {$limit}");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function org_crm_save_campaign(PDO $dbh, int $orgId, array $data, int $memberId = 0): array
{
    org_crm_lifecycle_ensure_schema($dbh);
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'error' => 'Campaign name is required.'];
    }
    $channel = strtolower(trim((string)($data['channel'] ?? 'email')));
    if (!in_array($channel, ['email', 'sms', 'social', 'other'], true)) {
        $channel = 'email';
    }
    try {
        $st = $dbh->prepare('
            INSERT INTO org_crm_campaigns (
                org_id, name, channel, message, status, scheduled_at,
                recipient_count, created_by_member_id, created_at, updated_at
            ) VALUES (
                :org, :name, :ch, :msg, :st, :sched,
                :cnt, :member, NOW(), NOW()
            )
        ');
        $st->execute([
            ':org' => $orgId,
            ':name' => mb_substr($name, 0, 200),
            ':ch' => $channel,
            ':msg' => trim((string)($data['message'] ?? '')) ?: null,
            ':st' => trim((string)($data['status'] ?? 'draft')),
            ':sched' => trim((string)($data['scheduled_at'] ?? '')) ?: null,
            ':cnt' => max(0, (int)($data['recipient_count'] ?? 0)),
            ':member' => $memberId > 0 ? $memberId : null,
        ]);
        return ['ok' => true, 'campaign_id' => (int)$dbh->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save campaign.'];
    }
}

/** @return list<array<string, mixed>> */
function org_crm_list_org_members(PDO $dbh, int $orgId): array
{
    if ($orgId <= 0) {
        return [];
    }
    try {
        $st = $dbh->prepare("
            SELECT om.id, om.member_type, om.member_id,
                   COALESCE(m.fullname, s.fullname, CONCAT('Member #', om.id)) AS display_name
            FROM org_members om
            LEFT JOIN managers m ON om.member_type = 'manager' AND m.id = om.member_id
            LEFT JOIN staff_accounts s ON om.member_type = 'staff' AND s.id = om.member_id
            WHERE om.org_id = :org
            ORDER BY om.id ASC
        ");
        $st->execute([':org' => $orgId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function org_crm_capture_lead(PDO $dbh, int $orgId, array $data, int $memberId = 0): array
{
    $source = strtolower(trim((string)($data['lead_source'] ?? 'manual')));
    $allowed = ['web', 'portal', 'phone', 'manual', 'referral', 'import', 'other'];
    if (!in_array($source, $allowed, true)) {
        $source = 'manual';
    }
    $data['lead_source'] = $source;
    $data['lifecycle_stage'] = trim((string)($data['lifecycle_stage'] ?? 'lead')) ?: 'lead';
    return org_crm_save_contact($dbh, $orgId, $data, null, $memberId);
}
