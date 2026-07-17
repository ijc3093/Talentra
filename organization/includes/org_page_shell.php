<?php
declare(strict_types=1);

/**
 * Legacy helpers — prefer including org_page_shell_head.php / org_page_shell_foot.php directly.
 */
if (!function_exists('org_page_body_open')) {
    function org_page_body_open(string $extraClasses = '', string $extraStyle = ''): void
    {
        $classes = trim('sh-pagebody org-surface-page ' . $extraClasses);
        $classAttr = htmlspecialchars($classes, ENT_QUOTES, 'UTF-8');
        $styleAttr = $extraStyle !== ''
            ? ' style="' . htmlspecialchars($extraStyle, ENT_QUOTES, 'UTF-8') . '"'
            : '';
        echo '<div class="' . $classAttr . '"' . $styleAttr . '>';
    }
}

if (!function_exists('org_page_shell_open')) {
    function org_page_shell_open(string $pageTitle, string $extraHead = ''): void
    {
        $GLOBALS['pageTitle'] = $pageTitle;
        $GLOBALS['orgPageExtraHead'] = $extraHead;
        require __DIR__ . '/org_page_shell_head.php';
    }

    function org_page_shell_close(): void
    {
        require __DIR__ . '/org_page_shell_foot.php';
    }
}
