<?php
declare(strict_types=1);

/**
 * Helpers for Settings → Integrations (catalog + platform settings map).
 */

if (!function_exists('admin_integrations_h')) {
    function admin_integrations_h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_integrations_normalize_status')) {
    function admin_integrations_normalize_status(string $status): string
    {
        $s = strtolower(trim($status));
        return in_array($s, ['active', 'inactive', 'failed'], true) ? $s : 'inactive';
    }
}

if (!function_exists('admin_integrations_now')) {
    function admin_integrations_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('admin_integrations_format_dt')) {
    function admin_integrations_format_dt(?string $raw): string
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return '—';
        }
        $ts = strtotime($raw);
        return $ts !== false ? date('M j, Y g:i A', $ts) : $raw;
    }
}

if (!function_exists('admin_integrations_catalog')) {
    /**
     * Fixed catalog of 12 integrations.
     *
     * @return list<array{id:string,name:string,description:string,category:string,icon:string,popular_pct:int}>
     */
    function admin_integrations_catalog(): array
    {
        return [
            [
                'id' => 'google_analytics',
                'name' => 'Google Analytics 4',
                'description' => 'Track traffic, engagement, and conversions across the Admin Panel.',
                'category' => 'Analytics',
                'icon' => 'fa-line-chart',
                'popular_pct' => 92,
            ],
            [
                'id' => 'slack',
                'name' => 'Slack',
                'description' => 'Send alerts and moderation updates to your team channels.',
                'category' => 'Communication',
                'icon' => 'fa-slack',
                'popular_pct' => 88,
            ],
            [
                'id' => 'sendgrid',
                'name' => 'SendGrid',
                'description' => 'Transactional email delivery for invites, resets, and alerts.',
                'category' => 'Email',
                'icon' => 'fa-paper-plane',
                'popular_pct' => 81,
            ],
            [
                'id' => 'stripe',
                'name' => 'Stripe',
                'description' => 'Payments, Connect payouts, and commerce billing.',
                'category' => 'Payments',
                'icon' => 'fa-cc-stripe',
                'popular_pct' => 95,
            ],
            [
                'id' => 'aws_s3',
                'name' => 'AWS S3',
                'description' => 'Object storage for media uploads and backups.',
                'category' => 'Storage',
                'icon' => 'fa-amazon',
                'popular_pct' => 74,
            ],
            [
                'id' => 'cloudflare',
                'name' => 'Cloudflare',
                'description' => 'CDN, DNS, and edge security for public traffic.',
                'category' => 'Security',
                'icon' => 'fa-cloud',
                'popular_pct' => 69,
            ],
            [
                'id' => 'twilio',
                'name' => 'Twilio',
                'description' => 'SMS and voice messaging for verification and alerts.',
                'category' => 'Communication',
                'icon' => 'fa-phone',
                'popular_pct' => 61,
            ],
            [
                'id' => 'sentry',
                'name' => 'Sentry',
                'description' => 'Error monitoring and performance traces for developers.',
                'category' => 'Developer',
                'icon' => 'fa-bug',
                'popular_pct' => 77,
            ],
            [
                'id' => 'github',
                'name' => 'GitHub',
                'description' => 'Source control webhooks and deployment notifications.',
                'category' => 'Developer',
                'icon' => 'fa-github',
                'popular_pct' => 86,
            ],
            [
                'id' => 'microsoft_teams',
                'name' => 'Microsoft Teams',
                'description' => 'Push admin alerts into Teams channels and chats.',
                'category' => 'Communication',
                'icon' => 'fa-users',
                'popular_pct' => 64,
            ],
            [
                'id' => 'zapier',
                'name' => 'Zapier',
                'description' => 'Automate workflows between the Admin Panel and other apps.',
                'category' => 'Automation',
                'icon' => 'fa-bolt',
                'popular_pct' => 79,
            ],
            [
                'id' => 'mailchimp',
                'name' => 'Mailchimp',
                'description' => 'Audience sync and marketing campaigns for email lists.',
                'category' => 'Email',
                'icon' => 'fa-envelope',
                'popular_pct' => 58,
            ],
        ];
    }
}

if (!function_exists('admin_integrations_catalog_ids')) {
    /**
     * @return list<string>
     */
    function admin_integrations_catalog_ids(): array
    {
        $ids = [];
        foreach (admin_integrations_catalog() as $row) {
            $ids[] = (string)$row['id'];
        }
        return $ids;
    }
}

