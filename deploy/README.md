# Linux Deadline Reminder Setup

The dashboard checks reminders while it is open. For reminders to run while nobody is using the site, install the cron job on the Linux host.

Before installing, configure `config/database.php` with the production database credentials and configure the Linux PHP CLI mail transport. `config/email.php` sets the sender address; it does not configure the SMTP relay.

Run the installer as the account that should own the job and can access the application, database, and mail transport:

```sh
bash deploy/install-deadline-reminders.sh /absolute/path/to/PDU /usr/bin/php
```

The PHP binary argument is optional if `php` is on that account's `PATH`. The installer validates PHP syntax and the reminder script path, then adds or replaces only its own tagged entry in that user's crontab. It checks every 15 minutes and logs output under `${XDG_STATE_HOME:-$HOME/.local/state}/pdu/deadline-reminders.log`.

Verify the schedule with `crontab -l`. Test without sending mail with:

```sh
/usr/bin/php /absolute/path/to/PDU/api/send_deadline_reminders.php --dry-run
```

The job sends to users with valid email addresses and records successful deliveries to avoid duplicate reminders for a stage deadline.