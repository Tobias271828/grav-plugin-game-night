<?php
/*
 * Game Night – automatische Tests für die API (Protokoll, Eingabeprüfung, Spielablauf, Geheimnisse,
 * Limits, gleichzeitige Anfragen, Aufräumen). Startet dafür einen PHP-Entwicklungsserver.
 *
 * Aufruf: php tests/api-test.php      (Exit-Code 0 = alles in Ordnung)
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

const GUARD_LINE = "<?php http_response_code(404); exit; ?>\n";

// ---------------------------------------------------------------------------
// Mini-Testrahmen

$failures = 0;
$checks = 0;

function check(bool $ok, string $what, $detail = null): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
        echo "  ✗ $what" . ($detail !== null ? ' — ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) : '') . "\n";
    }
}

function section(string $name): void
{
    echo "• $name\n";
}

function rrmdir(string $dir): void
{
    foreach (glob("$dir/{,.}[!.,!..]*", GLOB_BRACE) ?: [] as $f) {
        is_dir($f) ? rrmdir($f) : unlink($f);
    }
    @rmdir($dir);
}

// ---------------------------------------------------------------------------
// Testserver mit mehreren Instanzen

$tmp = sys_get_temp_dir() . '/gn-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
$instances = [
    'open'     => ['lobby' => true],
    'unlisted' => ['lobby' => false, 'games' => ['trombone', 'werbinich', 'handbrain', 'connect4'], 'max_rooms' => 3, 'creates_per_hour' => 0],
    'limited'  => ['requests_per_minute' => 5],
    'creates'  => ['creates_per_hour' => 2],
    'percl'    => ['rooms_per_client' => 2, 'creates_per_hour' => 0],
];
foreach ($instances as $name => &$settings) {
    $settings['data_dir'] = "$tmp/data/$name";
}
unset($settings);
file_put_contents("$tmp/config.json", json_encode(['state_dir' => "$tmp/data/.state", 'instances' => $instances]));
mkdir("$tmp/www");

$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/router.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$tmp/server.log", 'a'], 2 => ['file', "$tmp/server.log", 'a']],
    $pipes,
    "$tmp/www",
    getenv() + ['GN_TEST_CONFIG' => "$tmp/config.json", 'PHP_CLI_SERVER_WORKERS' => '4']
);
register_shutdown_function(function () use ($server, $tmp) {
    proc_terminate($server);
    proc_close($server);
    rrmdir($tmp);
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

/** Anfrage an die API; gibt [Status, JSON, Rohtext, Header] zurück. */
function req(string $inst, $body, string $method = 'POST', string $type = 'application/json'): array
{
    global $port;
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Content-Type: $type\r\n",
        'content' => is_string($body) ? $body : json_encode($body),
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $raw = (string)@file_get_contents("http://127.0.0.1:$port/$inst/api", false, $ctx);
    $headers = $http_response_header ?? [];
    preg_match('#^HTTP/\S+ (\d+)#', $headers[0] ?? '', $m);
    return [(int)($m[1] ?? 0), json_decode($raw, true), $raw, $headers];
}

function api(string $inst, array $data): array
{
    return req($inst, $data + ['lang' => 'de']);   // Meldungen in den Prüfungen sind deutsch
}

/** Gültiger Spielstand für Vier in einer Reihe (wer am Zug ist, plus beliebige Zusatzfelder). */
function c4(array $turn, array $extra = []): array
{
    return $extra + ['board' => array_fill(0, 42, -1), 'turn' => $turn, 'starter' => 0, 'over' => false, 'last' => null, 'line' => null, 'end' => null];
}

function token(): string
{
    return bin2hex(random_bytes(16));
}

function header_value(array $headers, string $name): ?string
{
    foreach ($headers as $h) {
        if (stripos($h, "$name:") === 0) {
            return trim(substr($h, strlen($name) + 1));
        }
    }
    return null;
}

/** Mehrere Anfragen wirklich gleichzeitig abschicken (rohe HTTP-Verbindungen); gibt die Statuscodes zurück. */
function parallel(string $inst, array $bodies): array
{
    global $port;
    $socks = [];
    foreach ($bodies as $i => $body) {
        $json = json_encode($body);
        $s = stream_socket_client("tcp://127.0.0.1:$port", $errno, $err, 5);
        fwrite($s, "POST /$inst/api HTTP/1.0\r\nHost: x\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n\r\n$json");
        $socks[$i] = $s;
    }
    $codes = [];
    foreach ($socks as $i => $s) {
        $resp = stream_get_contents($s);
        fclose($s);
        preg_match('#^HTTP/\S+ (\d+)#', $resp, $m);
        $codes[$i] = (int)($m[1] ?? 0);
    }
    return $codes;
}

function room_file(string $inst, string $id): ?string
{
    global $tmp;
    return glob("$tmp/data/$inst/room-*$id.php")[0] ?? null;
}

// ---------------------------------------------------------------------------
section('Protokoll');

