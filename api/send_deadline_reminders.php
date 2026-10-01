<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/deadline_reminder_service.php';

$dryRun = in_array('--dry-run', $argv, true);

try {
    $stats = runDeadlineReminders(db(), $dryRun);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

foreach ($stats['would_send'] as $message) {
    echo 'Would send ' . $message . PHP_EOL;
}

if ($dryRun) {
    echo 'Dry run complete. Due stages: ' . $stats['due_stages'] . PHP_EOL;
} else {
    echo 'Due stages: ' . $stats['due_stages'] . '; emails sent: ' . $stats['sent'] . '; failed: ' . $stats['failed'] . PHP_EOL;
}