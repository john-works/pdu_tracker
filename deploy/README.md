# Linux Deadline Reminder Setup

The dashboard checks reminders while it is open. For reminders to run while nobody is using the site, install the cron job on the Linux host.

Before deployment, set these environment variables for the web PHP process and the cron PHP process:

```text
PDU_DB_HOST=database-hostname
PDU_DB_PORT=3306
PDU_DB_NAME=pdu
PDU_DB_USER=application-user
PDU_DB_PASS=application-password
```

Use the credentials for the production database; do not use WAMP's local `root` defaults. Configure the Linux PHP mail transport as well. `config/email.php` sets the sender address; it does not configure the SMTP relay.

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