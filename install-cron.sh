#!/usr/bin/env bash
#
# Installs a cronjob for the DirectAdmin DDNS update script.
#
# Usage (as root, or with sudo available):
#   curl -s https://yourdomain.com/ddns/install-cron.sh | bash -s -- 'https://yourdomain.com/ddns/update.php?secret=YOUR_SECRET'
#
# Or with arguments:
#   ./install-cron.sh <update-url> [interval]
#     interval: "hourly" (default), "*/5 * * * *" style cron expression, or "daily"
#
# Writes /etc/cron.d/directadmin-ddns. Safe to re-run (overwrites).

set -euo pipefail

CRON_FILE="/etc/cron.d/directadmin-ddns"

UPDATE_URL="${1:-}"
INTERVAL="${2:-hourly}"

if [[ -z "$UPDATE_URL" ]]; then
    echo "Error: no update URL given." >&2
    echo "Usage: $0 <update-url> [interval]" >&2
    echo "  interval: hourly (default) | daily | '*/5 * * * *'" >&2
    exit 1
fi

case "$INTERVAL" in
    hourly) SCHEDULE="0 * * * *" ;;
    daily)  SCHEDULE="30 3 * * *" ;;
    *)      SCHEDULE="$INTERVAL" ;;
esac

# Validate the URL a little before installing it.
if [[ ! "$UPDATE_URL" =~ ^https?:// ]]; then
    echo "Error: update URL must start with http:// or https://" >&2
    exit 1
fi

# Verify curl exists and the endpoint responds before installing anything.
if ! command -v curl >/dev/null 2>&1; then
    echo "Error: curl is not installed." >&2
    exit 1
fi

echo "Testing endpoint..."
http_code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$UPDATE_URL" 2>/dev/null) || http_code="000"
if [[ "$http_code" == "000" ]]; then
    echo "Error: could not reach the DDNS endpoint: $UPDATE_URL" >&2
    exit 1
fi
if [[ "$http_code" != "200" ]]; then
    echo "Warning: endpoint responded with HTTP $http_code (expected 200)." >&2
    if [[ -t 0 ]]; then
        read -r -p "Install the cronjob anyway? [y/N] " answer
        [[ "$answer" =~ ^[Yy]$ ]] || exit 1
    else
        echo "Error: not a terminal; refusing to install. Fix the endpoint or run interactively." >&2
        exit 1
    fi
else
    echo "Endpoint OK (HTTP $http_code)."
fi

# privilege escalation
if [[ "$(id -u)" -eq 0 ]]; then
    RUNAS=""
elif command -v sudo >/dev/null 2>&1; then
    RUNAS="sudo"
else
    echo "Error: must be root (or have sudo) to write $CRON_FILE." >&2
    exit 1
fi

echo "Installing cronjob to $CRON_FILE (schedule: $SCHEDULE)..."
$RUNAS tee "$CRON_FILE" >/dev/null <<EOF
# DirectAdmin DDNS update - installed $(date -u +%Y-%m-%dT%H:%M:%SZ)
# Updates $(sed -e 's/?.*//' -e 's/.*\/\///' <<<"$UPDATE_URL") (IPv4 and IPv6).
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

# IPv4 (fall back to IPv4 explicitly)
$SCHEDULE root curl -4 -sf --max-time 60 "$UPDATE_URL" >/dev/null 2>&1
# IPv6 (only runs when this host actually has IPv6 connectivity)
$SCHEDULE root (curl -6 -sf --max-time 30 -o /dev/null "$UPDATE_URL" 2>/dev/null || true)
EOF

$RUNAS chmod 644 "$CRON_FILE"

echo "Done. Cron file contents:"
echo "------------------------------------------------------------"
cat "$CRON_FILE"
echo "------------------------------------------------------------"
echo "Remove again with: ${RUNAS:+$RUNAS }rm $CRON_FILE"