[$code] = req('open', '', 'GET');
check($code === 405, 'GET wird abgelehnt', $code);
[$code] = req('open', '{"action":"list"}', 'POST', 'text/plain');
check($code === 415, 'Content-Type text/plain wird abgelehnt', $code);
[$code, $json, , $headers] = api('open', ['action' => 'list']);
check($code === 200 && isset($json['rooms']), 'list funktioniert', $json);
check(header_value($headers, 'X-Content-Type-Options') === 'nosniff', 'Header nosniff');
check(str_contains((string)header_value($headers, 'Content-Security-Policy'), "frame-ancestors 'none'"), 'Header CSP');
check(header_value($headers, 'Cache-Control') === 'no-store', 'Header no-store');
[$code] = req('open', str_repeat('a', 230000));
check($code === 413, 'zu großer Body → 413', $code);
[$code] = req('open', '{"action":');
check($code === 400, 'kaputtes JSON → 400', $code);
[$code] = req('open', str_repeat('[', 200) . str_repeat(']', 200));
check($code === 400, 'tief verschachteltes JSON → 400', $code);
[$code] = api('open', ['action' => ['list']]);
check($code === 400, 'action als Array → 400', $code);
[$code, $json] = api('open', ['action' => 'quatsch', 'lang' => 'en']);
check($code === 400 && $json['error'] === 'Unknown action', 'unbekannte Aktion, englische Meldung', $json);
[$code, $json] = req('open', ['action' => 'quatsch']);
check($code === 400 && $json['error'] === 'Unknown action', 'ohne Sprachangabe englische Meldungen', $json);
[$code, $json] = req('open', ['action' => 'quatsch', 'lang' => 'fr']);
check($code === 400 && $json['error'] === 'Unknown action', 'unbekannte Sprache → Englisch', $json);

// ---------------------------------------------------------------------------
section('Raum-IDs');

foreach (['../../etc/passwd', 'ABCDEF12', 'abc', str_repeat('a', 17), 'abc12345.php', "abc12345\0", ''] as $bad) {
    [$code, $json] = api('open', ['action' => 'get', 'room' => $bad]);
    check($code === 400, 'ungültige Raum-ID abgelehnt: ' . json_encode($bad), [$code, $json]);
}
[$code] = api('open', ['action' => 'get', 'room' => ['x']]);
check($code === 400, 'Raum-ID als Array abgelehnt', $code);
[$code] = api('open', ['action' => 'get', 'room' => 'abcdef12']);
check($code === 404, 'unbekannter Raum → 404', $code);

// ---------------------------------------------------------------------------
section('Tisch anlegen und Dateiinhalt');

[$code] = api('open', ['action' => 'create', 'game' => 'poker']);
check($code === 400, 'unbekanntes Spiel abgelehnt', $code);
[$code] = api('open', ['action' => 'create', 'game' => 'connect4', 'state' => 'x']);
check($code === 400, 'Startzustand muss ein Objekt sein', $code);
[$code] = api('open', ['action' => 'create', 'game' => 'connect4', 'state' => ['x' => str_repeat('a', 200001)]]);
check($code === 413 || $code === 400, 'zu großer Startzustand abgelehnt', $code);

// Nicht darstellbare Zahlen (1e400 → INF) dürfen keinen Tisch zerstören (früher: Datei nur noch Schutzzeile)
$inf = fn(string $num) => substr(json_encode(c4([0])), 0, -1) . ',"x":' . $num . '}';   // sonst gültiger Stand
[$code] = req('open', '{"action":"create","game":"connect4","state":' . $inf('1e400') . '}');
check($code === 400, 'Startzustand mit 1e400 abgelehnt', $code);
$c4 = c4([0]);
foreach ([
    'riesige Zahl'         => ['zahlenkampf', ['stones' => [['id' => 0, 'side' => 0, 'v' => 9007199254740881, 'kind' => 'b', 'r' => 0, 'c' => 0, 'conv' => false]], 'reserve' => [[], []], 'turn' => [0]]],
    '__proto__ als Zeile'  => ['zahlenkampf', ['stones' => [['id' => 0, 'side' => 0, 'v' => 4, 'kind' => 'b', 'r' => '__proto__', 'c' => 'map', 'conv' => false]], 'reserve' => [[], []], 'turn' => [0]]],
    '401 Schachzüge'       => ['handbrain', ['moves' => array_fill(0, 401, 'e4'), 'phase' => 'choose', 'piece' => null, 'turn' => [0]]],
    'Schlüssel __proto__'  => ['connect4', ['__proto__' => ['x' => 1]] + $c4],
    'Platz 7 am Zug'       => ['connect4', ['turn' => [7]] + $c4],
] as $what => [$game, $state]) {
    [$code] = api('open', ['action' => 'create', 'game' => $game, 'state' => $state]);
    check($code === 400, "manipulierter Startzustand abgelehnt: $what", $code);
}
[$code] = api('open', ['action' => 'create', 'game' => 'connect4', 'state' => $c4]);
check($code === 200, 'gültiger Startzustand angenommen', $code);

$evil = "<?php system('id'); ?>\u{202E}Tisch\x07 <script>";
[$code, $json] = api('open', ['action' => 'create', 'game' => 'connect4', 'title' => $evil,
    'state' => c4([0], ['note' => '<?php echo 1; ?>'])]);
check($code === 200 && preg_match('/^[0-9a-f]{8}$/', $json['id'] ?? ''), 'Tisch angelegt, 8-stellige ID (mit Tischliste)', $json);
$room = $json['id'];
$file = room_file('open', $room);
$content = (string)file_get_contents((string)$file);
check($file !== null && preg_match('#/room-[a-z0-9-]+\.php$#', $file), 'Dateiname nur aus [a-z0-9-]', $file);
check(str_starts_with($content, GUARD_LINE), 'Datei beginnt mit Schutzzeile');
check(!str_contains(substr($content, strlen(GUARD_LINE)), '<'), 'kein „<“ im JSON-Teil (kein PHP einschleusbar)');
[, $json] = api('open', ['action' => 'get', 'room' => $room]);
$title = $json['room']['title'] ?? '';
check(!str_contains($title, "\u{202E}") && !str_contains($title, "\x07"), 'Steuer-/Richtungszeichen aus dem Titel entfernt', $title);
check(mb_strlen($title) <= 40, 'Titel höchstens 40 Zeichen', $title);

