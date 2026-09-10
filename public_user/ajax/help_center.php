<?php
declare(strict_types=1);

/**
 * Help Center nav + articles for the iOS app.
 * Same copy as index.php?tab=help (includes/index_footer_tabs.php).
 */

require_once __DIR__ . '/../includes/index_footer_tabs.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function help_center_json(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function help_center_paragraphs(array $article): array
{
    $out = [];
    foreach ((array)($article['paras'] ?? []) as $para) {
        $para = trim((string)$para);
        if ($para !== '') {
            $out[] = $para;
        }
    }
    foreach (index_help_guides_for_article($article) as $guide) {
        $heading = trim((string)($guide['heading'] ?? $guide['title'] ?? ''));
        if ($heading !== '') {
            $out[] = $heading;
        }
        foreach ((array)($guide['steps'] ?? []) as $step) {
            $step = trim((string)$step);
            if ($step !== '') {
                $out[] = $step;
            }
        }
        $note = trim((string)($guide['note'] ?? ''));
        if ($note !== '') {
            $out[] = $note;
        }
    }
    foreach ((array)($article['sections'] ?? []) as $section) {
        if (!is_array($section) || count($section) < 2) {
            continue;
        }
        $heading = trim((string)$section[0]);
        if ($heading !== '') {
            $out[] = $heading;
        }
        foreach ((array)$section[1] as $para) {
            $para = trim((string)$para);
            if ($para !== '') {
                $out[] = $para;
            }
        }
    }
    return array_values(array_unique($out));
}

function help_center_article_for_tab(string $tab): ?array
{
    $tab = trim($tab);
    if ($tab === '') {
        return null;
    }
    $articles = index_feature_articles();
    if (isset($articles[$tab]) && is_array($articles[$tab])) {
        $article = $articles[$tab];
        return [
            'tab' => $tab,
            'title' => (string)($article['title'] ?? index_help_tab_title($tab)),
            'lead' => (string)($article['lead'] ?? ''),
            'paragraphs' => help_center_paragraphs($article),
        ];
    }
    $topic = index_help_topic_for_tab($tab);
    if ($topic !== null) {
        $paragraphs = [];
        foreach ((array)($topic['items'] ?? []) as $item) {
            $label = trim((string)($item['label'] ?? ''));
            if ($label !== '') {
                $paragraphs[] = $label;
            }
        }
        return [
            'tab' => $tab,
            'title' => (string)($topic['label'] ?? index_help_tab_title($tab)),
            'lead' => 'Open a topic below, or message an admin from Help.',
            'paragraphs' => $paragraphs,
        ];
    }
    $tabs = index_footer_tabs();
    if (isset($tabs[$tab])) {
        return [
            'tab' => $tab,
            'title' => (string)$tabs[$tab],
            'lead' => 'This page lives on Talsora Help.',
            'paragraphs' => ['Open Help on talsora.com for the full article, or message an admin.'],
        ];
    }
    return null;
}

function help_center_nav_payload(): array
{
    $sections = [];
    foreach (index_help_nav_sections() as $section) {
        $topics = [];
        foreach ((array)($section['topics'] ?? []) as $topic) {
            $items = [];
            foreach ((array)($topic['items'] ?? []) as $item) {
                $itemTab = trim((string)($item['tab'] ?? ''));
                $itemLabel = trim((string)($item['label'] ?? ''));
                if ($itemTab === '' && $itemLabel === '') {
                    continue;
                }
                $items[] = [
                    'tab' => $itemTab,
                    'label' => $itemLabel !== '' ? $itemLabel : $itemTab,
                ];
            }
            $topics[] = [
                'id' => (string)($topic['id'] ?? ''),
                'label' => (string)($topic['label'] ?? ''),
                'icon' => (string)($topic['icon'] ?? ''),
                'tab' => index_help_topic_tab($topic),
                'items' => $items,
            ];
        }
        $sections[] = [
            'id' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)($section['label'] ?? ''))),
            'label' => (string)($section['label'] ?? ''),
            'topics' => $topics,
        ];
    }

    $footerTabs = index_footer_tabs();
    foreach (index_footer_tab_groups() as $group) {
        $topics = [];
        foreach ((array)($group['items'] ?? []) as $tabKey) {
            $tabKey = trim((string)$tabKey);
            if ($tabKey === '') {
                continue;
            }
            $label = trim((string)($footerTabs[$tabKey] ?? $tabKey));
            $icons = [
                'about' => 'info',
                'guidance' => 'book',
                'help' => 'phone',
                'shop' => 'cart',
                'popular' => 'star',
                'live' => 'live',
            ];
            $topics[] = [
                'id' => $tabKey,
                'label' => $label,
                'icon' => $icons[$tabKey] ?? 'info',
                'tab' => $tabKey,
                'items' => [
                    ['tab' => $tabKey, 'label' => $label],
                ],
            ];
        }
        $sections[] = [
            'id' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)($group['label'] ?? 'using-talsora'))),
            'label' => (string)($group['label'] ?? 'Using Talsora'),
            'topics' => $topics,
        ];
    }

    return $sections;
}

function help_center_featured_payload(): array
{
    return [
        ['tab' => 'login-cant', 'label' => 'Check your account status', 'text' => 'Can\'t log in, deactivated, or the wrong account type.'],
        ['tab' => 'topic-reels', 'label' => 'Clips', 'text' => 'Create, manage, and share short video.'],
        ['tab' => 'topic-edits', 'label' => 'Edits', 'text' => 'Trim, crop, cover, and drafts.'],
        ['tab' => 'signup-types', 'label' => 'Publisher and commerce', 'text' => 'Public pages and Shop brands.'],
        ['tab' => 'topic-publisher', 'label' => 'Publisher', 'text' => 'Create a public page and get found on Discover.'],
        ['tab' => 'topic-seller', 'label' => 'Seller', 'text' => 'List products, take orders, and get paid.'],
        ['tab' => 'topic-shop-feat', 'label' => 'Shop', 'text' => 'Listings, orders, and purchase protection.'],
        ['tab' => 'help', 'label' => 'Message an admin', 'text' => 'Account, posts, shop, or anything else.'],
    ];
}

$tab = trim((string)($_GET['tab'] ?? $_POST['tab'] ?? ''));
$mode = strtolower(trim((string)($_GET['mode'] ?? $_POST['mode'] ?? '')));
$wantsArticle = $mode === 'article' || ($tab !== '' && $tab !== 'help' && $mode !== 'nav');
if ($wantsArticle) {
    $article = help_center_article_for_tab($tab);
    if ($article === null) {
        help_center_json(['ok' => false, 'error' => 'Help topic not found.']);
    }
    help_center_json(['ok' => true, 'article' => $article]);
}

help_center_json([
    'ok' => true,
    'source' => 'index.php?tab=help',
    'featured' => help_center_featured_payload(),
    'sections' => help_center_nav_payload(),
]);
