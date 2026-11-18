<?php
/*
 * Game Night – vollständige HTML-Seite für die Standalone-Version (in Grav übernimmt das Theme
 * den Seitenrahmen, siehe templates/game-night.html.twig).
 *
 * Kein Inline-JavaScript und kein Inline-CSS: Die Seite kommt mit einer strengen
 * Content-Security-Policy aus, die nur Dateien vom eigenen Server erlaubt.
 */

namespace GameNight;

\defined('GAME_NIGHT') or die();

/** Skripte in Ladereihenfolge (auch für das Grav-Template). */
// Reihenfolge der Spiele = Reihenfolge auf der Übersicht
const SCRIPTS = ['chess.min.js', 'core.js', 'trombone.js', 'werbinich.js', 'handbrain.js', 'connect4.js',
                 'konferenz.js', 'zahlenkampf-logic.js', 'zahlenkampf.js', 'app.js'];

/** Neuestes Änderungsdatum der Assets (für ?v=…): Browser laden nach einem Update die neuen Dateien. */
function asset_version(string $assetDir): string
{
    $latest = 0;
    foreach (array_merge(glob("$assetDir/*.css") ?: [], glob("$assetDir/js/*.js") ?: []) as $file) {
        $latest = max($latest, (int)filemtime($file));
    }
    return base_convert((string)$latest, 10, 36);
}

/** Sprache: ?lang=de|en, sonst aus dem Browser (Accept-Language), sonst Englisch. */
function page_lang(): string
{
    $q = $_GET['lang'] ?? null;
    if ($q === 'de' || $q === 'en') {
        return $q;
    }
    // Erste Sprache aus der Liste des Browsers, die es hier gibt (Gewichtungen q=… stehen meist schon sortiert)
    preg_match_all('/(?:^|,)\s*(de|en)\b/i', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), $m);
    return strtolower($m[1][0] ?? 'en');
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Seite ausgeben.
 * $config: instance_config(...); $page: ['assets' => URL des assets-Ordners (mit / am Ende),
 * 'api' => URL der API, 'title' => Seitentitel (optional), 'links' => Links für die Fußzeile:
 * [Text => URL] für beide Sprachen oder ['de' => [Text => URL], 'en' => [Text => URL]]].
 */
function render_page(array $config, array $page): void
{
    $lang = page_lang();
    $assets = $page['assets'];
    $v = asset_version($page['asset_dir']);
    $title = $page['title'] ?? ($lang === 'de' ? 'Spieleabend' : 'Game Night');

    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
         . "connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    header('Content-Language: ' . $lang);

    $other = $lang === 'de' ? 'en' : 'de';
    $links = '';
    $linkList = (array)($page['links'] ?? []);
    if (isset($linkList['de']) || isset($linkList['en'])) {
        $linkList = (array)($linkList[$lang] ?? []);
    }
    foreach ($linkList as $text => $url) {
        if (is_string($url) && $url !== '') {
            $links .= ' · <a href="' . e($url) . '">' . e($text) . '</a>';
        }
    }
    ?><!doctype html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e($assets) ?>standalone.css?v=<?= $v ?>">
<link rel="stylesheet" href="<?= e($assets) ?>style.css?v=<?= $v ?>">
</head>
<body>
<main class="sa">
    <div class="sa-bar">
        <a class="sa-brand" href="#"><?= e($title) ?></a>
        <span id="whoami" class="whoami"></span>
    </div>
    <div id="app" class="app"
         data-api="<?= e($page['api']) ?>"
         data-lang="<?= e($lang) ?>"
         data-lobby="<?= $config['lobby'] ? '1' : '0' ?>"
         data-contact="<?= e($config['contact_url']) ?>"
         data-games="<?= e(implode(',', $config['games'])) ?>"
         data-lang-param="1"></div>
    <div id="toast" class="toast" hidden></div>
</main>
<footer class="footer sa-footer">
    <a class="sa-lang" href="?lang=<?= $other ?>" hreflang="<?= $other ?>"><?= $other === 'en' ? 'English' : 'Deutsch' ?></a><?= $links ?>
</footer>
<?php foreach (SCRIPTS as $file): ?>
<script src="<?= e($assets) ?>js/<?= $file ?>?v=<?= $v ?>"></script>
<?php endforeach ?>
</body>
</html>
<?php
}
