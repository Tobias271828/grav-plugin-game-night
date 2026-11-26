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
    'party'    => ['games' => ['konferenz', 'zahlenkampf'], 'requests_per_minute' => 0, 'creates_per_hour' => 0, 'rooms_per_client' => 0],
];
// Trombone-Bots: Gewichtsdatei und Denkpause [von, bis] in ms lassen sich nur hier in den Tests einstellen (tests/router.php)
$free = ['requests_per_minute' => 0, 'creates_per_hour' => 0, 'rooms_per_client' => 0,
         'max_bots_per_client' => 0, 'max_bots_total' => 0, 'bot_moves_per_minute' => 0];
$instances += [
    'bots'     => $free + ['net_file' => "$tmp/net.bin", 'bot_delay' => [0, 0]],              // Bots ziehen sofort
    'botslow'  => $free + ['net_file' => "$tmp/net.bin", 'bot_delay' => [600000, 600000]],    // … praktisch nie
    'bottimed' => $free + ['net_file' => "$tmp/net.bin", 'bot_delay' => [1500, 1500]],
    'maxbots'  => $free + ['net_file' => "$tmp/net.bin", 'max_bots' => 2],
    'nobots'   => $free + ['net_file' => "$tmp/net.bin", 'bots' => false],
    'nonet'    => $free + ['net_file' => "$tmp/fehlt.bin"],
    'badnet'   => $free + ['net_file' => "$tmp/net-bad.bin"],
    'vanish'   => $free + ['net_file' => "$tmp/net-vanish.bin", 'bot_delay' => [0, 0]],       // Datei verschwindet im Test
    // Grenzen für Bots (Voreinstellung der Instanz: 14 pro IP, 40 insgesamt, 600 Züge pro Minute)
    'botcap'    => ['max_bots_total' => 3] + $free + ['net_file' => "$tmp/net.bin"],
    'botmine'   => ['max_bots_per_client' => 2] + $free + ['net_file' => "$tmp/net.bin"],
    'botbudget' => ['bot_moves_per_minute' => 4] + $free + ['net_file' => "$tmp/net.bin", 'bot_delay' => [0, 0]],   // pro IP: 1 Zug
    'botnan'    => $free + ['net_file' => "$tmp/net-nan.bin", 'bot_delay' => [0, 0]],          // Gewichte sind NaN
];

/** Gewichtsdatei mit Zufallsgewichten (Format siehe tbot_net_info() in core/TromboneBot.php; wie write_net() in bot-test.php). */
function test_net(string $file, int $featureOffset = 0): void
{
    $src = (string)file_get_contents(dirname(__DIR__) . '/core/TromboneBot.php');
    $const = function (string $name) use ($src): int {
        preg_match('/const ' . $name . '\s*=\s*(\d+)/', $src, $m);
        return (int)$m[1];
    };
    $sizes = [$const('TBOT_INPUTS'), 128, 128, $const('TBOT_ACTIONS')];
    mt_srand(7);
    $bin = 'TBN1' . pack('v3', $const('TBOT_FORMAT_VERSION'), $const('TBOT_FEATURE_VERSION') + $featureOffset, count($sizes) - 1)
        . pack('v*', ...$sizes) . pack('g*', 5.0, 0.40, 0.38, 0.36, 0.20, 0.55, 0.45) . pack('V', 2) . '{}';
    for ($l = 0; $l < count($sizes) - 1; $l++) {
        $w = [];
        for ($i = $sizes[$l] * $sizes[$l + 1] + $sizes[$l + 1]; $i > 0; $i--) {
            $w[] = (mt_rand() / mt_getrandmax() - 0.5) * 0.4;
        }
        $bin .= pack('g*', ...$w);
    }
    file_put_contents($file, $bin);
}
test_net("$tmp/net.bin");
test_net("$tmp/net-bad.bin", 1);   // andere Merkmalsversion: passt nicht zum Code
copy("$tmp/net.bin", "$tmp/net-vanish.bin");
// gültiger Kopf, aber alle Gewichte ab Byte 200 sind NaN (die Gewichte beginnen an einer durch 4 teilbaren Stelle)
$raw = (string)file_get_contents("$tmp/net.bin");
file_put_contents("$tmp/net-nan.bin", substr($raw, 0, 200) . str_repeat(pack('g', NAN), intdiv(strlen($raw) - 200, 4)));
unset($raw);
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

// Zahlenkampf: gültiger Startzustand (wie ZK.initState, gekürzt) und manipulierte Varianten
$zk = fn(array $stone = []) => [
    'stones' => [$stone + ['id' => 0, 'side' => 0, 'shape' => 'rd', 'v' => 4, 'r' => 2, 'c' => 5],
                 ['id' => 1, 'side' => 1, 'shape' => 'P', 'v' => 190, 'r' => 14, 'c' => 7,
                  'parts' => [['shape' => 'sq', 'v' => 64], ['shape' => 'rd', 'v' => 16]]]],
    'taken' => [['n' => 0, 'sum' => 0], ['n' => 0, 'sum' => 0]], 'turn' => [0], 'over' => false, 'winner' => null, 'end' => null,
    'last' => null, 'captured' => [], 'triumph' => null, 'log' => []];
[$code] = api('party', ['action' => 'create', 'game' => 'zahlenkampf', 'state' => $zk()]);
check($code === 200, 'Zahlenkampf: gültiger Startzustand angenommen', $code);
$zkLog = ['side' => 0, 'stone' => ['v' => 8, 'shape' => 'rd'], 'lost' => [],
          'caps' => [['stone' => ['v' => 16, 'shape' => 'rd', 'of' => 'P'], 'why' => ['t' => 'mul', 'a' => 8, 'b' => 2]]]];
[$code] = api('party', ['action' => 'create', 'game' => 'zahlenkampf', 'state' =>
    ['log' => [$zkLog], 'over' => true, 'winner' => 0, 'end' => ['type' => 'triumph', 'kind' => 'arith', 'values' => [2, 4, 6, 8]]] + $zk()]);
check($code === 200, 'Zahlenkampf: Protokoll und Triumph angenommen', $code);
foreach ([
    'riesige Zahl'        => $zk(['v' => 9007199254740881]),
    '__proto__ als Reihe' => $zk(['r' => '__proto__', 'c' => 'map']),
    'Reihe außerhalb'     => $zk(['r' => 16]),
    'unbekannte Form'     => $zk(['shape' => 'zauber']),
    'Pyramide ohne Teile' => $zk(['shape' => 'P']),
    'zu viele Steine'     => ['stones' => array_fill(0, 49, $zk()['stones'][0])] + $zk(),
    'Zählerstand'         => ['taken' => [['n' => 'x', 'sum' => 0], ['n' => 0, 'sum' => 0]]] + $zk(),
    'Triumph ohne Werte'  => ['end' => ['type' => 'triumph', 'kind' => 'arith', 'values' => 1]] + $zk(),
    'unbekanntes Ende'    => ['end' => ['type' => 'zauber']] + $zk(),
    'Protokoll kaputt'    => ['log' => [['caps' => 5]]] + $zk(),
] as $what => $state) {
    [$code] = api('party', ['action' => 'create', 'game' => 'zahlenkampf', 'state' => $state]);
    check($code === 400, "Zahlenkampf: manipulierter Startzustand abgelehnt: $what", $code);
}

