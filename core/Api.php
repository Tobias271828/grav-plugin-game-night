<?php
/*
 * Game Night – Tisch-/Spielstand-API (nur POST mit JSON). Unabhängig von Grav: eingebunden vom
 * Grav-Plugin (game-night.php, für <route>/api) oder von der Standalone-Version (standalone/index.php).
 * Einstieg: run(instance_config(...)).
 *
 * Jede Instanz hat einen eigenen Datenordner. Jeder Tisch ist dort eine Datei room-<tischname>-<id>.php.
 * Sie beginnt mit einer PHP-Zeile, die sofort abbricht, damit der Inhalt auch dann nicht abrufbar ist,
 * wenn der Server .htaccess ignoriert. Danach folgt der JSON-Inhalt. Schreiben unter exklusiver, Lesen
 * unter geteilter Dateisperre.
 *
 * Vier in einer Reihe, Schach zu viert und Zahlenkampf: Die Spiellogik läuft im Browser. Der Server prüft,
 *  - ob der Stand noch aktuell ist (version),
 *  - ob der Absender auf einem Platz sitzt, der laut letztem Stand am Zug ist, und
 *  - ob der neue Stand die erwartete Form hat (Größe und Struktur, siehe Validate.php).
 * Trombone (verdeckte Karten) und Wer bin ich? (geheime Begriffe): Die Logik läuft komplett auf dem
 * Server, siehe Trombone.php und WerBinIch.php.
 *
 * Missbrauchsschutz: Anfragelimits pro IP (RateLimit.php), Größenlimits, automatisches Aufräumen.
 */

namespace GameNight;

\defined('GAME_NIGHT') or die();

// In den Dateien < > & ' maskieren: so kann dort nie „<?php“ o. Ä. aus Benutzereingaben stehen
const FILE_JSON     = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
const GUARD         = "<?php http_response_code(404); exit; ?>\n";
const MAX_STATE     = 200000;           // Bytes
const MAX_BODY      = MAX_STATE + 20000;
const GAMES         = ['connect4' => 2, 'handbrain' => 4, 'zahlenkampf' => 2, 'trombone' => 8, 'werbinich' => 12];
const SERVER_GAMES  = ['trombone', 'werbinich'];   // Spiele mit Geheimnissen: Logik auf dem Server
const WIP_GAMES     = ['zahlenkampf'];             // noch in Arbeit: nur, wenn ausdrücklich in 'games' eingetragen

/** Voreinstellungen einer Instanz (Schlüssel wie in der Konfiguration). */
const DEFAULTS = [
    'lobby'                => true,    // Tischliste anzeigen; false: Tische nur per Link erreichbar
    'max_rooms'            => 200,     // jeder Tisch hat nur wenige KB
    'room_ttl_days'        => 7,       // Tische ohne Aktivität nach so vielen Tagen löschen
    'empty_room_ttl_hours' => 0,       // Tische, an denen niemand sitzt, schon früher löschen (0 = aus)
    'requests_per_minute'  => 1200,    // pro IP (bzw. IPv6-/64-Netz) und Instanz; 0 = unbegrenzt
    'creates_per_hour'     => 30,      // neue Tische pro IP und Stunde; 0 = unbegrenzt
    'rooms_per_client'     => 20,      // gleichzeitig bestehende, heute angelegte Tische pro IP; 0 = unbegrenzt
    'contact_url'          => '',      // Link für Hinweise/Fehlermeldungen in der Übersicht
    'games'                => [],      // angebotene Spiele (IDs aus GAMES); leer = alle außer WIP_GAMES
    'proxy_ip_header'      => '',      // hinter einem Proxy: Kopfzeile mit der Besucheradresse, z. B. 'CF-Connecting-IP'
];

require_once __DIR__ . '/Trombone.php';
require_once __DIR__ . '/WerBinIch.php';
require_once __DIR__ . '/RateLimit.php';
require_once __DIR__ . '/Validate.php';

/**
 * Konfiguration einer Instanz zusammenstellen.
 * $raw: Einstellungen (siehe DEFAULTS), $dataDir: Ordner mit den Tischen dieser Instanz,
 * $stateDir: Ordner für instanzübergreifende Dateien (Schlüssel, Zähler fürs Anfragelimit).
 */
