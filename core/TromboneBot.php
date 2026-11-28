<?php
/*
 * Trombone-Bot „TromboneZero“ (kurz T0): zieht seine Züge aus einem kleinen neuronalen Netz, das lokal per
 * Self-Play trainiert wurde (Ordner training/, nicht Teil der Release-Pakete). Der Server bekommt nur die
 * fertige Gewichtsdatei core/trombone-net.bin und wertet sie hier in reinem PHP aus.
 *
 *   1. Wahrscheinlichkeitsrechner: Wie wahrscheinlich ist ein Gebot aus Sicht einer Hand? (exakt)
 *   2. Merkmale: der Spielstand aus Sicht des Bot-Platzes als Vektor von TBOT_INPUTS Zahlen
 *   3. Netz laden und auswerten
 *   4. Aktionen ausblenden (Spielregeln, harte Regeln des Rechners), Softmax, würfeln
 *
 * Rechner, Merkmale und Maske müssen exakt zu training/tz/ passen (calc.py, features.py); die Tests
 * vergleichen beide Seiten über Referenzdateien (tests/bot-test.php, training/tests/).
 * Fehlt die Gewichtsdatei oder passt ihre Version nicht, gibt es keine Bots.
 *
 * Aktionen: Index 0 = Anzweifeln, 1..312 = alle Gebote aufsteigend nach tb_cmp.
 * Der Bot benutzt nur, was sein Platz sehen darf: die eigene Hand und offene Angaben.
 *
 * Am Ende der Datei: der Bot am Tisch (Platz, Name, Denkpause, Zug innerhalb der Anfrage eines Menschen).
 */

namespace GameNight;

\defined('GAME_NIGHT') or die();

const TBOT_MAGIC           = 'TBN1';
const TBOT_FORMAT_VERSION  = 2;
const TBOT_FEATURE_VERSION = 2;     // muss zu FEATURE_VERSION in training/tz/features.py passen
const TBOT_INPUTS          = 579;
const TBOT_ACTIONS         = 313;
const TBOT_NAME            = 'TromboneZero';

// ---------------------------------------------------------------------------
// Gebote und Rechner

/** Alle 312 Gebote [t, a, b] aufsteigend (b = 0, wenn die Kategorie nur einen Rang hat). */
function tbot_bids(): array
{
    static $bids = null;
    if ($bids === null) {
        $bids = [];
        for ($t = 0; $t <= 7; $t++) {
            for ($a = 2; $a <= 14; $a++) {
                if ($t === 2 || $t === 4) {
                    for ($b = 2; $b <= 14; $b++) {
                        if ($t === 2 ? $b < $a : $b !== $a) {
                            $bids[] = [$t, $a, $b];
                        }
                    }
                } else {
                    $bids[] = [$t, $a, 0];
                }
            }
        }
    }
    return $bids;
}

/** Aktionsindex (1..312) eines Gebots ['t', 'a', 'b']. */
function tbot_bid_index(array $bid): int
{
    static $index = null;
    if ($index === null) {
        $index = [];
        foreach (tbot_bids() as $i => [$t, $a, $b]) {
            $index["$t.$a.$b"] = $i + 1;
        }
    }
    return $index[$bid['t'] . '.' . $bid['a'] . '.' . ($bid['b'] ?? 0)];
}

/** Binomialkoeffizienten bis 54 (alle Werte passen in 64-Bit-Ganzzahlen). */
function tbot_binom(): array
{
    static $c = null;
    if ($c === null) {
        $c = [];
        for ($n = 0; $n <= 54; $n++) {
            $c[$n] = [1];
            for ($k = 1; $k <= $n; $k++) {
                $c[$n][$k] = $c[$n - 1][$k - 1] + ($c[$n - 1][$k] ?? 0);
            }
        }
    }
    return $c;
}