// Nicht darstellbare Zahlen (1e400 → INF) dürfen keinen Tisch zerstören (früher: Datei nur noch Schutzzeile)
$inf = fn(string $num) => substr(json_encode(c4([0])), 0, -1) . ',"x":' . $num . '}';   // sonst gültiger Stand
[$code] = req('open', '{"action":"create","game":"connect4","state":' . $inf('1e400') . '}');
check($code === 400, 'Startzustand mit 1e400 abgelehnt', $code);
$c4 = c4([0]);
foreach ([
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
// Trombone-Bots (core/TromboneBot.php, Aktion 'bot'). Alle Antworten dieser Tische werden gesammelt und am
// Ende auf Bot-Token durchsucht.

$botRaw = '';
/** Wie api(), merkt sich aber die rohe Antwort. */
function bapi(string $inst, array $data): array
{
    global $botRaw;
    $r = api($inst, $data);
    $botRaw .= $r[2] . "\n";
    return $r;
}

/** Inhalt der Tischdatei (mit Tokens und allem, was nie ausgeliefert wird). */
function room_data(string $inst, string $id): array
{
    return (array)json_decode(substr((string)file_get_contents((string)room_file($inst, $id)), strlen(GUARD_LINE)), true);
}

/** Trombone-Tisch: die Menschen $humans (Tokens, ab Platz 0) setzen sich, dazu $bots Bots auf den nächsten Plätzen. */
function bot_table(string $inst, array $humans, int $bots, string $prefix = 'H'): string
{
    [, $json] = bapi($inst, ['action' => 'create', 'game' => 'trombone']);
    $id = $json['id'];
    foreach (array_values($humans) as $i => $tok) {
        bapi($inst, ['action' => 'sit', 'room' => $id, 'seat' => $i, 'name' => "$prefix$i", 'token' => $tok]);
    }
    for ($i = 0; $i < $bots; $i++) {
        bapi($inst, ['action' => 'bot', 'op' => 'add', 'room' => $id, 'seat' => count($humans) + $i, 'token' => $humans[0]]);
    }
    return $id;
}

/** Spiel starten (bei 3 Mitspielenden mit 52 Karten); gibt [Status, Spielstand] zurück. */
function bot_start(string $inst, string $id, string $token, int $players): array
{
    if ($players === 3) {
        bapi($inst, ['action' => 'game', 'room' => $id, 'op' => 'deck', 'deck' => '52', 'token' => $token]);
    }
    [$code, $json] = bapi($inst, ['action' => 'game', 'room' => $id, 'op' => 'start', 'players' => $players, 'token' => $token]);
    return [$code, $json['room']['state'] ?? $json];
}

/** Zug eines geskripteten Menschen: ohne Ansage das kleinste Gebot, sonst anzweifeln. */
function human_move(string $inst, string $id, string $token, array $st): array
{
    $in = $st['bids'] ? ['op' => 'challenge'] : ['op' => 'bid', 'bid' => ['t' => 0, 'a' => $st['deck'] === '32' ? 7 : 2]];
    [$code, $json] = bapi($inst, ['action' => 'game', 'room' => $id, 'deal' => $st['deal'], 'token' => $token] + $in);
    return [$code, $json['room']['state'] ?? $json];
}

/** Sitzt auf dem Platz, der am Zug ist, ein Bot? */
function bot_to_move(array $st): bool
{
    $bySeat = array_column($st['players'], null, 'seat');
    return $st['phase'] === 'bidding' && !empty($bySeat[$st['turn'][0]]['bot']);
}

// ---------------------------------------------------------------------------
section('Trombone-Bots: hinzufügen und entfernen');

$h = [token(), token(), token()];
$bt = bot_table('bots', [$h[0]], 0);
[, $json] = bapi('bots', ['action' => 'get', 'room' => $bt, 'token' => $h[0]]);
check(($json['room']['maxBots'] ?? null) === 7, 'Tisch meldet: Instanz bietet bis zu 7 Bots an', $json['room']['maxBots'] ?? null);
[$code] = bapi('bots', ['action' => 'bot', 'op' => 'add', 'room' => $bt, 'seat' => 1, 'token' => $h[1]]);
check($code === 403, 'Zuschauende können keinen Bot hinzufügen', $code);
[$code] = bapi('bots', ['action' => 'bot', 'op' => 'add', 'room' => $bt, 'seat' => 1]);
check($code === 403, 'ohne Token kein Bot', $code);
[$code] = bapi('bots', ['action' => 'bot', 'op' => 'quatsch', 'room' => $bt, 'seat' => 1, 'token' => $h[0]]);
check($code === 400, 'unbekannte Bot-Aktion abgelehnt', $code);
foreach ([99, -1, 'x', null] as $bad) {
    [$code] = bapi('bots', ['action' => 'bot', 'op' => 'add', 'room' => $bt, 'seat' => $bad, 'token' => $h[0]]);
    check($code === 400, 'Bot auf ungültigem Platz abgelehnt: ' . json_encode($bad), $code);
}
[$code] = bapi('bots', ['action' => 'bot', 'op' => 'add', 'room' => $bt, 'seat' => 0, 'token' => $h[0]]);
check($code === 409, 'Bot auf besetztem Platz abgelehnt', $code);
[$code, $json, $raw] = bapi('bots', ['action' => 'bot', 'op' => 'add', 'room' => $bt, 'seat' => 1, 'token' => $h[0]]);
$botSeat = $json['room']['seats'][1] ?? null;
check($code === 200 && ($botSeat['bot'] ?? null) === true && ($botSeat['name'] ?? '') !== '', 'Bot sitzt auf Platz 1, mit Kennzeichen und Namen', $json);
check(array_keys((array)$botSeat) === ['name', 'bot'] && array_keys($json['room']['seats'][0]) === ['name'], 'Plätze zeigen nur Name und (bei Bots) Kennzeichen', $json['room']['seats'] ?? null);
check(($json['mySeat'] ?? null) === 0, 'mySeat bleibt der eigene Platz');
$botToken = room_data('bots', $bt)['seats'][1]['token'] ?? '';
check(preg_match('/^bot-[0-9a-f]{32}$/', $botToken) === 1 && !preg_match('/^[0-9a-f]{32}$/', $botToken), 'Bot-Token hat nicht die Form der Browser-Tokens');
[$code] = bapi('bots', ['action' => 'bot', 'op' => 'add', 'room' => $bt, 'seat' => 1, 'token' => $h[0]]);
check($code === 409, 'kein zweiter Bot auf demselben Platz', $code);

// Das Kennzeichen setzt nur der Server
[$code] = bapi('bots', ['action' => 'sit', 'room' => $bt, 'seat' => 1, 'name' => 'Frech', 'token' => $h[1]]);
check($code === 409, 'auf einen Bot-Platz kann sich niemand setzen', $code);
[$code, $json] = bapi('bots', ['action' => 'sit', 'room' => $bt, 'seat' => 2, 'name' => '🐸 Bot', 'token' => $h[1], 'bot' => true]);
check($code === 200 && array_keys($json['room']['seats'][2] ?? []) === ['name'] && $json['room']['seats'][2]['name'] === '🐸 Bot',
    'sit mit bot-Feld (und Namen „🐸 Bot“) ergibt keinen Bot-Platz', $json['room']['seats'][2] ?? $json);
check(!array_key_exists('bot', room_data('bots', $bt)['seats'][2]), '… auch nicht in der Tischdatei');
[, $json] = bapi('bots', ['action' => 'sit', 'room' => $bt, 'seat' => 2, 'name' => 'Bot', 'token' => $h[1], 'bot' => true, 'state' => ['players' => [['seat' => 2, 'bot' => true]]]]);
check(array_keys($json['room']['seats'][2] ?? []) === ['name'], 'auch beim Umbenennen in „Bot“ kein Kennzeichen', $json['room']['seats'][2] ?? $json);
check(($json['room']['seats'][1]['bot'] ?? null) === true, 'der Bot-Platz behält sein Kennzeichen');
// Token in Bot-Form: schon am Eingang abgelehnt; die Hex-Ziffern allein sind ein fremdes Browser-Token
foreach (['get', 'sit', 'kick', 'game', 'bot', 'stand', 'delete'] as $action) {
    [$code, $json] = bapi('bots', ['action' => $action, 'room' => $bt, 'seat' => 1, 'name' => 'X', 'op' => 'start', 'players' => 3, 'token' => $botToken]);
    check($code === 400 && ($json['error'] ?? '') === 'Ungültiges Token', "Bot-Token als Client-Token abgelehnt: $action", [$code, $json]);
}
[$code, $json] = bapi('bots', ['action' => 'get', 'room' => $bt, 'token' => substr($botToken, 4)]);
check($code === 200 && $json['mySeat'] === null, 'Hex-Teil des Bot-Tokens gilt nicht als Bot', $json['mySeat'] ?? $json);
[$code] = bapi('bots', ['action' => 'game', 'room' => $bt, 'op' => 'start', 'players' => 3, 'token' => substr($botToken, 4)]);
check($code === 403, '… und darf nichts für ihn tun', $code);
[$code, $json] = bapi('bots', ['action' => 'sit', 'room' => $bt, 'seat' => 3, 'name' => $botSeat['name'], 'token' => $h[2]]);
check(($json['room']['seats'][3]['name'] ?? '') === $botSeat['name'] . ' 2', 'Mensch mit dem Namen eines Bots wird durchnummeriert', $json['room']['seats'][3] ?? $json);
bapi('bots', ['action' => 'stand', 'room' => $bt, 'token' => $h[2]]);
[, $json, $raw] = bapi('bots', ['action' => 'list']);
$listed = array_values(array_filter($json['rooms'] ?? [], fn($r) => $r['id'] === $bt))[0] ?? [];
check(($listed['bots'] ?? null) === [1] && ($listed['players'][1] ?? null) === $botSeat['name'], 'Tischliste kennzeichnet den Bot-Platz', $listed);

// entfernen
[$code] = bapi('bots', ['action' => 'bot', 'op' => 'remove', 'room' => $bt, 'seat' => 1, 'token' => $h[2]]);
check($code === 403, 'Zuschauende können keinen Bot entfernen', $code);
[$code] = bapi('bots', ['action' => 'bot', 'op' => 'remove', 'room' => $bt, 'seat' => 2, 'token' => $h[0]]);
check($code === 400, 'Bot entfernen trifft keine Menschen', $code);
[$code] = bapi('bots', ['action' => 'bot', 'op' => 'remove', 'room' => $bt, 'seat' => 5, 'token' => $h[0]]);
check($code === 400, 'Bot entfernen auf freiem Platz abgelehnt', $code);
[$code, $json] = bapi('bots', ['action' => 'bot', 'op' => 'remove', 'room' => $bt, 'seat' => 1, 'token' => $h[1]]);
check($code === 200 && $json['room']['seats'][1] === null, 'Bot entfernt (durch eine andere Person am Tisch)', $json);
// kick vor dem Start gibt einen Bot-Platz einfach frei
bapi('bots', ['action' => 'bot', 'op' => 'add', 'room' => $bt, 'seat' => 4, 'token' => $h[0]]);
[$code, $json] = bapi('bots', ['action' => 'kick', 'room' => $bt, 'seat' => 4, 'token' => $h[1]]);
check($code === 200 && $json['room']['seats'][4] === null, 'kick vor dem Start gibt den Bot-Platz frei', $json);

// andere Spiele
foreach (['connect4' => c4([0]), 'werbinich' => null] as $game => $state) {
    [, $json] = bapi('bots', ['action' => 'create', 'game' => $game, 'state' => $state]);
    bapi('bots', ['action' => 'sit', 'room' => $json['id'], 'seat' => 0, 'name' => 'A', 'token' => $h[0]]);
    [$code, $j] = bapi('bots', ['action' => 'bot', 'op' => 'add', 'room' => $json['id'], 'seat' => 1, 'token' => $h[0]]);
    check($code === 400 && !array_filter($j['room']['seats'] ?? []), "kein Bot bei $game", [$code, $j]);
    [, $j] = bapi('bots', ['action' => 'get', 'room' => $json['id'], 'token' => $h[0]]);
    check(!array_key_exists('maxBots', $j['room'] ?? []) && $j['room']['seats'][1] === null, "$game: Tisch unverändert, kein Bot-Angebot", $j['room']['seats'] ?? $j);
}

// Namen: lauter verschiedene Tiere, keines, das im Namen eines Menschen vorkommt
$zoo = ['🐸🦊🐼🐨🦁🐯🐮🐷🐵🐔🐧', 'Team 🦉🦆🐴🦄🐝🐢🐙🦀'];
[, $json] = bapi('bots', ['action' => 'create', 'game' => 'trombone']);
$bn = $json['id'];
foreach ($zoo as $i => $name) {
    bapi('bots', ['action' => 'sit', 'room' => $bn, 'seat' => $i, 'name' => $name, 'token' => $h[$i]]);
}
for ($i = 2; $i < 8; $i++) {
    [$code, $json] = bapi('bots', ['action' => 'bot', 'op' => 'add', 'room' => $bn, 'seat' => $i, 'token' => $h[0]]);
}
$seats = $json['room']['seats'] ?? [];
$botNames = array_column(array_filter($seats, fn($s) => !empty($s['bot'])), 'name');
check(count($botNames) === 6 && count(array_unique($botNames)) === 6, 'sechs Bots mit verschiedenen Namen', $botNames);
check(!array_filter($botNames, fn($n) => str_contains(implode($zoo), $n)), 'kein Bot heißt wie ein Tier im Namen eines Menschen', $botNames);
check(!array_filter($botNames, fn($n) => preg_match('/^.$/u', $n) !== 1), 'Bot-Namen sind je ein Emoji', $botNames);
check(empty($seats[0]['bot']) && empty($seats[1]['bot']) && $seats[0]['name'] === $zoo[0], 'Menschen mit Tier-Emojis im Namen bekommen kein Kennzeichen', $seats);
[$code] = bapi('bots', ['action' => 'bot', 'op' => 'add', 'room' => $bn, 'seat' => 2, 'token' => $h[0]]);
check($code === 409, 'voller Tisch: kein weiterer Bot', $code);

// Obergrenze der Instanz
$bm = bot_table('maxbots', [$h[0]], 2);
[$code, $json] = bapi('maxbots', ['action' => 'bot', 'op' => 'add', 'room' => $bm, 'seat' => 3, 'token' => $h[0]]);
check($code === 409 && str_contains($json['error'] ?? '', 'Mehr Bots'), 'max_bots: dritter Bot abgelehnt', [$code, $json]);
[, $json] = bapi('maxbots', ['action' => 'get', 'room' => $bm, 'token' => $h[0]]);
check($json['room']['maxBots'] === 2 && count(array_filter($json['room']['seats'])) === 3, 'max_bots: Tisch meldet die Obergrenze, zwei Bots sitzen', $json['room']['seats']);
bapi('maxbots', ['action' => 'bot', 'op' => 'remove', 'room' => $bm, 'seat' => 1, 'token' => $h[0]]);
[$code] = bapi('maxbots', ['action' => 'bot', 'op' => 'add', 'room' => $bm, 'seat' => 3, 'token' => $h[0]]);
check($code === 200, 'max_bots: nach dem Entfernen wieder Platz für einen Bot', $code);

// ---------------------------------------------------------------------------
section('Trombone-Bots: Start, Denkpause, ein Zug pro Anfrage');

// Spamschutz: Steht der letzte Mensch auf, verschwindet ein Tisch mit Bots sofort
$bs = bot_table('botslow', [$h[0]], 2);
[$code] = bapi('botslow', ['action' => 'stand', 'room' => $bs, 'token' => $h[0]]);
check($code === 200, 'letzter Mensch steht auf', $code);
[$code] = bapi('botslow', ['action' => 'get', 'room' => $bs]);
check($code === 404, 'Tisch, an dem nur noch Bots sitzen, ist sofort gelöscht', $code);
[$code] = bot_start('botslow', $bs, $h[1], 2);
check($code === 404, 'und lässt sich nicht mehr starten', $code);
// auch mitten im Spiel und auch, wenn der letzte Mensch sich selbst vom Platz nimmt
$bs = bot_table('botslow', [$h[0], $h[1]], 2);
[$code] = bot_start('botslow', $bs, $h[0], 4);
check($code === 200, 'Spiel mit 2 Menschen und 2 Bots läuft', $code);
bapi('botslow', ['action' => 'stand', 'room' => $bs, 'token' => $h[1]]);
[$code] = bapi('botslow', ['action' => 'get', 'room' => $bs, 'token' => $h[0]]);
check($code === 200, 'solange ein Mensch sitzt, bleibt der Tisch', $code);
$mine = null;
foreach (bapi('botslow', ['action' => 'get', 'room' => $bs, 'token' => $h[0]])[1]['room']['seats'] as $i => $seat) {
    if ($seat && empty($seat['bot'])) {
        $mine = $i;
    }
}
bapi('botslow', ['action' => 'kick', 'room' => $bs, 'seat' => $mine, 'token' => $h[0]]);
[$code] = bapi('botslow', ['action' => 'get', 'room' => $bs]);
check($code === 404, 'laufendes Spiel nur noch mit Bots: Tisch sofort gelöscht', $code);
// Ein Tisch ganz ohne Bots bleibt wie bisher stehen, wenn alle aufstehen
$bs = bot_table('botslow', [$h[0]], 0);
bapi('botslow', ['action' => 'stand', 'room' => $bs, 'token' => $h[0]]);
[$code] = bapi('botslow', ['action' => 'get', 'room' => $bs]);
check($code === 200, 'leerer Tisch ohne Bots bleibt bestehen', $code);

// Ein Mensch gegen einen Bot
$b1 = bot_table('botslow', [$h[0]], 1);
[$code] = bapi('botslow', ['action' => 'game', 'room' => $b1, 'op' => 'start', 'players' => 1, 'token' => $h[0]]);
check($code === 409, 'Start mit falscher Personenzahl (Bots zählen mit) abgelehnt', $code);
[$code, $st] = bot_start('botslow', $b1, $h[0], 2);
$flags = array_column($st['players'] ?? [], 'bot', 'seat');
check($code === 200 && $st['phase'] === 'bidding' && $st['deck'] === '32' && $flags === [1 => true], '1 Mensch + 1 Bot startet; Kennzeichen steht bei den Mitspielenden', $st);
check(!array_key_exists('botDue', $st) && !array_key_exists('botTurn', $st) && !array_key_exists('cred', $st), 'Antwort ohne interne Felder (Denkpause, Zähler)', array_keys($st));
[$code] = bapi('botslow', ['action' => 'bot', 'op' => 'remove', 'room' => $b1, 'seat' => 1, 'token' => $h[0]]);
check($code === 409, 'ein mitspielender Bot lässt sich nicht per remove wegnehmen', $code);
if (!bot_to_move($st)) {
    [, $st] = human_move('botslow', $b1, $h[0], $st);
}
check(bot_to_move($st), 'der Bot ist am Zug', $st);
$file = room_data('botslow', $b1);
$due = $file['state']['botDue'] ?? 0;
check($due > microtime(true) * 1000 + 500000 && $due < microtime(true) * 1000 + 700000, 'Denkpause im selben Schreibvorgang gesetzt (botDue)', $due);
check(hash_equals($file['seats'][1]['token'], $file['state']['players'][array_search(1, array_column($file['state']['players'], 'seat'))]['token']),
    'start übernimmt das Bot-Token nur in die Tischdatei');
$v = $file['version'];
for ($i = 0; $i < 3; $i++) {
    [, $json] = bapi('botslow', ['action' => 'get', 'room' => $b1, 'token' => $h[0]]);
}
check($json['room']['version'] === $v && count($json['room']['state']['bids']) === count($st['bids']), 'vor Ablauf der Denkpause zieht der Bot nicht', $json['room']['version']);
[, $json] = bapi('botslow', ['action' => 'get', 'room' => $b1, 'token' => $h[0], 'since' => $v]);
check(($json['unchanged'] ?? false) === true, '… und get meldet weiter „unchanged“', $json);
// Ein anderer Schreibvorgang (neuer Name) lässt die laufende Denkpause unberührt
bapi('botslow', ['action' => 'sit', 'room' => $b1, 'seat' => 0, 'name' => 'Hanna', 'token' => $h[0]]);
check((room_data('botslow', $b1)['state']['botDue'] ?? 0) === $due, 'andere Schreibvorgänge verlängern die Denkpause nicht');
// Der Bot klickt nie weiter und der Mensch kann nicht für ihn ziehen
[$code] = bapi('botslow', ['action' => 'game', 'room' => $b1, 'op' => 'bid', 'bid' => ['t' => 1, 'a' => 14], 'deal' => $st['deal'], 'token' => $h[0]]);
check($code === 400, 'der Mensch kann nicht für den Bot bieten', $code);
// Bots bleiben über Spiele hinweg sitzen
[, $json] = bapi('botslow', ['action' => 'game', 'room' => $b1, 'op' => 'lobby', 'token' => $h[0]]);
check($json['room']['state']['phase'] === 'lobby' && ($json['room']['seats'][1]['bot'] ?? null) === true, 'nach dem Spiel sitzt der Bot noch am Tisch');
check(!array_key_exists('botDue', room_data('botslow', $b1)['state']), 'ohne Bot am Zug keine Denkpause');
[$code, $st] = bot_start('botslow', $b1, $h[0], 2);
check($code === 200 && array_column($st['players'], 'bot', 'seat') === [1 => true], 'nächstes Spiel wieder mit dem Bot', $st);

// Denkpause von 1,5 s: vorher kein Zug, danach genau einer – auch bei gleichzeitigen Anfragen
$b2 = bot_table('bottimed', [$h[0]], 2);
[, $st] = bot_start('bottimed', $b2, $h[0], 3);
if (!bot_to_move($st)) {
    [, $st] = human_move('bottimed', $b2, $h[0], $st);
}
$v = room_data('bottimed', $b2)['version'];
$n = count($st['bids']);
[, $json] = bapi('bottimed', ['action' => 'get', 'room' => $b2, 'token' => $h[0], 'since' => $v]);
check(($json['unchanged'] ?? false) === true, 'Denkpause 1,5 s: sofort danach noch kein Zug', $json);
usleep(1700000);
$codes = parallel('bottimed', array_map(fn() => ['action' => 'get', 'room' => $b2, 'token' => token(), 'since' => $v], range(1, 8)));
check(count(array_keys($codes, 200)) === 8, 'acht gleichzeitige Abfragen nach der Denkpause: alle beantwortet', $codes);
[, $json] = bapi('bottimed', ['action' => 'get', 'room' => $b2, 'token' => $h[0]]);
$after = $json['room']['state'];
$moves = $after['phase'] === 'bidding' ? count($after['bids']) - $n : 1;
check($json['room']['version'] === $v + 1 && $moves === 1 && ($after['phase'] !== 'bidding' || end($after['bids'])['seat'] === $st['turn'][0]),
    'gleichzeitige Abfragen: genau ein Bot-Zug (Version + 1)', [$json['room']['version'] - $v, $moves]);
[, $json] = bapi('bottimed', ['action' => 'get', 'room' => $b2, 'token' => $h[0], 'since' => $v + 1]);
check(($json['unchanged'] ?? false) === true, 'der nächste Bot hat seine eigene Denkpause', $json);
// Der Auslöser war eine Abfrage mit „since“ auf dem alten Stand: Die Prüfung steht vor der Abkürzung „unchanged“
if (bot_to_move($after)) {
    usleep(1700000);
    [, $json] = bapi('bottimed', ['action' => 'get', 'room' => $b2, 'since' => $v + 1]);
    check(($json['room']['version'] ?? 0) === $v + 2, 'get mit aktuellem „since“ löst den fälligen Zug aus und liefert ihn mit (auch für Zuschauende)', $json['unchanged'] ?? $json['room']['version']);
}

// ---------------------------------------------------------------------------
section('Trombone-Bots: ganzes Spiel');

foreach ([1, 3] as $nBots) {
    $bg = bot_table('bots', [$h[0]], $nBots);
    [$code, $st] = bot_start('bots', $bg, $h[0], $nBots + 1);
    check($code === 200, "Spiel mit $nBots Bot(s) startet", $st);
    $ok = true;          // jede Abfrage: höchstens ein Zug
    $double = false;     // zwei Abfragen nacheinander mit je einem Bot-Zug gesehen
    $botMoves = $rounds = 0;
    $lastWasBot = false;
    for ($i = 0; $i < 3000 && $st['phase'] !== 'over'; $i++) {
        if ($st['phase'] === 'reveal') {
            [, $json] = bapi('bots', ['action' => 'game', 'room' => $bg, 'op' => 'next', 'round' => $st['round'], 'token' => $h[0]]);
            $st = $json['room']['state'];
            $rounds++;
            $lastWasBot = false;
        } elseif (!bot_to_move($st)) {
            $before = $st;
            [$code, $st] = human_move('bots', $bg, $h[0], $st);
            $ok = $ok && $code === 200 && ($st['phase'] !== 'bidding' || count($st['bids']) === count($before['bids']) + 1);
            $lastWasBot = false;
        } else {
            $before = $st;
            $v = room_data('bots', $bg)['version'];
            [$code, $json] = bapi('bots', ['action' => 'get', 'room' => $bg, 'token' => $h[0]]);
            $st = $json['room']['state'] ?? $st;
            $bid = $st['phase'] === 'bidding' && count($st['bids']) === count($before['bids']) + 1 && end($st['bids'])['seat'] === $before['turn'][0];
            $challenge = $st['phase'] !== 'bidding' && ($st['reveal']['challenger'] ?? null) === $before['turn'][0] && count($st['bids']) === count($before['bids']);
            $ok = $ok && $code === 200 && ($bid || $challenge) && $json['room']['version'] === $v + 1;
            $botMoves++;
            $double = $double || $lastWasBot;
            $lastWasBot = true;
        }
        if (max(array_column($st['players'], 'cards')) > 6
            || ($st['phase'] === 'bidding' && count((array)$st['myHand']) !== array_column($st['players'], 'cards', 'seat')[0])) {
            $ok = false;
        }
    }
    check($st['phase'] === 'over' && $st['loser'] !== null, "Spiel mit $nBots Bot(s) läuft bis zum Ende ($rounds Runden, $botMoves Bot-Züge)", $st['phase']);
    check($ok, "mit $nBots Bot(s): jede Abfrage löst genau einen regelgerechten Bot-Zug aus, Aktionen des Menschen keinen");
    check($nBots === 1 || $double, 'mehrere Bots hintereinander: je Abfrage einer');
    [, $json] = bapi('bots', ['action' => 'get', 'room' => $bg, 'token' => $h[0]]);
    check(count(array_filter($json['room']['seats'], fn($s) => !empty($s['bot']))) === $nBots, 'nach dem Spiel sitzen die Bots noch am Tisch');
}

// ---------------------------------------------------------------------------
section('Trombone-Bots: aufnehmen, entfernen, verwaiste Spiele');

// Menschen auf 0, 1 (spielen mit) und 4 (kommt später dazu), Bots auf 2 und 3; hier ziehen die Bots praktisch nie
$bp = bot_table('botslow', [$h[0], $h[1]], 2);
[$code, $st] = bot_start('botslow', $bp, $h[0], 4);
check($code === 200 && count($st['players']) === 4, 'Spiel mit 2 Menschen und 2 Bots startet', $st);
bapi('botslow', ['action' => 'sit', 'room' => $bp, 'seat' => 4, 'name' => 'Spät', 'token' => $h[2]]);
[$code, $json, $raw] = bapi('botslow', ['action' => 'game', 'room' => $bp, 'op' => 'propose', 'seat' => 4, 'cards' => 2, 'token' => $h[0]]);
check($code === 200 && ($json['room']['state']['proposal']['yes'] ?? null) === [0], 'Vorschlag wartet auf den zweiten Menschen', $json['room']['state']['proposal'] ?? $json);
check(array_keys($json['room']['state']['proposal']) === ['seat', 'name', 'cards', 'by', 'yes'], 'Vorschlag ohne Token', array_keys($json['room']['state']['proposal']));
[$code, $json] = bapi('botslow', ['action' => 'game', 'room' => $bp, 'op' => 'vote', 'yes' => true, 'token' => $h[1]]);
$st = $json['room']['state'] ?? [];
$late = array_values(array_filter($st['players'] ?? [], fn($p) => $p['seat'] === 4))[0] ?? null;
check($code === 200 && $st['proposal'] === null && ($st['proposalResult']['accepted'] ?? false) && !empty($late['joining']) && empty($late['bot']),
    'Abstimmung: die Stimmen der Menschen genügen, Bots stimmen nicht ab', $st['proposal'] ?? $st['proposalResult'] ?? $json);

// kick auf einen mitspielenden Bot: wie remove, mit denselben Rechten
[$code] = bapi('botslow', ['action' => 'kick', 'room' => $bp, 'seat' => 3, 'token' => $h[2]]);
check($code === 403, 'kick auf einen mitspielenden Bot: nicht durch Sitzende, die (noch) nicht mitspielen', $code);
[$code] = bapi('botslow', ['action' => 'kick', 'room' => $bp, 'seat' => 3, 'token' => token()]);
check($code === 403, '… und nicht durch Zuschauende', $code);
[, $json] = bapi('botslow', ['action' => 'get', 'room' => $bp, 'token' => $h[0]]);
check(($json['room']['seats'][3]['bot'] ?? null) === true, 'der Bot sitzt danach noch');
[$code, $json] = bapi('botslow', ['action' => 'kick', 'room' => $bp, 'seat' => 3, 'token' => $h[1]]);
$st = $json['room']['state'] ?? [];
$p3 = array_values(array_filter($st['players'] ?? [], fn($p) => $p['seat'] === 3))[0] ?? [];
check($code === 200 && $json['room']['seats'][3] === null && ($p3['out'] ?? null) === true && ($p3['bot'] ?? null) === true && $st['phase'] === 'bidding',
    'kick im laufenden Spiel: Bot ist raus (bleibt als Bot im Verlauf erkennbar), Platz ist frei', $p3);

// remove: der Bot ist raus, bleibt aber sitzen; wieder aufgenommen ist er weiter ein Bot
[$code] = bapi('botslow', ['action' => 'game', 'room' => $bp, 'op' => 'remove', 'seat' => 2, 'token' => $h[2]]);
check($code === 403, 'remove eines Bots nur durch aktive Mitspielende', $code);
[$code, $json] = bapi('botslow', ['action' => 'game', 'room' => $bp, 'op' => 'remove', 'seat' => 2, 'token' => $h[0]]);
$st = $json['room']['state'] ?? [];
$p2 = array_values(array_filter($st['players'] ?? [], fn($p) => $p['seat'] === 2))[0] ?? [];
check($code === 200 && ($p2['out'] ?? null) === true && ($json['room']['seats'][2]['bot'] ?? null) === true, 'remove eines Bots: raus aus dem Spiel, sitzt aber noch', $p2);
check($st['phase'] === 'bidding' && $st['bids'] === [] && !bot_to_move($st) && !array_key_exists('botDue', room_data('botslow', $bp)['state']),
    'danach sind nur noch Menschen in der Runde: keine Denkpause', $st['turn'] ?? $st);
[$code, $json] = bapi('botslow', ['action' => 'game', 'room' => $bp, 'op' => 'propose', 'seat' => 2, 'cards' => 3, 'token' => $h[1]]);
check($code === 200 && ($json['room']['state']['proposal']['bot'] ?? null) === true && !str_contains(json_encode($json), 'token'), 'Vorschlag, den Bot wieder aufzunehmen: mit Kennzeichen, ohne Token', $json['room']['state']['proposal'] ?? $json);
[$code, $json] = bapi('botslow', ['action' => 'game', 'room' => $bp, 'op' => 'vote', 'yes' => true, 'token' => $h[0]]);
$p2 = array_values(array_filter($json['room']['state']['players'] ?? [], fn($p) => $p['seat'] === 2))[0] ?? [];
check($code === 200 && ($p2['bot'] ?? null) === true && !empty($p2['joining']) && $p2['cards'] === 3 && ($json['room']['state']['proposalResult']['bot'] ?? null) === true,
    'wieder aufgenommener Bot bleibt ein Bot', $p2);

// Mit dem Entfernen eines anderen beginnen die Gebote neu; ist dann ein Bot am Zug, beginnt seine Denkpause
$br = bot_table('botslow', [$h[0], $h[1]], 1);
[, $st] = bot_start('botslow', $br, $h[0], 3);
for ($i = 0; $i < 2 && !bot_to_move($st); $i++) {   // bis der Bot am Zug ist (höchstens zwei Menschen vor ihm)
    $tok = $h[$st['turn'][0]];
    [, $json] = bapi('botslow', ['action' => 'game', 'room' => $br, 'op' => 'bid', 'bid' => ['t' => 0, 'a' => 2 + count($st['bids'])], 'deal' => $st['deal'], 'token' => $tok]);
    $st = $json['room']['state'];
}
$turnBefore = room_data('botslow', $br)['state']['botTurn'] ?? null;   // für welchen Zug die Denkpause gilt
$order = array_column($st['players'], 'seat');
$starterIsBot = $st['starter'] === 2;
// die Person entfernen, die nicht beginnt (oder irgendeinen Menschen, wenn der Bot beginnt)
$victim = $starterIsBot ? 1 : 1 - $st['starter'];
[$code, $json] = bapi('botslow', ['action' => 'game', 'room' => $br, 'op' => 'remove', 'seat' => $victim, 'token' => $h[1 - $victim]]);
$st = $json['room']['state'] ?? [];
$fileState = room_data('botslow', $br)['state'];
check($code === 200 && $st['bids'] === [] && $st['turn'] === [$st['starter']], 'remove eines Menschen: Gebote beginnen neu', $st);
check(bot_to_move($st) ? isset($fileState['botDue']) && ($fileState['botTurn'] ?? null) !== $turnBefore : !array_key_exists('botDue', $fileState),
    'Denkpause nach dem Neubeginn: neu, wenn der Bot beginnt, sonst keine', [$starterIsBot, $turnBefore, $fileState['botTurn'] ?? null]);
// Ein Mensch und ein Bot spielen weiter; steht der Mensch auf und setzt sich jemand anderes, ist das Spiel verwaist
bapi('botslow', ['action' => 'stand', 'room' => $br, 'token' => $h[1 - $victim]]);
[, $json] = bapi('botslow', ['action' => 'sit', 'room' => $br, 'seat' => 5, 'name' => 'Neu', 'token' => $h[2]]);
check($json['room']['state']['orphaned'] === true, 'Spiel, in dem nur noch der Bot sitzt, gilt als verwaist (Bots zählen nicht als anwesend)');
[$code, $json] = bapi('botslow', ['action' => 'game', 'room' => $br, 'op' => 'lobby', 'token' => $h[2]]);
check($code === 200 && $json['room']['state']['phase'] === 'lobby', '… und lässt sich von Sitzenden abbrechen', $json);
// Entfernen des letzten Gegners beendet das Spiel (auch wenn es ein Bot ist)
$bo = bot_table('botslow', [$h[0]], 1);
bot_start('botslow', $bo, $h[0], 2);
[$code, $json] = bapi('botslow', ['action' => 'kick', 'room' => $bo, 'seat' => 1, 'token' => $h[0]]);
check($code === 200 && $json['room']['state']['phase'] === 'over' && $json['room']['seats'][1] === null, 'kick des einzigen Bots beendet das Spiel', $json['room']['state']['phase'] ?? $json);

// ---------------------------------------------------------------------------
section('Trombone-Bots: im laufenden Spiel aufnehmen');

$bm = bot_table('botslow', [$h[0], $h[1]], 1);
[, $st] = bot_start('botslow', $bm, $h[0], 3);
[$code, $json] = bapi('botslow', ['action' => 'bot', 'op' => 'add', 'room' => $bm, 'seat' => 3, 'token' => $h[2]]);
check($code === 403, 'Zuschauende können auch im Spiel keinen Bot hinsetzen', $code);
[$code, $json] = bapi('botslow', ['action' => 'bot', 'op' => 'add', 'room' => $bm, 'seat' => 3, 'token' => $h[0]]);
check($code === 200 && ($json['room']['seats'][3]['bot'] ?? null) === true && count($json['room']['state']['players']) === 3,
    'im laufenden Spiel: Bot sitzt am Tisch, spielt aber noch nicht mit', $code);
[$code, $json] = bapi('botslow', ['action' => 'game', 'room' => $bm, 'op' => 'propose', 'seat' => 3, 'cards' => 2, 'token' => $h[0]]);
$prop = $json['room']['state']['proposal'] ?? null;
check($code === 200 && $prop && ($prop['bot'] ?? null) === true && $prop['yes'] === [0], 'Vorschlag für den Bot wartet auf den zweiten Menschen (nicht auf den Bot am Tisch)', $prop);
[$code, $json] = bapi('botslow', ['action' => 'game', 'room' => $bm, 'op' => 'vote', 'yes' => false, 'token' => $h[1]]);
check($code === 200 && $json['room']['seats'][3] === null && $json['room']['state']['proposal'] === null, 'Ablehnung: der neue Bot verschwindet wieder vom Tisch', $json['room']['seats']);
bapi('botslow', ['action' => 'bot', 'op' => 'add', 'room' => $bm, 'seat' => 3, 'token' => $h[0]]);
bapi('botslow', ['action' => 'game', 'room' => $bm, 'op' => 'propose', 'seat' => 3, 'cards' => 2, 'token' => $h[0]]);
[$code, $json] = bapi('botslow', ['action' => 'game', 'room' => $bm, 'op' => 'vote', 'yes' => true, 'token' => $h[1]]);
$np = array_column($json['room']['state']['players'] ?? [], null, 'seat')[3] ?? null;
check($code === 200 && $np && ($np['bot'] ?? null) === true && $np['cards'] === 2 && $json['room']['state']['proposal'] === null,
    'Zustimmung aller Menschen: der Bot ist aufgenommen', $np);
check(hash_equals(room_data('botslow', $bm)['seats'][3]['token'], array_column(room_data('botslow', $bm)['state']['players'], null, 'seat')[3]['token']),
    'der aufgenommene Bot zieht mit seinem Server-Token');
// Ein Mensch allein mit Bots: niemand sonst muss zustimmen
$bm1 = bot_table('botslow', [$h[0]], 1);
bot_start('botslow', $bm1, $h[0], 2);
bapi('botslow', ['action' => 'bot', 'op' => 'add', 'room' => $bm1, 'seat' => 2, 'token' => $h[0]]);
[$code, $json] = bapi('botslow', ['action' => 'game', 'room' => $bm1, 'op' => 'propose', 'seat' => 2, 'cards' => 1, 'token' => $h[0]]);
check($code === 200 && count($json['room']['state']['players']) === 3 && $json['room']['state']['proposal'] === null, 'einziger Mensch: Bot sofort aufgenommen', $code);
// Ein Bot, der am Tisch sitzt und nicht mitspielt, lässt sich auch im Spiel wegnehmen
bapi('botslow', ['action' => 'bot', 'op' => 'add', 'room' => $bm1, 'seat' => 3, 'token' => $h[0]]);
[$code, $json] = bapi('botslow', ['action' => 'bot', 'op' => 'remove', 'room' => $bm1, 'seat' => 3, 'token' => $h[0]]);
check($code === 200 && $json['room']['seats'][3] === null, 'wartender Bot im laufenden Spiel entfernt', $code);

// ---------------------------------------------------------------------------
section('Trombone-Bots: Grenzen und Schutz vor Hängern');

// Bots insgesamt in der Instanz
$cap1 = bot_table('botcap', [$h[0]], 2);
$cap2 = bot_table('botcap', [$h[1]], 1);
[$code, $json] = bapi('botcap', ['action' => 'bot', 'op' => 'add', 'room' => $cap2, 'seat' => 2, 'token' => $h[1]]);
check($code === 429 && str_contains($json['error'] ?? '', 'keine weiteren Bots'), 'max_bots_total: vierter Bot der Instanz abgelehnt', [$code, $json]);
bapi('botcap', ['action' => 'bot', 'op' => 'remove', 'room' => $cap1, 'seat' => 1, 'token' => $h[0]]);
[$code] = bapi('botcap', ['action' => 'bot', 'op' => 'add', 'room' => $cap2, 'seat' => 2, 'token' => $h[1]]);
check($code === 200, 'max_bots_total: nach dem Entfernen wieder Platz', $code);
// Bots pro Absender (alle Testanfragen kommen von derselben Adresse)
$cap3 = bot_table('botmine', [$h[0]], 2);
$cap4 = bot_table('botmine', [$h[1]], 0);
[$code] = bapi('botmine', ['action' => 'bot', 'op' => 'add', 'room' => $cap4, 'seat' => 1, 'token' => $h[1]]);
check($code === 429, 'max_bots_per_client: dritter Bot desselben Absenders abgelehnt (auch an einem anderen Tisch)', $code);
$seatData = room_data('botmine', $cap3)['seats'][1];
check(preg_match('/^[0-9a-f]{32}$/', $seatData['by'] ?? '') === 1, 'Tischdatei merkt sich pseudonym, wer den Bot hingesetzt hat', $seatData);
[, $json, $raw] = bapi('botmine', ['action' => 'get', 'room' => $cap3, 'token' => $h[0]]);
check(!str_contains($raw, $seatData['by']) && !isset($json['room']['seats'][1]['by']), '… ausgeliefert wird das nie');

// Budget an Bot-Zügen: Ist es erschöpft, zieht der Bot nicht – die Abfrage läuft normal weiter
$bb = bot_table('botbudget', [$h[0]], 1);
[, $st] = bot_start('botbudget', $bb, $h[0], 2);
$toBot = function (string $inst, string $id, string $tok, array $st): array {
    for ($i = 0; $i < 8 && !bot_to_move($st); $i++) {
        if ($st['phase'] === 'reveal') {
            [, $json] = bapi($inst, ['action' => 'game', 'room' => $id, 'op' => 'next', 'round' => $st['round'], 'token' => $tok]);
            $st = $json['room']['state'];
        } else {
            [, $st] = human_move($inst, $id, $tok, $st);
        }
    }
    return $st;
};
$st = $toBot('botbudget', $bb, $h[0], $st);
$v = room_data('botbudget', $bb)['version'];
[$code, $json] = bapi('botbudget', ['action' => 'get', 'room' => $bb, 'token' => $h[0]]);
check($code === 200 && $json['room']['version'] === $v + 1, 'Budget: der erste Bot-Zug wird ausgeführt', [$code, $json['room']['version'] ?? null, $v]);
$st = $toBot('botbudget', $bb, $h[0], $json['room']['state']);
check(bot_to_move($st), 'Budget: der Bot ist wieder am Zug', $st['phase']);
$v = room_data('botbudget', $bb)['version'];
for ($i = 0; $i < 3; $i++) {
    [$code, $json] = bapi('botbudget', ['action' => 'get', 'room' => $bb, 'token' => $h[0]]);
}
check($code === 200 && $json['room']['version'] === $v, 'Budget erschöpft: der Bot wartet, die Abfrage antwortet normal', [$code, $json['room']['version'] ?? null, $v]);

// Beschädigte Gewichte (NaN): Der Bot zieht den Ersatzzug, das Spiel bleibt nicht hängen
$bn2 = bot_table('botnan', [$h[0]], 1);
[, $st] = bot_start('botnan', $bn2, $h[0], 2);
$st = $toBot('botnan', $bn2, $h[0], $st);
$had = count($st['bids']);
[$code, $json] = bapi('botnan', ['action' => 'get', 'room' => $bn2, 'token' => $h[0]]);
$now = $json['room']['state'] ?? [];
check($code === 200 && (($now['phase'] ?? '') !== 'bidding' || count($now['bids']) === $had + 1) && !bot_to_move($now),
    'NaN-Gewichte: Abfrage gelingt und der Bot hat trotzdem gezogen (Ersatzzug)', [$code, $now['phase'] ?? null]);
check($had > 0 ? in_array($now['phase'], ['reveal', 'over'], true) : ($now['bids'][0]['bid'] ?? null) == ['t' => 0, 'a' => 7, 'b' => null],
    'Ersatzzug: anzweifeln bzw. ohne Gebot das niedrigste Gebot', $now['bids'] ?? null);

// Spielt kein Mensch mehr mit, der auch am Tisch sitzt, ziehen die Bots nicht weiter
$bo = bot_table('bots', [$h[0], $h[1]], 1);
[, $st] = bot_start('bots', $bo, $h[0], 3);
bapi('bots', ['action' => 'game', 'room' => $bo, 'op' => 'remove', 'seat' => 1, 'token' => $h[0]]);   // H1 spielt nicht mehr mit, sitzt aber noch
[, $json] = bapi('bots', ['action' => 'sit', 'room' => $bo, 'seat' => 0, 'name' => 'H0', 'token' => $h[0]]);
$st = $toBot('bots', $bo, $h[0], $json['room']['state']);
check(bot_to_move($st) && isset(room_data('bots', $bo)['state']['botDue']), 'Bot am Zug, Denkpause läuft', $st['phase']);
bapi('bots', ['action' => 'stand', 'room' => $bo, 'token' => $h[0]]);                                   // der letzte mitspielende Mensch steht auf
$file = room_data('bots', $bo);
check(!isset($file['state']['botDue']), 'ohne mitspielenden Menschen am Tisch keine Denkpause mehr', array_keys($file['state']));
for ($i = 0; $i < 3; $i++) {
    [$code, $json] = bapi('bots', ['action' => 'get', 'room' => $bo, 'token' => $h[1]]);
}
check($code === 200 && $json['room']['version'] === $file['version'] && $json['room']['state']['orphaned'] === true,
    '… die Bots ziehen nicht weiter, und wer am Tisch sitzt, darf abbrechen', [$code, $json['room']['version'] ?? null]);
bapi('bots', ['action' => 'sit', 'room' => $bo, 'seat' => 0, 'name' => 'H0', 'token' => $h[0]]);
[, $json] = bapi('bots', ['action' => 'get', 'room' => $bo, 'token' => $h[0]]);
check($json['room']['version'] > $file['version'] + 1, 'setzt sich der Mensch wieder, zieht der Bot weiter', $json['room']['version']);

// ---------------------------------------------------------------------------
section('Trombone-Bots: ohne passende Gewichtsdatei oder ausgeschaltet');

foreach (['nonet' => 'Gewichtsdatei fehlt', 'badnet' => 'Gewichtsdatei passt nicht', 'nobots' => 'bots: false'] as $inst => $what) {
    $bx = bot_table($inst, [$h[0], $h[1]], 1);
    [$code, $json] = bapi($inst, ['action' => 'bot', 'op' => 'add', 'room' => $bx, 'seat' => 3, 'token' => $h[0]]);
    check($code === 403 && ($json['error'] ?? '') === 'Hier gibt es keine Bots', "$what: kein Bot", [$code, $json]);
    [, $json] = bapi($inst, ['action' => 'get', 'room' => $bx, 'token' => $h[0]]);
    check($json['room']['maxBots'] === 0 && count(array_filter($json['room']['seats'])) === 2, "$what: Tisch bietet keine Bots an", $json['room']['seats']);
    [$code, $st] = bot_start($inst, $bx, $h[0], 2);
    check($code === 200 && $st['phase'] === 'bidding', "$what: Trombone läuft trotzdem", $st);
    [$code, $st] = human_move($inst, $bx, $h[$st['turn'][0]], $st);
    check($code === 200 && count($st['bids']) === 1, "$what: Bieten geht", $st);
    [$code, $json] = bapi($inst, ['action' => 'list']);
    check($code === 200 && ($json['rooms'][0]['bots'] ?? null) === [], "$what: Tischliste geht", $json);
}

// Die Gewichtsdatei verschwindet (z. B. beim Hochladen einer neuen): Bots ziehen nicht, alles andere geht weiter
$bv = bot_table('vanish', [$h[0]], 1);
rename("$tmp/net-vanish.bin", "$tmp/net-vanish.weg");
[$code, $st] = bot_start('vanish', $bv, $h[0], 2);
check($code === 409 && str_contains($st['error'] ?? '', 'Bots'), 'Start mit Bots ohne Gewichtsdatei wird abgelehnt (das Spiel bliebe hängen)', $st);
[$code] = bapi('vanish', ['action' => 'bot', 'op' => 'add', 'room' => $bv, 'seat' => 2, 'token' => $h[0]]);
check($code === 403, '… weitere Bots gibt es dann nicht', $code);
rename("$tmp/net-vanish.weg", "$tmp/net-vanish.bin");
[$code, $st] = bot_start('vanish', $bv, $h[0], 2);
check($code === 200, 'mit Gewichtsdatei startet das Spiel', $st);
if (!bot_to_move($st)) {
    [, $st] = human_move('vanish', $bv, $h[0], $st);
}
rename("$tmp/net-vanish.bin", "$tmp/net-vanish.weg");
$v = room_data('vanish', $bv)['version'];
[$code, $json] = bapi('vanish', ['action' => 'get', 'room' => $bv, 'token' => $h[0]]);
check($code === 200 && $json['room']['version'] === $v && bot_to_move($json['room']['state']), 'Gewichtsdatei weg: Abfrage geht, der Bot zieht nicht', [$code, $json['room']['version'] ?? $json]);
[$code, $json] = bapi('vanish', ['action' => 'get', 'room' => $bv, 'token' => $h[0], 'since' => $v]);
check($code === 200 && ($json['unchanged'] ?? false) === true, '… und es wird nichts geschrieben', $json);
[$code, $json] = bapi('vanish', ['action' => 'game', 'room' => $bv, 'op' => 'remove', 'seat' => 1, 'token' => $h[0]]);
check($code === 200 && $json['room']['state']['phase'] === 'over', '… Menschen können den Bot aus dem Spiel nehmen', $json);
[$code, $json] = bapi('vanish', ['action' => 'bot', 'op' => 'remove', 'room' => $bv, 'seat' => 1, 'token' => $h[0]]);
check($code === 200 && $json['room']['seats'][1] === null, '… und vom Tisch (auch ohne Gewichtsdatei)', $json);
rename("$tmp/net-vanish.weg", "$tmp/net-vanish.bin");
[, $json] = bapi('vanish', ['action' => 'bot', 'op' => 'add', 'room' => $bv, 'seat' => 1, 'token' => $h[0]]);
[$code, $st] = bot_start('vanish', $bv, $h[0], 2);
for ($i = 0; $i < 20 && $code === 200 && bot_to_move($st); $i++) {
    [$code, $json] = bapi('vanish', ['action' => 'get', 'room' => $bv, 'token' => $h[0]]);
    $st = $json['room']['state'];
}
check($code === 200 && !bot_to_move($st), 'ist die Gewichtsdatei wieder da, ziehen die Bots wieder', $st['turn'] ?? $st);

// Bot-Token: in keiner einzigen Antwort dieser Tests
$botTokens = [];
foreach (array_keys($instances) as $inst) {
    foreach (glob("$tmp/data/$inst/room-*.php") ?: [] as $f) {
        $data = (array)json_decode(substr((string)file_get_contents($f), strlen(GUARD_LINE)), true);
        foreach (array_merge($data['seats'] ?? [], $data['state']['players'] ?? []) as $x) {
            if (is_array($x) && str_starts_with((string)($x['token'] ?? ''), 'bot-')) {
                $botTokens[$x['token']] = true;
            }
        }
    }
}
check(count($botTokens) >= 10, 'Bot-Token stehen (nur) in den Tischdateien', count($botTokens));
check(!array_filter(array_keys($botTokens), fn($tok) => str_contains($botRaw, $tok) || str_contains($botRaw, substr($tok, 4))), 'kein Bot-Token in irgendeiner Antwort');
check(!preg_match('/bot-[0-9a-f]{8}/', $botRaw) && !str_contains($botRaw, '"token"') && !str_contains($botRaw, 'botDue'), 'Antworten enthalten weder Token-Felder noch etwas in Bot-Token-Form');
check(!str_contains($botRaw, $h[0]) && !str_contains($botRaw, $h[1]) && !str_contains($botRaw, $h[2]), '… und keine Tokens der Menschen');

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
$mk('aaaaaa05', [['name' => '🐸', 'token' => 'bot-x', 'bot' => true], null], 7200);   // nur ein Bot: wie leer → weg
$mk('aaaaaa06', [['name' => '🐸', 'token' => 'bot-x', 'bot' => true], $seated[0]], 7200);   // Bot und Mensch → bleibt
GameNight\cleanup();
$left = array_map(fn($f) => substr(basename($f), 5, 8), glob("$dir/tables/*"));
sort($left);
check($left === ['aaaaaa02', 'aaaaaa03', 'aaaaaa06'], 'Aufräumen nach empty_room_ttl und room_ttl (Tische nur mit Bots gelten als leer)', $left);

$c = GameNight\instance_config(['games' => ['quatsch'], 'max_rooms' => -5, 'requests_per_minute' => -1, 'unbekannt' => 1], "$dir/x", $dir);
check($c['games'] === array_values(array_diff(array_keys(GameNight\GAMES), GameNight\WIP_GAMES)), 'unbekannte Spiele → alle fertigen Spiele', $c['games']);
check(!in_array('konferenz', GameNight\instance_config([], "$dir/x", $dir)['games'], true), 'Spiele in Arbeit sind standardmäßig aus');
check(in_array('konferenz', GameNight\instance_config(['games' => ['konferenz']], "$dir/x", $dir)['games'], true), '… lassen sich aber ausdrücklich einschalten');
check(in_array('zahlenkampf', GameNight\instance_config([], "$dir/x", $dir)['games'], true), 'Zahlenkampfspiel ist standardmäßig an');

// Trombone-Bots: Einstellungen; Gewichtsdatei und Denkpause sind nicht einstellbar
$cb = GameNight\instance_config([], "$dir/x", $dir);
check($cb['bots'] === true && $cb['max_bots'] === 7 && $cb['bot_delay'] === [2000, 4000] && $cb['net_file'] === dirname(__DIR__) . '/core/trombone-net.bin',
    'Bots: Voreinstellungen', [$cb['bots'], $cb['max_bots'], $cb['bot_delay'], $cb['net_file']]);
$cb = GameNight\instance_config(['bots' => 0, 'max_bots' => 99, 'net_file' => '/etc/passwd', 'bot_delay' => [0, 0]], "$dir/x", $dir);
check($cb['bots'] === false && $cb['max_bots'] === 7 && $cb['bot_delay'] === [2000, 4000] && str_ends_with($cb['net_file'], '/core/trombone-net.bin'),
    'Bots: höchstens 7 pro Tisch; net_file und bot_delay kommen nie aus den Einstellungen', $cb);
check(GameNight\instance_config(['max_bots' => -3], "$dir/x", $dir)['max_bots'] === 0, 'Bots: negative Obergrenze → 0');
GameNight\cfg(['net_file' => "$tmp/net.bin"] + GameNight\instance_config([], "$dir/tables", $dir));
check(GameNight\tbot_offered() === 7, 'mit Gewichtsdatei: 7 Bots pro Tisch');
GameNight\cfg(['max_bots' => 0] + GameNight\cfg());
check(GameNight\tbot_offered() === 0, 'max_bots 0: keine Bots');
GameNight\cfg(['max_bots' => 7, 'net_file' => "$tmp/net-bad.bin"] + GameNight\cfg());
check(GameNight\tbot_offered() === 0, 'unpassende Gewichtsdatei: keine Bots');
check(count(GameNight\TBOT_EMOJIS) >= 16 && count(array_unique(array_map('GameNight\\name_key', GameNight\TBOT_EMOJIS))) === count(GameNight\TBOT_EMOJIS)
    && !array_filter(GameNight\TBOT_EMOJIS, fn($e) => preg_match('/^.$/u', $e) !== 1), 'mindestens 16 verschiedene Tiere, je ein Zeichen');
// Sind alle Tiere schon vergeben (über die API nicht erreichbar: 8 Plätze), gibt es trotzdem nie einen doppelten Namen
$full = ['game' => 'trombone', 'state' => ['players' => []], 'seats' => array_map(fn($e) => ['name' => $e, 'token' => 'x'], GameNight\TBOT_EMOJIS)];
$name = GameNight\tbot_seat_name($full);
check(preg_match('/^. 2$/u', $name) === 1, 'alle Tiere vergeben: Bot-Name wird durchnummeriert', $name);
$full['seats'] = [['name' => 'A', 'token' => 'x'], null];
$full['state']['players'] = array_map(fn($e) => ['seat' => 5, 'name' => $e, 'token' => 'y'], array_slice(GameNight\TBOT_EMOJIS, 1));
check(GameNight\tbot_seat_name($full) === GameNight\TBOT_EMOJIS[0], 'Bot-Name auch verschieden von Mitspielenden im Spielstand, die nicht mehr sitzen');
// tb_act lehnt ein Spiel nur aus Bots ab. Über die API ist das nicht erreichbar (starten kann nur, wer sitzt);
// fail() beendet den Prozess, deshalb in einem eigenen.
$code = 'define("GAME_NIGHT", 1); require ' . var_export(dirname(__DIR__) . '/core/Api.php', true) . '; GameNight\\lang("de");'
    . '$bot = fn($n) => ["name" => $n, "token" => "bot-" . $n, "bot" => true];'
    . '$room = ["game" => "trombone", "seats" => [$bot("a"), $bot("b"), null], "state" => GameNight\\tb_init()];'
    . 'GameNight\\tb_act($room, 0, "bot-a", "start", ["players" => 2]); echo "gestartet";';
$outLines = [];
exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $outLines);
check(implode("\n", $outLines) === '{"error":"Mindestens ein Mensch muss mitspielen"}', 'tb_act: Start ohne Menschen schlägt fehl', $outLines);
GameNight\cfg(GameNight\instance_config(['empty_room_ttl_hours' => 1, 'room_ttl_days' => 7], "$dir/tables", $dir));

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

require __DIR__ . '/konferenz-test.php';

// ---------------------------------------------------------------------------
// Meldungen beim Start von PHP („in Unknown on line 0“, z. B. zu Erweiterungen) kommen von der Umgebung, nicht vom Plugin
$log = preg_replace('/^.*PHP \w+:.* in Unknown on line 0\R?/m', '', (string)@file_get_contents("$tmp/server.log"));
check(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $log), 'keine PHP-Warnungen im Serverlog',
    implode("\n", array_slice(preg_grep('/PHP (Warning|Notice|Fatal|Deprecated)/', explode("\n", $log)), 0, 5)));

echo "\n$checks Prüfungen, " . ($failures ? "$failures FEHLGESCHLAGEN" : 'alle bestanden') . "\n";
exit($failures ? 1 : 0);