// ---------------------------------------------------------------------------
section('Plätze, Namen, Tokens');

$ta = token();
$tb = token();
foreach (['kurz', str_repeat('<', 40000), strtoupper(token()), token() . 'a', [token()]] as $bad) {
    [$code] = api('open', ['action' => 'sit', 'room' => $room, 'seat' => 0, 'name' => 'Anna', 'token' => $bad]);
    check($code === 400, 'Token in falschem Format abgelehnt: ' . substr(json_encode($bad), 0, 20), $code);
}
[$code] = api('open', ['action' => 'sit', 'room' => $room, 'seat' => 0, 'name' => "\u{200B} \x01 ", 'token' => $ta]);
check($code === 400, 'Name nur aus unsichtbaren Zeichen abgelehnt', $code);
[$code] = api('open', ['action' => 'sit', 'room' => $room, 'seat' => 5, 'name' => 'Anna', 'token' => $ta]);
check($code === 400, 'ungültiger Platz abgelehnt', $code);
[$code] = api('open', ['action' => 'sit', 'room' => $room, 'seat' => '0 OR 1', 'name' => 'Anna', 'token' => $ta]);
check($code === 400, 'Platz als Text abgelehnt', $code);
[$code, $json] = api('open', ['action' => 'sit', 'room' => $room, 'seat' => 0, 'name' => str_repeat('Ä', 40), 'token' => $ta]);
check($code === 200 && mb_strlen($json['room']['seats'][0]['name']) === 24, 'Name auf 24 Zeichen gekürzt', $json['room']['seats'][0] ?? null);
api('open', ['action' => 'sit', 'room' => $room, 'seat' => 0, 'name' => 'Anna', 'token' => $ta]);
[$code] = api('open', ['action' => 'sit', 'room' => $room, 'seat' => 0, 'name' => 'Bert', 'token' => $tb]);
check($code === 409, 'besetzter Platz → 409', $code);
[$code, $json, $raw] = api('open', ['action' => 'sit', 'room' => $room, 'seat' => 1, 'name' => 'ANNA', 'token' => $tb]);
check(($json['room']['seats'][1]['name'] ?? '') === 'ANNA 2', 'doppelter Name wird eindeutig gemacht', $json['room']['seats'] ?? null);
check(!str_contains($raw, $ta) && !str_contains($raw, $tb), 'Antwort enthält keine Tokens');
check(!str_contains($raw, 'creator'), 'Antwort enthält keinen Absender-Schlüssel');
check(($json['mySeat'] ?? null) === 1, 'mySeat stimmt');
[, $json, $raw] = api('open', ['action' => 'list']);
check(!str_contains($raw, $ta) && !str_contains($raw, $tb), 'Tischliste enthält keine Tokens');

// ---------------------------------------------------------------------------
section('Züge (Spiellogik im Browser)');

[, $json] = api('open', ['action' => 'get', 'room' => $room]);
$v = $json['room']['version'];
$tc = token();
[$code] = api('open', ['action' => 'move', 'room' => $room, 'version' => $v, 'state' => c4([1]), 'token' => $tc]);
check($code === 403, 'Zuschauer darf nicht ziehen', $code);
[$code] = api('open', ['action' => 'move', 'room' => $room, 'version' => $v, 'state' => c4([1]), 'token' => $tb]);
check($code === 409, 'wer nicht am Zug ist, darf nicht ziehen', $code);
[$code] = api('open', ['action' => 'move', 'room' => $room, 'version' => $v - 1, 'state' => c4([1]), 'token' => $ta]);
check($code === 409, 'veralteter Stand → 409', $code);
[$code] = api('open', ['action' => 'move', 'room' => $room, 'version' => $v, 'state' => 'x', 'token' => $ta]);
check($code === 400, 'Zustand muss ein Objekt sein', $code);
[$code] = req('open', '{"action":"move","room":"' . $room . '","version":' . $v . ',"kind":"reset","token":"' . $tb . '","state":' . $inf('-1e999') . '}');
check($code === 400, 'Zug mit -1e999 abgelehnt', $code);
[$code] = api('open', ['action' => 'get', 'room' => $room]);
check($code === 200, 'Tisch danach weiter lesbar', $code);
[$code, $json] = api('open', ['action' => 'move', 'room' => $room, 'version' => $v, 'state' => c4([1]), 'token' => $ta]);
check($code === 200 && $json['room']['version'] === $v + 1, 'gültiger Zug erhöht die Version', $json);
// Aufgeben erst, wenn alle Plätze besetzt sind
[, $jx] = api('open', ['action' => 'create', 'game' => 'connect4', 'state' => c4([0])]);
$tz = token();
api('open', ['action' => 'sit', 'room' => $jx['id'], 'seat' => 0, 'name' => 'Allein', 'token' => $tz]);
[, $jg] = api('open', ['action' => 'get', 'room' => $jx['id']]);
[$code] = api('open', ['action' => 'move', 'room' => $jx['id'], 'version' => $jg['room']['version'], 'kind' => 'resign', 'state' => c4([0], ['over' => true]), 'token' => $tz]);
check($code === 409, 'Aufgeben vor vollem Tisch abgelehnt', $code);
[$code] = api('open', ['action' => 'move', 'room' => $room, 'version' => $v + 1, 'kind' => 'hack', 'state' => [], 'token' => $tb]);
check($code === 400, 'unbekannte Zugart abgelehnt', $code);
[, $json] = api('open', ['action' => 'get', 'room' => $room, 'since' => $v + 1]);
check(($json['unchanged'] ?? false) === true, 'get mit aktueller Version → unchanged', $json);
[$code] = api('open', ['action' => 'game', 'room' => $room, 'op' => 'start', 'token' => $ta]);
check($code === 400, 'Server-Aktion bei Browser-Spiel abgelehnt', $code);
[$code] = api('open', ['action' => 'kick', 'room' => $room, 'seat' => 0, 'token' => $tc]);
check($code === 403, 'Zuschauer darf niemanden entfernen', $code);
[$code] = api('open', ['action' => 'delete', 'room' => $room, 'token' => $tc]);
check($code === 403, 'Zuschauer darf besetzten Tisch nicht löschen', $code);
[$code] = api('open', ['action' => 'delete', 'room' => $room, 'token' => $ta]);
check($code === 200 && room_file('open', $room) === null, 'Mitspielende dürfen löschen', $code);
[$code] = api('open', ['action' => 'get', 'room' => $room]);
check($code === 404, 'gelöschter Tisch → 404', $code);

