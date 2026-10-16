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
echo 'LOGIN: ' . end($r) . "\n";

// Check Sent Items for our test subject
$r = imapCommand($imap, 'SELECT "Sent Items"');
echo 'SELECT: ' . end($r) . "\n";

$r = imapCommand($imap, 'UID SEARCH SUBJECT "PDU SMTP Test"');
echo 'Search sent for test: ' . implode(' | ', $r) . "\n";

// Fetch last 3 messages from Sent
$r = imapCommand($imap, 'UID SEARCH ALL');
preg_match('/\* SEARCH (.*)/', implode("\n", $r), $m);
$uids = explode(' ', trim($m[1] ?? ''));
echo 'Total sent: ' . count($uids) . "\n";
$lastUids = array_slice($uids, -3);
echo 'Last 3 UIDs: ' . implode(', ', $lastUids) . "\n";

foreach ($lastUids as $uid) {
    $r = imapCommand($imap, "UID FETCH $uid (RFC822.SIZE BODY[HEADER.FIELDS (DATE FROM TO SUBJECT)])");
    echo "--- UID $uid ---\n";
    foreach ($r as $line) {
        if (strpos($line, '*') === 0) echo "  $line\n";
    }
}

// Also check helpdesktest INBOX just in case
$r = imapCommand($imap, 'SELECT INBOX');
echo "\nINBOX: " . end($r) . "\n";
$r = imapCommand($imap, 'UID SEARCH UNSEEN');
echo 'Unseen in helpdesk INBOX: ' . implode(' | ', $r) . "\n";
$r = imapCommand($imap, 'UID SEARCH SUBJECT "PDU"');
echo 'Search INBOX for PDU: ' . implode(' | ', $r) . "\n";

imapCommand($imap, 'LOGOUT');
fclose($imap);
