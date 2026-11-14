<?php
/*
 * Game Night – Server-Check: prüft einmalig, ob ein Webspace für Game Night geeignet ist.
 *
 *   1. Unten KEY auf einen langen Zufallswert und EXPIRES auf ein Datum in den nächsten Tagen setzen.
 *   2. Datei in den Webroot hochladen (bei Grav: neben index.php), im Browser öffnen:
 *        https://example.org/server-check.php?key=<KEY>
 *   3. Ergebnis ansehen und die Datei danach wieder LÖSCHEN.
 *
 * Geprüft wird: PHP-Version und Erweiterungen, Schreibrechte, ob .htaccess-Sperren wirken, ob die
 * Besucher-IP echt ankommt (wichtig für die Anfragelimits) und ob Dateisperren (flock) zwischen
 * gleichzeitigen Anfragen zuverlässig wirken. Das Skript legt nur Dateien in einem eigenen
 * Testordner an und räumt sie am Ende wieder weg.
 */

const KEY     = '';             // z. B. ein 32-stelliger Zufallswert; leer = Skript ist gesperrt
const EXPIRES = '2000-01-01';   // nach diesem Tag verweigert das Skript den Dienst

// ---------------------------------------------------------------------------

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$key = (string)($_GET['key'] ?? '');
if (strlen(KEY) < 16 || !hash_equals(KEY, $key) || date('Y-m-d') > EXPIRES) {
    http_response_code(404);
    exit;
}

$base = __DIR__ . '/gn-server-check';

