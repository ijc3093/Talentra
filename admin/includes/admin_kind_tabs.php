<?php
declare(strict_types=1);

/**
 * Shared Personal / Publisher / Commerce audience tabs for admin pages.
 */

if (!function_exists('admin_kind_normalize')) {
    /** @return 'personal'|'publisher'|'commerce' */
    function admin_kind_normalize(string $kind): string
    {
        $kind = strtolower(trim($kind));
        return in_array($kind, ['personal', 'publisher', 'commerce'], true) ? $kind : 'personal';
    }
}

if (!function_exists('admin_kind_from_request')) {
    /** @return 'personal'|'publisher'|'commerce' */
    function admin_kind_from_request(?array $src = null): string
    {
        $src = $src ?? $_GET;
        return admin_kind_normalize((string)($src['kind'] ?? 'personal'));
    }
}

if (!function_exists('admin_kind_user_where')) {
    function admin_kind_user_where(string $kind, string $alias = 'u'): string
    {
        $kind = admin_kind_normalize($kind);
        $p = $alias !== '' ? ($alias . '.') : '';
        $commerce = "(LOWER(COALESCE({$p}account_kind,'')) = 'commerce' OR LOWER(COALESCE({$p}publisher_category,'')) = 'commerce')";
        $publisher = "(LOWER(COALESCE({$p}account_kind,'')) = 'publisher' OR UPPER(COALESCE({$p}friend_code,'')) LIKE 'PUB-%')";
        return match ($kind) {
            'commerce' => $commerce,
            'publisher' => "({$publisher}) AND NOT ({$commerce})",
            default => "NOT ({$commerce}) AND NOT ({$publisher})",
        };
    }
}

if (!function_exists('admin_kind_classify_user_row')) {
    /**
     * @param array<string,mixed>|object $row
     * @return 'personal'|'publisher'|'commerce'
     */
    function admin_kind_classify_user_row($row): string
    {
        if (is_object($row)) {
            $row = (array)$row;
        }
        $accountKind = strtolower(trim((string)($row['account_kind'] ?? 'personal')));
        $category = strtolower(trim((string)($row['publisher_category'] ?? '')));
        $friendCode = strtoupper(trim((string)($row['friend_code'] ?? '')));
        if ($accountKind === 'commerce' || $category === 'commerce') {
            return 'commerce';
        }
        if ($accountKind === 'publisher' || strpos($friendCode, 'PUB-') === 0) {
            return 'publisher';
        }
        return 'personal';
    }
}

