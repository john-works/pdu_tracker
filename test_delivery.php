<?php
$config = require __DIR__ . '/config/email.php';
require_once __DIR__ . '/config/smtp_mailer.php';

$marker = 'DELIVERY-TEST-' . date('His');

// Send to helpdesk itself so we can verify
$ok = pduSendMail('helpdesk@ppda.go.ug', 'PDU ' . $marker, "Self delivery test $marker\n");
echo "Send to self: " . ($ok ? 'accepted' : 'FAILED') . "\n";

// Send to jssekamatte
$ok2 = pduSendMail('jssekamatte@ppda.go.ug', 'PDU ' . $marker, "Recipient test $marker\n");
echo "Send to jssekamatte: " . ($ok2 ? 'accepted' : 'FAILED') . "\n";

sleep(5);

// Now check helpdesk INBOX for the self-test
$imap = @fsockopen('ssl://mail.ppda.go.ug', 993, $errno, $errstr, 15);
stream_set_timeout($imap, 15);
function cmd($fp, $c) {
    $tag = 'T' . strtoupper(bin2hex(random_bytes(3)));
    fwrite($fp, $tag . ' ' . $c . "\r\n");
    $lines = [];
    while ($line = fgets($fp, 65536)) { $line = rtrim($line, "\r\n"); $lines[] = $line; if (strpos($line, $tag . ' ') === 0) break; }
    return $lines;
}
fgets($imap, 2048);
cmd($imap, 'LOGIN helpdesk@ppda.go.ug "' . $config['smtp_password'] . '"');
cmd($imap, 'SELECT INBOX');
$r = cmd($imap, 'UID SEARCH SUBJECT "' . $marker . '"');
echo "Search self-test in INBOX: " . implode(' | ', $r) . "\n";

$r = cmd($imap, 'UID SEARCH RECENT');
echo "Recent: " . implode(' | ', $r) . "\n";
cmd($imap, 'LOGOUT');
fclose($imap);
