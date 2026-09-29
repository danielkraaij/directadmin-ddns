# Purpose
PHP script that can act as a Dynamic-DNS endpoint for your domain that is hosted on a Directadmin machine.
It connects to the DirectAdmin API endpoint and updates a preset record with the IP of the requester of this script. 

## Features
- Updates DNS records on DirectAdmin via API
- Supports IPv4 and IPv6
- Optional secret key for security
- Lightweight and easy to use

## Setup

1. Copy `config.example.php` to `config.php` and fill in the required values.
2. Upload the script to your web server.
3. Configure your router or DDNS client to call the script's URL when your IP changes.

## Configuration

Edit `config.php` and set the following values:

- `DA_HOST` - The hostname or IP address of your DirectAdmin server.
- `DA_PORT` - The port of your DirectAdmin server (default: 2222).
- `DA_USER` - Your DirectAdmin username.
- `DA_PASS` - Your DirectAdmin password or login key. A login key with only "DNS Control" access is recommended (DirectAdmin -> User Profile -> Login Keys).
- `DA_DOMAIN` - The domain for which you want to update the DNS record.
- `DA_RECORD` - The DNS record name to update (e.g. `home`). Use `@` to update the domain apex.
- `DA_TTL` - The TTL for the DNS record (default: 300).
- `SECRET` - Shared secret that clients must pass as `?secret=...`. Leave empty to disable authentication (not recommended).

Optional advanced settings:

- `TRUST_PROXY` - Set to `true` to determine the client IP from the `X-Forwarded-For` header when running behind a reverse proxy. Only enable this if your proxy always sets/overwrites the header.
- `ALLOW_PRIVATE_IPS` - Set to `true` to allow updating records to private/reserved IP addresses (default: `false`).
- `DA_VERIFY_SSL` - Set to `true` to validate the TLS certificate of the DirectAdmin panel (default: `false`, since DirectAdmin uses a self-signed certificate by default).

## Usage

Point your DDNS client to the URL of this script, for example:

https://yourdomain.com/ddns/update.php?secret=YOUR_SECRET

Optionally, you can set a secret key in `config.php` to prevent unauthorized updates.

You can also create a cronjob to update your IP address automatically every hour.

## Examples

### Basic Usage

Call the script from your browser or DDNS client:

```
https://yourdomain.com/ddns/update.php
```

### With a Secret Key

If you have set a `SECRET` in `config.php`, include it in the request:

```
https://yourdomain.com/ddns/update.php?secret=YOUR_SECRET
```

### Force IPv4 or IPv6

You can force the script to use a specific IP version by passing the `ip` parameter:

```
https://yourdomain.com/ddns/update.php?secret=YOUR_SECRET&ip=1.2.3.4
https://yourdomain.com/ddns/update.php?secret=YOUR_SECRET&ip=2001:db8::1
```

### Automatic Cronjob Installer (recommended)

Install a cronjob on your server in one command (run as root, or with sudo available):

```
curl -s https://yourdomain.com/ddns/install-cron.sh | bash -s -- "https://yourdomain.com/ddns/update.php?secret=YOUR_SECRET"
```

This smoke-tests the endpoint, then writes `/etc/cron.d/directadmin-ddns` with hourly IPv4 and IPv6 update jobs. Optionally pass a schedule as second argument: `hourly` (default), `daily`, or a cron expression such as `"*/5 * * * *"`.

### Manual Cronjob Example

To update your IPv4 address automatically every hour, add the following to your crontab:

```
0 * * * * curl -s "https://yourdomain.com/ddns/update.php?secret=YOUR_SECRET" > /dev/null
```

To update your IPv6 address automatically every hour, add the following to your crontab:

```
0 * * * * curl -s -6 "https://yourdomain.com/ddns/update.php?secret=YOUR_SECRET" > /dev/null
```

## Requirements

- PHP 8.0 or higher
- cURL extension enabled
- A DirectAdmin server with API access enabled

## Files

```
update.php          The DDNS endpoint. Upload this (plus config.php) to your web server.
config.example.php  Configuration template. Copy to config.php and fill in your values.
config.php          Your real credentials (git-ignored, never commit this).
install-cron.sh     One-command cron job installer for your DDNS client machine.
```

## License

MIT