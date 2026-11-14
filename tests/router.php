<?php
/*
 * Router für den PHP-Entwicklungsserver in den Tests: /<instanz>/api → API dieser Instanz.
 * Die Instanzen stehen als JSON in der Datei, auf die GN_TEST_CONFIG zeigt:
 *   {"state_dir": "...", "instances": {"name": {"data_dir": "...", ...Einstellungen}}}
 */

define('GAME_NIGHT', 1);
require dirname(__DIR__) . '/core/Api.php';

$conf = json_decode((string)file_get_contents((string)getenv('GN_TEST_CONFIG')), true);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/([a-z0-9_-]+)/api$#', $path, $m) && isset($conf['instances'][$m[1]])) {
    $settings = $conf['instances'][$m[1]];
    GameNight\run(GameNight\instance_config($settings, $settings['data_dir'], $conf['state_dir']));
}
http_response_code(404);
