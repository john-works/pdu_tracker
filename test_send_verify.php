<?php
require_once __DIR__ . '/config/smtp_mailer.php';

$config = require __DIR__ . '/config/email.php';
$to = 'jssekamatte@ppda.go.ug';
$subject = 'PDU SMTP Test - ' . date('Y-m-d H:i:s');
$body = "Test email from PDU Procurement System.\n\n"
      . 'Sent at: ' . date('Y-m-d H:i:s T') . "\n"
      . 'From: ' . $config['from_address'] . "\n"
      . 'To: ' . $to . "\n";

$headers = [
    'Disposition-Notification-To: ' . $config['from_address'],
    'Return-Receipt-To: ' . $config['from_address'],
];

echo "Sending to {$to}...\n";
$ok = pduSendMail($to, $subject, $body, $headers);
echo $ok ? "Server accepted (250 OK).\n" : "FAILED.\n";

// Also verify by reading back the sent folder via IMAP
echo "\nVerifying via IMAP...\n";
$imap = @fsockopen('ssl://mail.ppda.go.ug', 993, $errno, $errstr, 15);
if (!$imap) { die("IMAP connect failed: $errstr\n"); }
stream_set_timeout($imap, 15);

function imapRead($fp) {
    $out = [];
    while ($line = fgets($fp, 65536)) {
        $line = rtrim($line, "\r\n");
        $out[] = $line;
        if (preg_match('/^\* (SEARCH|LIST|STATUS|FETCH|OK|BYE|BAD|NO|\d+ (OK|BAD|NO))/', $line)) break;
        if (preg_match('/^\d+ (OK|BAD|NO)/', $line)) break;
        if (isset($line[0]) && $line[0] === '+' ) break;
    }
    return $out;
}

// Read greeting
$greeting = fgets($imap, 2048);
echo "Greeting: " . rtrim($greeting) . "\n";

// Login
fwrite($imap, "A001 LOGIN helpdesk@ppda.go.ug \"{$config['smtp_password']}\"\r\n");
$resp = [];
while ($line = fgets($imap, 2048)) {
    $resp[] = rtrim($line);
    if (strpos($line, 'A001 OK') !== false || strpos($line, 'A001 NO') !== false || strpos($line, 'A001 BAD') !== false) break;
}
echo "Login: " . end($resp) . "\n";

// Select SENT folder
fwrite($imap, "A002 SELECT \"Sent\"\r\n");
$resp = [];
while ($line = fgets($imap, 2048)) {
    $resp[] = rtrim($line);
    if (strpos($line, 'A002 OK') !== false || strpos($line, 'A002 NO') !== false || strpos($line, 'A002 BAD') !== false) break;
}
echo "Select Sent: " . end($resp) . "\n";

// Search for recent messages
fwrite($imap, "A003 SEARCH RECENT\r\n");
$resp = [];
while ($line = fgets($imap, 2048)) {
    $resp[] = rtrim($line);
    if (strpos($line, 'A003 OK') !== false || strpos($line, 'A003 NO') !== false) break;
}
echo "Recent: " . implode(' | ', $resp) . "\n";

// Search all in sent
fwrite($imap, "A004 SEARCH ALL\r\n");
$resp = [];
while ($line = fgets($imap, 2048)) {
    $resp[] = rtrim($line);
    if (strpos($line, 'A004 OK') !== false || strpos($line, 'A004 NO') !== false) break;
}
echo "All sent: " . implode(' | ', $resp) . "\n";

fwrite($imap, "A005 LOGOUT\r\n");
fclose($imap);
