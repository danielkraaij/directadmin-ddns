<?php

declare(strict_types=1);

/*
 * DirectAdmin DDNS update endpoint.
 *
 * Determines the IP address of the requester (or the ?ip= parameter)
 * and updates the configured A / AAAA record through the DirectAdmin
 * DNS Control API.
 *
 * Usage:
 *   https://yourdomain.com/ddns/update.php
 *   https://yourdomain.com/ddns/update.php?secret=YOUR_SECRET
 *   https://yourdomain.com/ddns/update.php?secret=YOUR_SECRET&ip=1.2.3.4
 */

header('Content-Type: text/plain; charset=utf-8');

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    fail(500, 'Config missing. Copy config.example.php to config.php and configure it.');
}
$config = require $configFile;

/**
 * End the request with an error message and HTTP status code.
 */
function fail(int $status, string $message): void
{
    http_response_code($status);
    echo $message . "\n";
    exit;
}

/**
 * Check the shared secret, if one is configured.
 */
function check_secret(array $config): void
{
    $secret = trim((string) ($config['SECRET'] ?? ''));
    if ($secret === '') {
        return; // Authentication disabled.
    }

    $given = (string) ($_GET['secret'] ?? '');
    if (!hash_equals($secret, $given)) {
        fail(401, 'Unauthorized: invalid or missing secret.');
    }
}

/**
 * Resolve the IP address to publish.
 */
function resolve_ip(array $config): string
{
    // An explicit ?ip= parameter always wins.
    $ip = trim((string) ($_GET['ip'] ?? ''));

    if ($ip === '') {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        // Optionally trust X-Forwarded-For when running behind a proxy.
        if (!empty($config['TRUST_PROXY']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
            $ip = $parts[0];
        }
    }

    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        fail(400, "Invalid IP address: {$ip}");
    }

    if (empty($config['ALLOW_PRIVATE_IPS'])
        && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
    ) {
        fail(400, "Refusing private or reserved IP address: {$ip}");
    }

    return $ip;
}

/**
 * Perform a request against the DirectAdmin DNS Control API.
 *
 * @param array<string, mixed> $params POST parameters for CMD_API_DNS_CONTROL.
 * @return array Decoded JSON response.
 */
function da_dns_request(array $config, array $params): array
{
    $host = (string) ($config['DA_HOST'] ?? '');
    $port = (int) ($config['DA_PORT'] ?? 2222);
    if ($host === '') {
        fail(500, 'Configuration error: DA_HOST is not set.');
    }

    $url = sprintf('https://%s:%d/CMD_API_DNS_CONTROL', $host, $port);
    $verifySsl = !empty($config['DA_VERIFY_SSL']);

    // Ask DirectAdmin for a JSON response ("records" array).
    $params['json'] = 'yes';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_USERPWD => ($config['DA_USER'] ?? '') . ':' . ($config['DA_PASS'] ?? ''),
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    ]);

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        fail(502, "Could not reach the DirectAdmin server: {$error}");
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($status === 401) {
        fail(401, 'DirectAdmin rejected the credentials (DA_USER / DA_PASS).');
    }

    // On errors DirectAdmin replies with a form-encoded "error=..." body.
    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        $form = [];
        parse_str((string) $body, $form);
        if (!empty($form['error'])) {
            $message = $form['error'] . ' ' . ($form['errorlevel'] ?? '');
            fail(502, 'DirectAdmin returned an error: ' . trim($message));
        }
        if ($status >= 200 && $status < 300) {
            fail(502, 'Unexpected response from DirectAdmin: ' . substr((string) $body, 0, 200));
        }
        fail(502, "DirectAdmin returned HTTP {$status}: " . substr((string) $body, 0, 200));
    }

    if (!empty($decoded['error'])) {
        $message = $decoded['error'] . ' ' . ($decoded['errorlevel'] ?? '');
        fail(502, 'DirectAdmin returned an error: ' . trim($message));
    }

    return $decoded;
}

/**
 * Normalise a record name from the DNS Control JSON output to its FQDN
 * (lowercase, without trailing dot).
 */
function record_name_to_fqdn(string $name, string $domain): string
{
    $name = strtolower(rtrim(trim($name), '.'));
    if ($name === '' || $name === '@' || $name === $domain) {
        return $domain;
    }
    if (str_ends_with($name, '.' . $domain)) {
        return $name;
    }
    return $name . '.' . $domain;
}

/*
 * ---------------------------------------------------------------------------
 * Main
 * ---------------------------------------------------------------------------
 */

check_secret($config);
$ip = resolve_ip($config);

$domain = strtolower(trim((string) ($config['DA_DOMAIN'] ?? '')));
$record = strtolower(trim((string) ($config['DA_RECORD'] ?? '')));
$ttl = (int) ($config['DA_TTL'] ?? 300);

if ($domain === '' || !str_contains($domain, '.')) {
    fail(500, 'Configuration error: DA_DOMAIN is not set correctly.');
}
if ($record === '@') {
    $record = '';
}

$isIPv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
$type = $isIPv6 ? 'AAAA' : 'A';

$fqdn = $record === '' ? $domain : $record . '.' . $domain;

// 1. Fetch the current zone records and look for matching entries.
$zone = da_dns_request($config, ['domain' => $domain]);

$upToDate = false;
$staleRecord = null;
foreach ((array) ($zone['records'] ?? []) as $rec) {
    if (!is_array($rec) || strtoupper((string) ($rec['type'] ?? '')) !== $type) {
        continue;
    }
    if (record_name_to_fqdn((string) ($rec['name'] ?? ''), $domain) !== $fqdn) {
        continue;
    }
    $value = rtrim(trim((string) ($rec['value'] ?? '')), '.');
    if (strcasecmp($value, $ip) === 0) {
        $upToDate = true;
        break;
    }
    if ($staleRecord === null) {
        $staleRecord = $rec;
    }
}

if ($upToDate) {
    echo "OK: {$type} record {$fqdn}. already points to {$ip}, nothing to do.\n";
    exit;
}

// 2. Remove a stale record of the same type, if present. The "combined"
//    field from the JSON response is exactly what the API expects for
//    deletion (e.g. "name=home&value=1.2.3.4").
if ($staleRecord !== null) {
    // Deletion uses action=select with the record's "combined" string
    // (e.g. "name=home&value=1.2.3.4") in its bucket field:
    // "arecs0" for A records, "aaaarecs0" for AAAA records.
    $bucket = $isIPv6 ? 'aaaarecs0' : 'arecs0';
    $combined = (string) ($staleRecord['combined'] ?? '');
    if ($combined === '') {
        $combined = 'name=' . ($staleRecord['name'] ?? $record) . '&value=' . ($staleRecord['value'] ?? '');
    }
    da_dns_request($config, [
        'domain' => $domain,
        'action' => 'select',
        $bucket => $combined,
    ]);
}

// 3. Add the record with the new IP address.
da_dns_request($config, [
    'domain' => $domain,
    'action' => 'add',
    'type' => $type,
    'name' => $record,
    'value' => $ip,
    'ttl' => $ttl,
]);

$action = $staleRecord !== null ? 'updated' : 'created';
echo "OK: {$type} record {$fqdn}. {$action} to {$ip}.\n";
