<?php
require_once dirname(__DIR__, 2) . '/config.php';

if (!function_exists('loadMailerAutoload')) {
    function loadMailerAutoload(): bool
    {
        static $loaded = null;
        if ($loaded !== null) {
            return $loaded;
        }

        $candidates = [
            dirname(__DIR__) . '/vendor/autoload.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
        ];

        foreach ($candidates as $file) {
            if (is_file($file)) {
                require_once $file;
                $loaded = true;
                return true;
            }
        }

        $loaded = false;
        return false;
    }
}

if (!function_exists('mailerEncodeHeader')) {
    function mailerEncodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}

if (!function_exists('mailerOpenSocket')) {
    function mailerOpenSocket(Config $cfg)
    {
        $port = (int)$cfg->SMTP_PORT;
        $host = (string)$cfg->SMTP_HOST;
        $transport = $port === 465 ? 'ssl://' . $host . ':' . $port : 'tcp://' . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($transport, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            throw new RuntimeException("SMTP connection failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 20);
        return $socket;
    }
}

if (!function_exists('mailerReadResponse')) {
    function mailerReadResponse($socket): array
    {
        $response = '';

        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) {
                break;
            }

            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        $code = (int)substr($response, 0, 3);
        return [$code, trim($response)];
    }
}

if (!function_exists('mailerSendCommand')) {
    function mailerSendCommand($socket, string $command, array $expectedCodes): string
    {
        fwrite($socket, $command . "\r\n");
        [$code, $response] = mailerReadResponse($socket);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException("SMTP command failed [{$command}] {$response}");
        }
        return $response;
    }
}

if (!function_exists('mailerSendData')) {
    function mailerSendData($socket, string $data): void
    {
        $data = preg_replace("/\r\n|\r|\n/", "\r\n", $data);
        $data = preg_replace('/^\./m', '..', $data);
        fwrite($socket, $data . "\r\n.\r\n");
    }
}

if (!function_exists('sendSmtpNotificationEmail')) {
    function sendSmtpNotificationEmail(string $to, string $subject, string $htmlBody, Config $cfg): bool
    {
        $socket = null;

        try {
            $socket = mailerOpenSocket($cfg);
            [$code, $banner] = mailerReadResponse($socket);
            if ($code !== 220) {
                throw new RuntimeException('SMTP banner rejected: ' . $banner);
            }

            $ehloHost = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
            $ehloHost = $ehloHost !== '' ? $ehloHost : 'localhost';
            $ehloResponse = mailerSendCommand($socket, 'EHLO ' . $ehloHost, [250]);

            if ((int)$cfg->SMTP_PORT !== 465) {
                if (stripos($ehloResponse, 'STARTTLS') === false) {
                    throw new RuntimeException('SMTP server does not advertise STARTTLS.');
                }

                mailerSendCommand($socket, 'STARTTLS', [220]);
                $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($cryptoEnabled !== true) {
                    throw new RuntimeException('Unable to enable TLS for SMTP connection.');
                }
                mailerSendCommand($socket, 'EHLO ' . $ehloHost, [250]);
            }

            mailerSendCommand($socket, 'AUTH LOGIN', [334]);
            mailerSendCommand($socket, base64_encode((string)$cfg->SMTP_USER), [334]);
            mailerSendCommand($socket, base64_encode((string)$cfg->SMTP_PASS), [235]);

            $from = trim((string)$cfg->SMTP_FROM);
            $to = trim($to);
            mailerSendCommand($socket, 'MAIL FROM:<' . $from . '>', [250]);
            mailerSendCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            mailerSendCommand($socket, 'DATA', [354]);

            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'To: <' . $to . '>',
                'From: ' . mailerEncodeHeader((string)$cfg->SMTP_FROM_NAME) . ' <' . $from . '>',
                'Reply-To: <' . $from . '>',
                'Subject: ' . mailerEncodeHeader($subject),
                'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $ehloHost . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];

            $message = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody;
            mailerSendData($socket, $message);
            [$dataCode, $dataResponse] = mailerReadResponse($socket);
            if ($dataCode !== 250) {
                throw new RuntimeException('SMTP DATA rejected: ' . $dataResponse);
            }

            mailerSendCommand($socket, 'QUIT', [221]);
            fclose($socket);
            return true;
        } catch (Throwable $e) {
            if (is_resource($socket)) {
                fclose($socket);
            }
            error_log('SMTP mailer error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sendNativeNotificationEmail')) {
    function sendNativeNotificationEmail(string $to, string $subject, string $htmlBody, Config $cfg): bool
    {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . mailerEncodeHeader((string)$cfg->SMTP_FROM_NAME) . ' <' . $cfg->SMTP_FROM . '>',
            'Reply-To: ' . $cfg->SMTP_FROM,
        ];

        return @mail($to, mailerEncodeHeader($subject), $htmlBody, implode("\r\n", $headers));
    }
}

if (!function_exists('sendNotificationEmail')) {
    function sendNotificationEmail(string $to, string $subject, string $htmlBody): bool
    {
        $cfg = new Config();

        if (loadMailerAutoload() && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $mailClass = 'PHPMailer\\PHPMailer\\PHPMailer';
            $mail = new $mailClass(true);

            try {
                $mail->SMTPDebug = 0;
                $mail->Debugoutput = 'html';
                $mail->isSMTP();
                $mail->Host = $cfg->SMTP_HOST;
                $mail->SMTPAuth = true;
                $mail->Username = $cfg->SMTP_USER;
                $mail->Password = $cfg->SMTP_PASS;
                $mail->SMTPSecure = defined($mailClass . '::ENCRYPTION_STARTTLS')
                    ? constant($mailClass . '::ENCRYPTION_STARTTLS')
                    : 'tls';
                $mail->Port = (int)$cfg->SMTP_PORT;
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ];
                $mail->setFrom($cfg->SMTP_FROM, $cfg->SMTP_FROM_NAME);
                $mail->addAddress($to);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $htmlBody;
                $mail->AltBody = trim(strip_tags($htmlBody));

                return $mail->send();
            } catch (Throwable $e) {
                error_log('Mailer error: ' . $e->getMessage());
            }
        } else {
            error_log('PHPMailer autoload not found, falling back to direct SMTP.');
        }

        if (sendSmtpNotificationEmail($to, $subject, $htmlBody, $cfg)) {
            return true;
        }

        error_log('Direct SMTP failed, falling back to native mail().');
        return sendNativeNotificationEmail($to, $subject, $htmlBody, $cfg);
    }
}
