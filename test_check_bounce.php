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

fgets($imap, 2048);
$r = imapCommand($imap, 'LOGIN helpdesk@ppda.go.ug "' . $config['smtp_password'] . '"');
echo 'LOGIN: ' . end($r) . "\n\n";

foreach (['INBOX', 'Junk E-mail', 'Deleted Items'] as $folder) {
    $r = imapCommand($imap, 'SELECT "' . $folder . '"');
    $status = end($r);
    echo "=== $folder ($status) ===\n";
    if (strpos($status, ' OK ') === false) continue;

    $r = imapCommand($imap, 'UID SEARCH ALL');
    preg_match('/\* SEARCH(.*)/', implode("\n", $r), $m);
    $uids = array_filter(explode(' ', trim($m[1] ?? '')));
    echo '  Total messages: ' . count($uids) . "\n";

    $last = array_slice($uids, -5);
    foreach ($last as $uid) {
        $r = imapCommand($imap, "UID FETCH $uid (BODY[HEADER.FIELDS (DATE FROM TO SUBJECT)])");
        $headers = '';
        foreach ($r as $line) {
            if (preg_match('/^(From|To|Subject|Date):/i', $line)) $headers .= '    ' . $line . "\n";
        }
        echo "  [UID $uid]\n$headers";
    }
    echo "\n";
}

imapCommand($imap, 'LOGOUT');
fclose($imap);
