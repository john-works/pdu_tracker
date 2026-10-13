<?php
function smtpReadResponse($fp, int $maxLines = 100): array
{
    $lines = [];
    while (count($lines) < $maxLines) {
        $line = fgets($fp, 2048);
        if ($line === false) {
            break;
        }
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            continue;
        }
        $lines[] = $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $lines;
}

function smtpWrite($fp, string $command): void
{
    fwrite($fp, $command . "\r\n");
}

function smtpEncodeSubject(string $subject): string
{
    $subject = str_replace(["\r", "\n"], '', $subject);
    if (preg_match('/[^\x20-\x7E]/', $subject)) {
        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }
    return $subject;
}

function pduSendMail(string $to, string $subject, string $body, array $additionalHeaders = []): bool
{
    $config = require __DIR__ . '/email.php';
    $host = trim((string) ($config['smtp_host'] ?? ''));
    $port = (int) ($config['smtp_port'] ?? 25);
    $username = trim((string) ($config['smtp_username'] ?? ''));
    $password = (string) ($config['smtp_password'] ?? '');
    if ($host === '' || $username === '' || $password === '') {
        error_log('PDU mailer: missing SMTP host, username, or password (set PDU_SMTP_PASS or config/email.php smtp_password)');
        return false;
    }

    $fromName = str_replace(["\r", "\n"], '', (string) ($config['from_name'] ?? ''));
    $fromAddress = trim((string) ($config['from_address'] ?? ''));
    $replyTo = trim((string) ($config['reply_to'] ?? $fromAddress));
    if ($fromAddress === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL) || !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        error_log('PDU mailer: invalid sender settings in config/email.php');
        return false;
    }

    $timeout = 30;
    $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout);
    if ($fp === false) {
        error_log('PDU mailer: cannot connect to ' . $host . ':' . $port . ' (' . $errstr . ')');
        return false;
    }
    stream_set_timeout($fp, $timeout);

    $greeting = smtpReadResponse($fp);
    if (substr($greeting[0] ?? '', 0, 3) !== '220') {
        error_log('PDU mailer: unexpected greeting: ' . implode(' | ', $greeting));
        fclose($fp);
        return false;
    }

    $helloName = gethostname();
    if ($helloName === false || $helloName === '') {
        $helloName = 'localhost';
    }

    smtpWrite($fp, 'EHLO ' . $helloName);
    $ehlo = smtpReadResponse($fp);
    $capabilities = implode("\n", $ehlo);

    $encryption = strtoupper((string) ($config['smtp_encryption'] ?? 'STARTTLS'));
    if (($encryption === 'STARTTLS' || $encryption === 'TLS' || $encryption === 'AUTO') && stripos($capabilities, 'STARTTLS') !== false) {
        smtpWrite($fp, 'STARTTLS');
        $response = smtpReadResponse($fp);
        if (substr($response[0] ?? '', 0, 3) !== '220') {
            error_log('PDU mailer: STARTTLS failed: ' . implode(' | ', $response));
            fclose($fp);
            return false;
        }
        $tls = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($tls !== true) {
            error_log('PDU mailer: TLS negotiation failed');
            fclose($fp);
            return false;
        }
        smtpWrite($fp, 'EHLO ' . $helloName);
        $ehlo = smtpReadResponse($fp);
        $capabilities = implode("\n", $ehlo);
    }

    $authenticated = false;
    if (stripos($capabilities, 'AUTH') !== false) {
        smtpWrite($fp, 'AUTH LOGIN');
        $response = smtpReadResponse($fp);
        $code = substr($response[0] ?? '', 0, 3);
        if ($code === '334') {
            smtpWrite($fp, base64_encode($username));
            $response = smtpReadResponse($fp);
            if (substr($response[0] ?? '', 0, 3) === '334') {
                smtpWrite($fp, base64_encode($password));
                $response = smtpReadResponse($fp);
                if (substr($response[0] ?? '', 0, 3) === '235') {
                    $authenticated = true;
                }
            }
        }
        if (!$authenticated) {
            smtpWrite($fp, 'AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password));
            $response = smtpReadResponse($fp);
            if (substr($response[0] ?? '', 0, 3) === '235') {
                $authenticated = true;
            }
        }
    }
    if (!$authenticated) {
        error_log('PDU mailer: SMTP authentication failed for ' . $username);
        fclose($fp);
        return false;
    }

    smtpWrite($fp, 'MAIL FROM:<' . $fromAddress . '>');
    $response = smtpReadResponse($fp);
    if (substr($response[0] ?? '', 0, 3) !== '250') {
        error_log('PDU mailer: MAIL FROM rejected: ' . implode(' | ', $response));
        fclose($fp);
        return false;
    }

    smtpWrite($fp, 'RCPT TO:<' . $to . '>');
    $response = smtpReadResponse($fp);
    if (substr($response[0] ?? '', 0, 3) !== '250') {
        error_log('PDU mailer: RCPT TO rejected for ' . $to . ': ' . implode(' | ', $response));
        fclose($fp);
        return false;
    }

    smtpWrite($fp, 'DATA');
    $response = smtpReadResponse($fp);
    if (substr($response[0] ?? '', 0, 3) !== '354') {
        error_log('PDU mailer: DATA not accepted: ' . implode(' | ', $response));
        fclose($fp);
        return false;
    }

    $safeBody = preg_replace("/\r\n|\r|\n/", "\r\n", $body);
    $safeBody = preg_replace('/^\./m', '..', $safeBody);

    $message = 'From: ' . $fromName . ' <' . $fromAddress . ">\r\n"
             . 'Reply-To: ' . $replyTo . "\r\n"
             . 'To: ' . $to . "\r\n"
             . 'Subject: ' . smtpEncodeSubject($subject) . "\r\n"
             . 'Date: ' . date('r') . "\r\n"
             . 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $helloName . ">\r\n";
    if (!empty($additionalHeaders)) {
        $message .= implode("\r\n", $additionalHeaders) . "\r\n";
    }
    $message .= 'MIME-Version: 1.0' . "\r\n"
              . 'Content-Type: text/plain; charset=utf-8' . "\r\n"
              . "\r\n"
              . $safeBody . "\r\n";

    smtpWrite($fp, $message);
    smtpWrite($fp, '.');
    $response = smtpReadResponse($fp);
    $accepted = substr($response[0] ?? '', 0, 3) === '250';
    if (!$accepted) {
        error_log('PDU mailer: message not accepted: ' . implode(' | ', $response));
    }

    smtpWrite($fp, 'QUIT');
    fclose($fp);
    return $accepted;
}