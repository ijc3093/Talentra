<?php
declare(strict_types=1);

/**
 * Content settings helpers for admin/settings.php?section=content.
 */

if (!function_exists('admin_content_type_catalog')) {
    /**
     * @return array<string,array{label:string,icon:string,color:string}>
     */
    function admin_content_type_catalog(): array
    {
        return [
            'image' => ['label' => 'Image', 'icon' => 'fa-image', 'color' => '#2563eb'],
            'video' => ['label' => 'Video', 'icon' => 'fa-video-camera', 'color' => '#7c3aed'],
            'article' => ['label' => 'Article', 'icon' => 'fa-file-text-o', 'color' => '#16a34a'],
            'document' => ['label' => 'Document', 'icon' => 'fa-file-o', 'color' => '#ca8a04'],
            'pdf' => ['label' => 'PDF', 'icon' => 'fa-file-pdf-o', 'color' => '#dc2626'],
            'audio' => ['label' => 'Audio', 'icon' => 'fa-music', 'color' => '#4f46e5'],
            'gif' => ['label' => 'GIF', 'icon' => 'fa-picture-o', 'color' => '#ea580c'],
        ];
    }
}

if (!function_exists('admin_content_default_types')) {
    /**
     * @return list<string>
     */
    function admin_content_default_types(): array
    {
        return ['image', 'video', 'article', 'document', 'pdf', 'gif'];
    }
}

if (!function_exists('admin_content_normalize_types')) {
    /**
     * @param mixed $raw
     * @return list<string>
     */
    function admin_content_normalize_types($raw): array
    {
        $allowed = array_keys(admin_content_type_catalog());
        if (!is_array($raw)) {
            return admin_content_default_types();
        }
        $out = [];
        foreach ($raw as $id) {
            $id = strtolower(trim((string)$id));
            if (in_array($id, $allowed, true) && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }
}

if (!function_exists('admin_content_metric_cards')) {
    /**
     * @param array<string,mixed> $stats from posts_admin_stats()
     * @return list<array{key:string,label:string,value:int,sub:string,sub_cls:string,icon:string,icon_cls:string}>
     */
    function admin_content_metric_cards(array $stats): array
    {
        $total = (int)($stats['all']['value'] ?? 0);
        $published = (int)($stats['published']['value'] ?? 0);
        $pending = (int)($stats['pending']['value'] ?? 0);
        $removed = (int)($stats['removed']['value'] ?? 0);

        $pct = static function (int $n, int $t): string {
            if ($t <= 0) {
                return '0% of total';
            }
            return rtrim(rtrim(number_format(($n / $t) * 100, 1), '0'), '.') . '% of total';
        };

        return [
            [
                'key' => 'total',
                'label' => 'Total Content',
                'value' => $total,
                'sub' => 'All time',
                'sub_cls' => 'muted',
                'icon' => 'fa-file-text-o',
                'icon_cls' => 'blue',
            ],
            [
                'key' => 'published',
                'label' => 'Published',
                'value' => $published,
                'sub' => $pct($published, $total),
                'sub_cls' => 'ok',
                'icon' => 'fa-check-circle',
                'icon_cls' => 'green',
            ],
            [
                'key' => 'pending',
                'label' => 'Pending Review',
                'value' => $pending,
                'sub' => $pct($pending, $total),
                'sub_cls' => 'warn',
                'icon' => 'fa-clock-o',
                'icon_cls' => 'orange',
            ],
            [
                'key' => 'removed',
                'label' => 'Removed',
                'value' => $removed,
                'sub' => $pct($removed, $total),
                'sub_cls' => 'bad',
                'icon' => 'fa-trash-o',
                'icon_cls' => 'red',
            ],
        ];
    }
}

if (!function_exists('admin_content_max_size_options')) {
    /**
     * @return list<int>
     */
    function admin_content_max_size_options(): array
    {
        return [5, 10, 25, 50, 100, 250, 500];
    }
}

if (!function_exists('admin_content_visibility_options')) {
    /**
     * @return array<string,string>
     */
    function admin_content_visibility_options(): array
    {
        return [
            'public' => 'Public',
            'friends' => 'Friends',
            'private' => 'Private',
        ];
    }
}
