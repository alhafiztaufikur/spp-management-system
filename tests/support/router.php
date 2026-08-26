<?php

// Router khusus PHP built-in server untuk integration test pada database audit.
// Apache menolak seluruh folder tests melalui .htaccess.
$requestPath = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'));
$normalizedPath = '/' . ltrim(str_replace('\\', '/', $requestPath), '/');

if (preg_match('#^/(?:\.git|\.agents|\.codex|tests|sql|documentation|includes|config|vendor)(?:/|$)#i', $normalizedPath)) {
    http_response_code(404);
    exit('Not Found');
}

if (preg_match('#^/(?:composer\.(?:json|lock)|koneksi\.php|\.env(?:\..*)?)$#i', $normalizedPath)) {
    http_response_code(404);
    exit('Not Found');
}

return false;
