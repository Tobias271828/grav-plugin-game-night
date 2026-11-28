<?php
/*
 * Router für den PHP-Entwicklungsserver in den Browser-Tests (tests/e2e/run.js).
 *
 *   php -S 127.0.0.1:8030 -t <Plugin-Ordner> tests/e2e/router.php
 *
 * GN_E2E_CONFIG zeigt auf eine JSON-Datei:
 *   {"state_dir": "...", "instances": {"a": {"data_dir": "...", ...Einstellungen}, ...}}
 * Jede Instanz <name> ist unter /<name>/ erreichbar (Seite wie in der Standalone-Version),
 * ihre API unter /<name>/api. /assets/ liefert der Entwicklungsserver direkt aus.
 */

define('GAME_NIGHT', 1);
$root = dirname(__DIR__, 2);
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($path, '/assets/')) {
    return false;   // statische Datei aus dem Plugin-Ordner
}

$conf = json_decode((string)file_get_contents((string)getenv('GN_E2E_CONFIG')), true);
if (!preg_match('#^/([a-z0-9_-]+)/(api)?$#', $path, $m) || !isset($conf['instances'][$m[1]])) {
    http_response_code(404);
    exit;
}

require $root . '/core/Api.php';
$settings = $conf['instances'][$m[1]];
$config = GameNight\instance_config($settings, $settings['data_dir'], $conf['state_dir']);
// nur in den Tests einstellbar: Gewichtsdatei und Denkpause [von, bis] des Trombone-Bots
$config = array_intersect_key($settings, ['net_file' => 1, 'bot_delay' => 1]) + $config;

if (($m[2] ?? '') === 'api') {
    GameNight\run($config);   // beendet die Anfrage
}

require $root . '/core/Page.php';
GameNight\render_page($config, [
    'api'       => '/' . $m[1] . '/api',
    'assets'    => '/assets/',
    'asset_dir' => $root . '/assets',
    'links'     => (array)($settings['footer_links'] ?? []),
    'favicon'   => $settings['favicon'] ?? null,
]);
