<?php
/*
 * Game Night – Tests für den Trombone-Bot (core/TromboneBot.php) ohne Server:
 * Abgleich von Regeln, Rechner, Merkmalen, Zählern, Maske und Netz mit der Python-Seite (training/tz/)
 * über die Referenzdatei tests/ref/trombone-ref.jsonl, dazu Gewichtsdatei, Geheimhaltung und Laufzeit.
 *
 * Aufruf: php tests/bot-test.php                    (Exit-Code 0 = alles in Ordnung)
 *         php tests/bot-test.php gen <Seed> <Spiele> [Netzdatei]
 *                                                   schreibt Zufallsspiele als JSONL (für training/tests/)
 *
 * Format der Referenzspiele (eine Zeile pro Spiel):
 *   {deck, order: [Plätze], rounds: [{hands: {Platz: [Karten]}, turns: [{seat, act, mask, x?, p?}],
 *    true, loser, over, cards: {Platz: n}, cred: {Platz: [11 Zähler]}}]}
 *   act: Aktionsindex; mask: 313 Zeichen 0/1; x: Merkmale ungleich null als [Index, Wert];
 *   p: Verteilung des Testnetzes als [Index, Wert].
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

define('GAME_NIGHT', 1);
require dirname(__DIR__) . '/core/Api.php';

use function GameNight\{tb_init, tb_act, tb_deal, tb_test_decks, tb_view, tbot_bids, tbot_features, tbot_mask,
    tbot_net, tbot_net_info, tbot_policy, tbot_decide, tbot_forward, tbot_prob};

const REF_MU = [0.40, 0.38, 0.36, 0.20, 0.55, 0.45];
const REF_K = 5.0;
const REF_DIR = __DIR__ . '/ref';

/** Tisch mit den Plätzen $order (in dieser Zugreihenfolge) und gestartetem Spiel, noch ohne Karten. */
function ref_room(string $deck, array $order): array
{
    $seats = array_fill(0, 8, null);
    foreach ($order as $seat) {
        $seats[$seat] = ['name' => "P$seat", 'token' => str_repeat(dechex($seat), 32)];
    }
    $room = ['game' => 'trombone', 'seats' => $seats, 'state' => tb_init()];
    $room['state']['deck'] = $deck;
    $first = $order[0];
    tb_test_decks([array_fill(0, 60, 'X')]);
    tb_act($room, $first, $seats[$first]['token'], 'start', ['players' => count($order)]);
    // Reihenfolge wie vorgegeben (start lost sie aus)
    $bySeat = array_column($room['state']['players'], null, 'seat');
    $room['state']['players'] = array_map(fn($s) => $bySeat[$s], $order);
    $room['state']['starter'] = $first;
    $room['state']['round'] = 0;
    $room['state']['phase'] = 'reveal';
    return $room;
}

/** Runde mit vorgegebenen Händen austeilen. */
function ref_deal(array &$room, array $hands): void
{
    $deck = [];
    foreach (GameNight\tb_active($room['state']) as $p) {
        $deck = array_merge($deck, $hands[$p['seat']]);
    }
    tb_test_decks([$deck]);
    tb_deal($room['state']);
}

function ref_act(array &$room, int $seat, int $act): void
{
    $in = ['deal' => $room['state']['deal']];
    if ($act > 0) {
        [$t, $a, $b] = tbot_bids()[$act - 1];
        $in['bid'] = ['t' => $t, 'a' => $a, 'b' => $b ?: null];
    }
    tb_act($room, $seat, $room['seats'][$seat]['token'], $act > 0 ? 'bid' : 'challenge', $in);
}

function sparse(array $x): array
{
    $out = [];
    foreach ($x as $i => $v) {
        if ($v != 0.0) {
            $out[] = [$i, $v];
        }
    }
    return $out;
}

function mask_string(array $mask): string
{
    return implode('', array_map(fn($m) => $m ? '1' : '0', $mask));
}