// ---------------------------------------------------------------------------
section('Gleichzeitige Anfragen');

[, $json] = api('open', ['action' => 'create', 'game' => 'connect4', 'state' => c4([0])]);
$room = $json['id'];
$codes = parallel('open', array_map(fn() => ['action' => 'sit', 'room' => $room, 'seat' => 0, 'name' => 'X', 'token' => token()], range(1, 12)));
check(count(array_keys($codes, 200)) === 1 && count(array_keys($codes, 409)) === 11, 'nur eine von 12 gleichzeitigen Personen bekommt den Platz', $codes);
$owner = token();
[, $json] = api('open', ['action' => 'create', 'game' => 'connect4', 'state' => c4([0])]);
$room2 = $json['id'];
api('open', ['action' => 'sit', 'room' => $room2, 'seat' => 0, 'name' => 'A', 'token' => $owner]);
[, $json] = api('open', ['action' => 'get', 'room' => $room2]);
$v = $json['room']['version'];
$codes = parallel('open', array_map(fn($i) => ['action' => 'move', 'room' => $room2, 'version' => $v, 'state' => c4([0], ['n' => $i]), 'token' => $owner], range(1, 10)));
check(count(array_keys($codes, 200)) === 1, 'von 10 gleichzeitigen Zügen auf denselben Stand gilt genau einer', $codes);
[, $json] = api('open', ['action' => 'get', 'room' => $room2]);
check($json['room']['version'] === $v + 1, 'Version danach genau +1', $json['room']['version']);

// ---------------------------------------------------------------------------
section('Trombone: verdeckte Karten');

[, $json] = api('open', ['action' => 'create', 'game' => 'trombone']);
$tr = $json['id'];
$p = [token(), token(), token()];
foreach ([0, 1] as $i) {
    api('open', ['action' => 'sit', 'room' => $tr, 'seat' => $i, 'name' => "P$i", 'token' => $p[$i]]);
}
[$code] = api('open', ['action' => 'game', 'room' => $tr, 'op' => 'start', 'players' => 2, 'token' => $p[0]]);
check($code === 200, 'Trombone startet mit 2 Personen', $code);
[, $j0, $raw0] = api('open', ['action' => 'get', 'room' => $tr, 'token' => $p[0]]);
[, $jx, $rawx] = api('open', ['action' => 'get', 'room' => $tr, 'token' => $p[2]]);
$state = $j0['room']['state'] ?? [];
check(!empty($state['myHand']), 'Mitspielende sehen ihre Karten');
check(!array_key_exists('hands', $state), 'keine fremden Hände in der Antwort');
check(($jx['room']['state']['myHand'] ?? null) === null && !array_key_exists('hands', $jx['room']['state'] ?? []), 'Zuschauende sehen keine Karten');
check(!str_contains($raw0 . $rawx, $p[0]) && !str_contains($raw0 . $rawx, $p[1]), 'keine Tokens in Trombone-Antworten');
foreach ([['op' => 'bid', 'bid' => 'x'], ['op' => 'bid', 'bid' => ['t' => 99, 'a' => -1]], ['op' => 'bid', 'bid' => ['t' => [], 'a' => []]],
          ['op' => 'remove', 'seat' => ['x']], ['op' => 'vote', 'yes' => 'x'], ['op' => ['x']]] as $bad) {
    [$code] = api('open', ['action' => 'game', 'room' => $tr, 'token' => $p[0]] + $bad);
    check($code >= 400 && $code < 500, 'Trombone: unsinnige Eingabe sauber abgelehnt ' . json_encode($bad), $code);
}

// Namensänderung während des Spiels: auch in der Liste der Mitspielenden; Namen bleiben eindeutig
[, $json] = api('open', ['action' => 'create', 'game' => 'trombone']);
$tn = $json['id'];
$pn = [token(), token(), token()];
foreach ([0, 1] as $i) {
    api('open', ['action' => 'sit', 'room' => $tn, 'seat' => $i, 'name' => "N$i", 'token' => $pn[$i]]);
}
api('open', ['action' => 'game', 'room' => $tn, 'op' => 'start', 'players' => 2, 'token' => $pn[0]]);
[, $json] = api('open', ['action' => 'sit', 'room' => $tn, 'seat' => 0, 'name' => 'Paula', 'token' => $pn[0]]);
$names = array_column($json['room']['state']['players'] ?? [], 'name', 'seat');
check(($names[0] ?? null) === 'Paula' && ($names[1] ?? null) === 'N1', 'Trombone: neuer Name im laufenden Spiel', $names);
api('open', ['action' => 'stand', 'room' => $tn, 'token' => $pn[1]]);   // N1 spielt weiter, sitzt aber nicht mehr
[, $json] = api('open', ['action' => 'sit', 'room' => $tn, 'seat' => 1, 'name' => 'N1', 'token' => $pn[2]]);
check(($json['room']['seats'][1]['name'] ?? null) === 'N1 2', 'neuer Name kollidiert nicht mit Mitspielenden ohne Platz', $json['room']['seats'][1] ?? null);
[, , $raw] = api('open', ['action' => 'get', 'room' => $tn, 'token' => $pn[0]]);
check(!str_contains($raw, $pn[0]) && !str_contains($raw, $pn[1]), 'auch danach keine Tokens in Trombone-Antworten');

