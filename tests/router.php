<?php
/*
 * Router für den PHP-Entwicklungsserver in den Tests: /<instanz>/api → API dieser Instanz.
 * Die Instanzen stehen als JSON in der Datei, auf die GN_TEST_CONFIG zeigt:
 *   {"state_dir": "...", "instances": {"name": {"data_dir": "...", ...Einstellungen}}}
 * Zusätzlich je Instanz möglich: "net_file" und "bot_delay" (Trombone-Bot, sonst nicht einstellbar).
 */

define('GAME_NIGHT', 1);
require dirname(__DIR__) . '/core/Api.php';

$conf = json_decode((string)file_get_contents((string)getenv('GN_TEST_CONFIG')), true);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/([a-z0-9_-]+)/api$#', $path, $m) && isset($conf['instances'][$m[1]])) {
    $settings = $conf['instances'][$m[1]];
    $config = GameNight\instance_config($settings, $settings['data_dir'], $conf['state_dir']);
    // nur in den Tests einstellbar: Gewichtsdatei und Denkpause [von, bis] des Trombone-Bots
    $config = array_intersect_key($settings, ['net_file' => 1, 'bot_delay' => 1]) + $config;
    GameNight\run($config);
}
http_response_code(404);