if (!function_exists('admin_integrations_detect_cfg')) {
    /**
     * @return object|null
     */
    function admin_integrations_detect_cfg(?object $cfg = null): ?object
    {
        if (is_object($cfg)) {
            return $cfg;
        }
        try {
            if (!class_exists('Config', false) && is_file(dirname(__DIR__, 2) . '/config.php')) {
                require_once dirname(__DIR__, 2) . '/config.php';
            }
            if (class_exists('Config', false)) {
                return new Config();
            }
        } catch (Throwable $e) {
        }
        return null;
    }
}

if (!function_exists('admin_integrations_stripe_configured')) {
    function admin_integrations_stripe_configured(?object $cfg): bool
    {
        if (!is_object($cfg)) {
            return false;
        }
        $secret = trim((string)($cfg->STRIPE_SECRET_KEY ?? ''));
        $pub = trim((string)($cfg->STRIPE_PUBLISHABLE_KEY ?? ''));
        return $secret !== '' || $pub !== '';
    }
}

if (!function_exists('admin_integrations_smtp_host')) {
    function admin_integrations_smtp_host(?object $cfg, array $settings = []): string
    {
        if (is_object($cfg)) {
            $host = trim((string)($cfg->SMTP_HOST ?? ''));
            if ($host !== '') {
                return $host;
            }
        }
        return trim((string)($settings['smtp_host'] ?? ''));
    }
}

if (!function_exists('admin_integrations_sendgrid_live_active')) {
    /**
     * SendGrid is treated active for display when SMTP host looks like SendGrid,
     * or when SMTP is configured (related email delivery).
     */
    function admin_integrations_sendgrid_live_active(?object $cfg, array $settings = []): bool
    {
        $host = strtolower(admin_integrations_smtp_host($cfg, $settings));
        if ($host === '') {
            return false;
        }
        if (strpos($host, 'sendgrid') !== false) {
            return true;
        }
        // SMTP configured → related email channel considered active for display.
        return true;
    }
}

if (!function_exists('admin_integrations_default_map')) {
    /**
     * Demo defaults (overridable in integrations_map).
     *
     * @return array<string,array{status:string,last_sync:string,notes:string}>
     */
    function admin_integrations_default_map(?object $cfg = null): array
    {
        $cfg = admin_integrations_detect_cfg($cfg);
        $stripeOn = admin_integrations_stripe_configured($cfg);
        $sync = [
            'google_analytics' => '2026-08-14 09:12:00',
            'slack' => '2026-08-15 07:40:00',
            'sendgrid' => '2026-08-15 06:05:00',
            'stripe' => $stripeOn ? '2026-08-15 08:22:00' : '',
            'aws_s3' => '2026-08-13 18:30:00',
            'cloudflare' => '',
            'twilio' => '',
            'sentry' => '2026-08-12 11:15:00',
            'github' => '2026-08-14 21:00:00',
            'microsoft_teams' => '2026-08-14 16:45:00',
            'zapier' => '2026-08-13 12:00:00',
            'mailchimp' => '2026-08-11 10:20:00',
        ];
        $active = [
            'google_analytics', 'slack', 'sendgrid', 'aws_s3',
            'github', 'microsoft_teams', 'mailchimp', 'zapier',
        ];
        $inactive = ['cloudflare', 'twilio'];
        $failed = ['sentry'];

        $map = [];
        foreach (admin_integrations_catalog_ids() as $id) {
            if ($id === 'stripe') {
                $status = $stripeOn ? 'active' : 'inactive';
            } elseif (in_array($id, $active, true)) {
                $status = 'active';
            } elseif (in_array($id, $failed, true)) {
                $status = 'failed';
            } elseif (in_array($id, $inactive, true)) {
                $status = 'inactive';
            } else {
                $status = 'inactive';
            }
            $map[$id] = [
                'status' => $status,
                'last_sync' => (string)($sync[$id] ?? ''),
                'notes' => '',
            ];
        }
        return $map;
    }
}