// Reihenfolge und Startspieler werden bei jedem Spiel neu ausgelost; wer vorne steht, beginnt
[, $json] = api('open', ['action' => 'create', 'game' => 'trombone']);
$to = $json['id'];
$po = [token(), token(), token(), token()];
foreach ([0, 1, 2, 3] as $i) {
    api('open', ['action' => 'sit', 'room' => $to, 'seat' => $i, 'name' => "O$i", 'token' => $po[$i]]);
}
$orders = [];
$startsFirst = true;
for ($k = 0; $k < 12; $k++) {
    [, $json] = api('open', ['action' => 'game', 'room' => $to, 'op' => 'start', 'players' => 4, 'token' => $po[0]]);
    $st = $json['room']['state'] ?? [];
    $order = array_column($st['players'] ?? [], 'seat');
    $orders[implode(',', $order)] = true;
    $startsFirst = $startsFirst && ($st['turn'][0] ?? null) === ($order[0] ?? -1);
    api('open', ['action' => 'game', 'room' => $to, 'op' => 'lobby', 'token' => $po[0]]);
}
check(count($orders) > 1, 'Reihenfolge wird bei jedem Spiel neu gemischt', array_keys($orders));
check($startsFirst, 'wer in der Reihenfolge vorne steht, beginnt');

// Gebote gehören zu einer bestimmten Runde; Entfernen teilt nicht neu aus
[, $json] = api('open', ['action' => 'create', 'game' => 'trombone']);
$t3 = $json['id'];
$pp = [token(), token(), token()];
foreach ([0, 1, 2] as $i) {
    api('open', ['action' => 'sit', 'room' => $t3, 'seat' => $i, 'name' => "T$i", 'token' => $pp[$i]]);
}
api('open', ['action' => 'game', 'room' => $t3, 'op' => 'deck', 'deck' => '52', 'token' => $pp[0]]);
[, $json] = api('open', ['action' => 'game', 'room' => $t3, 'op' => 'start', 'players' => 3, 'token' => $pp[0]]);
$st = $json['room']['state'];
$first = $st['turn'][0];
[$code] = api('open', ['action' => 'game', 'room' => $t3, 'op' => 'bid', 'bid' => ['t' => 0, 'a' => 14], 'token' => $pp[$first]]);
check($code === 409, 'Gebot ohne Rundenkennung abgelehnt', $code);
[$code, $json] = api('open', ['action' => 'game', 'room' => $t3, 'op' => 'bid', 'bid' => ['t' => 0, 'a' => 14], 'deal' => $st['deal'], 'token' => $pp[$first]]);
check($code === 200 && count($json['room']['state']['bids']) === 1, 'Gebot mit Rundenkennung angenommen', $json);
$second = $json['room']['state']['turn'][0];
$target = 3 - $first - $second;   // die dritte Person
$hands = [];
foreach ([$first, $second] as $i) {
    [, $j] = api('open', ['action' => 'get', 'room' => $t3, 'token' => $pp[$i]]);
    $hands[$i] = $j['room']['state']['myHand'];
}
[$code, $json] = api('open', ['action' => 'game', 'room' => $t3, 'op' => 'remove', 'seat' => $target, 'token' => $pp[$first]]);
$after = $json['room']['state'] ?? [];
check($code === 200 && $after['bids'] === [] && $after['deal'] === $st['deal'] + 1 && $after['round'] === $st['round'], 'Entfernen: Gebote neu, gleiche Runde', $after);
[, $j] = api('open', ['action' => 'get', 'room' => $t3, 'token' => $pp[$second]]);
check($after['myHand'] === $hands[$first] && $j['room']['state']['myHand'] === $hands[$second], 'Entfernen: alle anderen behalten ihre Karten');
check(($after['prevBids'][0]['removed'] ?? null) === $target && count($after['prevBids'][0]['bids'] ?? []) === 1,
    'Entfernen: bisherige Ansagen bleiben zur Information sichtbar', $after['prevBids'] ?? null);
$now = $after['turn'][0];
[$code] = api('open', ['action' => 'game', 'room' => $t3, 'op' => 'bid', 'bid' => ['t' => 0, 'a' => 14], 'deal' => $st['deal'], 'token' => $pp[$now]]);
check($code === 409, 'Gebot zur alten Runde nach Neubeginn abgelehnt', $code);