/**
 * Wahrscheinlichkeit als [Zähler, Nenner], dass ein Gebot wahr ist, wenn die fremden Karten eine
 * gleichverteilte Auswahl von $U aus den ungesehenen Karten sind (die Gebote der anderen zählen nicht).
 * $own: eigene Kartenzahl; $ca, $cb, $j: eigene Karten der Ränge a und b und eigene Joker (für den
 * „Rechner mit Ansagen“ dürfen $ca und $cb auch angesagte Karten anderer enthalten, höchstens 4);
 * $na, $nb: benötigte Anzahlen ($nb = 0: Gebot mit nur einem Rang).
 */
function tbot_prob(string $deck, int $own, int $U, int $ca, int $cb, int $j, int $na, int $nb): array
{
    $C = tbot_binom();
    $D = ($deck === '32' ? 33 : 54) - $own;
    $J = ($deck === '32' ? 1 : 2) - $j;
    $ra = 4 - $ca;
    $rb = $nb ? 4 - $cb : 0;
    $rest = $D - $ra - $rb - $J;
    $num = 0;
    for ($x = 0; $x <= $ra; $x++) {
        for ($y = 0; $y <= $rb; $y++) {
            for ($z = 0; $z <= $J; $z++) {
                $k = $U - $x - $y - $z;
                if ($k < 0 || $k > $rest) {
                    continue;
                }
                $missing = max(0, $na - $ca - $x) + ($nb ? max(0, $nb - $cb - $y) : 0);
                if ($missing <= $j + $z) {
                    $num += $C[$ra][$x] * $C[$rb][$y] * $C[$J][$z] * $C[$rest][$k];
                }
            }
        }
    }
    return [$num, $C[$D][$U]];
}

/** Karten → [Rang => Anzahl, 'X' => Joker]. */
function tbot_count(array $cards): array
{
    $count = ['X' => 0];
    foreach ($cards as $c) {
        $r = $c === 'X' ? 'X' : (int)substr($c, 0, -1);
        $count[$r] = ($count[$r] ?? 0) + 1;
    }
    return $count;
}

/**
 * Sicht des Bot-Platzes: eigene Zählung, eigene Kartenzahl, Karten der anderen ($U) und die
 * Mitspielenden der laufenden Runde in Zugreihenfolge nach dem Bot. null, wenn der Platz nicht mitspielt.
 */
function tbot_sight(array $s, int $seat): ?array
{
    $act = tb_active($s);
    $i = array_search($seat, array_column($act, 'seat'), true);
    $hand = $s['hands'][(string)$seat] ?? null;
    if ($i === false || !is_array($hand) || !$hand) {
        return null;
    }
    $others = array_merge(array_slice($act, $i + 1), array_slice($act, 0, $i));
    return [
        'n' => count($act), 'others' => $others, 'count' => tbot_count($hand), 'own' => count($hand),
        'U' => array_sum(array_column($others, 'cards')),
    ];
}

/** [Zähler, Nenner] für das Gebot [t, a, b] aus der Sicht $v (von tbot_sight). */
function tbot_bid_prob(string $deck, array $v, array $bid): array
{
    [$t, $a, $b] = $bid;
    [$na, $nb] = TB_NEED[$t] + [1 => 0];
    return tbot_prob($deck, $v['own'], $v['U'], $v['count'][$a] ?? 0, $nb ? ($v['count'][$b] ?? 0) : 0, $v['count']['X'], $na, $nb);
}

/**
 * Je Rangindex (0..12) die höchste Anzahl, die Platz $seat in dieser Runde angesagt hat –
 * mit $others = true stattdessen die höchste Anzahl, die irgendjemand außer $seat angesagt hat.
 */