if (!function_exists('admin_integrations_map_from_settings')) {
    /**
     * @param array<string,mixed> $settings
     * @return array<string,array{status:string,last_sync:string,notes:string}>
     */
    function admin_integrations_map_from_settings(array $settings, ?object $cfg = null): array
    {
        $defaults = admin_integrations_default_map($cfg);
        $raw = $settings['integrations_map'] ?? null;
        if (!is_array($raw) || $raw === []) {
            return $defaults;
        }
        $out = $defaults;
        foreach ($raw as $id => $entry) {
            $id = strtolower(trim((string)$id));
            if ($id === '' || !isset($out[$id]) || !is_array($entry)) {
                continue;
            }
            $status = admin_integrations_normalize_status((string)($entry['status'] ?? $out[$id]['status']));
            $out[$id] = [
                'status' => $status,
                'last_sync' => trim((string)($entry['last_sync'] ?? $out[$id]['last_sync'])),
                'notes' => trim((string)($entry['notes'] ?? '')),
            ];
        }
        return $out;
    }
}

if (!function_exists('admin_integrations_ensure_map')) {
    /**
     * Ensure integrations_map exists on settings (mutates).
     *
     * @param array<string,mixed> $settings
     */
    function admin_integrations_ensure_map(array &$settings, ?object $cfg = null): void
    {
        if (!isset($settings['integrations_map']) || !is_array($settings['integrations_map']) || $settings['integrations_map'] === []) {
            $settings['integrations_map'] = admin_integrations_default_map($cfg);
        } else {
            $settings['integrations_map'] = admin_integrations_map_from_settings($settings, $cfg);
        }
    }
}

if (!function_exists('admin_integrations_resolve')) {
    /**
     * Merge catalog + map + live stripe/smtp detection.
     *
     * @param array<string,mixed> $settings
     * @return list<array<string,mixed>>
     */
    function admin_integrations_resolve(?PDO $dbh, array $settings, $cfg = null): array
    {
        unset($dbh); // reserved for future live probes
        $cfgObj = is_object($cfg) ? $cfg : admin_integrations_detect_cfg(null);
        $map = admin_integrations_map_from_settings($settings, $cfgObj);
        $stripeOn = admin_integrations_stripe_configured($cfgObj);
        $sendgridLive = admin_integrations_sendgrid_live_active($cfgObj, $settings);
        $rows = [];

        foreach (admin_integrations_catalog() as $item) {
            $id = (string)$item['id'];
            $entry = $map[$id] ?? ['status' => 'inactive', 'last_sync' => '', 'notes' => ''];
            $status = admin_integrations_normalize_status((string)($entry['status'] ?? 'inactive'));

            if ($id === 'stripe') {
                if ($stripeOn) {
                    $status = 'active';
                }
                // empty keys → keep map status
            } elseif ($id === 'sendgrid') {
                // Map remains source of truth, but live SMTP/SendGrid detection
                // can promote display to active when email delivery is configured.
                if ($sendgridLive && $status === 'inactive') {
                    $status = 'active';
                }
                if (strpos(strtolower(admin_integrations_smtp_host($cfgObj, $settings)), 'sendgrid') !== false) {
                    $status = 'active';
                }
            }

            $rows[] = [
                'id' => $id,
                'name' => (string)$item['name'],
                'description' => (string)$item['description'],
                'category' => (string)$item['category'],
                'icon' => (string)$item['icon'],
                'popular_pct' => (int)$item['popular_pct'],
                'status' => $status,
                'last_sync' => (string)($entry['last_sync'] ?? ''),
                'notes' => (string)($entry['notes'] ?? ''),
                'stripe_configured' => $id === 'stripe' ? $stripeOn : false,
                'manage_url' => $id === 'stripe' ? 'org_stripe_connect.php' : '',
            ];
        }

        return $rows;
    }
}

if (!function_exists('admin_integrations_stats')) {
    /**
     * @param list<array<string,mixed>> $rows
     * @return array{total:int,active:int,inactive:int,failed:int,active_pct:int,inactive_pct:int,failed_pct:int}
     */
    function admin_integrations_stats(array $rows): array
    {
        $total = count($rows);
        $active = 0;
        $inactive = 0;
        $failed = 0;
        foreach ($rows as $r) {
            $st = admin_integrations_normalize_status((string)($r['status'] ?? 'inactive'));
            if ($st === 'active') {
                $active++;
            } elseif ($st === 'failed') {
                $failed++;
            } else {
                $inactive++;
            }
        }
        $pct = static function (int $n, int $t): int {
            return $t > 0 ? (int)round(($n / $t) * 100) : 0;
        };
        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'failed' => $failed,
            'active_pct' => $pct($active, $total),
            'inactive_pct' => $pct($inactive, $total),
            'failed_pct' => $pct($failed, $total),
        ];
    }
}