// Während der Ansagen aufgenommen, dann bleibt nur eine Person mit Karten übrig: neu austeilen
[, $json] = api('open', ['action' => 'create', 'game' => 'trombone']);
$t4 = $json['id'];
$pq = [token(), token(), token()];
foreach ([0, 1, 2] as $i) {
    api('open', ['action' => 'sit', 'room' => $t4, 'seat' => $i, 'name' => "U$i", 'token' => $pq[$i]]);
}
api('open', ['action' => 'stand', 'room' => $t4, 'token' => $pq[2]]);
api('open', ['action' => 'game', 'room' => $t4, 'op' => 'start', 'players' => 2, 'token' => $pq[0]]);
api('open', ['action' => 'sit', 'room' => $t4, 'seat' => 2, 'name' => 'U2', 'token' => $pq[2]]);
api('open', ['action' => 'game', 'room' => $t4, 'op' => 'propose', 'seat' => 2, 'cards' => 1, 'token' => $pq[0]]);
[, $json] = api('open', ['action' => 'game', 'room' => $t4, 'op' => 'vote', 'yes' => true, 'token' => $pq[1]]);
$joining = array_values(array_filter($json['room']['state']['players'] ?? [], fn($p) => !empty($p['joining'])));
check(count($joining) === 1, 'Person während der Ansagen aufgenommen (steigt nächste Runde ein)', $json['room']['state']['players'] ?? $json);
[$code, $json] = api('open', ['action' => 'game', 'room' => $t4, 'op' => 'remove', 'seat' => 1, 'token' => $pq[0]]);
$st4 = $json['room']['state'] ?? [];
$active = array_filter($st4['players'] ?? [], fn($p) => !$p['out'] && empty($p['joining']));
check($code === 200 && count($active) === 2 && $st4['phase'] === 'bidding', 'nach dem Entfernen wird neu ausgeteilt, niemand bietet allein', $st4);
[, $j2] = api('open', ['action' => 'get', 'room' => $t4, 'token' => $pq[2]]);
check(!empty($j2['room']['state']['myHand']), 'die aufgenommene Person hat jetzt Karten');

// Alle Mitspielenden weg (entfernt): Dann darf jede Person am Tisch das Spiel abbrechen
$q = token();
api('open', ['action' => 'sit', 'room' => $tr, 'seat' => 2, 'name' => 'Neu', 'token' => $q]);
api('open', ['action' => 'kick', 'room' => $tr, 'seat' => 0, 'token' => $q]);
[, $json] = api('open', ['action' => 'kick', 'room' => $tr, 'seat' => 1, 'token' => $q]);
check(($json['room']['state']['orphaned'] ?? null) === true, 'verwaistes Spiel wird erkannt', $json['room']['state']['orphaned'] ?? $json);
[$code, $json] = api('open', ['action' => 'game', 'room' => $tr, 'op' => 'lobby', 'token' => $q]);
check($code === 200 && ($json['room']['state']['phase'] ?? '') === 'lobby', 'verwaistes Spiel lässt sich abbrechen', $json);

// ---------------------------------------------------------------------------
section('Wer bin ich?: geheime Begriffe');

[, $json] = api('open', ['action' => 'create', 'game' => 'werbinich']);
$wb = $json['id'];
$w = [token(), token(), token()];
foreach ([0, 1, 2] as $i) {
    api('open', ['action' => 'sit', 'room' => $wb, 'seat' => $i, 'name' => "W$i", 'token' => $w[$i]]);
}
[$code] = api('open', ['action' => 'game', 'room' => $wb, 'op' => 'start', 'players' => 3, 'token' => $w[0]]);
check($code === 200, 'Wer bin ich? startet mit 3 Personen', $code);
// Namensänderung während des Spiels kommt im Spielstand an (Trombone ebenso, siehe dort)
[, $json] = api('open', ['action' => 'sit', 'room' => $wb, 'seat' => 1, 'name' => 'Wilma', 'token' => $w[1]]);
$names = array_column($json['room']['state']['players'] ?? [], 'name', 'seat');
check(($names[1] ?? null) === 'Wilma' && ($names[0] ?? null) === 'W0', 'Wer bin ich?: neuer Name im laufenden Spiel', $names);
$terms = ['Begriffnullzwei', 'Begriffeinsnull', 'Begriffzweieins'];
foreach ([[0, 1], [1, 2], [2, 0]] as $k => [$by, $target]) {
    [$code, $json] = api('open', ['action' => 'game', 'room' => $wb, 'op' => 'pick', 'target' => $target, 'token' => $w[$by]]);
    check($code === 200, "W$by wählt W$target", $json);
}
[, , $rawC] = api('open', ['action' => 'get', 'room' => $wb, 'token' => $w[2]]);
api('open', ['action' => 'game', 'room' => $wb, 'op' => 'term', 'term' => $terms[0], 'token' => $w[0]]);
[, , $rawC] = api('open', ['action' => 'get', 'room' => $wb, 'token' => $w[2]]);
check(!str_contains($rawC, $terms[0]), 'beim Aussuchen sieht nur die schreibende Person den Begriff');
api('open', ['action' => 'game', 'room' => $wb, 'op' => 'term', 'term' => $terms[1], 'token' => $w[1]]);
[$code, $json] = api('open', ['action' => 'game', 'room' => $wb, 'op' => 'term', 'term' => $terms[2], 'token' => $w[2]]);
check(($json['room']['state']['phase'] ?? '') === 'playing', 'alle Begriffe da → Raten beginnt', $json['room']['state']['phase'] ?? $json);
[, , $raw1] = api('open', ['action' => 'get', 'room' => $wb, 'token' => $w[1]]);
[, , $raw2] = api('open', ['action' => 'get', 'room' => $wb, 'token' => $w[2]]);
[, , $rawX] = api('open', ['action' => 'get', 'room' => $wb, 'token' => token()]);
check(!str_contains($raw1, $terms[0]) && str_contains($raw1, $terms[1]), 'W1 sieht den eigenen Begriff nicht, wohl aber andere');
check(str_contains($raw2, $terms[0]) && !str_contains($raw2, $terms[1]), 'W2 sieht den eigenen Begriff nicht, wohl aber andere');
check(!str_contains($rawX, $terms[0]) && !str_contains($rawX, $terms[1]) && !str_contains($rawX, $terms[2]), 'Zuschauende sehen keine Begriffe');
foreach ([['op' => 'pick', 'target' => '1'], ['op' => 'term', 'term' => ['x']], ['op' => 'remove', 'seat' => 'x'], ['op' => 'guessed', 'x' => []]] as $bad) {
    [$code] = api('open', ['action' => 'game', 'room' => $wb, 'token' => $w[0]] + $bad);
    check($code < 500, 'Wer bin ich?: unsinnige Eingabe ohne Serverfehler ' . json_encode($bad), $code);
}

