<?php
declare(strict_types=1);

/**
 * Customer / seller ↔ Admin support chat via feedback_admin (channel=user_admin).
 * Matches admin/feedback.php public inbox: sender=user email, receiver='Admin'.
 */

if (!function_exists('admin_support_id_col')) {
    function admin_support_id_col(PDO $dbh): string
    {
        static $col = null;
        if ($col !== null) {
            return $col;
        }

        try {
            $st = $dbh->query("SHOW KEYS FROM feedback_admin WHERE Key_name = 'PRIMARY'");
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if ($row && !empty($row['Column_name'])) {
                $col = (string)$row['Column_name'];
                return $col;
            }
        } catch (Throwable $e) {
            // ignore
        }

        $candidates = [
            'id_feedback_admin',
            'id',
            'feedback_id',
            'idfeedback',
            'id_feedback',
            'idfeedback_admin',
            'feedback_admin_id',
        ];
        try {
            $st = $dbh->query('SHOW COLUMNS FROM feedback_admin');
            $cols = $st ? $st->fetchAll(PDO::FETCH_COLUMN, 0) : [];
            foreach ($candidates as $c) {
                if (in_array($c, $cols, true)) {
                    $col = $c;
                    return $col;
                }
            }
            if (!empty($cols[0])) {
                $col = (string)$cols[0];
                return $col;
            }
        } catch (Throwable $e) {
            // ignore
        }

        $col = 'id_feedback_admin';
        return $col;
    }
}

if (!function_exists('admin_support_user_email')) {
    function admin_support_user_email(PDO $dbh, int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        try {
            $st = $dbh->prepare('SELECT email FROM users WHERE id = :id AND status = 1 LIMIT 1');
            $st->execute([':id' => $userId]);
            return trim((string)($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('admin_support_topic_meta')) {
    /**
     * @return array{title:string,label:string,prefix:string}
     */
    function admin_support_topic_meta(string $topic, string $role): array
    {
        $topic = strtolower(trim($topic));
        $role = strtolower(trim($role)) === 'seller' ? 'seller' : 'customer';

        if ($role === 'seller') {
            $map = [
                'seller_help' => [
                    'title' => 'Seller Help',
                    'label' => 'Seller help',
                    'prefix' => '[Seller help] ',
                ],
                'orders' => [
                    'title' => 'Seller Orders Help',
                    'label' => 'Orders & fulfillment',
                    'prefix' => '[Seller orders] ',
                ],
                'account' => [
                    'title' => 'Seller Account Help',
                    'label' => 'Store & account',
                    'prefix' => '[Seller account] ',
                ],
            ];
            return $map[$topic] ?? $map['seller_help'];
        }

        $map = [
            'dispute' => [
                'title' => 'Customer Dispute',
                'label' => 'Dispute with seller',
                'prefix' => '[Dispute] ',
            ],
            'help' => [
                'title' => 'Customer Help',
                'label' => 'Need help',
                'prefix' => '[Help] ',
            ],
        ];
        return $map[$topic] ?? $map['help'];
    }
}

if (!function_exists('admin_support_poll')) {
    /**
     * @return array{ok:bool,items?:array<int,array<string,mixed>>,error?:string}
     */
    function admin_support_poll(PDO $dbh, string $meEmail, int $afterId = 0, bool $markRead = true): array
    {
        $meEmail = trim($meEmail);
        if ($meEmail === '' || !filter_var($meEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Missing account email.'];
        }

        $idCol = admin_support_id_col($dbh);

        try {
            if ($markRead) {
                $mk = $dbh->prepare("
                    UPDATE feedback_admin
                    SET is_read = 1, read_at = NOW()
                    WHERE channel = 'user_admin'
                      AND sender = 'Admin'
                      AND receiver = :me
                      AND is_read = 0
                ");
                $mk->execute([':me' => $meEmail]);
            }

            $st = $dbh->prepare("
                SELECT {$idCol} AS id, sender, receiver, title, feedbackdata, attachment, created_at
                FROM feedback_admin
                WHERE channel = 'user_admin'
                  AND (
                        (sender = :me AND receiver = 'Admin')
                     OR (sender = 'Admin' AND receiver = :me2)
                  )
                  AND {$idCol} > :after
                ORDER BY {$idCol} ASC
                LIMIT 300
            ");
            $st->execute([
                ':me' => $meEmail,
                ':me2' => $meEmail,
                ':after' => max(0, $afterId),
            ]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not load support chat.'];
        }

        $items = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $sender = (string)($row['sender'] ?? '');
            $isMe = strcasecmp($sender, $meEmail) === 0;
            $created = (string)($row['created_at'] ?? '');
            $ts = $created !== '' ? strtotime($created) : false;
            $items[] = [
                'id' => $id,
                'is_me' => $isMe,
                'from' => $isMe ? 'You' : 'Admin',
                'title' => (string)($row['title'] ?? ''),
                'text' => (string)($row['feedbackdata'] ?? ''),
                'attachment' => (string)($row['attachment'] ?? ''),
                'created_at' => $created,
                'time_label' => $ts ? date('M j, g:i A', $ts) : '',
            ];
        }

        return ['ok' => true, 'items' => $items];
    }
}

if (!function_exists('admin_support_send')) {
    /**
     * @return array{ok:bool,item?:array<string,mixed>,error?:string}
     */
    function admin_support_send(
        PDO $dbh,
        string $meEmail,
        string $text,
        string $topic,
        string $role,
        ?string $extraContext = null
    ): array {
        $meEmail = trim($meEmail);
        $text = trim($text);
        if ($meEmail === '' || !filter_var($meEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Missing account email.'];
        }
        if ($text === '') {
            return ['ok' => false, 'error' => 'Type a message.'];
        }
        if (mb_strlen($text) > 4000) {
            return ['ok' => false, 'error' => 'Message is too long.'];
        }

        $meta = admin_support_topic_meta($topic, $role);
        $body = $meta['prefix'] . $text;
        $extraContext = trim((string)$extraContext);
        if ($extraContext !== '') {
            $body .= "\n\n—\n" . $extraContext;
        }

        try {
            $ins = $dbh->prepare("
                INSERT INTO feedback_admin (sender, receiver, channel, title, feedbackdata, attachment, is_read)
                VALUES (:s, 'Admin', 'user_admin', :title, :d, NULL, 0)
            ");
            $ins->execute([
                ':s' => $meEmail,
                ':title' => $meta['title'],
                ':d' => $body,
            ]);
            $id = (int)$dbh->lastInsertId();
            if ($id <= 0) {
                // Some schemas need explicit PK read after insert.
                $idCol = admin_support_id_col($dbh);
                $st = $dbh->prepare("
                    SELECT {$idCol} AS id
                    FROM feedback_admin
                    WHERE channel = 'user_admin' AND sender = :s AND receiver = 'Admin'
                    ORDER BY {$idCol} DESC
                    LIMIT 1
                ");
                $st->execute([':s' => $meEmail]);
                $id = (int)($st->fetchColumn() ?: 0);
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not send message.'];
        }

        $now = date('Y-m-d H:i:s');
        return [
            'ok' => true,
            'item' => [
                'id' => $id,
                'is_me' => true,
                'from' => 'You',
                'title' => $meta['title'],
                'text' => $body,
                'attachment' => '',
                'created_at' => $now,
                'time_label' => date('M j, g:i A'),
            ],
        ];
    }
}
