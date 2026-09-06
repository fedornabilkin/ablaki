<?php
// Public, read-only contract check. No account, token, or economic mutation.
$base = isset($argv[1]) ? $argv[1] : '';
$url = parse_url($base);
if (!$url || !isset($url['scheme'], $url['host']) || !in_array($url['scheme'], ['http', 'https'], true)
    || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || substr($base, -1) !== '/') {
    fwrite(STDERR, "Expected an absolute HTTP(S) API base URL ending with /.\n");
    exit(1);
}
function getApiJson(string $url): array
{
    $context = stream_context_create(['http' => ['timeout' => 10, 'follow_location' => 0, 'header' => "Accept: application/json\r\n"]]);
    $json = @file_get_contents($url, false, $context);
    if ($json === false || !isset($http_response_header[0]) || !preg_match('~^HTTP/\S+ 200 ~', $http_response_header[0])) throw new RuntimeException('API unavailable');
    $data = json_decode($json, true);
    if (!is_array($data)) throw new RuntimeException('Invalid API JSON');
    return $data;
}
try {
    $health = getApiJson($base . 'health');
    if (($health['status'] ?? '') !== 'ok' || ($health['portalListsVersion'] ?? 0) !== 1
        || !isset($health['revision']) || !preg_match('/^[a-f0-9]{40}$/D', $health['revision'])
        || (isset($argv[2]) && $health['revision'] !== $argv[2])) throw new RuntimeException('Unexpected API release');
    $online = getApiJson($base . 'v1/users/online-count');
    if (!isset($online['count'], $online['windowSeconds']) || !is_int($online['count']) || $online['count'] < 0 || !is_int($online['windowSeconds']) || $online['windowSeconds'] < 1) throw new RuntimeException('Missing presence API');
    $comments = getApiJson($base . 'v1/forum-comment?envelope=1&per-page=1');
    if (!isset($comments['items'], $comments['_meta']['totalCount']) || !is_array($comments['items']) || !is_int($comments['_meta']['totalCount'])) throw new RuntimeException('Missing list envelope');
    foreach ($comments['items'] as $comment) {
        if (!isset($comment['gift_count']) || !is_int($comment['gift_count'])) throw new RuntimeException('Missing forum gifts');
    }
    echo "API contracts ready\n";
} catch (Throwable $error) {
    fwrite(STDERR, "API readiness check failed.\n");
    exit(1);
}
