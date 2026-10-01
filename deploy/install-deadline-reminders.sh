#!/usr/bin/env bash
set -eu

if [ "$#" -lt 1 ]; then
    printf 'Usage: bash %s /absolute/path/to/PDU [php-binary]\n' "$0" >&2
    exit 2
fi

APP_DIR=$(cd -- "$1" && pwd)
PHP_BIN=${2:-$(command -v php || true)}
REMINDER_SCRIPT="$APP_DIR/api/send_deadline_reminders.php"
MARKER='# pdu-deadline-reminders'
LOG_DIR="${XDG_STATE_HOME:-$HOME/.local/state}/pdu"
LOG_FILE="$LOG_DIR/deadline-reminders.log"

if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
    printf 'PHP CLI executable not found. Pass its absolute path as the second argument.\n' >&2
    exit 1
fi

if [ ! -f "$REMINDER_SCRIPT" ]; then
    printf 'Reminder script not found: %s\n' "$REMINDER_SCRIPT" >&2
    exit 1
fi

if ! "$PHP_BIN" -l "$REMINDER_SCRIPT" >/dev/null; then
    printf 'Reminder script has PHP syntax errors.\n' >&2
    exit 1
fi

mkdir -p "$LOG_DIR"
if [ ! -w "$LOG_DIR" ]; then
    printf 'Cannot write reminder log: %s\n' "$LOG_FILE" >&2
    exit 1
fi

quote_for_shell() {
    local value=${1//\'/\'\\\'\'}
    printf "'%s'" "$value"
}

ENTRY="*/15 * * * * cd $(quote_for_shell "$APP_DIR") && $(quote_for_shell "$PHP_BIN") $(quote_for_shell "$REMINDER_SCRIPT") >> $(quote_for_shell "$LOG_FILE") 2>&1 $MARKER"
CURRENT_CRONTAB=$(crontab -l 2>/dev/null || true)
FILTERED_CRONTAB=$(printf '%s\n' "$CURRENT_CRONTAB" | sed "/$MARKER$/d")

{
    if [ -n "$FILTERED_CRONTAB" ]; then
        printf '%s\n' "$FILTERED_CRONTAB"
    fi
    printf '%s\n' "$ENTRY"
} | crontab -

printf 'Installed deadline reminders every 15 minutes for %s\n' "$APP_DIR"
printf 'Log: %s\n' "$LOG_FILE"
printf 'Verify with: crontab -l\n'