// Hilfsanfragen, die das Skript an sich selbst schickt -------------------------------------------
$worker = $_GET['worker'] ?? null;
if ($worker === 'flock') {
    // Zähler n-mal unter Sperre erhöhen; „langsam“, damit sich gleichzeitige Anfragen überschneiden
    $n = min(500, max(1, (int)($_GET['n'] ?? 100)));
    $start = microtime(true);
    for ($i = 0; $i < $n; $i++) {
        $fh = fopen("$base/counter", 'c+');
        flock($fh, LOCK_EX);
        $v = (int)stream_get_contents($fh);
        usleep(300);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string)($v + 1));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    header('Content-Type: application/json');
    echo json_encode(['start' => $start, 'end' => microtime(true), 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    exit;
}
if ($worker === 'ip') {
    header('Content-Type: text/plain');
    echo $_SERVER['REMOTE_ADDR'] ?? '';
    exit;
}

// ---------------------------------------------------------------------------

$results = [];   // [Status ok|warn|fail|info, Prüfung, Ergebnis, Hinweis]
function result(string $status, string $what, string $value, string $hint = ''): void
{
    global $results;
    $results[] = [$status, $what, $value, $hint];
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
$self = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$dirUrl = rtrim(dirname($self), '/');

/** Mehrere URLs gleichzeitig abrufen; gibt [Status, Inhalt] je URL zurück (oder null ohne curl). */
function fetch_all(array $urls): ?array
{
    if (!function_exists('curl_multi_init')) {
        return null;
    }
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $i => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);
    $out = [];
    foreach ($handles as $i => $ch) {
        $out[$i] = [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), (string)curl_multi_getcontent($ch)];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

// 1. PHP ----------------------------------------------------------------------------------------
result(PHP_VERSION_ID >= 80000 ? 'ok' : 'fail', 'PHP-Version', PHP_VERSION, 'Game Night braucht PHP 8.0 oder neuer.');
foreach (['json' => true, 'hash' => true, 'mbstring' => false, 'intl' => false, 'iconv' => false, 'curl' => false, 'zip' => false] as $ext => $required) {
    $loaded = extension_loaded($ext);
    result($loaded ? 'ok' : ($required ? 'fail' : 'warn'), "Erweiterung $ext", $loaded ? 'vorhanden' : 'fehlt',
        $required ? 'notwendig' : ($ext === 'intl' ? 'optional (Unicode-Normalisierung von Namen)' : ($ext === 'curl' ? 'nur für diesen Check' : 'optional')));
}
try {
    random_bytes(16);
    result('ok', 'Zufallszahlen (random_bytes)', 'funktioniert');
} catch (Throwable $e) {
    result('fail', 'Zufallszahlen (random_bytes)', $e->getMessage());
}
$display = ini_get('display_errors');
result(in_array(strtolower((string)$display), ['', '0', 'off', 'stderr'], true) ? 'ok' : 'warn', 'display_errors', (string)$display,
    'Sollte aus sein, sonst können Fehlermeldungen Serverpfade zeigen.');
result('info', 'open_basedir', ini_get('open_basedir') ?: '(keins)');
result('info', 'memory_limit / max_execution_time / post_max_size',
    ini_get('memory_limit') . ' / ' . ini_get('max_execution_time') . ' s / ' . ini_get('post_max_size'));
result('info', 'Server-Software / PHP-SAPI', ($_SERVER['SERVER_SOFTWARE'] ?? '?') . ' / ' . PHP_SAPI);

// 2. Dateisystem --------------------------------------------------------------------------------
@mkdir($base, 0775, true);
$writable = is_dir($base) && is_writable($base);
result($writable ? 'ok' : 'fail', 'Schreibrechte im Webroot', $writable ? 'ja' : 'nein', 'Game Night braucht einen beschreibbaren Datenordner.');
if ($writable) {
    file_put_contents("$base/a", 'x');
    $renamed = @rename("$base/a", "$base/b") && file_exists("$base/b");
    result($renamed ? 'ok' : 'fail', 'Dateien umbenennen (rename)', $renamed ? 'funktioniert' : 'fehlgeschlagen');
    $free = @disk_free_space($base);
    result('info', 'Freier Speicher', $free ? round($free / 1e9, 1) . ' GB' : 'unbekannt');
    $fs = 'unbekannt';
    $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES);
    if ($mounts) {
        $best = '';
        foreach ($mounts as $line) {
            [, $mp, $type] = explode(' ', $line) + [null, '', ''];
            if (str_starts_with(realpath($base) . '/', rtrim($mp, '/') . '/') && strlen($mp) >= strlen($best)) {
                $best = $mp;
                $fs = "$type (eingehängt unter $mp)";
            }
        }
    }
    result(preg_match('/nfs|cifs|smb|fuse|gluster|ceph/i', $fs) ? 'warn' : 'info', 'Dateisystem', $fs,
        'Bei Netzwerk-Dateisystemen ist der flock-Test unten besonders wichtig.');
}

// 3. .htaccess-Sperren --------------------------------------------------------------------------
if ($writable) {
    @mkdir("$base/locked", 0775);
    file_put_contents("$base/locked/.htaccess", "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    file_put_contents("$base/locked/secret.json", '{"secret":true}');
    file_put_contents("$base/guard.php", "<?php http_response_code(404); exit; ?>\n{\"secret\":true}");
    $r = fetch_all([
        'locked' => "$dirUrl/gn-server-check/locked/secret.json",
        'guard'  => "$dirUrl/gn-server-check/guard.php",
    ]);
    if ($r === null) {
        result('warn', '.htaccess-Sperre', 'nicht automatisch prüfbar (curl fehlt)',
            "Bitte selbst öffnen, es muss „403“ oder „404“ kommen: $dirUrl/gn-server-check/locked/secret.json");
    } else {
        [$code, $body] = $r['locked'];
        $blocked = !str_contains($body, 'secret');
        result($blocked ? 'ok' : 'fail', '.htaccess-Sperre (Ordner mit „Require all denied“)', "HTTP $code",
            $blocked ? '' : 'Der Server beachtet .htaccess nicht: Datenordner unbedingt außerhalb des Webroots anlegen!');
        [$code, $body] = $r['guard'];
        $blocked = !str_contains($body, 'secret');
        result($blocked ? 'ok' : 'fail', 'Schutzzeile in .php-Datendateien', "HTTP $code",
            $blocked ? '' : 'PHP-Dateien werden nicht ausgeführt – Tischdateien wären lesbar!');
    }
}

// 4. Besucher-IP --------------------------------------------------------------------------------
$visitor = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$public = filter_var($visitor, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
result($public ? 'ok' : 'warn', 'Deine IP laut Server (REMOTE_ADDR)', $visitor,
    $public ? 'Bitte vergleichen: Ist das deine echte öffentliche IP-Adresse?'
            : 'Private/interne Adresse: Vermutlich steht ein Proxy davor – dann würden alle Besucher als eine IP gezählt.');
foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED'] as $h) {
    if (!empty($_SERVER[$h])) {
        result('info', "Header $h", (string)$_SERVER[$h], 'Ein Proxy-Header ist gesetzt; REMOTE_ADDR sollte trotzdem deine echte IP sein.');
    }
}
$r = fetch_all(['ip' => "$self?key=" . rawurlencode(KEY) . '&worker=ip']);
if ($r !== null && $r['ip'][0] === 200) {
    $serverIp = trim($r['ip'][1]);
    result($serverIp !== $visitor ? 'ok' : 'fail', 'Anfrage vom Server selbst kommt mit anderer IP an', $serverIp,
        $serverIp !== $visitor ? '' : 'Server und Besucher haben dieselbe REMOTE_ADDR – alle Besucher würden als eine IP gezählt!');
}

// 5. flock zwischen gleichzeitigen Anfragen -----------------------------------------------------
if ($writable) {
    file_put_contents("$base/counter", '0');
    $workers = 4;
    $n = 100;
    $urls = [];
    for ($i = 0; $i < $workers; $i++) {
        $urls[$i] = "$self?key=" . rawurlencode(KEY) . "&worker=flock&n=$n&i=$i";
    }
    $r = fetch_all($urls);
    if ($r === null) {
        result('warn', 'Dateisperren (flock)', 'nicht prüfbar (curl fehlt)');
    } else {
        $runs = array_filter(array_map(fn($x) => $x[0] === 200 ? json_decode($x[1], true) : null, $r));
        $count = (int)file_get_contents("$base/counter");
        $overlap = false;
        foreach ($runs as $a) {
            foreach ($runs as $b) {
                if ($a !== $b && $a['start'] < $b['end'] && $b['start'] < $a['end']) {
                    $overlap = true;
                }
            }
        }
        $expected = $workers * $n;
        if (count($runs) < $workers) {
            result('warn', 'Dateisperren (flock)', count($runs) . " von $workers Testanfragen erfolgreich", 'Test unvollständig.');
        } elseif ($count !== $expected) {
            result('fail', 'Dateisperren (flock)', "Zähler $count statt $expected",
                'Sperren wirken nicht zuverlässig – gleichzeitige Züge könnten sich überschreiben!');
        } else {
            result($overlap ? 'ok' : 'warn', 'Dateisperren (flock)', "Zähler $count von $expected" . ($overlap ? ', Anfragen liefen gleichzeitig' : ''),
                $overlap ? '' : 'Die Testanfragen liefen nacheinander, nicht gleichzeitig – Sperren dadurch nicht wirklich geprüft (aber auch unkritisch).');
        }
        $dur = $runs ? max(array_column($runs, 'end')) - min(array_column($runs, 'start')) : 0;
        result('info', 'Dauer des Sperrtests', round($dur, 2) . " s für $expected gesperrte Schreibvorgänge");
    }
}

// Aufräumen -------------------------------------------------------------------------------------
foreach (['locked/.htaccess', 'locked/secret.json', 'guard.php', 'counter', 'a', 'b'] as $f) {
    @unlink("$base/$f");
}
@rmdir("$base/locked");
@rmdir($base);

// Ausgabe ---------------------------------------------------------------------------------------
$icons = ['ok' => '✅', 'warn' => '⚠️', 'fail' => '❌', 'info' => 'ℹ️'];
$summary = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
foreach ($results as $r) {
    $summary[$r[0]]++;
}
$text = "Game Night Server-Check " . date('Y-m-d H:i') . "\n";
foreach ($results as [$s, $what, $value, $hint]) {
    $text .= strtoupper($s) . " | $what | $value" . ($hint !== '' && $s !== 'ok' ? " | $hint" : '') . "\n";
}
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="de">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Game Night Server-Check</title>
<style>
body { font: 15px/1.5 system-ui, sans-serif; max-width: 960px; margin: 2rem auto; padding: 0 16px; color: #222; background: #fff; }
table { border-collapse: collapse; width: 100%; } td { border-top: 1px solid #ddd; padding: .4rem .5rem; vertical-align: top; }
td.hint { color: #666; font-size: .9em; } textarea { width: 100%; height: 14rem; font: 12px monospace; }
@media (prefers-color-scheme: dark) { body { background: #1b1b1b; color: #ddd; } td { border-color: #444; } td.hint { color: #aaa; } }
</style></head>
<body>
<h1>Game Night Server-Check</h1>
<p><?= $summary['fail'] ? "❌ {$summary['fail']} Problem(e)" : '✅ Keine Probleme' ?>, <?= $summary['warn'] ?> Hinweis(e).
<b>Diese Datei bitte gleich wieder löschen.</b></p>
<table>
<?php foreach ($results as [$s, $what, $value, $hint]): ?>
<tr><td><?= $icons[$s] ?></td><td><?= $e($what) ?></td><td><?= $e($value) ?></td><td class="hint"><?= $s === 'ok' ? '' : $e($hint) ?></td></tr>
<?php endforeach ?>
</table>
<h2>Zum Kopieren</h2>
<textarea readonly><?= $e($text) ?></textarea>
</body>
</html>
