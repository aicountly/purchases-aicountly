<?php

declare(strict_types=1);

/**
 * E8-ID fault listener (tests/environment.php): stands in for a misbehaving intermediary — a WAF
 * page, a proxy's maintenance HTML, a truncated body — in front of a receiver. It is never a
 * receiver: it answers only the fault shapes below, under /fault/<case> (with or without /api).
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$case = basename($path);

switch ($case) {
    case 'html':
        http_response_code(200);
        header('Content-Type: text/html');
        echo '<html><body>Request accepted</body></html>';
        break;
    case 'empty':
        http_response_code(200);
        header('Content-Type: application/json');
        break;
    case 'scalar':
        http_response_code(200);
        header('Content-Type: application/json');
        echo 'true';
        break;
    case 'object':
        http_response_code(200);
        header('Content-Type: application/json');
        echo '{"data":{}}';
        break;
    case 'nocontent':
        http_response_code(204);
        break;
    default:
        http_response_code(404);
        header('Content-Type: application/json');
        echo '{"error":{"code":"not_found"}}';
}