// ---------------------------------------------------------------------------
section('Instanz ohne Tischliste');

[$code] = api('unlisted', ['action' => 'list']);
check($code === 403, 'list → 403', $code);
[$code, $json] = api('unlisted', ['action' => 'create', 'game' => 'connect4', 'state' => c4([0])]);
check(preg_match('/^[0-9a-f]{16}$/', $json['id'] ?? ''), '16-stellige ID ohne Tischliste', $json);
// „Deine Tische“: welche Tische gibt es noch? (nur IDs, gelöschte fallen heraus)
$u1 = $json['id'];
[$code, $json] = api('unlisted', ['action' => 'exists', 'rooms' => [$u1, 'abcdef0123456789', $u1]]);
check($code === 200 && $json['rooms'] === [$u1], 'exists: nur vorhandene Tische, ohne Doppelte', $json);
[$code, $json] = api('open', ['action' => 'exists', 'rooms' => [$u1]]);
check($code === 200 && $json['rooms'] === [], 'exists: Tische anderer Instanzen gibt es dort nicht', $json);
[$code] = api('unlisted', ['action' => 'exists', 'rooms' => ['../x']]);
check($code === 400, 'exists: ungültige ID → 400', $code);
[$code] = api('unlisted', ['action' => 'exists', 'rooms' => array_fill(0, 21, $u1)]);
check($code === 400, 'exists: höchstens 20 IDs', $code);
[$code] = api('unlisted', ['action' => 'exists', 'rooms' => 'x']);
check($code === 400, 'exists: keine Liste → 400', $code);
[$code] = api('unlisted', ['action' => 'create', 'game' => 'zahlenkampf', 'state' => c4([0])]);
check($code === 400, 'nicht angebotenes Spiel abgelehnt', $code);
api('unlisted', ['action' => 'create', 'game' => 'connect4', 'state' => c4([0])]);
api('unlisted', ['action' => 'create', 'game' => 'connect4', 'state' => c4([0])]);
[$code] = api('unlisted', ['action' => 'create', 'game' => 'connect4', 'state' => c4([0])]);
check($code === 503, 'max_rooms erreicht → 503', $code);
[, $json] = api('open', ['action' => 'list']);
check(count($json['rooms']) >= 1 && !array_filter($json['rooms'], fn($r) => strlen($r['id']) === 16), 'Instanzen sind getrennt');

// ---------------------------------------------------------------------------
section('Anfragelimits');

$codes = [];
for ($i = 0; $i < 7; $i++) {
    [$codes[], , , $headers] = api('limited', ['action' => 'list']);
}
check($codes === [200, 200, 200, 200, 200, 429, 429], '5 Anfragen pro Minute, danach 429', $codes);
check(header_value($headers, 'Retry-After') !== null, '429 mit Retry-After');
[$code] = api('open', ['action' => 'list']);
check($code === 200, 'Limit einer Instanz betrifft die anderen nicht', $code);
$codes = [];
for ($i = 0; $i < 3; $i++) {
    [$codes[]] = api('creates', ['action' => 'create', 'game' => 'connect4', 'state' => c4([0])]);
}
check($codes === [200, 200, 429], '2 neue Tische pro Stunde, danach 429', $codes);
$codes = [];
for ($i = 0; $i < 3; $i++) {
    [$codes[]] = api('percl', ['action' => 'create', 'game' => 'connect4', 'state' => $c4]);
}
check($codes === [200, 200, 429], 'höchstens 2 bestehende Tische pro Absender', $codes);
file_put_contents("$tmp/data/.state/secret.php", 'kaputt');
api('open', ['action' => 'list']);
check(preg_match('/^[0-9a-f]{64}$/', substr((string)file_get_contents("$tmp/data/.state/secret.php"), strlen(GUARD_LINE))) === 1,
    'beschädigte Schlüsseldatei wird ersetzt');
$files = glob("$tmp/data/.state/ratelimit/*") ?: [];
check($files && !array_filter($files, fn($f) => str_contains(basename($f), '127.0.0.1')), 'Zählerdateien enthalten keine IP-Adresse');
check(str_starts_with((string)@file_get_contents("$tmp/data/.state/secret.php"), GUARD_LINE), 'Schlüsseldatei mit Schutzzeile');

// ---------------------------------------------------------------------------
section('Aufräumen und Konfiguration (direkt, ohne Server)');

define('GAME_NIGHT', 1);
require dirname(__DIR__) . '/core/Api.php';

$dir = "$tmp/cleanup";
mkdir("$dir/tables", 0775, true);
GameNight\cfg(GameNight\instance_config(['empty_room_ttl_hours' => 1, 'room_ttl_days' => 7], "$dir/tables", $dir));
$mk = function (string $id, array $seats, int $age) use ($dir) {
    $f = "$dir/tables/room-$id.php";
    file_put_contents($f, GUARD_LINE . json_encode(['id' => $id, 'seats' => $seats, 'state' => []]));
    touch($f, time() - $age);
};
$seated = [['name' => 'A', 'token' => 'x'], null];
$mk('aaaaaa01', [null, null], 7200);        // leer, alt → weg
$mk('aaaaaa02', [null, null], 600);         // leer, neu → bleibt
$mk('aaaaaa03', $seated, 7200);             // besetzt → bleibt
$mk('aaaaaa04', $seated, 8 * 86400);        // zu alt → weg
GameNight\cleanup();
$left = array_map(fn($f) => substr(basename($f), 5, 8), glob("$dir/tables/*"));
sort($left);
check($left === ['aaaaaa02', 'aaaaaa03'], 'Aufräumen nach empty_room_ttl und room_ttl', $left);