function instance_config(array $raw, string $dataDir, string $stateDir): array
{
    $c = array_replace(DEFAULTS, array_intersect_key($raw, DEFAULTS));
    return [
        'data_dir'            => rtrim($dataDir, '/'),
        'state_dir'           => rtrim($stateDir, '/'),
        'lobby'               => (bool)$c['lobby'],
        'max_rooms'           => max(1, (int)$c['max_rooms']),
        'room_ttl'            => (int)round(max(1 / 24, (float)$c['room_ttl_days']) * 86400),   // mindestens 1 h
        'empty_room_ttl'      => (int)round(max(0, (float)$c['empty_room_ttl_hours']) * 3600),
        'requests_per_minute' => max(0, (int)$c['requests_per_minute']),
        'creates_per_hour'    => max(0, (int)$c['creates_per_hour']),
        'rooms_per_client'    => max(0, (int)$c['rooms_per_client']),
        'contact_url'         => is_string($c['contact_url']) ? $c['contact_url'] : '',
        'games'               => array_values(array_intersect(array_keys(GAMES), array_filter((array)$c['games'], 'is_string')))
                                 ?: array_values(array_diff(array_keys(GAMES), WIP_GAMES)),
        // Adresse des Besuchers für die Anfragelimits: null = REMOTE_ADDR (der Grav-Adapter setzt Grav's Ermittlung ein)
        'client_ip'           => is_string($c['proxy_ip_header']) && $c['proxy_ip_header'] !== '' ? forwarded_ip($c['proxy_ip_header']) : null,
        'id'                  => substr(hash('sha256', rtrim($dataDir, '/')), 0, 12),   // für Zählerdateien
    ];
}

function cfg(?array $set = null): array
{
    static $cfg = [];
    if ($set !== null) {
        $cfg = $set;
    }
    return $cfg;
}

function data_dir(): string
{
    return cfg()['data_dir'];
}

function out($data, int $code = 200): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $code = 500;
        $json = '{"error":"Interner Fehler"}';
    }
    http_response_code($code);
    echo $json;
    exit;
}

