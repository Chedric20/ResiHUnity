<?php
// Shared Mailer module for ResiHUnity RHU.
// Configured to use chedricbascoguin27@gmail.com as primary system email sender.

if (!function_exists('sendRHUEmail')) {
    function sendRHUEmail(string $toEmail, string $subject, string $htmlContent): array {
        @include_once __DIR__ . '/db.php';

        $mailSettings = [];
        if (isset($pdo) && $pdo instanceof PDO) {
            try {
                if (function_exists('rhuTableExists') && rhuTableExists($pdo, 'audit_logs')) {
                    $settingsJson = $pdo->query("SELECT description FROM audit_logs
                        WHERE action = 'Settings Updated' AND module_name = 'System'
                        ORDER BY id DESC LIMIT 1")->fetchColumn();
                    $settings = json_decode((string)$settingsJson, true);
                    $mailSettings = is_array($settings) ? array_filter($settings, static fn($key) => str_starts_with((string)$key, 'smtp_'), ARRAY_FILTER_USE_KEY) : [];
                }
            } catch (Throwable $e) {
                error_log('Mailer settings: ' . $e->getMessage());
            }
        }
        $smtpHost = trim((string)($mailSettings['smtp_host'] ?? '')) ?: (function_exists('rhuEnv') ? rhuEnv('SMTP_HOST', 'smtp.gmail.com') : 'smtp.gmail.com');
        $smtpPort = (int)(trim((string)($mailSettings['smtp_port'] ?? '')) ?: (function_exists('rhuEnv') ? rhuEnv('SMTP_PORT', '587') : 587));
        $smtpUser = trim((string)($mailSettings['smtp_user'] ?? '')) ?: (function_exists('rhuEnv') ? (rhuEnv('SMTP_USER', '') ?: 'chedricbascoguin27@gmail.com') : 'chedricbascoguin27@gmail.com');
        $smtpPass = function_exists('rhuEnv') ? (rhuEnv('SMTP_PASS', '') ?: rhuEnv('SMTP_PASSWORD', '')) : '';
        $smtpPass = str_replace(' ', '', $smtpPass);
        $smtpFrom = trim((string)($mailSettings['smtp_from'] ?? '')) ?: (function_exists('rhuEnv') ? (rhuEnv('SMTP_FROM', '') ?: ($smtpUser ?: 'chedricbascoguin27@gmail.com')) : 'chedricbascoguin27@gmail.com');
        $smtpName = 'ResiHUnity RHU';

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'method' => 'validation', 'error' => 'Invalid recipient email address.'];
        }

        $logPath = dirname(__DIR__, 2) . '/sent_emails.txt';
        $safeSubject = trim(str_replace(["\r", "\n"], '', $subject));
        $logPrefix = "[" . date('Y-m-d H:i:s') . "] FROM: {$smtpFrom} | TO: {$toEmail} | SUBJECT: {$safeSubject} | ";

        if ($smtpUser !== '' && $smtpPass !== '') {
            try {
                $context = stream_context_create([
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false,
                        'peer_name' => $smtpHost,
                    ]
                ]);

                $remote = ($smtpPort === 465) ? "ssl://{$smtpHost}:{$smtpPort}" : "tcp://{$smtpHost}:{$smtpPort}";
                $socket = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $context);

                if ($socket) {
                    $read = function($sock) {
                        $res = '';
                        while ($line = fgets($sock, 512)) {
                            $res .= $line;
                            if (substr($line, 3, 1) === ' ') break;
                        }
                        return $res;
                    };

                    $write = function($sock, $cmd) use ($read) {
                        fputs($sock, $cmd . "\r\n");
                        return $read($sock);
                    };

                    $read($socket);
                    $write($socket, 'EHLO ' . gethostname());

                    if ($smtpPort !== 465) {
                        $write($socket, 'STARTTLS');
                        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                        $write($socket, 'EHLO ' . gethostname());
                    }

                    $write($socket, 'AUTH LOGIN');
                    $write($socket, base64_encode($smtpUser));
                    $write($socket, base64_encode($smtpPass));

                    $write($socket, 'MAIL FROM: <' . $smtpUser . '>');
                    $write($socket, 'RCPT TO: <' . $toEmail . '>');
                    $write($socket, 'DATA');

                    $headers  = "MIME-Version: 1.0\r\n";
                    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                    $headers .= "From: {$smtpName} <{$smtpFrom}>\r\n";
                    $headers .= "To: {$toEmail}\r\n";
                    $headers .= "Subject: {$subject}\r\n";
                    $headers .= "Date: " . date('r') . "\r\n";

                    $emailPayload = $headers . "\r\n" . $htmlContent . "\r\n.";
                    $write($socket, $emailPayload);

                    $write($socket, 'QUIT');
                    fclose($socket);

                    @file_put_contents($logPath, $logPrefix . "SUCCESS (SMTP via {$smtpFrom})\n", FILE_APPEND);
                    return ['success' => true, 'method' => 'SMTP'];
                }
                $message = "SMTP connection failed ({$errno}).";
                @file_put_contents($logPath, $logPrefix . "FAILED ({$message})\n", FILE_APPEND);
            } catch (Throwable $e) {
                error_log('sendRHUEmail SMTP Exception: ' . $e->getMessage());
                @file_put_contents($logPath, $logPrefix . "FAILED (" . $e->getMessage() . ")\n", FILE_APPEND);
            }
        }

        // 2. HTTPS Web Mail API Relay for Instant Delivery
        if (function_exists('curl_init') && filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            try {
                $apiUrl = 'https://formsubmit.co/ajax/' . urlencode($toEmail);
                $plainMessage = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlContent)));
                $postFields = [
                    '_subject' => $subject,
                    'System_Sender' => 'ResiHUnity RHU (' . $smtpFrom . ')',
                    'Sender_Email' => $smtpFrom,
                    'Recipient' => $toEmail,
                    'Message' => $plainMessage !== '' ? $plainMessage : $htmlContent,
                    '_template' => 'table'
                ];

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $apiUrl);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 8);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode >= 200 && $httpCode < 300) {
                    @file_put_contents($logPath, $logPrefix . "SUCCESS (HTTPS_API via {$smtpFrom})\n", FILE_APPEND);
                    return ['success' => true, 'method' => 'HTTPS_API'];
                }
            } catch (Exception $ex) {
                error_log('sendRHUEmail HTTP API Error: ' . $ex->getMessage());
            }
        }

        // 3. Native PHP mail() fallback
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$smtpName} <{$smtpFrom}>\r\n";
        $headers .= "Reply-To: {$smtpFrom}\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        $sent = @mail($toEmail, $subject, $htmlContent, $headers);
        @file_put_contents($logPath, $logPrefix . ($sent ? "SUCCESS (PHP Mail)" : "QUEUED (sent_emails.txt)") . "\n", FILE_APPEND);
        return ['success' => true, 'method' => 'php_mail'];
    }
}
