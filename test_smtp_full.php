<?php
$config = require __DIR__ . '/config/email.php';
$host = $config['smtp_host'];
$port = $config['smtp_port'];

$fp = stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, 30);
stream_set_timeout($fp, 30);

function read($fp) {
    $out = [];
    while ($line = fgets($fp, 2048)) {
        $out[] = rtrim($line);
        if (isset($out[count($out)-1][3]) && $out[count($out)-1][3] === ' ') break;
    }
    return implode(' | ', $out);
}

echo 'S: ' . read($fp) . "\n";
fwrite($fp, "EHLO localhost\r\n"); echo "C: EHLO\n";
read($fp);
fwrite($fp, "AUTH LOGIN\r\n");
read($fp);
fwrite($fp, base64_encode($config['smtp_username']) . "\r\n");
read($fp);
fwrite($fp, base64_encode($config['smtp_password']) . "\r\n");
echo 'AUTH: ' . read($fp) . "\n";

$from = 'helpdesk@ppda.go.ug';
$to = 'jssekamatte@ppda.go.ug';

fwrite($fp, "MAIL FROM:<$from>\r\n");
echo "MAIL FROM: " . read($fp) . "\n";
fwrite($fp, "RCPT TO:<$to>\r\n");
echo "RCPT TO ($to): " . read($fp) . "\n";
fwrite($fp, "DATA\r\n");
echo "DATA: " . read($fp) . "\n";

$msg = "From: IT Helpdesk <$from>\r\n"
     . "To: $to\r\n"
     . "Subject: PDU test " . date('H:i:s') . "\r\n"
     . "Date: " . date('r') . "\r\n"
     . "Message-ID: <" . bin2hex(random_bytes(8)) . "@ppda.go.ug>\r\n"
     . "MIME-Version: 1.0\r\n"
     . "Content-Type: text/plain; charset=utf-8\r\n"
     . "\r\n"
     . "Test message body at " . date('Y-m-d H:i:s') . "\r\n";

fwrite($fp, $msg . "\r\n.\r\n");
echo "After data: " . read($fp) . "\n";
fwrite($fp, "QUIT\r\n");
fclose($fp);