$c = GameNight\instance_config(['games' => ['quatsch'], 'max_rooms' => -5, 'requests_per_minute' => -1, 'unbekannt' => 1], "$dir/x", $dir);
check($c['games'] === array_values(array_diff(array_keys(GameNight\GAMES), GameNight\WIP_GAMES)), 'unbekannte Spiele → alle fertigen Spiele', $c['games']);
check(!in_array('zahlenkampf', GameNight\instance_config([], "$dir/x", $dir)['games'], true), 'Spiele in Arbeit sind standardmäßig aus');
check(in_array('zahlenkampf', GameNight\instance_config(['games' => ['zahlenkampf']], "$dir/x", $dir)['games'], true), '… lassen sich aber ausdrücklich einschalten');

// Besucheradresse hinter einem Proxy (nur mit proxy_ip_header; bei Listen zählt der letzte, vom Proxy angehängte Eintrag)
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 203.0.113.5';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '192.0.2.44';
check(GameNight\instance_config([], "$dir/x", $dir)['client_ip'] === null, 'ohne proxy_ip_header: Kopfzeilen werden ignoriert (REMOTE_ADDR)');
check(GameNight\instance_config(['proxy_ip_header' => 'X-Forwarded-For'], "$dir/x", $dir)['client_ip'] === '203.0.113.5', 'X-Forwarded-For: letzter Eintrag');
check(GameNight\instance_config(['proxy_ip_header' => 'CF-Connecting-IP'], "$dir/x", $dir)['client_ip'] === '192.0.2.44', 'CF-Connecting-IP');
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'kein-ip';
check(GameNight\instance_config(['proxy_ip_header' => 'CF-Connecting-IP'], "$dir/x", $dir)['client_ip'] === null, 'ungültige Adresse in der Kopfzeile → REMOTE_ADDR');
GameNight\cfg(['client_ip' => '203.0.113.5'] + GameNight\cfg());
$kProxy = GameNight\client_key();
$_SERVER['REMOTE_ADDR'] = '10.0.0.2';   // anderer Proxy-Knoten, gleiche Besucheradresse
check(GameNight\client_key() === $kProxy, 'Anfragelimits zählen die Besucheradresse, nicht die des Proxys');
GameNight\cfg(['client_ip' => null] + GameNight\cfg());
unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP']);
check($c['max_rooms'] === 1 && $c['requests_per_minute'] === 0, 'negative Werte werden begrenzt', $c);
check(!array_key_exists('unbekannt', $c), 'unbekannte Einstellungen werden ignoriert');

$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:aaaa::1';
$k1 = GameNight\client_key();
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:bbbb::2';
$k2 = GameNight\client_key();
$_SERVER['REMOTE_ADDR'] = '2001:db8:1:3::1';
$k3 = GameNight\client_key();
check($k1 === $k2 && $k1 !== $k3, 'IPv6: gleiches /64-Netz = gleicher Absender');
$_SERVER['REMOTE_ADDR'] = '::ffff:192.0.2.1';
$m1 = GameNight\client_key();
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
$m2 = GameNight\client_key();
$_SERVER['REMOTE_ADDR'] = '::ffff:192.0.2.2';
$m3 = GameNight\client_key();
check($m1 === $m2 && $m1 !== $m3, 'IPv4 in IPv6-Schreibweise zählt wie IPv4 (nicht alle als ein Absender)');
// Viele /64-Netze aus einem /48-Netz: nur begrenzt viele neue Zählerdateien
$before = count(glob("$dir/ratelimit/*") ?: []);
$allowed = 0;
for ($i = 0; $i < 300; $i++) {
    $_SERVER['REMOTE_ADDR'] = sprintf('2001:db8:aaaa:%x::1', $i);
    $allowed += GameNight\rate_limit('req', GameNight\client_key(), 100, 60) ? 1 : 0;
}
$created = count(glob("$dir/ratelimit/*") ?: []) - $before;
check($allowed === GameNight\NEW_CLIENTS_PER_NETWORK, 'pro /48-Netz höchstens 256 neue Absender je Stunde', $allowed);
check($created <= GameNight\NEW_CLIENTS_PER_NETWORK + 3, 'Zahl der Zählerdateien bleibt begrenzt', $created);
$_SERVER['REMOTE_ADDR'] = '2001:db8:bbbb::1';
check(GameNight\rate_limit('req', GameNight\client_key(), 100, 60), 'anderes /48-Netz ist nicht betroffen');

// ---------------------------------------------------------------------------
// Meldungen beim Start von PHP („in Unknown on line 0“, z. B. zu Erweiterungen) kommen von der Umgebung, nicht vom Plugin
$log = preg_replace('/^.*PHP \w+:.* in Unknown on line 0\R?/m', '', (string)@file_get_contents("$tmp/server.log"));
check(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $log), 'keine PHP-Warnungen im Serverlog',
    implode("\n", array_slice(preg_grep('/PHP (Warning|Notice|Fatal|Deprecated)/', explode("\n", $log)), 0, 5)));

echo "\n$checks Prüfungen, " . ($failures ? "$failures FEHLGESCHLAGEN" : 'alle bestanden') . "\n";
exit($failures ? 1 : 0);
