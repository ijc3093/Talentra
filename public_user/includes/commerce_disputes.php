<?php
declare(strict_types=1);

/**
 * Product / seller business disputes opened from buyer Support Center (Report).
 *
 * Lifecycle:
 * - open: buyer reported; Admin reviewing
 * - seller_notified: Admin opened dispute to seller with Dispute ID (30-day response window)
 * - resolved: customer problem solved; both cases closed
 * - refunded_suspended: seller missed window → Admin refunds customer + suspends seller shop
 */

if (!function_exists('commerce_disputes_ensure_schema')) {
    function commerce_disputes_ensure_schema(PDO $dbh): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }
        try {
            $dbh->exec("
                CREATE TABLE IF NOT EXISTS commerce_disputes (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    dispute_code VARCHAR(32) NOT NULL,
                    buyer_user_id INT UNSIGNED NOT NULL,
                    publisher_user_id INT UNSIGNED NOT NULL DEFAULT 0,
                    org_id INT UNSIGNED NOT NULL DEFAULT 0,
                    product_id INT UNSIGNED NOT NULL DEFAULT 0,
                    seller_business_name VARCHAR(190) NOT NULL DEFAULT '',
                    product_title VARCHAR(255) NOT NULL DEFAULT '',
                    product_cover_path VARCHAR(500) NOT NULL DEFAULT '',
                    reason VARCHAR(64) NOT NULL DEFAULT 'product_concern',
                    buyer_message TEXT NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'open',
                    customer_case_closed TINYINT(1) NOT NULL DEFAULT 0,
                    seller_case_closed TINYINT(1) NOT NULL DEFAULT 0,
                    seller_notified_at DATETIME NULL DEFAULT NULL,
                    seller_response_due_at DATETIME NULL DEFAULT NULL,
                    seller_responded_at DATETIME NULL DEFAULT NULL,
                    resolved_at DATETIME NULL DEFAULT NULL,
                    refund_marked_at DATETIME NULL DEFAULT NULL,
                    suspended_at DATETIME NULL DEFAULT NULL,
                    admin_notes TEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_commerce_dispute_code (dispute_code),
                    KEY idx_cd_buyer (buyer_user_id, status),
                    KEY idx_cd_seller (publisher_user_id, status),
                    KEY idx_cd_org (org_id, status),
                    KEY idx_cd_product (product_id),
                    KEY idx_cd_due (seller_response_due_at, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $ready = true;
        } catch (Throwable $e) {
            // retry next call
        }
    }
}

if (!function_exists('commerce_dispute_format_id')) {
    function commerce_dispute_format_id(int $id): string
    {
        $id = max(1, $id);
        return 'DSP-' . strtoupper(base_convert((string)$id, 10, 36));
    }
}

if (!function_exists('commerce_dispute_parse_id')) {
    function commerce_dispute_parse_id(string $raw): int
    {
        $raw = strtoupper(trim($raw));
        if ($raw === '') {
            return 0;
        }
        if (preg_match('/^DSP-([0-9A-Z]+)$/', $raw, $m)) {
            $n = (int)base_convert($m[1], 36, 10);
            return $n > 0 ? $n : 0;
        }
        if (ctype_digit($raw)) {
            return (int)$raw;
        }
        return 0;
    }
}

if (!function_exists('commerce_support_report_url')) {
    /** Deep-link buyer Report → Support Center with product focus. */
    function commerce_support_report_url(int $productId, int $publisherUserId = 0): string
    {
        $q = ['topic' => 'dispute', 'support_report' => 1];
        if ($productId > 0) {
            $q['about_product'] = $productId;
        }
        if ($publisherUserId > 0) {
            $q['about_seller'] = $publisherUserId;
        }
        return 'Your_Shopping_preferences.php?' . http_build_query($q) . '#support-center';
    }
}

if (!function_exists('commerce_dispute_get')) {
    function commerce_dispute_get(PDO $dbh, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        commerce_disputes_ensure_schema($dbh);
        try {
            $st = $dbh->prepare('SELECT * FROM commerce_disputes WHERE id = :id LIMIT 1');
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('commerce_dispute_get_by_code')) {
    function commerce_dispute_get_by_code(PDO $dbh, string $code): ?array
    {
        $id = commerce_dispute_parse_id($code);
        if ($id > 0) {
            return commerce_dispute_get($dbh, $id);
        }
        commerce_disputes_ensure_schema($dbh);
        try {
            $st = $dbh->prepare('SELECT * FROM commerce_disputes WHERE dispute_code = :c LIMIT 1');
            $st->execute([':c' => strtoupper(trim($code))]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('commerce_dispute_create_from_product')) {
    /**
     * @return array{ok:bool,id?:int,code?:string,error?:string,duplicate?:bool}
     */
    function commerce_dispute_create_from_product(
        PDO $dbh,
        int $buyerUserId,
        int $productId,
        string $buyerMessage = '',
        string $reason = 'product_concern'
    ): array {
        if ($buyerUserId <= 0 || $productId <= 0) {
            return ['ok' => false, 'error' => 'Missing buyer or product.'];
        }
        require_once __DIR__ . '/org_shop.php';
        if (function_exists('commerce_messaging_product_focus')) {
            // commerce_messaging.php already loaded in many call sites
        }
        $product = org_shop_get_product($dbh, $productId);
        if (!$product) {
            return ['ok' => false, 'error' => 'Product not found.'];
        }
        $orgId = (int)($product['org_id'] ?? 0);
        $publisherUserId = 0;
        $sellerBusiness = '';
        try {
            if ($orgId > 0) {
                $st = $dbh->prepare("
                    SELECT org.id, org.name, org.publisher_user_id
                    FROM organizations org
                    WHERE org.id = :id AND org.status IN (0, 1)
                    LIMIT 1
                ");
                $st->execute([':id' => $orgId]);
                $org = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $publisherUserId = (int)($org['publisher_user_id'] ?? 0);
                $sellerBusiness = trim((string)($org['name'] ?? ''));
            }
            if ($publisherUserId <= 0) {
                $publisherUserId = (int)($product['publisher_user_id'] ?? 0);
            }
            if ($sellerBusiness === '' && function_exists('org_shop_seller_pickup_display') && $orgId > 0) {
                $info = org_shop_seller_pickup_display($dbh, $orgId);
                $sellerBusiness = trim((string)($info['store_name'] ?? ''));
            }
            if ($sellerBusiness === '' && $publisherUserId > 0) {
                $stU = $dbh->prepare("
                    SELECT COALESCE(NULLIF(TRIM(name), ''), NULLIF(TRIM(username), ''), friend_code) AS n
                    FROM users WHERE id = :id LIMIT 1
                ");
                $stU->execute([':id' => $publisherUserId]);
                $sellerBusiness = trim((string)($stU->fetchColumn() ?: 'Seller'));
            }
        } catch (Throwable $e) {
            // keep defaults
        }

        commerce_disputes_ensure_schema($dbh);

        // Reuse open case for same buyer+product.
        try {
            $stDup = $dbh->prepare("
                SELECT id, dispute_code FROM commerce_disputes
                WHERE buyer_user_id = :b AND product_id = :p
                  AND status IN ('open', 'seller_notified')
                  AND customer_case_closed = 0
                ORDER BY id DESC
                LIMIT 1
            ");
            $stDup->execute([':b' => $buyerUserId, ':p' => $productId]);
            $dup = $stDup->fetch(PDO::FETCH_ASSOC);
            if ($dup) {
                $dupId = (int)$dup['id'];
                $code = trim((string)($dup['dispute_code'] ?? ''));
                if ($code === '') {
                    $code = commerce_dispute_format_id($dupId);
                    $dbh->prepare('UPDATE commerce_disputes SET dispute_code = :c WHERE id = :id LIMIT 1')
                        ->execute([':c' => $code, ':id' => $dupId]);
                }
                if (trim($buyerMessage) !== '') {
                    $dbh->prepare('UPDATE commerce_disputes SET buyer_message = :m, updated_at = NOW() WHERE id = :id LIMIT 1')
                        ->execute([':m' => mb_substr(trim($buyerMessage), 0, 4000), ':id' => $dupId]);
                }
                return ['ok' => true, 'id' => $dupId, 'code' => $code, 'duplicate' => true];
            }
        } catch (Throwable $e) {
            // continue to insert
        }

        $title = trim((string)($product['title'] ?? 'Product'));
        $cover = trim((string)($product['cover_image_path'] ?? ''));
        $tmpCode = 'TMP-' . bin2hex(random_bytes(4));
        try {
            $st = $dbh->prepare("
                INSERT INTO commerce_disputes (
                    dispute_code, buyer_user_id, publisher_user_id, org_id, product_id,
                    seller_business_name, product_title, product_cover_path,
                    reason, buyer_message, status, created_at, updated_at
                ) VALUES (
                    :code, :buyer, :pub, :org, :pid,
                    :biz, :title, :cover,
                    :reason, :msg, 'open', NOW(), NOW()
                )
            ");
            $st->execute([
                ':code' => $tmpCode,
                ':buyer' => $buyerUserId,
                ':pub' => $publisherUserId,
                ':org' => $orgId,
                ':pid' => $productId,
                ':biz' => mb_substr($sellerBusiness !== '' ? $sellerBusiness : 'Seller', 0, 190),
                ':title' => mb_substr($title !== '' ? $title : ('Product #' . $productId), 0, 255),
                ':cover' => mb_substr($cover, 0, 500),
                ':reason' => mb_substr($reason !== '' ? $reason : 'product_concern', 0, 64),
                ':msg' => mb_substr(trim($buyerMessage), 0, 4000),
            ]);
            $id = (int)$dbh->lastInsertId();
            $code = commerce_dispute_format_id($id);
            $dbh->prepare('UPDATE commerce_disputes SET dispute_code = :c WHERE id = :id LIMIT 1')
                ->execute([':c' => $code, ':id' => $id]);
            return ['ok' => true, 'id' => $id, 'code' => $code];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not open dispute case.'];
        }
    }
}

if (!function_exists('commerce_dispute_context_lines')) {
    /** Plain-text block appended to Admin / Seller chat messages. */
    function commerce_dispute_context_lines(array $case): string
    {
        $id = (int)($case['id'] ?? 0);
        $code = trim((string)($case['dispute_code'] ?? ''));
        if ($code === '' && $id > 0) {
            $code = commerce_dispute_format_id($id);
        }
        $lines = [];
        if ($code !== '') {
            $lines[] = 'Dispute ID: ' . $code;
        }
        $pid = (int)($case['product_id'] ?? 0);
        if ($pid > 0) {
            $lines[] = 'Product ID #' . $pid;
        }
        $title = trim((string)($case['product_title'] ?? ''));
        if ($title !== '') {
            $lines[] = 'Product: ' . $title;
        }
        $biz = trim((string)($case['seller_business_name'] ?? ''));
        if ($biz !== '') {
            $lines[] = 'Seller business: ' . $biz;
        }
        $due = trim((string)($case['seller_response_due_at'] ?? ''));
        if ($due !== '') {
            $ts = strtotime($due);
            if ($ts) {
                $lines[] = 'Seller response due: ' . date('M j, Y', $ts) . ' (30 days)';
            }
        }
        return implode("\n", $lines);
    }
}

if (!function_exists('commerce_dispute_notify_seller')) {
    /**
     * Admin opens dispute to seller: message with Dispute ID + 30-day window.
     * @return array{ok:bool,error?:string,due_at?:string}
     */
    function commerce_dispute_notify_seller(PDO $dbh, int $disputeId, string $adminNote = ''): array
    {
        $case = commerce_dispute_get($dbh, $disputeId);
        if (!$case) {
            return ['ok' => false, 'error' => 'Dispute not found.'];
        }
        $publisherId = (int)($case['publisher_user_id'] ?? 0);
        if ($publisherId <= 0) {
            return ['ok' => false, 'error' => 'Seller account missing on this dispute.'];
        }
        require_once __DIR__ . '/admin_support_chat.php';
        $sellerEmail = admin_support_user_email($dbh, $publisherId);
        if ($sellerEmail === '') {
            return ['ok' => false, 'error' => 'Seller email missing.'];
        }

        $dueAt = date('Y-m-d H:i:s', time() + 30 * 86400);
        $case['seller_response_due_at'] = $dueAt;
        $ctx = commerce_dispute_context_lines($case);
        $note = trim($adminNote);
        $body = "[Admin dispute] A customer raised a concern about your product/business.\n"
            . "Please contact the customer and resolve the issue, then reply here.\n"
            . "You have 30 days to respond to Admin.\n\n"
            . $ctx;
        if ($note !== '') {
            $body .= "\n\nAdmin note: " . mb_substr($note, 0, 1500);
        }

        try {
            $ins = $dbh->prepare("
                INSERT INTO feedback_admin (sender, receiver, channel, scope, title, feedbackdata, attachment, is_read)
                VALUES ('Admin', :peer, 'dispute', 'seller', 'Seller Order Dispute', :d, NULL, 0)
            ");
            $ins->execute([':peer' => $sellerEmail, ':d' => $body]);
        } catch (Throwable $e) {
            try {
                $ins = $dbh->prepare("
                    INSERT INTO feedback_admin (sender, receiver, channel, title, feedbackdata, attachment, is_read)
                    VALUES ('Admin', :peer, 'dispute', 'Seller Order Dispute', :d, NULL, 0)
                ");
                $ins->execute([':peer' => $sellerEmail, ':d' => $body]);
            } catch (Throwable $e2) {
                return ['ok' => false, 'error' => 'Could not message seller.'];
            }
        }

        try {
            $dbh->prepare("
                UPDATE commerce_disputes
                SET status = 'seller_notified',
                    seller_notified_at = NOW(),
                    seller_response_due_at = :due,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ")->execute([':due' => $dueAt, ':id' => $disputeId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Dispute message sent but case update failed.'];
        }
        return ['ok' => true, 'due_at' => $dueAt];
    }
}

if (!function_exists('commerce_dispute_mark_seller_responded')) {
    function commerce_dispute_mark_seller_responded(PDO $dbh, int $disputeId): bool
    {
        if ($disputeId <= 0) {
            return false;
        }
        commerce_disputes_ensure_schema($dbh);
        try {
            $st = $dbh->prepare("
                UPDATE commerce_disputes
                SET seller_responded_at = COALESCE(seller_responded_at, NOW()),
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $st->execute([':id' => $disputeId]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('commerce_dispute_close_customer')) {
    function commerce_dispute_close_customer(PDO $dbh, int $disputeId, string $note = ''): bool
    {
        return commerce_dispute_close_side($dbh, $disputeId, 'customer', $note);
    }
}

if (!function_exists('commerce_dispute_close_seller')) {
    function commerce_dispute_close_seller(PDO $dbh, int $disputeId, string $note = ''): bool
    {
        return commerce_dispute_close_side($dbh, $disputeId, 'seller', $note);
    }
}

if (!function_exists('commerce_dispute_close_side')) {
    function commerce_dispute_close_side(PDO $dbh, int $disputeId, string $side, string $note = ''): bool
    {
        $case = commerce_dispute_get($dbh, $disputeId);
        if (!$case) {
            return false;
        }
        $side = strtolower(trim($side));
        $col = $side === 'seller' ? 'seller_case_closed' : 'customer_case_closed';
        $notes = trim((string)($case['admin_notes'] ?? ''));
        $note = trim($note);
        if ($note !== '') {
            $stamp = date('Y-m-d H:i') . ' close-' . $side . ': ' . mb_substr($note, 0, 500);
            $notes = $notes !== '' ? ($notes . "\n" . $stamp) : $stamp;
        }
        $custClosed = $side === 'customer' ? 1 : (int)($case['customer_case_closed'] ?? 0);
        $sellClosed = $side === 'seller' ? 1 : (int)($case['seller_case_closed'] ?? 0);
        if ($side === 'customer') {
            $custClosed = 1;
        } else {
            $sellClosed = 1;
        }
        $status = (string)($case['status'] ?? 'open');
        $resolvedAt = null;
        if ($custClosed === 1 && $sellClosed === 1) {
            $status = 'resolved';
            $resolvedAt = date('Y-m-d H:i:s');
        }
        try {
            $st = $dbh->prepare("
                UPDATE commerce_disputes
                SET customer_case_closed = :cc,
                    seller_case_closed = :sc,
                    status = :st,
                    resolved_at = COALESCE(resolved_at, :ra),
                    admin_notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ");
            $st->execute([
                ':cc' => $custClosed,
                ':sc' => $sellClosed,
                ':st' => $status,
                ':ra' => $resolvedAt,
                ':notes' => $notes,
                ':id' => $disputeId,
            ]);
            if ($side === 'customer') {
                commerce_dispute_notify_customer_case_closed($dbh, $case);
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('commerce_dispute_notify_customer_case_closed')) {
    /** Tell the buyer their Support Center dispute chat is closed until they Report again. */
    function commerce_dispute_notify_customer_case_closed(PDO $dbh, array $case): void
    {
        $buyerId = (int)($case['buyer_user_id'] ?? 0);
        if ($buyerId <= 0) {
            return;
        }
        require_once __DIR__ . '/admin_support_chat.php';
        $buyerEmail = admin_support_user_email($dbh, $buyerId);
        if ($buyerEmail === '') {
            return;
        }
        $code = trim((string)($case['dispute_code'] ?? ''));
        if ($code === '') {
            $code = commerce_dispute_format_id((int)($case['id'] ?? 0));
        }
        $ctx = commerce_dispute_context_lines($case);
        $body = "[Admin dispute] Your support case {$code} was closed by Admin.\n"
            . "Messaging Admin about this case is no longer available.\n"
            . "To open a new case, go to the product and tap Report.\n\n"
            . $ctx;
        try {
            $dbh->prepare("
                INSERT INTO feedback_admin (sender, receiver, channel, scope, title, feedbackdata, attachment, is_read)
                VALUES ('Admin', :peer, 'dispute', 'customer', 'Customer Dispute', :d, NULL, 0)
            ")->execute([':peer' => $buyerEmail, ':d' => $body]);
        } catch (Throwable $e) {
            try {
                $dbh->prepare("
                    INSERT INTO feedback_admin (sender, receiver, channel, title, feedbackdata, attachment, is_read)
                    VALUES ('Admin', :peer, 'dispute', 'Customer Dispute', :d, NULL, 0)
                ")->execute([':peer' => $buyerEmail, ':d' => $body]);
            } catch (Throwable $e2) {
                // ignore notify failure
            }
        }
    }
}

if (!function_exists('commerce_dispute_buyer_has_open_customer_case')) {
    /** True when buyer has at least one dispute with customer_case_closed = 0. */
    function commerce_dispute_buyer_has_open_customer_case(PDO $dbh, int $buyerUserId): bool
    {
        if ($buyerUserId <= 0) {
            return false;
        }
        commerce_disputes_ensure_schema($dbh);
        try {
            $st = $dbh->prepare("
                SELECT 1 FROM commerce_disputes
                WHERE buyer_user_id = :b
                  AND customer_case_closed = 0
                  AND status IN ('open', 'seller_notified')
                LIMIT 1
            ");
            $st->execute([':b' => $buyerUserId]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('commerce_dispute_buyer_latest_open_case')) {
    /** @return array<string,mixed>|null */
    function commerce_dispute_buyer_latest_open_case(PDO $dbh, int $buyerUserId): ?array
    {
        if ($buyerUserId <= 0) {
            return null;
        }
        commerce_disputes_ensure_schema($dbh);
        try {
            $st = $dbh->prepare("
                SELECT * FROM commerce_disputes
                WHERE buyer_user_id = :b
                  AND customer_case_closed = 0
                  AND status IN ('open', 'seller_notified')
                ORDER BY updated_at DESC, id DESC
                LIMIT 1
            ");
            $st->execute([':b' => $buyerUserId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('commerce_dispute_list_for_buyer')) {
    /**
     * Buyer Support Center case list (open + closed) for ⋯ history.
     * @return list<array<string,mixed>>
     */
    function commerce_dispute_list_for_buyer(PDO $dbh, int $buyerUserId, int $limit = 40): array
    {
        if ($buyerUserId <= 0) {
            return [];
        }
        commerce_disputes_ensure_schema($dbh);
        try {
            $st = $dbh->prepare("
                SELECT * FROM commerce_disputes
                WHERE buyer_user_id = :b
                ORDER BY updated_at DESC, id DESC
                LIMIT " . max(1, min(80, $limit))
            );
            $st->execute([':b' => $buyerUserId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $code = trim((string)($row['dispute_code'] ?? ''));
            if ($code === '' && $id > 0) {
                $code = commerce_dispute_format_id($id);
            }
            $pid = (int)($row['product_id'] ?? 0);
            $title = trim((string)($row['product_title'] ?? ''));
            if ($title === '' && $pid > 0) {
                $title = 'Product #' . $pid;
            }
            $cover = '';
            if (function_exists('org_shop_cover_url')) {
                $cover = org_shop_cover_url((string)($row['product_cover_path'] ?? ''));
            }
            $custClosed = (int)($row['customer_case_closed'] ?? 0) === 1;
            $status = strtolower(trim((string)($row['status'] ?? '')));
            $isOpen = !$custClosed && in_array($status, ['open', 'seller_notified'], true);
            $updated = trim((string)($row['updated_at'] ?? $row['created_at'] ?? ''));
            $ts = $updated !== '' ? strtotime($updated) : false;
            $out[] = [
                'id' => $id,
                'code' => $code,
                'product_id' => $pid,
                'product_title' => $title,
                'product_cover' => $cover,
                'seller_business' => trim((string)($row['seller_business_name'] ?? '')),
                'status' => $status,
                'is_open' => $isOpen,
                'customer_case_closed' => $custClosed,
                'updated_at' => $updated,
                'time_label' => $ts ? date('M j, Y', $ts) : '',
                'buyer_href' => $pid > 0 ? ('product_detail.php?id=' . $pid) : '',
            ];
        }
        return $out;
    }
}

if (!function_exists('commerce_dispute_get_for_buyer')) {
    /** @return array<string,mixed>|null */
    function commerce_dispute_get_for_buyer(PDO $dbh, int $buyerUserId, int $disputeId): ?array
    {
        if ($buyerUserId <= 0 || $disputeId <= 0) {
            return null;
        }
        commerce_disputes_ensure_schema($dbh);
        try {
            $st = $dbh->prepare('SELECT * FROM commerce_disputes WHERE id = :id AND buyer_user_id = :b LIMIT 1');
            $st->execute([':id' => $disputeId, ':b' => $buyerUserId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('commerce_dispute_refund_and_suspend')) {
    /**
     * Seller missed the 30-day window: mark refund responsibility + temporary shop suspension.
     * @return array{ok:bool,error?:string}
     */
    function commerce_dispute_refund_and_suspend(PDO $dbh, int $disputeId, string $note = ''): array
    {
        $case = commerce_dispute_get($dbh, $disputeId);
        if (!$case) {
            return ['ok' => false, 'error' => 'Dispute not found.'];
        }
        $orgId = (int)($case['org_id'] ?? 0);
        $publisherId = (int)($case['publisher_user_id'] ?? 0);
        $buyerId = (int)($case['buyer_user_id'] ?? 0);

        // Suspend seller shop (temporary cessation until payback / Admin clears).
        if ($orgId > 0) {
            try {
                $dbh->prepare('UPDATE organizations SET status = 0 WHERE id = :id LIMIT 1')
                    ->execute([':id' => $orgId]);
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => 'Could not suspend seller business.'];
            }
        }

        $notes = trim((string)($case['admin_notes'] ?? ''));
        $stamp = date('Y-m-d H:i') . ' refund+suspend: ' . mb_substr(trim($note) !== '' ? trim($note) : 'Seller did not respond within 30 days. Admin refunds customer; shop suspended until seller pays Admin back.', 0, 800);
        $notes = $notes !== '' ? ($notes . "\n" . $stamp) : $stamp;

        try {
            $dbh->prepare("
                UPDATE commerce_disputes
                SET status = 'refunded_suspended',
                    customer_case_closed = 1,
                    seller_case_closed = 1,
                    refund_marked_at = NOW(),
                    suspended_at = NOW(),
                    resolved_at = COALESCE(resolved_at, NOW()),
                    admin_notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ")->execute([':notes' => $notes, ':id' => $disputeId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not update dispute case.'];
        }

        // Notify buyer + seller.
        require_once __DIR__ . '/admin_support_chat.php';
        $buyerEmail = $buyerId > 0 ? admin_support_user_email($dbh, $buyerId) : '';
        $sellerEmail = $publisherId > 0 ? admin_support_user_email($dbh, $publisherId) : '';
        $code = trim((string)($case['dispute_code'] ?? commerce_dispute_format_id($disputeId)));
        $ctx = commerce_dispute_context_lines($case);

        if ($buyerEmail !== '') {
            $buyerBody = "[Admin dispute] Your case {$code} is closed.\n"
                . "The seller did not respond in time. Admin is processing your refund.\n\n" . $ctx;
            try {
                $dbh->prepare("
                    INSERT INTO feedback_admin (sender, receiver, channel, scope, title, feedbackdata, attachment, is_read)
                    VALUES ('Admin', :peer, 'dispute', 'customer', 'Customer Dispute', :d, NULL, 0)
                ")->execute([':peer' => $buyerEmail, ':d' => $buyerBody]);
            } catch (Throwable $e) {
                try {
                    $dbh->prepare("
                        INSERT INTO feedback_admin (sender, receiver, channel, title, feedbackdata, attachment, is_read)
                        VALUES ('Admin', :peer, 'dispute', 'Customer Dispute', :d, NULL, 0)
                    ")->execute([':peer' => $buyerEmail, ':d' => $buyerBody]);
                } catch (Throwable $e2) {
                    // ignore notify failure
                }
            }
        }

        if ($sellerEmail !== '') {
            $sellerBody = "[Admin dispute] Dispute {$code}: your shop is temporarily suspended.\n"
                . "You did not respond within 30 days. Admin refunded the customer.\n"
                . "Contact Admin to pay back and request shop reinstatement.\n\n" . $ctx;
            try {
                $dbh->prepare("
                    INSERT INTO feedback_admin (sender, receiver, channel, scope, title, feedbackdata, attachment, is_read)
                    VALUES ('Admin', :peer, 'dispute', 'seller', 'Seller Order Dispute', :d, NULL, 0)
                ")->execute([':peer' => $sellerEmail, ':d' => $sellerBody]);
            } catch (Throwable $e) {
                try {
                    $dbh->prepare("
                        INSERT INTO feedback_admin (sender, receiver, channel, title, feedbackdata, attachment, is_read)
                        VALUES ('Admin', :peer, 'dispute', 'Seller Order Dispute', :d, NULL, 0)
                    ")->execute([':peer' => $sellerEmail, ':d' => $sellerBody]);
                } catch (Throwable $e2) {
                    // ignore
                }
            }
        }

        return ['ok' => true];
    }
}

if (!function_exists('commerce_dispute_list_for_seller')) {
    /** @return list<array<string,mixed>> */
    function commerce_dispute_list_for_seller(PDO $dbh, int $publisherUserId, int $limit = 20): array
    {
        if ($publisherUserId <= 0) {
            return [];
        }
        commerce_disputes_ensure_schema($dbh);
        try {
            $st = $dbh->prepare("
                SELECT * FROM commerce_disputes
                WHERE publisher_user_id = :p
                  AND status IN ('open', 'seller_notified')
                  AND seller_case_closed = 0
                ORDER BY updated_at DESC
                LIMIT " . max(1, min(50, $limit))
            );
            $st->execute([':p' => $publisherUserId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('commerce_dispute_seller_latest_open_case')) {
    /** @return array<string,mixed>|null */
    function commerce_dispute_seller_latest_open_case(PDO $dbh, int $publisherUserId): ?array
    {
        $rows = commerce_dispute_list_for_seller($dbh, $publisherUserId, 1);
        return $rows[0] ?? null;
    }
}

if (!function_exists('commerce_dispute_list_history_for_seller')) {
    /**
     * All seller disputes (open + closed) for Support Center ⋯ Product ID history.
     * @return list<array<string,mixed>>
     */
    function commerce_dispute_list_history_for_seller(PDO $dbh, int $publisherUserId, int $limit = 40): array
    {
        if ($publisherUserId <= 0) {
            return [];
        }
        commerce_disputes_ensure_schema($dbh);
        try {
            $st = $dbh->prepare("
                SELECT * FROM commerce_disputes
                WHERE publisher_user_id = :p
                ORDER BY updated_at DESC, id DESC
                LIMIT " . max(1, min(80, $limit))
            );
            $st->execute([':p' => $publisherUserId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $code = trim((string)($row['dispute_code'] ?? ''));
            if ($code === '' && $id > 0) {
                $code = commerce_dispute_format_id($id);
            }
            $pid = (int)($row['product_id'] ?? 0);
            $title = trim((string)($row['product_title'] ?? ''));
            if ($title === '' && $pid > 0) {
                $title = 'Product #' . $pid;
            }
            $cover = '';
            if (function_exists('org_shop_cover_url')) {
                $cover = org_shop_cover_url((string)($row['product_cover_path'] ?? ''));
            }
            $sellerClosed = (int)($row['seller_case_closed'] ?? 0) === 1;
            $status = strtolower(trim((string)($row['status'] ?? '')));
            $isOpen = !$sellerClosed && in_array($status, ['open', 'seller_notified'], true);
            $updated = trim((string)($row['updated_at'] ?? $row['created_at'] ?? ''));
            $ts = $updated !== '' ? strtotime($updated) : false;
            $out[] = [
                'id' => $id,
                'code' => $code,
                'product_id' => $pid,
                'product_title' => $title,
                'product_cover' => $cover,
                'seller_business' => trim((string)($row['seller_business_name'] ?? '')),
                'status' => $status,
                'is_open' => $isOpen,
                'seller_case_closed' => $sellerClosed,
                'updated_at' => $updated,
                'time_label' => $ts ? date('M j, Y', $ts) : '',
                'seller_href' => $pid > 0
                    ? ('sales_management.php?inv_product=' . $pid . '#inventory-detail')
                    : '',
            ];
        }
        return $out;
    }
}

if (!function_exists('commerce_dispute_get_for_seller')) {
    /** @return array<string,mixed>|null */
    function commerce_dispute_get_for_seller(PDO $dbh, int $publisherUserId, int $disputeId): ?array
    {
        if ($publisherUserId <= 0 || $disputeId <= 0) {
            return null;
        }
        commerce_disputes_ensure_schema($dbh);
        try {
            $st = $dbh->prepare('SELECT * FROM commerce_disputes WHERE id = :id AND publisher_user_id = :p LIMIT 1');
            $st->execute([':id' => $disputeId, ':p' => $publisherUserId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('commerce_dispute_list_open_admin')) {
    /** @return list<array<string,mixed>> */
    function commerce_dispute_list_open_admin(PDO $dbh, int $limit = 50): array
    {
        commerce_disputes_ensure_schema($dbh);
        try {
            $st = $dbh->query("
                SELECT * FROM commerce_disputes
                WHERE status IN ('open', 'seller_notified')
                   OR (customer_case_closed = 0 OR seller_case_closed = 0)
                ORDER BY updated_at DESC
                LIMIT " . max(1, min(100, $limit))
            );
            return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('commerce_dispute_buyer_chat_url')) {
    function commerce_dispute_buyer_chat_url(array $case): string
    {
        $buyerId = (int)($case['buyer_user_id'] ?? 0);
        $productId = (int)($case['product_id'] ?? 0);
        if ($buyerId <= 0) {
            return 'sales_management.php#message';
        }
        if (function_exists('commerce_message_buyer_sales_url')) {
            return commerce_message_buyer_sales_url($buyerId, $productId);
        }
        $q = ['buyer_msg' => $buyerId];
        if ($productId > 0) {
            $q['about_product'] = $productId;
        }
        return 'sales_management.php?' . http_build_query($q) . '#message';
    }
}
