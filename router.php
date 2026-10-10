<?php
declare(strict_types=1);
// Apache uses the folder .htaccess files; PHP's development server needs a router.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$path = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$path = str_replace('\\', '/', $path);
$segments = explode('/', $path);
foreach ($segments as $segment) {
    if (str_contains($segment, "\0") || str_starts_with($segment, '.') || in_array(strtolower($segment), ['data', 'lib', 'tests'], true)) {
        http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Not found.'; return true;
    }
}
if (preg_match('/\.php$/i', $path) && !in_array(strtolower($path), ['/index.php', '/api.php'], true)) {
    http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Not found.'; return true;
}
$resolved = realpath(__DIR__ . DIRECTORY_SEPARATOR . ltrim($path, '/'));
if ($resolved !== false && $resolved !== __DIR__ && !str_starts_with(strtolower($resolved), strtolower(__DIR__ . DIRECTORY_SEPARATOR))) {
    http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Not found.'; return true;
}
return false;