const EN = [
    'Ungültige Tisch-ID' => 'Invalid table ID',
    'Diesen Tisch gibt es nicht' => 'This table does not exist',
    'Tisch ist gerade gesperrt – bitte noch einmal versuchen' => 'This table is locked right now – please try again',
    'Tischdatei beschädigt' => 'Table file is damaged',
    'Bitte einen Namen angeben' => 'Please enter a name',
    'Datenordner fehlt oder ist nicht beschreibbar' => 'Data folder is missing or not writable',
    'Die Tischliste ist hier ausgeschaltet' => 'The table list is disabled here',
    'Zu viele Anfragen – bitte kurz warten' => 'Too many requests – please wait a moment',
    'Zu viele neue Tische – bitte später noch einmal versuchen' => 'Too many new tables – please try again later',
    'Anfrage zu groß' => 'Request too large',
    'Speichern fehlgeschlagen' => 'Saving failed',
    'Interner Fehler' => 'Internal error',
    'Unbekanntes Spiel' => 'Unknown game',
    'Ungültiger Startzustand' => 'Invalid initial state',
    'Zu viele Tische – bitte später noch einmal versuchen' => 'Too many tables – please try again later',
    'Ungültiges Token' => 'Invalid token',
    'Ungültiger Platz' => 'Invalid seat',
    'Platz ist schon besetzt' => 'This seat is already taken',
    'Nur Mitspielende können Plätze freigeben' => 'Only players at this table can free seats',
    'Ungültiger Spielzustand' => 'Invalid game state',
    'Der Spielstand hat sich inzwischen geändert – bitte noch einmal.' => 'The game has changed in the meantime – please try again.',
    'Du sitzt nicht an diesem Tisch' => 'You are not seated at this table',
    'Das Spiel ist schon vorbei' => 'The game is already over',
    'Aufgeben geht erst, wenn alle Plätze besetzt sind' => 'You can only resign once all seats are taken',
    'Du bist nicht am Zug' => 'It is not your turn',
    'Unbekannte Aktion' => 'Unknown action',
    'Nur Mitspielende können einen besetzten Tisch löschen' => 'Only players can delete a table with seated players',
    'Das Spiel läuft schon' => 'The game is already running',
    'Mindestens zwei Personen müssen mitspielen' => 'At least two players are needed',
    'Bitte ein Kartenspiel wählen' => 'Please choose a deck',
    'Ungültiges Kartenspiel' => 'Invalid deck',
    'Ungültige Anfrage' => 'Invalid request',
    'Die Mitspielenden haben sich gerade geändert – bitte noch einmal prüfen.' => 'The players have just changed – please check again.',
    'Nur aktive Mitspielende können jemanden entfernen' => 'Only active players can remove someone',
    'Nur aktive Mitspielende können das Spiel abbrechen' => 'Only active players can abort the game',
    'Nur aktive Mitspielende können jemanden aufnehmen' => 'Only active players can add someone',
    'Es läuft schon eine Abstimmung' => 'A vote is already running',
    'Diese Person spielt schon mit' => 'This person is already playing',
    'Ungültige Kartenzahl' => 'Invalid number of cards',
    'Mit 32 Karten können höchstens 5 Personen mitspielen' => 'With 32 cards at most 5 people can play',
    'Es gibt gerade keine Abstimmung' => 'There is no vote right now',
    'Nur aktive Mitspielende können abstimmen' => 'Only active players can vote',
    'Ungültiges Gebot' => 'Invalid bid',
    'Das Gebot muss höher sein als das vorige' => 'Your bid must be higher than the previous one',
    'Es gibt noch keine Ansage zum Anzweifeln' => 'There is no bid to challenge yet',
    'Die Runde hat gerade neu begonnen – bitte noch einmal prüfen.' => 'The round has just restarted – please check again.',
    'Die Runde läuft schon' => 'The round is already running',
    'Du spielst in diesem Spiel nicht mit' => 'You are not playing in this game',
    'Die Begriffe stehen schon fest' => 'The terms have already been chosen',
    'Ungültige Person' => 'Invalid person',
    'Für diese Person sucht schon jemand anderes einen Begriff aus' => 'Someone else is already choosing a term for this person',
    'Dann bliebe für eine Person niemand übrig – bitte eine andere Person wählen' => 'Then one person would be left without anyone – please choose someone else',
    'Wähle zuerst, für wen du einen Begriff aussuchst' => 'First choose who you are picking a term for',
    'Dieser Begriff ist schon erraten' => 'This term has already been guessed',
    'Bitte einen Begriff eingeben' => 'Please enter a term',
    'Gerade wird nicht geraten' => 'Nobody is guessing right now',
    'Für dich gibt es keinen Begriff' => 'There is no term for you',
];

function lang(?string $set = null): string
{
    static $lang = 'en';
    if ($set !== null) {
        $lang = $set === 'de' ? 'de' : 'en';
    }
    return $lang;
}

function fail(string $msg, int $code = 400): void
{
    if (lang() === 'en' && isset(EN[$msg])) {
        $msg = EN[$msg];
    }
    out(['error' => $msg], $code);
}

/**
 * Datei eines Tisches finden. Dateiname: room-<tischname-als-ascii>-<id>.php
 * (der Name hilft nur beim Aufräumen per FTP; gefunden wird der Tisch über die ID).
 * Gibt es keine Datei, kommt ein Pfad zurück, unter dem nichts liegt (→ „gibt es nicht“).
 */
function room_path(string $id): string
{
    if (!preg_match('/^[0-9a-f]{8}([0-9a-f]{8})?$/', $id)) {   // 8 oder 16 Hex-Zeichen, siehe 'create'
        fail('Ungültige Tisch-ID');
    }
    foreach (room_files() as $file) {
        if (str_ends_with(basename($file), "-$id.php") || basename($file) === "room-$id.php") {
            return $file;
        }
    }
    return data_dir() . "/room-$id.php";
}

/** Alle Tischdateien (scandir statt glob, damit Sonderzeichen im Serverpfad nicht stören). */
function room_files(): array
{
    $files = [];
    foreach (@scandir(data_dir()) ?: [] as $name) {
        if (preg_match('/^room-[a-z0-9-]+\.php$/', $name)) {
            $files[] = data_dir() . '/' . $name;
        }
    }
    return $files;
}

