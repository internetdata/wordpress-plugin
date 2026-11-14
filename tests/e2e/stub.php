<?php

// A stand-in for the InternetData API, answering the addresses the e2e scenarios use in the shapes the
// real API answers them, by plan: no key (Unlimited's fields), a free-plan key within its daily trial
// (`free-key`), one past it (`trial-spent`), or any other key as a paid one.
// Every request is appended to /tmp/stub.log, so a scenario can count lookups. Run with `php -S`.

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$key = trim(preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '') ?: ($_SERVER['HTTP_X_API_KEY'] ?? ''));
file_put_contents('/tmp/stub.log', sprintf("%s %s %s\n", $_SERVER['REQUEST_METHOD'] ?? '', $path, $key === '' ? '-' : $key), FILE_APPEND);
header('Content-Type: application/json');

function reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($path === '/oauth/device_authorization') {
    @unlink('/tmp/stub-polls');
    reply(200, [
        'device_code' => 'device-code-1',
        'user_code' => 'WXYZ-1234',
        'verification_uri' => 'http://stub.test/device',
        'verification_uri_complete' => 'http://stub.test/device?user_code=WXYZ-1234',
        'expires_in' => 600,
        'interval' => 1,
    ]);
}
if ($path === '/oauth/token') {
    $polls = (int) @file_get_contents('/tmp/stub-polls') + 1;
    file_put_contents('/tmp/stub-polls', (string) $polls);
    if ($polls < 2) {
        reply(400, ['error' => 'authorization_pending']);
    }
    reply(200, [
        'access_token' => 'access-1',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'refresh_token' => 'refresh-1',
        'scope' => 'apikeys.read apikeys.reveal',
        'mslm:apikey_id' => '0ab424cc-7619-4dad-b027-afacdc2cedb0',
        'mslm:apikey' => 'max-key',
    ]);
}
if ($path === '/oauth/revoke') {
    reply(200, []);
}
if ($path === '/api/v1/entitlement') {
    if ($key === '' || $key === 'bad-key') {
        reply(401, ['error' => 'invalid API key']);
    }
    $free = $key === 'free-key' || $key === 'trial-spent';
    reply(200, [
        'org_id' => '85bb51e4-2eb6-4a31-8e4d-02ba8b98fe61',
        'apikey' => ['id' => '0ab424cc-7619-4dad-b027-afacdc2cedb0', 'expires' => null, 'allowed_cidrs' => []],
        'plan' => ['key' => $free ? 'free' : 'max', 'tier' => $free ? 'free' : 'max'],
        'usage' => [
            'requests' => $free ? 12 : 1240,
            'quota' => $free ? 100 : 5000000,
            'hard_limit' => null,
            'window_start' => '2026-11-01T00:00:00Z',
            'window_end' => '2026-12-01T00:00:00Z',
        ],
    ]);
}
if ($key === 'spent-key') {
    reply(429, ['error' => 'quota exceeded']);
}

