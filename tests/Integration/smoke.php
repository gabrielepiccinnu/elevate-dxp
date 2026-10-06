<?php

declare(strict_types=1);

/*
 * HTTP smoke tests against a freshly installed OpenDXP application (see install-and-smoke.sh).
 * Plain PHP + curl so it runs anywhere the application runs.
 */

$base = rtrim((string) getenv('BASE_URL'), '/') ?: 'http://127.0.0.1:8000';
final class Smoke
{
    public static int $failures = 0;
}

$jar = tempnam(sys_get_temp_dir(), 'edxp');
$browserUa = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Safari/605.1.15';

/**
 * @param array<string, string> $headers
 *
 * @return array{status: int, body: string, headers: string}
 */
function request(string $method, string $url, array $headers = [], ?string $body = null, ?string $jar = null, string $ua = 'curl'): array
{
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) {
        $h[] = $k.': '.$v;
    }
    curl_setopt_array($ch, [
        \CURLOPT_CUSTOMREQUEST => $method,
        \CURLOPT_RETURNTRANSFER => true,
        \CURLOPT_HEADER => true,
        \CURLOPT_HTTPHEADER => $h,
        \CURLOPT_USERAGENT => $ua,
        \CURLOPT_TIMEOUT => 60,
    ]);
    if ($body !== null) {
        curl_setopt($ch, \CURLOPT_POSTFIELDS, $body);
    }
    if ($jar !== null) {
        curl_setopt($ch, \CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, \CURLOPT_COOKIEFILE, $jar);
    }
    $raw = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, \CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
}

function check(string $label, bool $ok, string $detail = ''): void
{
    printf("%s %s%s\n", $ok ? '  ok ' : ' FAIL', $label, $ok || $detail === '' ? '' : ' — '.$detail);
    Smoke::$failures += $ok ? 0 : 1;
}

// 1. Frontend: first visit issues the signed visitor cookie (browser user agent; bots are excluded)
$r = request('GET', $base.'/', [], null, $jar, $browserUa);
check('homepage renders', $r['status'] === 200, 'status '.$r['status']);
check('visitor cookie issued', (bool) preg_match('/set-cookie: edxp_vid=[a-f0-9]{32}\./i', $r['headers']));
check('runtime injected', str_contains($r['body'], 'window.edxpConfig') && str_contains($r['body'], '/bundles/elevatedxp/js/edxp-runtime.js'));
check('demo experiment assigned', (bool) preg_match('/"hero":"(A|B)"/', $r['body']));
check('personalised response is private', (bool) preg_match('/cache-control:[^\r\n]*private/i', $r['headers']));

$bot = request('GET', $base.'/', [], null, null, 'Googlebot/2.1');
check('bots are not enrolled', !str_contains($bot['headers'], 'edxp_vid='));

// 2. Conversion tracking endpoint
$event = json_encode(['event' => 'signup', 'value' => 10]);
$r = request('POST', $base.'/_edxp/track', ['Content-Type' => 'application/json', 'Origin' => $base], $event, $jar, $browserUa);
check('track conversion (same origin)', $r['status'] === 204, 'status '.$r['status']);
$r = request('POST', $base.'/_edxp/track', ['Content-Type' => 'application/json', 'Origin' => 'https://evil.example'], $event, $jar, $browserUa);
check('cross-origin tracking rejected', $r['status'] === 403, 'status '.$r['status']);
$r = request('POST', $base.'/_edxp/track', ['Content-Type' => 'application/json', 'Origin' => $base], json_encode(['event' => 'exposure']), $jar, $browserUa);
check('client-side exposures rejected', $r['status'] === 422, 'status '.$r['status']);
$r = request('POST', $base.'/_edxp/track', ['Content-Type' => 'application/json'], $event);
check('unknown visitor accepted silently', $r['status'] === 204, 'status '.$r['status']);

// 3. Admin API requires an authenticated admin session
$r = request('GET', $base.'/admin/elevate-dxp/r/experiments/list');
check('admin API not public', in_array($r['status'], [302, 401, 403], true), 'status '.$r['status']);

// 4. Public endpoints are deny-by-default
$r = request('GET', $base.'/elevate-dxp/api/anything');
check('datahub REST denies without key', in_array($r['status'], [401, 404], true), 'status '.$r['status']);
$r = request('GET', $base.'/elevate-dxp/feed/unknown.csv?token=x');
check('feed URL does not leak names', $r['status'] === 404, 'status '.$r['status']);

@unlink($jar);
echo Smoke::$failures === 0 ? "\nAll smoke checks passed.\n" : "\n".Smoke::$failures." smoke check(s) failed.\n";
exit(Smoke::$failures === 0 ? 0 : 1);