/** Dateiname für einen neuen Tisch, z. B. „Trombone mit Jürgen“ → room-trombone-mit-juergen-1a2b3c4d.php */
function new_room_path(string $id, string $title): string
{
    $slug = strtr($title, [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
    ]);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);   // é → e, ł → l …
        if ($ascii !== false) {
            $slug = $ascii;
        }
    }
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $slug), '-'));
    $slug = rtrim(substr($slug, 0, 40), '-');
    return data_dir() . '/room-' . ($slug !== '' ? "$slug-" : '') . "$id.php";
}

/**
 * Inhalt einer Tischdatei. Schlägt die Umwandlung fehl (z. B. INF aus „1e400“), wird nichts geschrieben –
 * sonst bliebe nur die Schutzzeile übrig und der Tisch wäre kaputt.
 */
function room_content(array $room): string
{
    $json = json_encode($room, FILE_JSON);
    if ($json === false) {
        fail('Ungültiger Spielzustand');
    }
    return GUARD . $json;
}

/** Größe eines Spielstands so, wie er in der Datei steht (< > & ' " belegen dort 6 Bytes). */
function state_size($state): int
{
    $json = json_encode($state, FILE_JSON);
    return $json === false ? PHP_INT_MAX : strlen($json);
}

/** Spielstand vom Browser prüfen (Größe und Struktur, siehe Validate.php). */
function check_client_state(string $game, $state, string $msg): array
{
    if (!is_array($state) || state_size($state) > MAX_STATE || !valid_state($game, $state, GAMES[$game])) {
        fail($msg);
    }
    return $state;
}

function read_room_file(string $file): ?array
{
    $fh = @fopen($file, 'r');
    if (!$fh) {
        return null;
    }
    flock($fh, LOCK_SH);   // wartet, bis ein gerade laufender Schreibvorgang fertig ist
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $data = json_decode(substr((string)$raw, strlen(GUARD)), true);
    return is_array($data) ? $data : null;
}

/**
 * Öffnet einen Tisch mit exklusiver Sperre und ruft $fn(&$room) auf. Gibt $fn etwas anderes als null
 * zurück, wird der geänderte Tisch gespeichert (Version + 1). Rückgabe: der Tisch danach.
 */
