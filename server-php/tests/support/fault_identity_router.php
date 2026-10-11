<?php

declare(strict_types=1);

/**
 * E8-ID fault listener for tests/identity_outage.php. It stands IN FRONT of the repo's own test
 * stub (tests/stub/router.php) the way a broken proxy or a maintenance page stands in front of
 * my.aicountly.com or Manage: in "pass" mode every request goes to that stub unchanged; in a fault
 * mode the named upstream (portal = validatesession, manage = companyinfo) answers with the fault
 * instead. It never invents a positive answer of its own.
 *
 * The mode is read from the JSON file named by FAULT_MODE_FILE: {"portal": "...", "manage": "..."}.
 *   pass | http502 | http503 | html200 | empty200 | timeout | refused401 | json404 | http500
 */

$modes = json_decode((string) @file_get_contents((string) getenv('FAULT_MODE_FILE')), true);
$modes = is_array($modes) ? $modes : [];
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

$upstream = str_contains($path, 'validatesession') ? 'portal' : (str_contains($path, 'companyinfo') ? 'manage' : 'other');
$mode = (string) ($modes[$upstream] ?? 'pass');

switch ($mode) {
    case 'pass':
        require (string) getenv('FAULT_PASS_ROUTER');

        return;
    case 'http502':
    case 'http503':
    case 'http500':
        http_response_code((int) substr($mode, 4));
        header('Content-Type: text/html');
        echo '<html><body>Bad gateway</body></html>';
        break;
    case 'html200':
        http_response_code(200);
        header('Content-Type: text/html');
        echo '<html><body>Scheduled maintenance</body></html>';
        break;
    case 'empty200':
        http_response_code(200);
        break;
    case 'timeout':
        sleep(30);
        http_response_code(504);
        break;
    case 'refused401':
        // The portal's own refusal shape (my.aicountly.com AppCommonModel).
        http_response_code(401);
        header('Content-Type: application/json');
        echo '{"status":0,"message":"Invalid session"}';
        break;
    case 'json404':
        // Manage's own refusal (public/index.php companyinfo: Response::error(..., 404)).
        http_response_code(404);
        header('Content-Type: application/json');
        echo '{"success":false,"message":"Company not found or access denied"}';
        break;
    default:
        http_response_code(500);
}
