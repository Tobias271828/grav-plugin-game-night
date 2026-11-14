<?php
/*
 * Game Night – Standalone-Version: diesen Ordner auf einen beliebigen PHP-Webspace (PHP ≥ 8.0)
 * kopieren, config.example.php nach config.php kopieren und anpassen. Ein Ordner = eine Instanz.
 *
 *   index.php        Seite (GET) und API (POST an index.php?api)
 *   config.php       Einstellungen
 *   core/, assets/   Programm (bei Updates einfach ersetzen)
 *   data/            Tische (vom Webserver aus gesperrt; besser per data_dir außerhalb des Webroots)
 */

// Im fertigen Paket liegen core/ und assets/ neben index.php, im Quellcode-Repository eine Ebene höher
$root = is_dir(__DIR__ . '/core') ? __DIR__ : dirname(__DIR__);

// Quellcode-Ordner innerhalb einer Grav-Installation (Plugin per git clone installiert): nichts tun,
// sonst gäbe es unter /user/plugins/game-night/standalone/ ungewollt eine zusätzliche Instanz.
// (@: keine open_basedir-Warnung mit Serverpfad, falls der Ordner darüber nicht lesbar ist)
if ($root !== __DIR__ && @is_file(dirname(__DIR__, 4) . '/system/defines.php')) {
    http_response_code(404);
    exit;
}

define('GAME_NIGHT', 1);
$assetsUrl = $root === __DIR__ ? 'assets/' : '../assets/';

$settings = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
if (!is_array($settings)) {
    $settings = [];
}
$dataDir = rtrim((string)($settings['data_dir'] ?? __DIR__ . '/data'), '/');

require $root . '/core/Api.php';
$config = GameNight\instance_config($settings, $dataDir . '/tables', $dataDir);

if (isset($_GET['api'])) {
    GameNight\run($config);   // beendet die Anfrage
}

require $root . '/core/Page.php';
GameNight\render_page($config, [
    'api'       => '?api',
    'assets'    => $assetsUrl,
    'asset_dir' => $root . '/assets',
    'title'     => is_string($settings['title'] ?? null) ? $settings['title'] : null,
    'links'     => (array)($settings['footer_links'] ?? []),
]);
