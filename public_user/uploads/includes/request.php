<?php
// /Business_only3/includes/request.php

if (!function_exists('clientIp')) {
    function clientIp(): string
    {
        foreach ([
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = $_SERVER[$k];
                if ($k === 'HTTP_X_FORWARDED_FOR') {
                    $parts = explode(',', $ip);
                    $ip = trim($parts[0]);
                }
                return substr($ip, 0, 64);
            }
        }
        return 'unknown';
    }
}

if (!function_exists('clientUserAgent')) {
    function clientUserAgent(): string
    {
        return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}
