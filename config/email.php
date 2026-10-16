<?php
$smtpPassword = getenv('PDU_SMTP_PASS');
return [
    'from_name' => 'PDU & Depoint Procurement System',
    'from_address' => 'helpdesk@ppda.go.ug',
    'reply_to' => 'helpdesk@ppda.go.ug',
    'smtp_host' => 'mail.ppda.go.ug',
    'smtp_port' => 465,
    'smtp_username' => 'helpdesk@ppda.go.ug',
    'smtp_password' => $smtpPassword === false ? '' : $smtpPassword,
    'smtp_encryption' => 'SSL',
];