function tbot_claims(array $bids, int $seat, bool $others = false): array
{
    $out = array_fill(0, 13, 0);
    foreach ($bids as $e) {
        if (($e['seat'] === $seat) === $others) {
            continue;
        }
        $need = TB_NEED[$e['bid']['t']];
        $out[$e['bid']['a'] - 2] = max($out[$e['bid']['a'] - 2], $need[0]);
        if (isset($need[1])) {
            $out[$e['bid']['b'] - 2] = max($out[$e['bid']['b'] - 2], $need[1]);
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Merkmale und Maske

/**
 * Netzeingaben für Platz $seat (Aufbau siehe training/tz/features.py). $mu: Vorwerte der sechs
 * Glaubwürdigkeitswerte, $k: ihr Gewicht – beides aus dem Kopf der Gewichtsdatei.
 */
function tbot_features(array $s, int $seat, array $mu, float $k): ?array
{
    $v = tbot_sight($s, $seat);
    if ($v === null) {
        return null;
    }
    $deck = (string)$s['deck'];
    $bids = $s['bids'];
    $x = array_fill(0, TBOT_INPUTS, 0.0);

    // Tisch
    $x[0] = $deck === '52' ? 1.0 : 0.0;
    $x[1 + $v['n'] - 2] = 1.0;
    $x[8] = ($v['own'] + $v['U']) / 48;
    $x[9] = min(count($bids), 20) / 20;

    // eigene Hand
    $x[10 + $v['own'] - 1] = 1.0;
    for ($r = 2; $r <= 14; $r++) {
        $x[16 + $r - 2] = ($v['count'][$r] ?? 0) / 4;
    }
    $x[29] = $v['count']['X'] / 2;

    // eigene Ansagen
    foreach (tbot_claims($bids, $seat) as $i => $n) {
        $x[30 + $i] = $n / 6;
    }

    // aktuelles Gebot
    $last = $bids ? end($bids) : null;
    if ($last) {
        $b = $last['bid'];
        $x[43] = 1.0;
        $x[44 + $b['t']] = 1.0;
        $x[52 + $b['a'] - 2] = 1.0;
        if (!empty($b['b'])) {
            $x[65 + $b['b'] - 2] = 1.0;
        }
        [$num, $den] = tbot_bid_prob($deck, $v, [$b['t'], $b['a'], $b['b'] ?? 0]);
        $x[78] = $num / $den;
    }

    // Rechner: je Rang mindestens k Karten (Ränge, die es im Deck nicht gibt, bleiben null)
    for ($r = $deck === '32' ? 7 : 2; $r <= 14; $r++) {
        for ($n = 1; $n <= 6; $n++) {
            [$num, $den] = tbot_prob($deck, $v['own'], $v['U'], $v['count'][$r] ?? 0, 0, $v['count']['X'], $n, 0);
            $x[79 + ($r - 2) * 6 + $n - 1] = $num / $den;
        }
    }

    // Rechner mit Ansagen: Was die anderen angesagt haben, zählt als Karten (höchstens 4 je Rang).
    // Beim aktuellen Gebot zählt dieses Gebot selbst nicht mit: Wie gut passt es zu den früheren Ansagen?
    if ($last) {
        $b = $last['bid'];
        [$na, $nb] = TB_NEED[$b['t']] + [1 => 0];
        $before = tbot_claims(array_slice($bids, 0, -1), $seat, true);
        $ca = min(4, ($v['count'][$b['a']] ?? 0) + $before[$b['a'] - 2]);
        $cb = $nb ? min(4, ($v['count'][$b['b']] ?? 0) + $before[$b['b'] - 2]) : 0;
        [$num, $den] = tbot_prob($deck, $v['own'], $v['U'], $ca, $cb, $v['count']['X'], $na, $nb);
        $x[157] = $num / $den;
    }
    $claimed = tbot_claims($bids, $seat, true);
    for ($r = $deck === '32' ? 7 : 2; $r <= 14; $r++) {
        $c = min(4, ($v['count'][$r] ?? 0) + $claimed[$r - 2]);
        for ($n = 1; $n <= 6; $n++) {
            [$num, $den] = tbot_prob($deck, $v['own'], $v['U'], $c, 0, $v['count']['X'], $n, 0);
            $x[158 + ($r - 2) * 6 + $n - 1] = $num / $den;
        }
    }

    // Mitspielende in Zugreihenfolge nach dem Bot
    $P = TB_CRED_POS;
    foreach (array_slice($v['others'], 0, 7) as $i => $p) {
        $o = 236 + $i * 49;
        $mine = array_values(array_filter($bids, fn($e) => $e['seat'] === $p['seat']));
        $x[$o] = 1.0;
        $x[$o + 1] = $p['cards'] / 6;
        $x[$o + 2] = $last && $last['seat'] === $p['seat'] ? 1.0 : 0.0;
        $x[$o + 3] = min(count($mine), 8) / 8;
        foreach (tbot_claims($mine, $p['seat']) as $j => $n) {
            $x[$o + 4 + $j] = $n / 6;
        }
        if ($mine) {   // dasselbe nur für das erste und nur für das letzte Gebot der Runde
            foreach (tbot_claims([$mine[0]], $p['seat']) as $j => $n) {
                $x[$o + 17 + $j] = $n / 6;
            }
            foreach (tbot_claims([end($mine)], $p['seat']) as $j => $n) {
                $x[$o + 30 + $j] = $n / 6;
            }
        }
        // Glaubwürdigkeit: Deckung des ersten, zweiten, späteren Gebots; Zweifelquote; Trefferquote beim
        // Anzweifeln; Anteil wahrer Gebote, wenn die Person angezweifelt wurde
        $c = tb_cred($s, $p['seat']);
        for ($q = 0; $q < $P; $q++) {
            $x[$o + 43 + $q] = ($c[2 * $q + 1] / 60 + $k * $mu[$q]) / ($c[2 * $q] + $k);
        }
        $x[$o + 43 + $P] = ($c[2 * $P + 1] + $k * $mu[$P]) / ($c[2 * $P] + $k);
        $x[$o + 44 + $P] = ($c[2 * $P + 2] + $k * $mu[$P + 1]) / ($c[2 * $P + 1] + $k);
        $x[$o + 45 + $P] = ($c[2 * $P + 4] + $k * $mu[$P + 2]) / ($c[2 * $P + 3] + $k);
    }
    return $x;
}

/**
 * Erlaubte Aktionen (TBOT_ACTIONS Wahrheitswerte) nach Spielregeln und harten Regeln des Rechners:
 *   1. Gebote müssen zum Deck passen und höher sein als das aktuelle; Anzweifeln braucht ein Gebot.
 *   2. Nie ein Gebot, das mit den Karten im Spiel unmöglich ist (P = 0); nie anzweifeln, wenn das
 *      Gebot schon aus der eigenen Hand bewiesen ist (P = 1).
 *   3. Bleibt nichts übrig (aktuelles Gebot sicher wahr, nichts Höheres möglich), sind alle höheren
 *      Gebote erlaubt – Anzweifeln wäre der sichere Verlust. Gibt es kein höheres Gebot: anzweifeln.
 */
function tbot_mask(array $s, int $seat): ?array
{
    $v = tbot_sight($s, $seat);
    if ($v === null) {
        return null;
    }
    $deck = (string)$s['deck'];
    $last = $s['bids'] ? end($s['bids']) : null;
    $cur = $last ? tbot_bid_index($last['bid']) : 0;
    $legal = array_fill(0, TBOT_ACTIONS, false);
    $possible = $legal;
    $anyLegal = $anyPossible = false;
    foreach (tbot_bids() as $i => $bid) {
        if ($i + 1 <= $cur || ($deck === '32' && ($bid[0] === 7 || $bid[1] < 7 || ($bid[2] && $bid[2] < 7)))) {
            continue;
        }
        $legal[$i + 1] = $anyLegal = true;
        if (tbot_bid_prob($deck, $v, $bid)[0] > 0) {
            $possible[$i + 1] = $anyPossible = true;
        }
    }
    if ($cur) {
        [$num, $den] = tbot_bid_prob($deck, $v, tbot_bids()[$cur - 1]);
        if ($num !== $den) {
            $possible[0] = $anyPossible = true;
        }
    }
    if ($anyPossible) {
        return $possible;
    }
    if (!$anyLegal) {
        $legal[0] = true;
    }
    return $legal;
}

// ---------------------------------------------------------------------------
// Netz

/**
 * Kopf der Gewichtsdatei lesen und prüfen. Rückgabe: ['sizes', 'mu', 'k', 'meta', 'offset'] oder null,
 * wenn die Datei fehlt, beschädigt ist oder nicht zu diesem Code passt (dann gibt es keine Bots).
 *
 * Format (Little-Endian): 'TBN1', uint16 Formatversion, uint16 Merkmalsversion, uint16 Schichtzahl L,
 * (L + 1) × uint16 Schichtgrößen, float32 k, 6 × float32 μ, uint32 Länge und Text der Metadaten (JSON),
 * danach je Schicht die Gewichte (Ausgang × Eingang, zeilenweise) und die Schwellen als float32.
 */
function tbot_net_info(string $file): ?array
{
    static $cache = [];
    if (array_key_exists($file, $cache)) {
        return $cache[$file];
    }
    $cache[$file] = null;
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return null;
    }
    $head = (string)fread($fh, 10);
    if (strlen($head) !== 10 || substr($head, 0, 4) !== TBOT_MAGIC) {
        fclose($fh);
        return null;
    }
    $h = unpack('vformat/vfeatures/vlayers', $head, 4);
    $L = $h['layers'];
    if ($h['format'] !== TBOT_FORMAT_VERSION || $h['features'] !== TBOT_FEATURE_VERSION || $L < 1 || $L > 8) {
        fclose($fh);
        return null;
    }
    $nf = 1 + TB_CRED_POS + 3;   // k und die Vorwerte μ
    $rest = (string)fread($fh, 2 * ($L + 1) + 4 * $nf + 4);
    if (strlen($rest) !== 2 * ($L + 1) + 4 * $nf + 4) {
        fclose($fh);
        return null;
    }
    $sizes = array_values(unpack('v' . ($L + 1), $rest));
    $f = array_values(unpack('g' . $nf, $rest, 2 * ($L + 1)));
    $metaLen = unpack('V', $rest, 2 * ($L + 1) + 4 * $nf)[1];
    $meta = $metaLen > 0 && $metaLen <= 65536 ? json_decode((string)fread($fh, $metaLen), true) : null;
    fclose($fh);
    $offset = 10 + 2 * ($L + 1) + 4 * $nf + 4 + $metaLen;
    $floats = 0;
    for ($l = 0; $l < $L; $l++) {
        $floats += $sizes[$l] * $sizes[$l + 1] + $sizes[$l + 1];
    }
    clearstatcache(true, $file);
    if ($sizes[0] !== TBOT_INPUTS || $sizes[$L] !== TBOT_ACTIONS || !($f[0] > 0) || @filesize($file) !== $offset + 4 * $floats) {
        return null;
    }
    return $cache[$file] = ['sizes' => $sizes, 'k' => $f[0], 'mu' => array_slice($f, 1),
                            'meta' => is_array($meta) ? $meta : [], 'offset' => $offset];
}

/** Netz vollständig laden (nur, wenn wirklich ein Bot ziehen muss). null wie bei tbot_net_info(). */
function tbot_net(string $file): ?array
{
    static $cache = [];
    if (isset($cache[$file])) {
        return $cache[$file];
    }
    $info = tbot_net_info($file);
    $data = $info ? @file_get_contents($file) : false;
    if ($data === false) {
        return null;
    }
    $pos = $info['offset'];
    $layers = [];
    for ($l = 0; $l < count($info['sizes']) - 1; $l++) {
        [$in, $out] = [$info['sizes'][$l], $info['sizes'][$l + 1]];
        // unpack liefert ab Index 1 nummerierte Felder
        $w = unpack('g' . ($in * $out), $data, $pos);
        $pos += 4 * $in * $out;
        $b = unpack('g' . $out, $data, $pos);
        $pos += 4 * $out;
        $layers[] = ['in' => $in, 'out' => $out, 'w' => $w, 'b' => $b];
    }
    return $cache[$file] = $info + ['layers' => $layers];
}

/** Durchlauf durch das Netz (ReLU zwischen den Schichten); gibt die TBOT_ACTIONS Ausgaben zurück. */
function tbot_forward(array $net, array $x): array
{
    $last = count($net['layers']) - 1;
    foreach ($net['layers'] as $l => $layer) {
        $w = $layer['w'];
        $b = $layer['b'];
        $in = $layer['in'];
        // nur die Eingänge ungleich null (die Merkmale sind großteils null, nach ReLU etwa die Hälfte)
        $nz = [];
        foreach ($x as $i => $val) {
            if ($val != 0.0) {
                $nz[$i + 1] = $val;
            }
        }
        $y = [];
        for ($o = 0, $base = 0; $o < $layer['out']; $o++, $base += $in) {
            $sum = $b[$o + 1];
            foreach ($nz as $i => $val) {
                $sum += $w[$base + $i] * $val;
            }
            $y[] = $l < $last && $sum < 0 ? 0.0 : $sum;
        }
        $x = $y;
    }
    return $x;
}

/**
 * Verteilung des Bots über die Aktionen (ausgeblendete haben 0). null, wenn der Platz nicht mitspielt.
 */
function tbot_policy(array $s, int $seat, array $net): ?array
{
    $x = tbot_features($s, $seat, $net['mu'], $net['k']);
    $mask = tbot_mask($s, $seat);
    if ($x === null || $mask === null) {
        return null;
    }
    $logits = tbot_forward($net, $x);
    $max = -INF;
    foreach ($mask as $i => $ok) {
        if ($ok && $logits[$i] > $max) {
            $max = $logits[$i];
        }
    }
    $p = [];
    $sum = 0.0;
    foreach ($mask as $i => $ok) {
        $p[$i] = $ok ? exp($logits[$i] - $max) : 0.0;
        $sum += $p[$i];
    }
    if (!is_finite($sum) || $sum <= 0.0) {
        return null;   // beschädigte Gewichte (NaN, unendlich): kein Zug aus dem Netz, siehe tbot_move
    }
    foreach ($p as $i => $val) {
        $p[$i] = $val / $sum;
    }
    return $p;
}

/**
 * Zug des Bots auf Platz $seat würfeln: ['op' => 'challenge'] oder ['op' => 'bid', 'bid' => [...]].
 * null, wenn der Platz gerade nicht ziehen kann.
 */
function tbot_decide(array $s, int $seat, array $net): ?array
{
    $p = tbot_policy($s, $seat, $net);
    if ($p === null) {
        return null;
    }
    $r = random_int(0, (1 << 53) - 1) / (1 << 53);
    $choice = null;
    foreach ($p as $i => $val) {
        if ($val <= 0.0) {
            continue;
        }
        $choice = $i;   // bei Rundungsresten bleibt es die letzte erlaubte Aktion
        $r -= $val;
        if ($r < 0) {
            break;
        }
    }
    if ($choice === 0) {
        return ['op' => 'challenge'];
    }
    [$t, $a, $b] = tbot_bids()[$choice - 1];
    return ['op' => 'bid', 'bid' => ['t' => $t, 'a' => $a, 'b' => $b ?: null]];
}

// ---------------------------------------------------------------------------
// Der Bot am Tisch (Aufrufe aus Api.php)
//
// Ein Bot ist ein Platz mit 'bot' => true, den nur die Aktion 'bot' der API anlegt; 'start' übernimmt das
// Kennzeichen in die Liste der Mitspielenden. Es gibt keinen eigenen Prozess: Geht der Zug an einen Bot,
// merkt sich der Tisch das Ende seiner Denkpause (botDue). Die nächste Abfrage ('get') eines Menschen
// danach führt den Zug aus – durch tb_act, mit derselben Prüfung wie bei einem Menschen.
// Bots bieten und zweifeln nur; sie klicken nie weiter, entfernen niemanden und stimmen nicht ab.

/** Namen der Bots: je ein Tier (einzelne Zeichen ohne Varianten-Auswahl, damit der Vergleich einfach bleibt). */
const TBOT_EMOJIS = ['🐸', '🦊', '🐼', '🐨', '🦁', '🐯', '🐮', '🐷', '🐵', '🐔', '🐧', '🦉', '🦆', '🐴', '🦄', '🐝',
                     '🐢', '🐙', '🦀', '🐬', '🐳', '🦔', '🐰', '🐻', '🐭', '🐹', '🐺', '🦝', '🦥', '🦦', '🦩', '🦜'];

/**
 * Wie viele Bots bietet diese Instanz pro Tisch an? 0 = keine: ausgeschaltet (Einstellungen bots, max_bots)
 * oder die Gewichtsdatei fehlt bzw. passt nicht zu diesem Code.
 */
function tbot_offered(): int
{
    $c = cfg();
    return !empty($c['bots']) && tbot_net_info((string)($c['net_file'] ?? '')) !== null ? (int)($c['max_bots'] ?? 0) : 0;
}

/**
 * Name für einen neuen Bot an diesem Tisch: ein zufälliges Tier, das noch niemand benutzt – weder als Name
 * (andere Bots, Menschen am Tisch, Mitspielende im Spielstand) noch innerhalb des Namens eines Menschen.
 * Das Bot-Abzeichen hängt nie am Namen, sondern allein am Kennzeichen 'bot'.
 */
function tbot_seat_name(array $room): string
{
    $named = array_merge(array_filter($room['seats']),
        array_filter((array)($room['state']['players'] ?? []), fn($p) => is_array($p) && is_string($p['name'] ?? null)));
    $names = array_map(fn($p) => name_key($p['name']), $named);
    $free = array_filter(TBOT_EMOJIS, function ($e) use ($names) {
        foreach ($names as $name) {
            if (str_contains($name, name_key($e))) {
                return false;
            }
        }
        return true;
    });
    if ($free) {
        return array_values($free)[random_int(0, count($free) - 1)];
    }
    // alle Tiere kommen schon in Namen vor (kaum möglich): wenigstens nie derselbe Name – wie bei Menschen durchnummerieren
    return unique_name(TBOT_EMOJIS[random_int(0, count(TBOT_EMOJIS) - 1)], $named);
}

/** Spielt auf Platz $seat gerade ein Bot mit (nicht ausgeschieden), der auch noch dort sitzt? */
function tbot_playing(array $room, int $seat): bool
{
    $s = $room['state'];
    if (empty($room['seats'][$seat]['bot']) || !in_array($s['phase'] ?? '', ['bidding', 'reveal'], true)) {
        return false;
    }
    foreach ($s['players'] as $p) {
        if ($p['seat'] === $seat && !$p['out']) {
            return !empty($p['bot']);
        }
    }
    return false;
}

/** Platz des Bots, der gerade am Zug ist – oder null. */
function tbot_turn(array $s): ?int
{
    if (($s['phase'] ?? '') !== 'bidding') {
        return null;
    }
    $seat = $s['turn'][0] ?? null;
    foreach ($s['players'] ?? [] as $p) {
        if ($p['seat'] === $seat && !empty($p['bot']) && tb_is_active($p)) {
            return $seat;
        }
    }
    return null;
}

function tbot_now(): int
{
    return (int)floor(microtime(true) * 1000);
}

/**
 * Nach jedem Schreibvorgang an einem Trombone-Tisch (with_room): Ist jetzt ein Bot am Zug, beginnt seine
 * Denkpause – botDue ist ihr Ende in Millisekunden. botTurn hält fest, für welchen Zug sie gilt: Schreibt
 * währenddessen jemand anderes (z. B. neuer Name), läuft die Pause weiter; für jeden neuen Zug beginnt sie neu.
 */
function tbot_schedule(array &$room): void
{
    $s = &$room['state'];
    $seat = tbot_turn($s);
    if ($seat === null || !tbot_humans_playing($room)) {
        unset($s['botDue'], $s['botTurn']);
        return;
    }
    $key = ($s['deal'] ?? 0) . '.' . count($s['bids']) . '.' . $seat;
    if (($s['botTurn'] ?? null) !== $key || !isset($s['botDue'])) {
        [$min, $max] = cfg()['bot_delay'] ?? [2000, 4000];
        $s['botTurn'] = $key;
        $s['botDue'] = tbot_now() + random_int((int)$min, max((int)$min, (int)$max));
    }
}

/**
 * Soll jetzt ein Bot ziehen? (Prüfung am gelesenen Tisch und noch einmal unter Sperre.) Ohne brauchbare
 * Gewichtsdatei zieht kein Bot; Menschen können ihn dann aus dem Spiel nehmen.
 */
function tbot_due(array $s): bool
{
    return tbot_turn($s) !== null && isset($s['botDue']) && tbot_now() >= $s['botDue']
        && tbot_net_info((string)(cfg()['net_file'] ?? '')) !== null;
}

/**
 * Spielt noch ein Mensch mit, der auch an seinem Platz sitzt? Ohne ihn ziehen die Bots nicht weiter: Niemand
 * soll Bots unter sich spielen lassen können (Rechenzeit). Die Menschen am Tisch können das Spiel dann abbrechen.
 */
function tbot_humans_playing(array $room): bool
{
    foreach ($room['state']['players'] ?? [] as $p) {
        $seatToken = $room['seats'][$p['seat']]['token'] ?? null;
        if (empty($p['bot']) && empty($p['out']) && is_string($seatToken) && is_string($p['token'] ?? null)
            && hash_equals($p['token'], $seatToken)) {
            return true;
        }
    }
    return false;
}

/** Ersatzzug, der nach den Spielregeln immer gilt: anzweifeln – oder, ohne Gebot, das niedrigste Gebot ansagen. */
function tbot_fallback(array $s): array
{
    if ($s['bids']) {
        return ['op' => 'challenge'];
    }
    return ['op' => 'bid', 'bid' => ['t' => 0, 'a' => (string)$s['deck'] === '32' ? 7 : 2, 'b' => null]];
}

/**
 * Einen Bot-Zug ausführen, falls einer fällig ist (höchstens einer; ist danach wieder ein Bot am Zug, bekommt
 * er über tbot_schedule() seine eigene Denkpause). Rückgabe: Wurde der Tisch geändert?
 *
 * Der Zug läuft in der Abfrage eines Menschen und darf sie nie scheitern lassen – und das Spiel soll nie an
 * einem Bot hängen bleiben. Deshalb:
 *   1. Alles, was beim Laden und Auswerten des Netzes schiefgeht, wird abgefangen.
 *   2. Der Zug wird an einer Kopie des Tisches ausgeführt; tb_act() meldet Fehler hier als Ausnahme
 *      (fail_throws) statt die Anfrage zu beenden.
 *   3. Liefert das Netz keinen gültigen Zug, kommt der Ersatzzug (tbot_fallback).
 *   4. Geht auch der nicht, bleibt der Tisch unverändert und der Bot versucht es erst in einer Minute wieder –
 *      die Menschen können ihn solange aus dem Spiel nehmen.
 */
function tbot_move(array &$room): bool
{
    $s = $room['state'];
    $seat = tbot_turn($s);
    if ($seat === null || !tbot_due($s)) {
        return false;
    }
    $move = null;
    try {
        $net = tbot_net((string)cfg()['net_file']);
        $move = $net ? tbot_decide($s, $seat, $net) : null;
    } catch (\Throwable $e) {
        $move = null;
    }
    $me = tb_player($s, $seat);
    $token = is_string($me['token'] ?? null) ? $me['token'] : '';
    foreach ([$move, tbot_fallback($s)] as $m) {
        if (!is_array($m) || $token === '') {
            continue;
        }
        $copy = $room;
        try {
            fail_throws(true);
            tb_act($copy, $seat, $token, (string)($m['op'] ?? ''), ['deal' => $s['deal'] ?? 0, 'bid' => $m['bid'] ?? null]);
            fail_throws(false);
            $room = $copy;
            return true;
        } catch (\Throwable $e) {
            fail_throws(false);
        }
    }
    $room['state']['botDue'] = tbot_now() + 60000;
    return true;
}