if (!function_exists('admin_integrations_filter')) {
    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    function admin_integrations_filter(array $rows, string $itab, string $iq, string $icat): array
    {
        $itab = strtolower(trim($itab));
        if (!in_array($itab, ['all', 'active', 'inactive', 'failed'], true)) {
            $itab = 'all';
        }
        $iq = trim($iq);
        $icat = trim($icat);
        if (strcasecmp($icat, 'All Categories') === 0) {
            $icat = '';
        }

        $out = [];
        foreach ($rows as $r) {
            $st = admin_integrations_normalize_status((string)($r['status'] ?? 'inactive'));
            if ($itab !== 'all' && $st !== $itab) {
                continue;
            }
            if ($icat !== '' && strcasecmp((string)($r['category'] ?? ''), $icat) !== 0) {
                continue;
            }
            if ($iq !== '') {
                $hay = strtolower(
                    (string)($r['name'] ?? '') . ' ' .
                    (string)($r['description'] ?? '') . ' ' .
                    (string)($r['category'] ?? '') . ' ' .
                    (string)($r['id'] ?? '')
                );
                if (strpos($hay, strtolower($iq)) === false) {
                    continue;
                }
            }
            $out[] = $r;
        }
        return $out;
    }
}

if (!function_exists('admin_integrations_categories')) {
    /**
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    function admin_integrations_categories(array $rows): array
    {
        $cats = [];
        foreach ($rows as $r) {
            $c = trim((string)($r['category'] ?? ''));
            if ($c !== '' && !in_array($c, $cats, true)) {
                $cats[] = $c;
            }
        }
        sort($cats, SORT_NATURAL | SORT_FLAG_CASE);
        return $cats;
    }
}

if (!function_exists('admin_integrations_popular')) {
    /**
     * @param list<array<string,mixed>>|null $catalogRows
     * @return list<array{id:string,name:string,icon:string,popular_pct:int}>
     */
    function admin_integrations_popular(?array $catalogRows = null, int $limit = 5): array
    {
        $rows = $catalogRows ?? admin_integrations_catalog();
        usort($rows, static function ($a, $b): int {
            return ((int)($b['popular_pct'] ?? 0)) <=> ((int)($a['popular_pct'] ?? 0));
        });
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (string)$r['id'],
                'name' => (string)$r['name'],
                'icon' => (string)$r['icon'],
                'popular_pct' => (int)$r['popular_pct'],
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }
}

if (!function_exists('admin_integrations_set_status')) {
    /**
     * @param array<string,mixed> $settings
     */
    function admin_integrations_set_status(array &$settings, string $id, string $status, bool $touchSync = false): bool
    {
        $id = strtolower(trim($id));
        if (!in_array($id, admin_integrations_catalog_ids(), true)) {
            return false;
        }
        $status = admin_integrations_normalize_status($status);
        admin_integrations_ensure_map($settings);
        if (!isset($settings['integrations_map'][$id]) || !is_array($settings['integrations_map'][$id])) {
            $settings['integrations_map'][$id] = ['status' => 'inactive', 'last_sync' => '', 'notes' => ''];
        }
        $settings['integrations_map'][$id]['status'] = $status;
        if ($touchSync || $status === 'active') {
            $settings['integrations_map'][$id]['last_sync'] = admin_integrations_now();
        }
        return true;
    }
}

if (!function_exists('admin_integrations_retry')) {
    /**
     * Mark a failed integration active and refresh last_sync.
     *
     * @param array<string,mixed> $settings
     */
    function admin_integrations_retry(array &$settings, string $id): bool
    {
        return admin_integrations_set_status($settings, $id, 'active', true);
    }
}

if (!function_exists('admin_integrations_sync')) {
    /**
     * Touch last_sync; optionally promote to active.
     *
     * @param array<string,mixed> $settings
     */
    function admin_integrations_sync(array &$settings, string $id, bool $setActive = false): bool
    {
        $id = strtolower(trim($id));
        if (!in_array($id, admin_integrations_catalog_ids(), true)) {
            return false;
        }
        admin_integrations_ensure_map($settings);
        if (!isset($settings['integrations_map'][$id]) || !is_array($settings['integrations_map'][$id])) {
            $settings['integrations_map'][$id] = ['status' => 'inactive', 'last_sync' => '', 'notes' => ''];
        }
        $settings['integrations_map'][$id]['last_sync'] = admin_integrations_now();
        if ($setActive) {
            $settings['integrations_map'][$id]['status'] = 'active';
        }
        return true;
    }
}
