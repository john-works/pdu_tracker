<?php
$accounts = [
    'jssekamatte@ppda.go.ug' => getenv('PDU_SMTP_PASS'),
];

foreach ($accounts as $user => $pass) {
    $imap = @fsockopen('ssl://mail.ppda.go.ug', 993, $errno, $errstr, 15);
    if (!$imap) { echo "$user: connect failed\n"; continue; }
    stream_set_timeout($imap, 15);

    fgets($imap, 2048);
    $tag = 'T' . strtoupper(bin2hex(random_bytes(3)));
    fwrite($imap, $tag . ' LOGIN ' . $user . ' "' . $pass . "\"\r\n");
    $lines = [];
    while ($line = fgets($imap, 65536)) {
        $line = rtrim($line, "\r\n");
        if (strpos($line, $tag . ' ') === 0) { $lines[] = $line; break; }
    }
    echo "$user: " . implode(' | ', $lines) . "\n";
    fclose($imap);
}
