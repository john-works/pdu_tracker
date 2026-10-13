<?php
$smtpPassword = getenv('PDU_SMTP_PASS');
return [
    'from_name' => 'PDU & Depoint Procurement System',
    'from_address' => 'no-reply@ppda.go.ug',
    'reply_to' => 'no-reply@ppda.go.ug',
    'smtp_host' => 'mail.ppda.go.ug',
    'smtp_port' => 25,
    'smtp_username' => 'no-reply@ppda.go.ug',
    'smtp_password' => $smtpPassword === false ? '' : $smtpPassword,
    'smtp_encryption' => 'STARTTLS',
];