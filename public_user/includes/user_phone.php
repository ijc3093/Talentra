<?php
declare(strict_types=1);

if (!function_exists('user_phone_digits')) {
    function user_phone_digits(string $phone): string
    {
        return preg_replace('/\D+/', '', trim($phone)) ?? '';
    }
}

if (!function_exists('user_phone_is_valid')) {
    function user_phone_is_valid(string $phone): bool
    {
        $digits = user_phone_digits($phone);
        $length = strlen($digits);

        return $length >= 7 && $length <= 15;
    }
}

if (!function_exists('user_phone_normalize')) {
    function user_phone_normalize(string $phone): string
    {
        return user_phone_digits($phone);
    }
}

if (!function_exists('user_phone_for_display')) {
    function user_phone_for_display(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || strcasecmp($raw, 'N/A') === 0) {
            return '';
        }
        if (user_phone_is_valid($raw)) {
            return user_phone_normalize($raw);
        }

        return '';
    }
}

if (!function_exists('user_phone_from_user_row')) {
    function user_phone_from_user_row(array $row): string
    {
        foreach (['mobile', 'mobileno', 'phone'] as $key) {
            $display = user_phone_for_display(trim((string)($row[$key] ?? '')));
            if ($display !== '') {
                return $display;
            }
        }

        return '';
    }
}

if (!function_exists('user_phone_raw_from_user_row')) {
    function user_phone_raw_from_user_row(array $row): string
    {
        foreach (['mobile', 'mobileno', 'phone'] as $key) {
            $raw = trim((string)($row[$key] ?? ''));
            if ($raw !== '' && strcasecmp($raw, 'N/A') !== 0) {
                return $raw;
            }
        }

        return '';
    }
}

if (!function_exists('user_phone_repair_invalid_mobile')) {
    function user_phone_repair_invalid_mobile(PDO $dbh, int $userId, array $userRow): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $rawMobile = user_phone_raw_from_user_row($userRow);
        if ($rawMobile === '' || user_phone_is_valid($rawMobile)) {
            return false;
        }

        try {
            $st = $dbh->prepare('UPDATE users SET mobile = :mobile WHERE id = :id LIMIT 1');
            $st->execute([
                ':mobile' => '',
                ':id' => $userId,
            ]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
