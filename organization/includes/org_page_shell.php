<?php
declare(strict_types=1);

/**
 * Legacy helpers — prefer including org_page_shell_head.php / org_page_shell_foot.php directly.
 */
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
