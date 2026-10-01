<?php
function isReminderWorkingDay(DateTimeImmutable $date, array $publicHolidays): bool
{
    return (int) $date->format('N') < 6 && !in_array($date->format('m-d'), $publicHolidays, true);
}

function reminderWorkingDaysUntil(DateTimeImmutable $today, DateTimeImmutable $targetDate, array $publicHolidays): int
{
    $workingDays = 0;
    $date = $today;
    while ($date < $targetDate) {
        $date = $date->modify('+1 day');
        if (isReminderWorkingDay($date, $publicHolidays)) {
            $workingDays++;
        }
    }
    return $workingDays;
}

function runDeadlineReminders(mysqli $db, bool $dryRun = false): array
{
    date_default_timezone_set('Africa/Kampala');
    $emailConfig = require __DIR__ . '/email.php';
    $fromName = str_replace(["\r", "\n"], '', $emailConfig['from_name'] ?? '');
    $fromAddress = trim($emailConfig['from_address'] ?? '');
    $replyTo = trim($emailConfig['reply_to'] ?? $fromAddress);
    if ($fromName === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL) || !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid sender settings in config/email.php');
    }

    $db->query("CREATE TABLE IF NOT EXISTS deadline_reminder_deliveries (
        stage_id INT UNSIGNED NOT NULL,
        target_date DATE NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (stage_id, target_date, user_id),
        CONSTRAINT fk_reminder_stage FOREIGN KEY (stage_id) REFERENCES procurement_stages(id) ON DELETE CASCADE,
        CONSTRAINT fk_reminder_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Holidays are read from the table so the working-day maths matches the
    // calendar the rest of the application uses.
    $publicHolidays = [];
    $holidayResult = $db->query('SELECT `mmdd` FROM `public_holidays`');
    if ($holidayResult) {
        while ($holiday = $holidayResult->fetch_assoc()) {
            $publicHolidays[] = $holiday['mmdd'];
        }
    }

    $today = new DateTimeImmutable('today');
    $stagesResult = $db->query("SELECT p.ref_no, p.title, p.entity, s.id AS stage_id,
            cat.name AS stage_name, rp.name AS responsible_party, s.target_date
        FROM procurements p
        INNER JOIN procurement_stages s
            ON s.procurement_id = p.id AND s.stage_order = p.current_stage_index
        INNER JOIN process_stages cat ON cat.id = s.process_stage_id
        LEFT JOIN responsible_parties rp ON rp.id = s.responsible_party_id
        WHERE p.current_stage_index < (
            SELECT MAX(last_stage.stage_order)
            FROM procurement_stages last_stage
            WHERE last_stage.procurement_id = p.id
        )");
    $recipientsResult = $db->query("SELECT id, email FROM users
        WHERE email IS NOT NULL AND TRIM(email) <> ''
        ORDER BY id");
    $recipients = $recipientsResult->fetch_all(MYSQLI_ASSOC);
    $stats = ['due_stages' => 0, 'sent' => 0, 'failed' => 0, 'would_send' => []];

    $alreadySent = $db->prepare('SELECT 1 FROM deadline_reminder_deliveries WHERE stage_id = ? AND target_date = ? AND user_id = ? LIMIT 1');
    $recordSent = $db->prepare('INSERT IGNORE INTO deadline_reminder_deliveries (stage_id, target_date, user_id) VALUES (?, ?, ?)');

    while ($stage = $stagesResult->fetch_assoc()) {
        if (empty($stage['target_date'])) {
            continue;
        }

        $targetDate = new DateTimeImmutable($stage['target_date']);
        $daysRemaining = reminderWorkingDaysUntil($today, $targetDate, $publicHolidays);
        if ($daysRemaining < 0 || $daysRemaining > 2) {
            continue;
        }
        $stats['due_stages']++;

        $dayLabel = $daysRemaining === 1 ? '1 working day' : $daysRemaining . ' working days';
        $subject = 'Deadline in ' . $dayLabel . ': ' . $stage['ref_no'];
        $body = 'A procurement stage deadline is ' . $dayLabel . " away.\n\n"
              . 'Reference: ' . $stage['ref_no'] . "\n"
              . 'Procurement: ' . $stage['title'] . "\n"
              . 'Entity: ' . $stage['entity'] . "\n"
              . 'Stage: ' . $stage['stage_name'] . "\n"
              . 'Responsible party: ' . ($stage['responsible_party'] ?: 'Not specified') . "\n"
              . 'Deadline: ' . $targetDate->format('Y-m-d') . "\n\n"
              . 'Please review the procurement tracker and take any required action.';

        foreach ($recipients as $recipient) {
            if (!filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $userId = (int) $recipient['id'];
            $stageId = (int) $stage['stage_id'];
            $dateValue = $targetDate->format('Y-m-d');
            $alreadySent->bind_param('isi', $stageId, $dateValue, $userId);
            $alreadySent->execute();
            if ($alreadySent->get_result()->num_rows > 0) {
                continue;
            }

            if ($dryRun) {
                $stats['would_send'][] = $stage['ref_no'] . ' / ' . $stage['stage_name'] . ' to ' . $recipient['email'];
                continue;
            }

            $headers = 'From: ' . $fromName . ' <' . $fromAddress . ">\r\n"
                     . 'Reply-To: ' . $replyTo . "\r\n"
                     . "Content-Type: text/plain; charset=utf-8\r\n";
            if (@mail($recipient['email'], $subject, $body, $headers)) {
                $recordSent->bind_param('isi', $stageId, $dateValue, $userId);
                $recordSent->execute();
                $stats['sent']++;
            } else {
                error_log('Deadline reminder email failed for ' . $recipient['email'] . ' (' . $stage['ref_no'] . ')');
                $stats['failed']++;
            }
        }
    }

    return $stats;
}