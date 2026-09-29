<?php

declare(strict_types=1);

/*
 * DirectAdmin DDNS configuration.
 *
 * Copy this file to config.php and fill in your values.
 * Never commit config.php to version control.
 */

return [
    // Hostname or IP address of your DirectAdmin server.
    'DA_HOST' => 'da.example.com',

    // Port of the DirectAdmin panel (default: 2222).
    'DA_PORT' => 2222,

    // DirectAdmin username.
    'DA_USER' => 'your_username',

    // DirectAdmin password or a login key.
    // Login keys are recommended: DirectAdmin -> User Profile -> Login Keys.
    // Create a login key limited to "DNS Control" if possible.
    'DA_PASS' => 'your_password_or_login_key',

    // The domain that holds the DNS record, e.g. "example.com".
    'DA_DOMAIN' => 'example.com',

    // The hostname part of the record to update, e.g. "home".
    // This results in the record "home.example.com.".
    // Use "@" (or an empty string) to update the apex/domain itself.
    'DA_RECORD' => 'home',

    // TTL in seconds for the DNS record.
    'DA_TTL' => 300,

    // Shared secret that DDNS clients must pass as ?secret=....
    // Leave empty to disable authentication (NOT recommended on a public URL).
    'SECRET' => 'change-me-to-a-long-random-string',

    // --- Optional advanced settings ---

    // Set to true when this script runs behind a reverse proxy
    // (nginx, Cloudflare, etc.) and you want to trust the
    // X-Forwarded-For header to determine the client IP.
    // Only enable this if the proxy always sets/overwrites the header!
    'TRUST_PROXY' => false,

    // Set to true to allow updating records to private/reserved
    // IP addresses (e.g. for internal DNS). Not recommended.
    'ALLOW_PRIVATE_IPS' => false,

    // Set to true to validate the TLS certificate of the
    // DirectAdmin panel. Leave false when using a self-signed
    // certificate (the DirectAdmin default).
    'DA_VERIFY_SSL' => false,
];
