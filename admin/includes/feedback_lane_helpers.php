<?php
declare(strict_types=1);

/**
 * Shared Help-lane SQL for admin/feedback.php and admin/mailbox.php.
 * Keeps Personal / Customer / Seller / Publisher threads from mixing.
 */

if (!function_exists('feedback_sql_not_content_report')) {
    /** Exclude legacy Content Report rows and commerce disputes from Help. */
    function feedback_sql_not_content_report(string $alias = 'f'): string
    {
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'f';
        return "(
            COALESCE({$a}.title, '') <> 'Content Report'
            AND COALESCE({$a}.feedbackdata, '') NOT LIKE '[Report #%'
            AND COALESCE({$a}.feedbackdata, '') NOT LIKE 'Reporter message:%'
            AND COALESCE({$a}.title, '') NOT LIKE '%Dispute%'
            AND COALESCE({$a}.feedbackdata, '') NOT LIKE '[Dispute]%'
            AND COALESCE({$a}.feedbackdata, '') NOT LIKE '[Seller dispute]%'
            AND COALESCE({$a}.channel, '') <> 'dispute'
        )";
    }
}

if (!function_exists('feedback_sql_public_lane')) {
    /**
     * SQL fragment matching a public support lane (scope + title/body fallback).
     *
     * @return array{0:string,1:array<string,string>}
     */
    function feedback_sql_public_lane(string $lane, string $alias = 'f'): array
    {
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'f';
        $lane = strtolower(trim($lane));
        if ($lane === '' || $lane === 'all') {
            return ['1=1', []];
        }
        if (!in_array($lane, ['personal', 'customer', 'seller', 'publisher'], true)) {
            return ['1=0', []];
        }

        $scopeOk = "LOWER(TRIM(COALESCE({$a}.scope, ''))) = :lane_scope";
        $params = [':lane_scope' => $lane];

        if ($lane === 'seller') {
            $fallback = "(
                (
                  COALESCE({$a}.title, '') LIKE 'Seller%'
                  OR COALESCE({$a}.feedbackdata, '') LIKE '[Seller %'
                )
                AND COALESCE({$a}.title, '') NOT LIKE '%Dispute%'
                AND COALESCE({$a}.feedbackdata, '') NOT LIKE '[Seller dispute]%'
            )";
        } elseif ($lane === 'publisher') {
            $fallback = "(
                COALESCE({$a}.title, '') LIKE 'Publisher%'
                OR COALESCE({$a}.feedbackdata, '') LIKE '[Publisher %'
            )";
        } elseif ($lane === 'personal') {
            $fallback = "(
                COALESCE({$a}.title, '') LIKE 'Personal%'
                OR COALESCE({$a}.feedbackdata, '') LIKE '[Personal %'
            )";
        } else {
            // customer help only (disputes live on dispute.php)
            $fallback = "(
                COALESCE({$a}.title, '') LIKE 'Customer Help%'
                OR COALESCE({$a}.feedbackdata, '') LIKE '[Help] %'
            )";
        }

        // Prefer explicit scope; otherwise title/body prefixes from Support Center.
        // Also exclude rows that clearly belong to another lane's scope.
        $sql = "(
            {$scopeOk}
            OR (
                TRIM(COALESCE({$a}.scope, '')) = ''
                AND {$fallback}
            )
        )";
        return [$sql, $params];
    }
}

if (!function_exists('feedback_normalize_public_lane')) {
    function feedback_normalize_public_lane(string $lane): string
    {
        $lane = strtolower(trim($lane));
        return in_array($lane, ['all', 'personal', 'customer', 'seller', 'publisher'], true)
            ? $lane
            : 'all';
    }
}

if (!function_exists('feedback_lane_label')) {
    function feedback_lane_label(string $lane): string
    {
        $lane = feedback_normalize_public_lane($lane);
        $map = [
            'all' => 'All Help',
            'personal' => 'Personal Help',
            'customer' => 'Customer Help',
            'seller' => 'Seller Help',
            'publisher' => 'Publisher Help',
        ];
        return $map[$lane] ?? 'Help';
    }
}

if (!function_exists('feedback_lane_inbox_title')) {
    /** Short label for mailbox header / nav. */
    function feedback_lane_inbox_title(string $lane): string
    {
        $lane = feedback_normalize_public_lane($lane);
        $map = [
            'all' => 'Public Help',
            'personal' => 'Personal Inbox',
            'customer' => 'Customer Inbox',
            'seller' => 'Seller Inbox',
            'publisher' => 'Publisher Inbox',
        ];
        return $map[$lane] ?? 'Public Help';
    }
}

if (!function_exists('feedback_lane_reply_title')) {
    /** Default thread title prefix when admin replies in a lane. */
    function feedback_lane_reply_title(string $lane): string
    {
        $lane = feedback_normalize_public_lane($lane);
        $map = [
            'personal' => 'Personal Help',
            'customer' => 'Customer Help',
            'seller' => 'Seller Help',
            'publisher' => 'Publisher Help',
        ];
        return $map[$lane] ?? 'Support reply';
    }
}