function with_room(string $id, callable $fn)
{
    $file = room_path($id);
    $fh = @fopen($file, 'r+');   // 'r+' legt keine Datei an, falls sie gerade gelöscht wurde
    if (!$fh) {
        fail('Diesen Tisch gibt es nicht', 404);
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        fail('Tisch ist gerade gesperrt – bitte noch einmal versuchen', 503);
    }
    $raw = stream_get_contents($fh);
    clearstatcache(true, $file);
    if ($raw === '' || !file_exists($file)) {
        // während des Wartens auf die Sperre gelöscht
        flock($fh, LOCK_UN);
        fclose($fh);
        fail('Diesen Tisch gibt es nicht', 404);
    }
    $room = json_decode(substr($raw, strlen(GUARD)), true);
    if (!is_array($room)) {
        flock($fh, LOCK_UN);
        fclose($fh);
        fail('Tischdatei beschädigt', 500);
    }
    $result = $fn($room);
    if ($result !== null) {
        $room['version']++;
        $room['updated'] = time();
        $content = room_content($room);   // vor dem Leeren der Datei: scheitert das, bleibt alles, wie es war
        ftruncate($fh, 0);
        rewind($fh);
        $written = fwrite($fh, $content);
        fflush($fh);
        if ($written !== strlen($content)) {
            // Platte voll o. Ä.: alten Stand wiederherstellen
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $raw);
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
            fail('Speichern fehlgeschlagen', 507);
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $room;
}

/** Tisch ohne geheime Tokens (und ohne fremde Karten/Begriffe) für die Ausgabe an Platz $seat / Token $token. */
function public_room(array $room, ?int $seat, string $token): array
{
    if ($room['game'] === 'trombone') {
        $orphaned = tb_orphaned($room);
        $room['state'] = tb_view($room['state'], $seat, $token);
        $room['state']['orphaned'] = $orphaned;   // dann darf jede Person am Tisch abbrechen
    } elseif ($room['game'] === 'werbinich') {
        $orphaned = wb_orphaned($room);
        $room['state'] = wb_view($room['state'], $seat, $token);
        $room['state']['orphaned'] = $orphaned;
    }
    unset($room['creator']);
    foreach ($room['seats'] as $i => $s) {
        if ($s !== null) {
            $room['seats'][$i] = ['name' => $s['name']];
        }
    }
    return $room;
}

function seat_of(array $room, string $token): ?int
{
    if ($token === '') {
        return null;
    }
    foreach ($room['seats'] as $i => $seat) {
        if ($seat !== null && hash_equals($seat['token'], $token)) {
            return $i;
        }
    }
    return null;
}

/** UTF-8-sicher kürzen (ohne mbstring-Abhängigkeit). */
function cut(string $s, int $len): string
{
    return preg_match('/^.{0,' . $len . '}/su', $s, $m) ? $m[0] : '';
}

/**
 * Freitext (Namen, Tischtitel) säubern: einheitliche Unicode-Form, keine unsichtbaren Steuer-
 * und Formatzeichen (z. B. Nullbreite-Leerzeichen, Richtungswechsel), Leerraum zusammenfassen.
 * Der Nullbreite-Verbinder U+200D bleibt, weil zusammengesetzte Emojis (👨‍👩‍👧) ihn brauchen.
 */
function clean_text($text, int $max): string
{
    $text = (string)$text;
    if (!preg_match('//u', $text)) {
        return '';   // kein gültiges UTF-8
    }
    if (class_exists('Normalizer')) {
        $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
    }
    $text = preg_replace('/(?!\x{200D})[\p{Cc}\p{Cf}\p{Co}]/u', '', $text);
    $text = trim(preg_replace('/[\s\p{Z}]+/u', ' ', $text));
    return cut($text, $max);
}

function clean_name($name): string
{
    $name = clean_text($name, 24);
    if ($name === '') {
        fail('Bitte einen Namen angeben');
    }
    return $name;
}

/**
 * Name am Tisch eindeutig machen: Ist „Anna“ schon vergeben, wird daraus „Anna 2“, „Anna 3“ …
 * (gilt nur für diesen Tisch; Groß-/Kleinschreibung zählt nicht).
 */
/**
 * Neuer Name auch im laufenden Spiel: Trombone und „Wer bin ich?“ speichern die Mitspielenden (mit Token)
 * beim Start im Spielstand; die übrigen Spiele lesen die Namen direkt von den Plätzen.
 */
function rename_in_state(&$state, string $token, string $name): void
{
    if (!is_array($state)) {
        return;
    }
    $mine = fn($x) => is_array($x) && is_string($x['token'] ?? null) && hash_equals($x['token'], $token);
    foreach ((array)($state['players'] ?? []) as $i => $p) {
        if ($mine($p)) {
            $state['players'][$i]['name'] = $name;
        }
    }
    if ($mine($state['proposal'] ?? null)) {   // Trombone: gerade zur Aufnahme vorgeschlagen
        $state['proposal']['name'] = $name;
    }
}

function unique_name(string $name, array $seats): string
{
    $taken = [];
    foreach ($seats as $s) {
        if ($s !== null) {
            $taken[] = name_key($s['name']);
        }
    }
    if (!in_array(name_key($name), $taken, true)) {
        return $name;
    }
    for ($i = 2; ; $i++) {
        $suffix = ' ' . $i;
        $candidate = cut($name, 24 - strlen($suffix)) . $suffix;
        if (!in_array(name_key($candidate), $taken, true)) {
            return $candidate;
        }
    }
}

/** Vergleichsschlüssel: Groß-/Kleinschreibung und unsichtbare Emoji-Varianten (U+FE0E/FE0F, U+200D) zählen nicht. */
function name_key(string $name): string
{
    return mb_strtolower_safe(preg_replace('/[\x{200D}\x{FE0E}\x{FE0F}]/u', '', $name));
}

/** Kleinschreibung ohne mbstring-Abhängigkeit (für den Namensvergleich genügt das). */
function mb_strtolower_safe(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/** Textfeld aus der Anfrage; Arrays/Objekte o. Ä. werden abgelehnt statt eine PHP-Warnung auszulösen. */
function param(array $in, string $key, string $default = ''): string
{
    $v = $in[$key] ?? $default;
    if (!is_scalar($v)) {
        fail('Ungültige Anfrage');
    }
    return (string)$v;
}

/** Ganzzahl aus der Anfrage (oder $default). */
function int_param(array $in, string $key, int $default): int
{
    $v = $in[$key] ?? $default;
    if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d{1,9}$/', $v))) {
        return $default;
    }
    return (int)$v;
}

/**
 * Alte Tische löschen: ohne Aktivität seit room_ttl, oder – falls empty_room_ttl gesetzt ist –
 * Tische, an denen niemand sitzt, schon nach empty_room_ttl. (Jedes Speichern erneuert das
 * Änderungsdatum der Datei; bloßes Ansehen nicht.)
 */
function cleanup(): void
{
    $now = time();
    $ttl = cfg()['room_ttl'];
    $emptyTtl = cfg()['empty_room_ttl'];
    foreach (room_files() as $file) {
        $age = $now - (int)@filemtime($file);
        if ($age > $ttl) {
            delete_if($file, fn($room) => true, $now - $ttl);
        } elseif ($emptyTtl > 0 && $age > $emptyTtl) {
            delete_if($file, fn($room) => !array_filter($room['seats'] ?? []), $now - $emptyTtl);
        }
    }
}

/**
 * Tischdatei unter Sperre löschen, wenn sie seit $before nicht geändert wurde und $test(Inhalt) zutrifft.
 * (Unter Sperre und mit erneuter Prüfung: Ein gleichzeitiger Zug geht so nicht verloren.)
 */
function delete_if(string $file, callable $test, int $before): void
{
    $fh = @fopen($file, 'r+');
    if (!$fh) {
        return;
    }
    if (flock($fh, LOCK_EX | LOCK_NB)) {   // gerade in Benutzung: beim nächsten Mal
        clearstatcache(true, $file);
        $room = json_decode(substr((string)stream_get_contents($fh), strlen(GUARD)), true);
        // Unlesbare Dateien (sollte es nicht geben) werden auch gelöscht, sobald sie alt genug sind
        if ((!is_array($room) || $test($room)) && (int)@filemtime($file) < $before) {
            @unlink($file);
        }
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

/** Anfrage-Body lesen (höchstens MAX_BODY Bytes) und als JSON-Objekt zurückgeben. */
function read_input(): array
{
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > MAX_BODY) {
        fail('Anfrage zu groß', 413);
    }
    $raw = (string)file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
    if (strlen($raw) > MAX_BODY) {
        fail('Anfrage zu groß', 413);
    }
    $in = json_decode($raw, true, 64);
    return is_array($in) ? $in : [];
}

// ---------------------------------------------------------------------------

function run(array $config): void
{
    cfg($config);
    $dir = data_dir();
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        fail('Datenordner fehlt oder ist nicht beschreibbar', 500);
    }

    // Nur POST mit JSON (so landen Tokens nie in URLs/Server-Logs und nichts geschieht per Link-Klick).
    // Content-Type application/json erzwingt bei Anfragen von fremden Seiten eine CORS-Vorabprüfung,
    // die hier nie beantwortet wird – fremde Seiten können die API also nicht im Browser benutzen.
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $in = $isPost ? read_input() : [];
    lang(is_scalar($in['lang'] ?? null) ? (string)$in['lang'] : 'en');
    if (!$isPost) {
        fail('Unbekannte Aktion', 405);
    }
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        fail('Unbekannte Aktion', 415);
    }

    $client = client_key();
    if (!rate_limit('req', $client, cfg()['requests_per_minute'], 60)) {
        header('Retry-After: 10');
        fail('Zu viele Anfragen – bitte kurz warten', 429);
    }

    if (mt_rand(1, 100) === 1) {
        cleanup();
    }

    $action = param($in, 'action');
    $token  = param($in, 'token');
    // Token: 128 Bit als 32 Hex-Zeichen (vom Browser erzeugt). Feste Länge, damit niemand Dateien aufbläht.
    if ($token !== '' && !preg_match('/^[0-9a-f]{32}$/', $token)) {
        fail('Ungültiges Token');
    }

    switch ($action) {

        case 'list':
            if (!cfg()['lobby']) {
                fail('Die Tischliste ist hier ausgeschaltet', 403);
            }
            $rooms = [];
            foreach (room_files() as $file) {
                $room = read_room_file($file);
                if (!$room) {
                    continue;
                }
                $rooms[] = [
                    'id'      => $room['id'],
                    'game'    => $room['game'],
                    'title'   => $room['title'],
                    'updated' => $room['updated'],
                    'players' => array_map(fn($s) => $s ? $s['name'] : null, $room['seats']),
                    'over'    => !empty($room['state']['over']),
                ];
            }
            usort($rooms, fn($a, $b) => $b['updated'] <=> $a['updated']);
            out(['rooms' => $rooms]);

        case 'create':
            $game = param($in, 'game');
            // Spiele, die die Instanz nicht anbietet, lassen sich nicht neu anlegen (bestehende Tische laufen weiter)
            if (!isset(GAMES[$game]) || !in_array($game, cfg()['games'], true)) {
                fail('Unbekanntes Spiel');
            }
            $state = $game === 'trombone' ? tb_init() : ($game === 'werbinich' ? wb_init()
                : check_client_state($game, $in['state'] ?? null, 'Ungültiger Startzustand'));
            if (!rate_limit('create', $client, cfg()['creates_per_hour'], 3600)) {
                header('Retry-After: 600');
                fail('Zu viele neue Tische – bitte später noch einmal versuchen', 429);
            }
            cleanup();
            $files = room_files();
            if (count($files) >= cfg()['max_rooms']) {
                fail('Zu viele Tische – bitte später noch einmal versuchen', 503);
            }
            // Wie viele Tische dieses Absenders gibt es gerade? (Schlüssel wechselt täglich, siehe RateLimit.php)
            if (cfg()['rooms_per_client'] > 0) {
                $mine = 0;
                foreach ($files as $file) {
                    $mine += (read_room_file($file)['creator'] ?? null) === $client ? 1 : 0;
                }
                if ($mine >= cfg()['rooms_per_client']) {
                    fail('Zu viele neue Tische – bitte später noch einmal versuchen', 429);
                }
            }
            $title = clean_text(param($in, 'title'), 40);
            // Ohne Tischliste ist die ID das einzige, was Fremde fernhält: dann 64 statt 32 Bit
            $id = bin2hex(random_bytes(cfg()['lobby'] ? 4 : 8));
            $room = [
                'id'      => $id,
                'game'    => $game,
                'title'   => $title,
                'seats'   => array_fill(0, GAMES[$game], null),
                'state'   => $state,
                'version' => 1,
                'created' => time(),
                'updated' => time(),
                'creator' => $client,   // pseudonym, nur für rooms_per_client; wird nie ausgeliefert
            ];
            $content = room_content($room);
            if (file_put_contents(new_room_path($id, $title), $content, LOCK_EX) !== strlen($content)) {
                @unlink(new_room_path($id, $title));
                fail('Speichern fehlgeschlagen', 507);
            }
            out(['id' => $id]);

        case 'get':
            $file = room_path(param($in, 'room'));
            $room = read_room_file($file);
            if (!$room) {
                fail('Diesen Tisch gibt es nicht', 404);
            }
            $since = int_param($in, 'since', 0);
            if ($since && $room['version'] === $since) {
                out(['unchanged' => true]);
            }
            $seat = seat_of($room, $token);
            out(['room' => public_room($room, $seat, $token), 'mySeat' => $seat]);

        case 'sit':
            if ($token === '') {
                fail('Ungültiges Token');
            }
            $name = clean_name(param($in, 'name'));
            $seat = int_param($in, 'seat', -1);
            $room = with_room(param($in, 'room'), function (&$room) use ($seat, $token, $name) {
                if (!array_key_exists($seat, $room['seats'])) {
                    fail('Ungültiger Platz');
                }
                $current = $room['seats'][$seat];
                if ($current !== null && !hash_equals($current['token'], $token)) {
                    fail('Platz ist schon besetzt', 409);
                }
                // Wer woanders sitzt, steht dort auf
                foreach ($room['seats'] as $i => $s) {
                    if ($s !== null && hash_equals($s['token'], $token)) {
                        $room['seats'][$i] = null;
                    }
                }
                // eindeutig auch gegenüber Mitspielenden im Spielstand, die gerade keinen Platz haben
                $others = array_filter((array)($room['state']['players'] ?? []),
                    fn($p) => is_array($p) && is_string($p['name'] ?? null) && !hash_equals((string)($p['token'] ?? ''), $token));
                $room['seats'][$seat] = ['name' => unique_name($name, array_merge($room['seats'], array_values($others))), 'token' => $token];
                rename_in_state($room['state'], $token, $room['seats'][$seat]['name']);
                return true;
            });
            out(['room' => public_room($room, $seat, $token), 'mySeat' => $seat]);

        case 'stand':
            $room = with_room(param($in, 'room'), function (&$room) use ($token) {
                $seat = seat_of($room, $token);
                if ($seat === null) {
                    return null;
                }
                $room['seats'][$seat] = null;
                return true;
            });
            out(['room' => public_room($room, null, $token), 'mySeat' => null]);

        case 'kick':
            // Einen anderen Platz freiräumen (z. B. wenn jemand den Browser geschlossen hat)
            $target = int_param($in, 'seat', -1);
            $room = with_room(param($in, 'room'), function (&$room) use ($token, $target) {
                if (seat_of($room, $token) === null) {
                    fail('Nur Mitspielende können Plätze freigeben', 403);
                }
                if (!array_key_exists($target, $room['seats'])) {
                    fail('Ungültiger Platz');
                }
                $room['seats'][$target] = null;
                return true;
            });
            $seat = seat_of($room, $token);
            out(['room' => public_room($room, $seat, $token), 'mySeat' => $seat]);

        case 'move':
            // kind: 'move' (nur wer am Zug ist), 'reset' / 'resign' (alle Sitzenden)
            $kind = param($in, 'kind', 'move');
            $state = $in['state'] ?? null;
            $version = int_param($in, 'version', 0);
            if (!is_array($state)) {
                fail('Ungültiger Spielzustand');
            }
            $room = with_room(param($in, 'room'), function (&$room) use ($kind, $state, $version, $token) {
                if (in_array($room['game'], SERVER_GAMES, true)) {
                    fail('Unbekannte Aktion');
                }
                check_client_state($room['game'], $state, 'Ungültiger Spielzustand');
                if ($room['version'] !== $version) {
                    fail('Der Spielstand hat sich inzwischen geändert – bitte noch einmal.', 409);
                }
                $seat = seat_of($room, $token);
                if ($seat === null) {
                    fail('Du sitzt nicht an diesem Tisch', 403);
                }
                if ($kind === 'move') {
                    if (!empty($room['state']['over'])) {
                        fail('Das Spiel ist schon vorbei', 409);
                    }
                    $turn = $room['state']['turn'] ?? [];
                    if (!in_array($seat, (array)$turn, true)) {
                        fail('Du bist nicht am Zug', 409);
                    }
                } elseif ($kind === 'resign') {
                    if (in_array(null, $room['seats'], true)) {
                        fail('Aufgeben geht erst, wenn alle Plätze besetzt sind', 409);
                    }
                } elseif ($kind !== 'reset') {
                    fail('Unbekannte Aktion');
                }
                $room['state'] = $state;
                return true;
            });
            $seat = seat_of($room, $token);
            out(['room' => public_room($room, $seat, $token), 'mySeat' => $seat]);

        case 'game':
            // Aktionen für Spiele mit Server-Logik (Trombone, Wer bin ich?)
            $op = param($in, 'op');
            $room = with_room(param($in, 'room'), function (&$room) use ($op, $in, $token) {
                if (!in_array($room['game'], SERVER_GAMES, true)) {
                    fail('Unbekannte Aktion');
                }
                $seat = seat_of($room, $token);
                if ($seat === null) {
                    fail('Du sitzt nicht an diesem Tisch', 403);
                }
                if ($room['game'] === 'trombone') {
                    tb_act($room, $seat, $token, $op, $in);
                } else {
                    wb_act($room, $seat, $token, $op, $in);
                }
                return true;
            });
            $seat = seat_of($room, $token);
            out(['room' => public_room($room, $seat, $token), 'mySeat' => $seat]);

        case 'delete':
            $file = room_path(param($in, 'room'));
            // unter Sperre löschen, damit kein gleichzeitiger Speichervorgang dazwischenkommt
            with_room(param($in, 'room'), function (&$room) use ($token, $file) {
                $anyone = count(array_filter($room['seats'])) > 0;
                if ($anyone && seat_of($room, $token) === null) {
                    fail('Nur Mitspielende können einen besetzten Tisch löschen', 403);
                }
                @unlink($file);
                return null;   // nichts zurückschreiben
            });
            out(['ok' => true]);

        default:
            fail('Unbekannte Aktion');
    }
}