if (!function_exists('admin_kind_user_counts')) {
    /**
     * @return array{personal:int,publisher:int,commerce:int}
     */
    function admin_kind_user_counts(PDO $dbh): array
    {
        $out = ['personal' => 0, 'publisher' => 0, 'commerce' => 0];
        try {
            $st = $dbh->query("
                SELECT
                  COALESCE(account_kind, 'personal') AS account_kind,
                  COALESCE(publisher_category, '') AS publisher_category,
                  COALESCE(friend_code, '') AS friend_code
                FROM users
            ");
            $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            try {
                $st = $dbh->query("
                    SELECT COALESCE(account_kind, 'personal') AS account_kind,
                           '' AS publisher_category,
                           COALESCE(friend_code, '') AS friend_code
                    FROM users
                ");
                $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            } catch (Throwable $e2) {
                return $out;
            }
        }
        foreach ($rows as $r) {
            $k = admin_kind_classify_user_row($r);
            $out[$k]++;
        }
        return $out;
    }
}

if (!function_exists('admin_kind_blurbs')) {
    /**
     * @return array{personal:string,publisher:string,commerce:string}
     */
    function admin_kind_blurbs(string $context = 'default'): array
    {
        $map = [
            'default' => [
                'personal' => 'Everyday members — profiles, friends, posts, and safety reports.',
                'publisher' => 'Publisher brands and newsrooms — authority requests, public feeds, and content moderation.',
                'commerce' => 'Sellers and shops — brands, listings, rent/Stripe, and disputes.',
            ],
            'posts' => [
                'personal' => 'Posts from personal members — social updates, friends feeds, and profile content.',
                'publisher' => 'Posts from publisher brands and newsrooms — public content feeds and authority profiles.',
                'commerce' => 'Posts from sellers and shops — listings, product updates, and commerce profiles.',
            ],
            'reports' => [
                'personal' => 'Reports about personal members, their posts, profiles, and messages.',
                'publisher' => 'Reports about publisher brands, newsroom posts, and publisher profiles.',
                'commerce' => 'Reports about sellers, products, shops, and commerce orgs.',
            ],
            'activity' => [
                'personal' => 'Login and device activity for personal members.',
                'publisher' => 'Login and device activity for publisher accounts.',
                'commerce' => 'Login and device activity for seller accounts.',
            ],
            'requests' => [
                'personal' => 'Personal accounts do not submit publisher/seller name requests.',
                'publisher' => 'Publisher name and authority requests waiting for admin review.',
                'commerce' => 'Seller / commerce brand requests waiting for admin review.',
            ],
            'disputes' => [
                'personal' => 'Customer-side disputes tied to personal buyers when available.',
                'publisher' => 'Disputes involving publisher-linked shops (rare).',
                'commerce' => 'Seller and commerce order disputes — primary dispute queue.',
            ],
        ];
        return $map[$context] ?? $map['default'];
    }
}

if (!function_exists('admin_kind_tabs_css')) {
    function admin_kind_tabs_css(string $prefix = 'ak'): string
    {
        $p = preg_replace('/[^a-z0-9_-]/i', '', $prefix) ?: 'ak';
        return <<<CSS
  .{$p}-kind-wrap{flex:0 0 auto;display:flex;flex-direction:column;gap:6px;}
  .{$p}-kind-tabs{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
  .{$p}-kind-tabs a{
    display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 12px;border-radius:999px;
    font-size:11px;font-weight:800;color:#64748b;background:#fff;border:1px solid #e2e8f0;text-decoration:none;
  }
  .{$p}-kind-tabs a:hover{border-color:#93c5fd;color:#1e40af;text-decoration:none;}
  .{$p}-kind-tabs a.is-active{background:#2563eb;border-color:#2563eb;color:#fff;}
  .{$p}-kind-tabs a .cnt{
    display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:16px;padding:0 5px;
    border-radius:999px;font-size:9px;font-weight:800;background:#f1f5f9;color:#475569;
  }
  .{$p}-kind-tabs a.is-active .cnt{background:rgba(255,255,255,.22);color:#fff;}
  .{$p}-kind-note{font-size:11px;font-weight:600;color:#64748b;line-height:1.35;padding:0 2px;}
  .{$p}-kind-note strong{color:#0f172a;font-weight:800;}
CSS;
    }
}

if (!function_exists('admin_kind_tabs_html')) {
    /**
     * @param array{personal?:int,publisher?:int,commerce?:int} $counts
     * @param callable(string):string $hrefForKind
     * @param callable(string):string $h
     */
    function admin_kind_tabs_html(
        string $active,
        array $counts,
        callable $hrefForKind,
        callable $h,
        string $prefix = 'ak',
        string $context = 'default'
    ): string {
        $active = admin_kind_normalize($active);
        $prefix = preg_replace('/[^a-z0-9_-]/i', '', $prefix) ?: 'ak';
        $tabs = [
            ['key' => 'personal', 'label' => 'Personal', 'icon' => 'fa-user'],
            ['key' => 'publisher', 'label' => 'Publisher', 'icon' => 'fa-bullhorn'],
            ['key' => 'commerce', 'label' => 'Commerce', 'icon' => 'fa-shopping-bag'],
        ];
        $blurbs = admin_kind_blurbs($context);
        $out = '<div class="' . $h($prefix) . '-kind-wrap">';
        $out .= '<div class="' . $h($prefix) . '-kind-tabs" role="tablist" aria-label="Audience type">';
        foreach ($tabs as $tab) {
            $key = $tab['key'];
            $is = $active === $key;
            $out .= '<a class="' . ($is ? 'is-active' : '') . '" href="' . $h($hrefForKind($key)) . '" role="tab" aria-selected="' . ($is ? 'true' : 'false') . '">';
            $out .= '<i class="fa ' . $h($tab['icon']) . '" aria-hidden="true"></i> ';
            $out .= $h($tab['label']);
            $out .= ' <span class="cnt">' . number_format((int)($counts[$key] ?? 0)) . '</span>';
            $out .= '</a>';
        }
        $out .= '</div>';
        $out .= '<div class="' . $h($prefix) . '-kind-note"><strong>' . $h(ucfirst($active)) . ':</strong> ';
        $out .= $h((string)($blurbs[$active] ?? '')) . '</div>';
        $out .= '</div>';
        return $out;
    }
}
