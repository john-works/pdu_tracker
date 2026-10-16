<?php
$config = require __DIR__ . '/config/email.php';
require_once __DIR__ . '/config/smtp_mailer.php';

function imapCmd($fp, $c) {
    $tag = 'T' . strtoupper(bin2hex(random_bytes(3)));
    fwrite($fp, $tag . ' ' . $c . "\r\n");
    $lines = [];
    while ($line = fgets($fp, 65536)) { $line = rtrim($line, "\r\n"); $lines[] = $line; if (strpos($line, $tag . ' ') === 0) break; }
    return $lines;
}

function inboxCount($config) {
    $imap = @fsockopen('ssl://mail.ppda.go.ug', 993, $errno, $errstr, 15);
    stream_set_timeout($imap, 15);
    fgets($imap, 2048);
    imapCmd($imap, 'LOGIN helpdesk@ppda.go.ug "' . $config['smtp_password'] . '"');
    $r = imapCmd($imap, 'SELECT INBOX');
    $count = 0;
    foreach ($r as $l) { if (preg_match('/\* (\d+) EXISTS/', $l, $m)) $count = (int)$m[1]; }
    imapCmd($imap, 'LOGOUT');
    fclose($imap);
    return $count;
}

$before = inboxCount($config);
echo "INBOX before: $before\n";

$marker = 'CNT-' . date('His');
pduSendMail('helpdesk@ppda.go.ug', $marker, "count test\n");
echo "Sent marker: $marker\n";

echo "Waiting 30s...\n";
sleep(30);

$after = inboxCount($config);
echo "INBOX after: $after\n";
echo "Delta: " . ($after - $before) . "\n";