$ip = ltrim($path, '/');
if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
    reply(400, ['error' => 'not a valid IP address']);
}
$country = fn (string $code, string $name, string $continent, string $continentName, string $timezone): array => [
    'continent' => $continent, 'continent_name' => $continentName, 'country' => $code, 'country_name' => $name,
    'timezone' => $timezone, 'currency_code' => 'EUR', 'currency_name' => 'Euro', 'currency_symbol' => 'EUR',
    'flag_emoji' => '', 'flag_unicode' => '',
];
$location = $country('DE', 'Germany', 'EU', 'Europe', 'Europe/Berlin');
$asn = ['asn' => 3320, 'name' => 'Deutsche Telekom AG', 'domain' => 'telekom.de', 'type' => 'isp', 'prefix' => $ip . '/32'];
$company = ['name' => 'Deutsche Telekom AG', 'domain' => 'telekom.de'];
$free = [
    'is_tor' => false, 'is_relay' => false, 'is_anycast' => false, 'is_carrier' => false, 'is_satellite' => false,
    'is_ssh' => false, 'is_smtp' => false, 'is_web' => false, 'is_dns_resolver' => false, 'is_bittorrent' => false,
];
$paid = [
    'is_vpn' => false, 'is_resproxy' => false, 'is_dcproxy' => false, 'is_mobproxy' => false, 'is_pubproxy' => false,
    'is_hosting' => false, 'is_cdn' => false,
];
$details = ['tor' => (object) [], 'relay' => (object) []];
$paidDetails = [
    'vpn' => (object) [], 'resproxy' => (object) [], 'dcproxy' => (object) [], 'mobproxy' => (object) [],
    'pubproxy' => (object) [], 'hosting' => (object) [], 'cdn' => (object) [], 'carrier' => (object) [],
    'satellite' => (object) [], 'ssh' => (object) [], 'smtp' => (object) [], 'web' => [],
    'dns_resolver' => (object) [], 'bittorrent' => (object) [],
];
$class = fn (string $provider): array => [
    'provider' => $provider, 'provider_name' => ucfirst($provider), 'confidence' => 'high', 'last_seen' => '2026-11-13',
];
$proxy = fn (int $hits): array => [
    'provider' => 'ippeak', 'provider_name' => 'IPPeak', 'first_seen' => '2026-08-06', 'last_seen' => '2026-11-10',
    'hits' => $hits, 'hits_days_pct' => 2, 'providers_num' => 1,
];
switch ($ip) {
    case '45.83.91.1':
        $paid['is_vpn'] = $paid['is_hosting'] = true;
        $paidDetails['vpn'] = $class('nordvpn');
        $paidDetails['hosting'] = $class('m247');
        $location = $country('SE', 'Sweden', 'EU', 'Europe', 'Europe/Stockholm');
        $asn = ['asn' => 9009, 'name' => 'M247 Europe SRL', 'domain' => 'm247global.com', 'type' => 'hosting', 'prefix' => '45.83.91.0/24'];
        $company = ['name' => 'M247 Europe SRL', 'domain' => 'm247.com'];
        break;
    case '185.220.101.1':
        $free['is_tor'] = true;
        $paid['is_hosting'] = true;
        $details['tor'] = $class('');
        $paidDetails['hosting'] = $class('');
        $asn = ['asn' => 60729, 'name' => 'Stiftung Erneuerbare Freiheit', 'domain' => 'zwiebelfreunde.de', 'type' => 'hosting', 'prefix' => '185.220.101.0/24'];
        $company = ['name' => 'Stiftung Erneuerbare Freiheit', 'domain' => 'zwiebelfreunde.de'];
        break;
    case '5.6.7.8':
        $paid['is_resproxy'] = true;
        $paidDetails['resproxy'] = $proxy(2);
        break;
    case '66.249.66.1':
        $paid['is_hosting'] = true;
        $paidDetails['hosting'] = $class('gcp');
        $location = $country('US', 'United States', 'NA', 'North America', 'America/Chicago');
        $asn = ['asn' => 15169, 'name' => 'Google LLC', 'domain' => 'google.com', 'type' => 'hosting', 'prefix' => '66.249.64.0/19'];
        $company = ['name' => 'Google LLC', 'domain' => 'google.com'];
        break;
    case '90.0.0.1':
        $location = $country('FR', 'France', 'EU', 'Europe', 'Europe/Paris');
        $asn = ['asn' => 3215, 'name' => 'Orange S.A.', 'domain' => 'orange.com', 'type' => 'isp', 'prefix' => '90.0.0.0/16'];
        $company = ['name' => 'Orange S.A.', 'domain' => 'orange.com'];
        break;
    case '24.48.0.1':
        $location = $country('US', 'United States', 'NA', 'North America', 'America/New_York');
        $asn = ['asn' => 7922, 'name' => 'Comcast Cable Communications, LLC', 'domain' => 'comcast.com', 'type' => 'isp', 'prefix' => '24.48.0.0/16'];
        $company = ['name' => 'Comcast Cable Communications, LLC', 'domain' => 'comcast.com'];
        break;
}
$unlimited = ['ip' => $ip, 'is_bogon' => false] + $free + $details + [
    'location' => $location, 'asn' => $asn, 'company' => $company, 'hostname' => '',
    'abuse' => ['email' => 'abuse@' . $asn['domain']],
];
$full = ['ip' => $ip, 'is_bogon' => false] + $paid + $free + $paidDetails + $details + [
    'location' => $location + ['region' => '', 'region_code' => '', 'city' => '', 'postal_code' => '', 'latitude' => 0, 'longitude' => 0,
        'accuracy_radius' => 100, 'geoname_id' => '', 'dma_code' => '', 'last_changed' => '2026-10-06'],
    'asn' => $asn + ['country' => $location['country'], 'rpki_status' => 'valid'],
    'company' => $company + ['type' => $asn['type'], 'country' => $location['country']],
    'hostname' => '',
    'abuse' => ['email' => 'abuse@' . $asn['domain'], 'name' => $asn['name'], 'phone' => '', 'address' => '',
        'country' => $location['country'], 'network' => $asn['prefix'], 'validated' => true, 'unvalidated_since' => ''],
];
if ($key === '') {
    reply(200, $unlimited);
}
if ($key === 'free-key') {
    reply(200, $full + ['trial_expired' => false]);
}
if ($key === 'trial-spent') {
    reply(200, $unlimited + ['trial_expired' => true]);
}
reply(200, $full);
