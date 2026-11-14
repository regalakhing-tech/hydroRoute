<?php
// Run with php -n tests/supabase-legacy-curl.php to model the older Vercel libcurl.
declare(strict_types=1);

if (extension_loaded('curl')) {
    throw new RuntimeException('Run this test with php -n so the legacy transport can be simulated.');
}

// Only the options available in the older transport are present. No network is used.
foreach (['CURLOPT_CUSTOMREQUEST', 'CURLOPT_HTTPHEADER', 'CURLOPT_RETURNTRANSFER',
    'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT', 'CURLOPT_FOLLOWLOCATION',
    'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_PROTOCOLS',
    'CURLOPT_POSTFIELDS', 'CURLINFO_HTTP_CODE', 'CURLPROTO_HTTPS'] as $index => $name) {
    define($name, $index + 1);
}

$options = [];
if (!extension_loaded('curl')) {
    function curl_init(string $url): object { return (object) ['url' => $url]; }
    function curl_setopt_array(object $handle, array $values): bool {
        $GLOBALS['options'] = $values;
        return true;
    }
    function curl_setopt(object $handle, int $option, mixed $value): bool { return true; }
    function curl_exec(object $handle): string { return '[]'; }
    function curl_getinfo(object $handle, int $option): int { return 200; }
}

require dirname(__DIR__) . '/bootstrap.php';
if (isset($argv[1])) {
    require $argv[1];
}
$client = new App\Services\SupabaseClient([
    'url' => 'https://example.supabase.co',
    'publishable_key' => 'sb_publishable_test',
    'timeout_seconds' => 10,
]);
if ($client->select('stations', ['select' => 'id']) !== []) {
    throw new RuntimeException('The response must be decoded.');
}
foreach ([CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false] as $option => $expected) {
    if (($options[$option] ?? null) !== $expected) {
        throw new RuntimeException('Legacy requests must remain HTTPS-only with certificate verification and no redirects.');
    }
}
echo "Legacy libcurl request and TLS protections passed.\n";