/** Zufällige Hände für die aktiven Plätze. */
function random_hands(array $s): array
{
    $cards = ['X'];
    if ($s['deck'] === '52') {
        $cards[] = 'X';
    }
    foreach (GameNight\tb_ranks($s['deck']) as $r) {
        foreach (['S', 'H', 'D', 'C'] as $c) {
            $cards[] = $r . $c;
        }
    }
    for ($i = count($cards) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$cards[$i], $cards[$j]] = [$cards[$j], $cards[$i]];
    }
    $hands = [];
    foreach (GameNight\tb_active($s) as $p) {
        $hands[$p['seat']] = array_splice($cards, 0, $p['cards']);
    }
    return $hands;
}

// ---------------------------------------------------------------------------
// Zufallsspiele erzeugen (für den Abgleich in training/tests/)

if (($argv[1] ?? '') === 'gen') {
    mt_srand((int)($argv[2] ?? 1));
    $net = isset($argv[4]) ? tbot_net($argv[4]) : null;
    $mu = $net ? $net['mu'] : REF_MU;
    $k = $net ? $net['k'] : REF_K;
    for ($g = 0; $g < (int)($argv[3] ?? 20); $g++) {
        $n = mt_rand(2, 8);
        $deck = $n === 2 ? '32' : ($n >= 4 ? '52' : (mt_rand(0, 1) ? '32' : '52'));
        $order = range(0, 7);
        shuffle($order);
        $order = array_slice($order, 0, $n);
        $room = ref_room($deck, $order);
        $s = &$room['state'];
        $game = ['deck' => $deck, 'order' => $order, 'rounds' => []];
        while ($s['phase'] !== 'over') {
            $hands = random_hands($s);
            ref_deal($room, $hands);
            $round = ['hands' => $hands, 'turns' => []];
            while ($s['phase'] === 'bidding') {
                $seat = $s['turn'][0];
                $mask = tbot_mask($s, $seat);
                $turn = ['seat' => $seat, 'mask' => mask_string($mask), 'x' => sparse(tbot_features($s, $seat, $mu, $k))];
                if ($net) {
                    $turn['p'] = sparse(tbot_policy($s, $seat, $net));
                }
                // meist eine erlaubte Aktion, manchmal irgendeine regelgerechte (auch unmögliche Gebote)
                $last = $s['bids'] ? GameNight\tbot_bid_index(end($s['bids'])['bid']) : 0;
                $pool = [];
                if (mt_rand(0, 9) < 8) {
                    $pool = array_keys(array_filter($mask));
                } else {
                    foreach (tbot_bids() as $i => $bid) {
                        if ($i + 1 > $last && GameNight\tb_valid_bid(['t' => $bid[0], 'a' => $bid[1], 'b' => $bid[2] ?: null], $deck)) {
                            $pool[] = $i + 1;
                        }
                    }
                    if ($last) {
                        $pool[] = 0;
                    }
                }
                $bidsOnly = array_values(array_filter($pool));
                if (in_array(0, $pool, true) && (!$bidsOnly || mt_rand(0, 99) < 22)) {
                    $act = 0;
                } else {
                    $act = $bidsOnly[min(count($bidsOnly) - 1, mt_rand(0, mt_rand(0, 25)))];   // eher niedrige Gebote
                }
                $turn['act'] = $act;
                $round['turns'][] = $turn;
                ref_act($room, $seat, $act);
            }
            $round += ['true' => $s['reveal']['true'], 'loser' => $s['reveal']['loser'], 'over' => $s['over'],
                       'cards' => array_column($s['players'], 'cards', 'seat'), 'cred' => $s['cred']];
            $game['rounds'][] = $round;
        }
        unset($s);
        echo json_encode($game), "\n";
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Tests

$failures = 0;
$checks = 0;

function check(bool $ok, string $what, $detail = null): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
        if ($failures <= 40) {
            echo "  ✗ $what" . ($detail !== null ? ' — ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) : '') . "\n";
        }
    }
}

function section(string $name): void
{
    echo "• $name\n";
}

/** Testnetz in eine Datei schreiben (Format siehe tbot_net_info). */
function write_net(string $file, array $sizes, array $opt = []): void
{
    mt_srand($opt['seed'] ?? 7);
    $meta = json_encode(['name' => 'Testnetz']);
    $bin = ($opt['magic'] ?? 'TBN1') . pack('v3', $opt['format'] ?? GameNight\TBOT_FORMAT_VERSION, $opt['features'] ?? GameNight\TBOT_FEATURE_VERSION, count($sizes) - 1)
        . pack('v*', ...$sizes) . pack('g*', REF_K, ...REF_MU) . pack('V', strlen($meta)) . $meta;
    for ($l = 0; $l < count($sizes) - 1; $l++) {
        $n = $sizes[$l] * $sizes[$l + 1] + $sizes[$l + 1];
        $w = [];
        for ($i = 0; $i < $n; $i++) {
            $w[] = (mt_rand() / mt_getrandmax() - 0.5) * 0.4;
        }
        $bin .= pack('g*', ...$w);
    }
    file_put_contents($file, substr($bin, 0, strlen($bin) - ($opt['cut'] ?? 0)));
}

section('Rechner: einfache Fälle');
// 52er-Deck, 1 eigene Karte (ein Ass), 1 fremde Karte: Paar Asse braucht eines von 3 Assen + 2 Jokern aus 53
check(tbot_prob('52', 1, 1, 1, 0, 0, 2, 0) === [5, 53], 'Paar Asse mit einem Ass gegen eine Karte', tbot_prob('52', 1, 1, 1, 0, 0, 2, 0));
check(tbot_prob('52', 2, 5, 2, 0, 0, 2, 0)[0] === tbot_prob('52', 2, 5, 2, 0, 0, 2, 0)[1], 'eigenes Paar ist sicher');
check(tbot_prob('32', 1, 1, 0, 0, 0, 3, 0)[0] === 0, 'Drilling aus zwei Karten ist unmöglich');
check(tbot_prob('52', 6, 42, 0, 0, 0, 1, 0)[1] === 12271512, 'Nenner C(48, 42) exakt');
check(count(tbot_bids()) === 312, '312 Gebote');

section('Abgleich mit Python (tests/ref/trombone-ref.jsonl)');
if (!is_file(REF_DIR . '/test-net.bin') || !is_file(REF_DIR . '/trombone-ref.jsonl')) {
    // Die Referenz liegt nicht im Repository: Sie entsteht aus dem Trainingscode (nur Python mit numpy nötig)
    fwrite(STDERR, "tests/ref/ fehlt. Zuerst erzeugen:  pip install numpy && python3 training/gen_refs.py\n");
    exit(2);
}
$refNet = tbot_net(REF_DIR . '/test-net.bin');
check($refNet !== null, 'Testnetz der Referenz lädt');
$lines = @file(REF_DIR . '/trombone-ref.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
check(count($lines) > 0, 'Referenzspiele vorhanden');
$turns = $nx = $np = $maxX = $maxP = 0;
foreach ($refNet ? $lines : [] as $line) {
    $g = json_decode($line, true);
    $room = ref_room($g['deck'], $g['order']);
    $s = &$room['state'];
    foreach ($g['rounds'] as $ri => $round) {
        ref_deal($room, array_combine(array_map('intval', array_keys($round['hands'])), $round['hands']));
        foreach ($round['turns'] as $turn) {
            $turns++;
            check($s['phase'] === 'bidding' && $s['turn'] === [$turn['seat']], 'Platz am Zug', [$s['turn'], $turn['seat']]);
            check(mask_string(tbot_mask($s, $turn['seat'])) === $turn['mask'], "Maske (Runde $ri)");
            if (isset($turn['x'])) {
                $nx++;
                $x = tbot_features($s, $turn['seat'], $refNet['mu'], $refNet['k']);
                $want = array_fill(0, GameNight\TBOT_INPUTS, 0.0);
                foreach ($turn['x'] as [$i, $v]) {
                    $want[$i] = $v;
                }
                $d = max(array_map(fn($a, $b) => abs($a - $b), $x, $want));
                $maxX = max($maxX, $d);
                check($d <= 1e-9, 'Merkmale', $d);
            }
            if (isset($turn['p'])) {
                $np++;
                $p = tbot_policy($s, $turn['seat'], $refNet);
                $want = array_fill(0, GameNight\TBOT_ACTIONS, 0.0);
                foreach ($turn['p'] as [$i, $v]) {
                    $want[$i] = $v;
                }
                $d = max(array_map(fn($a, $b) => abs($a - $b), $p, $want));
                $maxP = max($maxP, $d);
                check($d <= 1e-4, 'Verteilung des Netzes', $d);
            }
            ref_act($room, $turn['seat'], $turn['act']);
        }
        check($s['reveal']['true'] === $round['true'] && $s['reveal']['loser'] === $round['loser'], 'Auflösung', $s['reveal']);
        check($s['over'] === $round['over'], 'Spielende');
        foreach ($round['cards'] as $seat => $n) {
            check(GameNight\tb_player($s, (int)$seat)['cards'] === $n, 'Kartenzahl');
        }
        foreach ($round['cred'] as $seat => $c) {
            check(($s['cred'][$seat] ?? array_fill(0, 11, 0)) === $c, 'Glaubwürdigkeitszähler', [$s['cred'][$seat] ?? null, $c]);
        }
    }
    unset($s);
}
echo "  $turns Züge ($nx mit Merkmalen, $np mit Verteilung), größte Abweichung: Merkmale " . sprintf('%.1e', $maxX) . ', Verteilung ' . sprintf('%.1e', $maxP) . "\n";
check($turns >= 300 && $nx >= 50 && $np >= 30, 'genug Züge verglichen', [$turns, $nx, $np]);

section('Glaubwürdigkeitszähler von Hand');
$room = ref_room('52', [0, 1, 2]);
$s = &$room['state'];
ref_deal($room, [0 => ['14S'], 1 => ['13S'], 2 => ['X']]);
ref_act($room, 0, GameNight\tbot_bid_index(['t' => 0, 'a' => 14, 'b' => null]));   // hohe Karte Ass: voll gedeckt
ref_act($room, 1, GameNight\tbot_bid_index(['t' => 1, 'a' => 14, 'b' => null]));   // Paar Asse: nichts in der Hand
ref_act($room, 2, GameNight\tbot_bid_index(['t' => 3, 'a' => 14, 'b' => null]));   // Drilling Asse: ein Joker von drei
ref_act($room, 0, 0);                                                              // zweifelt, Gebot ist falsch
check($s['cred'] === [[1, 60, 0, 0, 0, 0, 1, 1, 1, 0, 0], [1, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0], [1, 20, 0, 0, 0, 0, 1, 0, 0, 1, 0]], 'Zähler nach der ersten Runde', $s['cred']);
check($s['reveal']['loser'] === 2 && $s['starter'] === 0, 'Platz 2 verliert, Platz 0 beginnt');
tb_act($room, 0, $room['seats'][0]['token'], 'next', ['round' => $s['round']]);
check(!isset(tb_view($s, 0, $room['seats'][0]['token'])['cred']), 'Zähler stehen nicht in der Ansicht');
check(!isset(tb_view($s, 0, $room['seats'][0]['token'])['hands']), 'fremde Hände stehen nicht in der Ansicht');
unset($s);

section('Kein Wissen über fremde Karten');
mt_srand(11);
for ($i = 0; $i < 20; $i++) {
    $n = mt_rand(3, 8);
    $order = range(0, $n - 1);
    $room = ref_room('52', $order);
    $s = &$room['state'];
    foreach ($s['players'] as &$p) {
        $p['cards'] = mt_rand(1, 6);
    }
    unset($p);
    ref_deal($room, random_hands($s));
    for ($b = mt_rand(0, 3); $b > 0; $b--) {
        $allowed = array_values(array_filter(array_keys(array_filter(tbot_mask($s, $s['turn'][0])))));
        if ($allowed) {
            ref_act($room, $s['turn'][0], $allowed[0]);
        }
    }
    $seat = $s['turn'][0];
    $before = tbot_policy($s, $seat, $refNet);
    // fremde Karten neu verteilen (die eigene Hand bleibt)
    $mine = $s['hands'][(string)$seat];
    $rest = array_merge(...array_values(random_hands(['deck' => '52', 'players' => [['seat' => 0, 'cards' => 54, 'out' => false]]])));
    foreach ($mine as $card) {
        unset($rest[array_search($card, $rest, true)]);
    }
    $rest = array_values($rest);
    $changed = false;
    foreach ($s['hands'] as $h => $cards) {
        if ((int)$h !== $seat) {
            $new = array_splice($rest, 0, count($cards));
            $changed = $changed || $new !== $cards;
            $s['hands'][$h] = $new;
        }
    }
    check($changed && $s['hands'][(string)$seat] === $mine, 'fremde Hände sind vertauscht');
    check($before === tbot_policy($s, $seat, $refNet), 'gleiche Verteilung bei vertauschten fremden Händen');
    unset($s);
}

section('Gewichtsdatei');
$tmp = sys_get_temp_dir() . '/gn-bot-' . bin2hex(random_bytes(4));
mkdir($tmp);
write_net("$tmp/ok.bin", [GameNight\TBOT_INPUTS, 128, 128, 313]);
write_net("$tmp/features.bin", [GameNight\TBOT_INPUTS, 8, 313], ['features' => GameNight\TBOT_FEATURE_VERSION + 1]);
write_net("$tmp/format.bin", [GameNight\TBOT_INPUTS, 8, 313], ['format' => GameNight\TBOT_FORMAT_VERSION + 1]);
write_net("$tmp/magic.bin", [GameNight\TBOT_INPUTS, 8, 313], ['magic' => 'XXXX']);
write_net("$tmp/cut.bin", [GameNight\TBOT_INPUTS, 8, 313], ['cut' => 4]);
write_net("$tmp/inputs.bin", [GameNight\TBOT_INPUTS - 1, 8, 313]);
write_net("$tmp/actions.bin", [GameNight\TBOT_INPUTS, 8, 312]);
file_put_contents("$tmp/empty.bin", '');
check(tbot_net_info("$tmp/ok.bin") !== null && tbot_net("$tmp/ok.bin") !== null, 'passende Datei lädt');
check((tbot_net_info("$tmp/ok.bin")['meta']['name'] ?? '') === 'Testnetz', 'Metadaten lesbar');
foreach (['fehlt', 'features', 'format', 'magic', 'cut', 'inputs', 'actions', 'empty'] as $bad) {
    check(tbot_net_info("$tmp/$bad.bin") === null && tbot_net("$tmp/$bad.bin") === null, "unpassende Datei abgelehnt: $bad");
}

section('Entscheidung und Laufzeit');
$net = tbot_net("$tmp/ok.bin");
mt_srand(5);
$room = ref_room('52', range(0, 7));
$s = &$room['state'];
foreach ($s['players'] as &$p) {
    $p['cards'] = 6;
}
unset($p);
ref_deal($room, random_hands($s));
$seat = $s['turn'][0];
$d = tbot_decide($s, $seat, $net);
check($d !== null && $d['op'] === 'bid' && GameNight\tb_valid_bid($d['bid'], '52') !== null, 'erstes Gebot ist ein gültiges Gebot', $d);
check(tbot_decide($s, 99, $net) === null && tbot_policy($s, 99, $net) === null, 'unbekannter Platz zieht nicht');
ref_act($room, $seat, 40);
$t0 = microtime(true);
$seen = [];
for ($i = 0; $i < 100; $i++) {
    $d = tbot_decide($s, $s['turn'][0], $net);
    $seen[json_encode($d)] = true;
}
$ms = (microtime(true) - $t0) * 10;
$mask = tbot_mask($s, $s['turn'][0]);
check(abs(array_sum(tbot_policy($s, $s['turn'][0], $net)) - 1) < 1e-9, 'Verteilung summiert sich zu 1');
check(count($seen) > 1, 'der Zug wird gewürfelt', count($seen));
foreach (array_keys($seen) as $j) {
    $d = json_decode($j, true);
    $idx = $d['op'] === 'challenge' ? 0 : GameNight\tbot_bid_index($d['bid']);
    check($mask[$idx] === true && ($idx === 0 || $idx > 40), 'gewürfelter Zug ist erlaubt', $d);
}
echo '  ' . sprintf('%.2f', $ms) . " ms pro Entscheidung (ohne Laden der Datei)\n";
check($ms < 200, 'Entscheidung grob im Zeitbudget (Ziel 20 ms)', $ms);
unset($s);

array_map('unlink', glob("$tmp/*"));
rmdir($tmp);

echo "\n$checks Prüfungen, $failures fehlgeschlagen\n";
exit($failures ? 1 : 0);
