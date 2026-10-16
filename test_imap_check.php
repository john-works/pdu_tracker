<?php
$config = require __DIR__ . '/config/email.php';

$imap = @fsockopen('ssl://mail.ppda.go.ug', 993, $errno, $errstr, 15);
if (!$imap) { die("IMAP connect failed: $errstr\n"); }
stream_set_timeout($imap, 15);

function imapCommand($fp, $cmd) {
    $tag = 'T' . strtoupper(bin2hex(random_bytes(3)));
    fwrite($fp, $tag . ' ' . $cmd . "\r\n");
    $lines = [];
    while ($line = fgets($fp, 65536)) {
        $line = rtrim($line, "\r\n");
        $lines[] = $line;
        if (strpos($line, $tag . ' ') === 0) break;
    }
    return $lines;
}

// Greeting
fgets($imap, 2048);

// Login
$r = imapCommand($imap, 'LOGIN helpdesk@ppda.go.ug "' . $config['smtp_password'] . '"');
echo 'LOGIN: ' . end($r) . "\n";

// List all folders
$r = imapCommand($imap, 'LIST "" "*"');
echo "FOLDERS:\n";
foreach ($r as $line) {
    if (strpos($line, '*') === 0) echo "  $line\n";
}
echo 'LIST done: ' . end($r) . "\n\n";

// Try Sent variants
foreach (['Sent', 'SENT', 'INBOX.Sent', 'Sent Items', 'INBOX.Sent Items'] as $folder) {
    $r = imapCommand($imap, 'SELECT "' . $folder . '"');
    $status = end($r);
    if (strpos($status, ' OK ') !== false) {
        echo "SELECT '$folder': OK\n";
        // Search for recent
        $s = imapCommand($imap, 'UID SEARCH ALL');
        echo "  Messages: " . implode(', ', $s) . "\n";
        // Fetch last message header
        $s = imapCommand($imap, 'FETCH * (UID RFC822.SIZE BODY[HEADER.FIELDS (DATE FROM TO SUBJECT)])');
        echo "  Last: " . implode("\n  ", $s) . "\n\n";
        break;
    } else {
        echo "SELECT '$folder': " . $status . "\n";
    }
}

imapCommand($imap, 'LOGOUT');
fclose($imap);
