<?php
/*
 * Trombone – läuft (anders als die anderen Spiele) komplett auf dem Server,
 * damit niemand die Karten der anderen sehen kann.
 *
 * Karten: "<Rang><Farbe>" mit Rang 2–14 (11 = Bube, 12 = Dame, 13 = König, 14 = Ass)
 * und Farbe S/H/D/C, Joker = "X".
 *
 * Gebot: ['t' => Kategorie, 'a' => Rang, 'b' => zweiter Rang | null]
 *   0 Hohe Karte, 1 Paar, 2 Doppelpaar (a > b), 3 Drilling, 4 Full House (a = Drilling, b = Paar),
 *   5 Vierling, 6 Fünfling, 7 Sechsling
 */

namespace GameNight;

\defined('GAME_NIGHT') or die();

const TB_MAX_CARDS = 6;
const TB_NEED = [[1], [2], [2, 2], [3], [3, 2], [4], [5], [6]];

function tb_init(): array
{
    return [
        'phase'   => 'lobby',          // lobby | bidding | reveal | over
        'deck'    => null,             // wird beim Start festgelegt (bei 3 Personen vorher gemeinsam gewählt)
        'players' => [],               // [{seat, name, cards, out}]; Bots zusätzlich mit bot: true (siehe TromboneBot.php)
        'round'   => 0,
        'deal'    => 0,                // zählt Austeilen und Neubeginn der Gebote; Gebote müssen ihn mitschicken
        'starter' => null,
        'turn'    => [],
        'hands'   => [],               // seat => [Karten]  (wird nie an andere ausgeliefert)
        'bids'    => [],               // [{seat, bid}] der laufenden Runde
        'prevBids' => [],              // [{removed, bids}] Ansagen dieser Runde vor dem Entfernen einer Person (nur zur Info)
        'reveal'  => null,             // Auflösung der letzten Runde
        'lastReveal' => null,          // Auflösung der vorigen Runde (während der nächsten noch einsehbar)
        'history' => [],               // kurze Rundenübersicht
        'loser'   => null,
        'over'    => false,
        'proposal' => null,            // Vorschlag, jemanden aufzunehmen: {seat, name, cards, by, yes: [Plätze]}
        'proposalResult' => null,      // {name, accepted, by} – zuletzt entschiedener Vorschlag
        'cred'    => [],               // seat => Glaubwürdigkeitszähler dieses Spiels, siehe tb_cred_update()
    ];
}

function tb_ranks(string $deck): array
{
    return $deck === '32' ? range(7, 14) : range(2, 14);
}

function tb_new_deck(string $deck): array
{
    if (tb_test_decks()) {
        return tb_test_decks(null, true);
    }
    $cards = [];
    foreach (tb_ranks($deck) as $r) {
        foreach (['S', 'H', 'D', 'C'] as $s) {
            $cards[] = $r . $s;
        }
    }
    $jokers = $deck === '32' ? 1 : 2;
    for ($i = 0; $i < $jokers; $i++) {
        $cards[] = 'X';
    }
    // Fisher-Yates mit kryptographischem Zufall
    for ($i = count($cards) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$cards[$i], $cards[$j]] = [$cards[$j], $cards[$i]];
    }
    return $cards;
}

/**
 * Nur für Tests (Abgleich der Regeln mit der Python-Simulation): vorgegebene Decks, die tb_new_deck()
 * der Reihe nach ausgibt. Über die API nicht erreichbar – nur PHP-Code im selben Prozess kann sie setzen.
 */
function tb_test_decks(?array $set = null, bool $shift = false): array
{
    static $decks = [];
    if ($set !== null) {
        $decks = array_values($set);
    }
    return $shift ? (array)array_shift($decks) : $decks;
}

/** Stellen, nach denen die Deckung der Gebote getrennt gezählt wird: erstes, zweites, späteres Gebot einer Runde. */
const TB_CRED_POS = 3;

/** Leere Glaubwürdigkeitszähler eines Platzes; auch für Zähler in einem veralteten Format. */
function tb_cred(array $s, int $seat): array
{
    $c = $s['cred'][(string)$seat] ?? null;
    return is_array($c) && count($c) === 2 * TB_CRED_POS + 5 ? $c : array_fill(0, 2 * TB_CRED_POS + 5, 0);
}

/**
 * Glaubwürdigkeitszähler nach einem Zweifel fortschreiben (Eingaben für den Bot, siehe TromboneBot.php).
 * Pro Platz 11 ganze Zahlen:
 *   0..5  je Stelle p = 0, 1, 2 (erstes, zweites, drittes oder späteres eigenes Gebot einer Runde):
 *         Zahl der Gebote [2p] und Summe ihrer Deckung in Sechzigsteln [2p + 1]
 *   6     Züge mit vorliegendem Gebot      7  davon Zweifel      8  davon gewonnene Zweifel
 *   9     eigene Gebote, die angezweifelt wurden      10  davon wahr
 * Deckung eines Gebots = Anteil der benötigten Karten, den die Person selbst in der Hand hatte (Joker
 * zählen mit). Wer zuerst blufft und sich später an die eigenen Karten hält (oder umgekehrt), fällt so
 * auf. Beruht nur auf offengelegten Karten und gilt für ein Spiel; ausgewertet werden die laufenden
 * Gebote, nicht prevBids.
 */
function tb_cred_update(array &$s, int $challenger, bool $true): void
{
    $nth = [];
    $lastSeat = null;
    foreach ($s['bids'] as $i => $e) {
        $c = tb_cred($s, $e['seat']);
        $need = TB_NEED[$e['bid']['t']];
        $count = [];
        $jokers = 0;
        foreach ($s['hands'][(string)$e['seat']] ?? [] as $card) {
            if ($card === 'X') {
                $jokers++;
            } else {
                $r = (int)substr($card, 0, -1);
                $count[$r] = ($count[$r] ?? 0) + 1;
            }
        }
        $covered = min($need[0], $count[$e['bid']['a']] ?? 0) + $jokers;
        if (isset($need[1])) {
            $covered += min($need[1], $count[$e['bid']['b']] ?? 0);
        }
        $total = array_sum($need);
        $p = min($nth[$e['seat']] ?? 0, TB_CRED_POS - 1);
        $nth[$e['seat']] = ($nth[$e['seat']] ?? 0) + 1;
        $c[2 * $p]++;
        $c[2 * $p + 1] += intdiv(min($covered, $total) * 60, $total);
        $c[2 * TB_CRED_POS] += $i > 0 ? 1 : 0;
        $s['cred'][(string)$e['seat']] = $c;
        $lastSeat = $e['seat'];
    }
    $c = tb_cred($s, $challenger);
    $c[2 * TB_CRED_POS]++;
    $c[2 * TB_CRED_POS + 1]++;
    $c[2 * TB_CRED_POS + 2] += $true ? 0 : 1;
    $s['cred'][(string)$challenger] = $c;
    if ($lastSeat !== null) {
        $c = tb_cred($s, $lastSeat);
        $c[2 * TB_CRED_POS + 3]++;
        $c[2 * TB_CRED_POS + 4] += $true ? 1 : 0;
        $s['cred'][(string)$lastSeat] = $c;
    }
}

/** Spielt in der laufenden Runde mit (nicht raus, nicht erst beim nächsten Austeilen dabei). */
function tb_is_active(array $p): bool
{
    return !$p['out'] && empty($p['joining']);
}

function tb_active(array $s): array
{
    return array_values(array_filter($s['players'], __NAMESPACE__ . '\tb_is_active'));
}

function &tb_player(array &$s, int $seat): ?array
{
    foreach ($s['players'] as &$p) {
        if ($p['seat'] === $seat) {
            return $p;
        }
    }
    $none = null;
    return $none;
}

/**
 * Die Person, die zu diesem Platz UND diesem Browser-Token gehört (nicht ausgeschieden).
 * Setzt sich jemand anderes auf einen frei gewordenen Platz, gehört er damit nicht zum Spiel:
 * er sieht die Karten dieses Platzes nicht und darf dort nicht spielen.
 */
function tb_me(array $s, ?int $seat, string $token): ?array
{
    if ($seat === null) {
        return null;
    }
    foreach ($s['players'] as $p) {
        if ($p['seat'] === $seat && !$p['out']) {
            return isset($p['token']) && $token !== '' && hash_equals($p['token'], $token) ? $p : null;
        }
    }
    return null;
}

/** Siehe orphaned() in Api.php: mitspielend = aktiv (nicht ausgeschieden oder entfernt). */
function tb_orphaned(array $room): bool
{
    return orphaned($room, __NAMESPACE__ . '\tb_is_active');
}

/** Nächste aktive Person nach $seat in Sitzreihenfolge. */
function tb_next(array $s, int $seat): int
{
    $seats = array_column($s['players'], 'seat');
    $n = count($seats);
    $i = array_search($seat, $seats, true);
    for ($k = 1; $k <= $n; $k++) {
        $p = $s['players'][($i + $k) % $n];
        if (tb_is_active($p)) {
            return $p['seat'];
        }
    }
    return $seat;
}

function tb_deal(array &$s): void
{
    // Neu Aufgenommene steigen mit diesem Austeilen ein
    foreach ($s['players'] as &$p) {
        $p['joining'] = false;
    }
    unset($p);
    $deck = tb_new_deck($s['deck']);
    $s['hands'] = [];
    foreach (tb_active($s) as $p) {
        $s['hands'][(string)$p['seat']] = array_splice($deck, 0, $p['cards']);
    }
    $s['round']++;
    $s['deal'] = ($s['deal'] ?? 0) + 1;
    $s['bids'] = [];
    $s['prevBids'] = [];
    $s['lastReveal'] = $s['reveal'] ?? null;   // bleibt in der nächsten Runde aufklappbar
    $s['reveal'] = null;
    $s['phase'] = 'bidding';
    $s['turn'] = [$s['starter']];
}

function tb_valid_bid($bid, string $deck): ?array
{
    if (!is_array($bid)) {
        return null;
    }
    $t = $bid['t'] ?? null;
    $a = $bid['a'] ?? null;
    $b = $bid['b'] ?? null;
    if (!is_int($t) || $t < 0 || $t > 7 || !is_int($a) || !in_array($a, tb_ranks($deck), true)) {
        return null;
    }
    if ($t === 7 && $deck === '32') {
        return null;   // Sechsling nur mit 52 + 2 Jokern
    }
    if ($t === 2 || $t === 4) {
        if (!is_int($b) || !in_array($b, tb_ranks($deck), true)) {
            return null;
        }
        if ($t === 2 && $a <= $b) {
            return null;
        }
        if ($t === 4 && $a === $b) {
            return null;
        }
    } else {
        $b = null;
    }
    return ['t' => $t, 'a' => $a, 'b' => $b];
}

function tb_cmp(array $x, array $y): int
{
    return [$x['t'], $x['a'], $x['b'] ?? 0] <=> [$y['t'], $y['a'], $y['b'] ?? 0];
}

/** Steckt das Gebot in der Vereinigung aller Karten? Joker ersetzen beliebige Ränge. */
function tb_bid_true(array $bid, array $cards): bool
{
    $count = [];
    $jokers = 0;
    foreach ($cards as $c) {
        if ($c === 'X') {
            $jokers++;
        } else {
            $r = (int)substr($c, 0, -1);
            $count[$r] = ($count[$r] ?? 0) + 1;
        }
    }
    $need = TB_NEED[$bid['t']];
    $missing = max(0, $need[0] - ($count[$bid['a']] ?? 0));
    if (isset($need[1])) {
        $missing += max(0, $need[1] - ($count[$bid['b']] ?? 0));
    }
    return $missing <= $jokers;
}

/** Haben alle aktiven Mitspielenden zugestimmt (Bots stimmen nicht ab)? Dann die Person aufnehmen. */
function tb_check_proposal(array &$room): void
{
    $s = &$room['state'];
    $prop = $s['proposal'];
    if (!$prop) {
        return;
    }
    foreach (tb_active($s) as $p) {
        if (empty($p['bot']) && !in_array($p['seat'], $prop['yes'], true)) {
            return;
        }
    }
    $s['proposal'] = null;
    $bot = !empty($prop['bot']) ? ['bot' => true] : [];   // nur fürs Abzeichen in der Anzeige
    $seated = $room['seats'][$prop['seat']] ?? null;
    if ($seated === null || !hash_equals($prop['token'], $seated['token'])) {
        // inzwischen aufgestanden (oder jemand anderes sitzt jetzt dort)
        $s['proposalResult'] = ['round' => $s['round'], 'name' => $prop['name'], 'accepted' => false, 'by' => null] + $bot;
        return;
    }
    // Ein Bot, der aus dem Spiel genommen wurde und noch am Tisch sitzt, bleibt beim Wiederaufnehmen ein Bot
    $entry = ['seat' => $prop['seat'], 'name' => $seated['name'], 'token' => $seated['token'],
              'cards' => $prop['cards'], 'out' => false, 'joining' => true] + (!empty($seated['bot']) ? ['bot' => true] : []);
    $prev = tb_player($s, $prop['seat']);
    if (!$prev || !hash_equals((string)($prev['token'] ?? ''), $seated['token'])) {
        unset($s['cred'][(string)$prop['seat']]);   // andere Person auf diesem Platz: Zähler beginnen bei null
    }
    $found = false;
    foreach ($s['players'] as &$p) {
        if ($p['seat'] === $prop['seat']) {
            $p = $entry;
            $found = true;
        }
    }
    unset($p);
    if (!$found) {
        $s['players'][] = $entry;   // reiht sich hinten in die Reihenfolge ein
    }
    // In der Auflösung beginnt gleich eine neue Runde → sofort dabei; sonst beim nächsten Austeilen
    $s['proposalResult'] = ['round' => $s['round'], 'name' => $seated['name'], 'accepted' => true, 'by' => null] + $bot;
}

/** Was eine bestimmte Person sehen darf. */
function tb_view(array $s, ?int $seat, string $token): array
{
    $me = tb_me($s, $seat, $token);
    $s['meSeat'] = $me ? $me['seat'] : null;                         // spiele ich (mit diesem Browser) mit?
    // Wer erst mit der nächsten Runde einsteigt, hat noch keine Karten (unter dem Platz liegen evtl. noch fremde)
    $s['myHand'] = $me && tb_is_active($me) ? ($s['hands'][(string)$me['seat']] ?? null) : null;
    unset($s['hands'], $s['cred']);   // die Zähler sind nicht geheim, aber nur für den Bot da
    unset($s['botDue'], $s['botTurn']);   // Denkpause des Bots, siehe tbot_schedule()
    foreach ($s['players'] as &$p) {
        unset($p['token']);   // auch die Server-Token der Bots; ihr Kennzeichen 'bot' bleibt
    }
    unset($p);
    if ($s['proposal']) {
        unset($s['proposal']['token']);
    }
    return $s;
}

/** Spielaktion ausführen. Verändert $room['state'] oder bricht mit fail() ab. */
function tb_act(array &$room, int $seat, string $token, string $op, array $in): void
{
    $s = &$room['state'];
    $me = tb_me($s, $seat, $token);                 // null für Zuschauende und Ausgeschiedene
    $meActive = $me !== null && tb_is_active($me);  // spielt in der laufenden Runde mit
    $running = in_array($s['phase'], ['bidding', 'reveal'], true);

    switch ($op) {
        case 'start':
            if ($s['phase'] !== 'lobby' && $s['phase'] !== 'over') {
                fail('Das Spiel läuft schon');
            }
            $seated = [];
            foreach ($room['seats'] as $i => $p) {
                if ($p !== null) {
                    $seated[] = ['seat' => $i, 'name' => $p['name'], 'token' => $p['token'], 'cards' => 1, 'out' => false]
                        + (!empty($p['bot']) ? ['bot' => true] : []);   // das Kennzeichen kommt nur vom Platz (Aktion 'bot')
                }
            }
            if (count($seated) < 2) {
                fail('Mindestens zwei Personen müssen mitspielen');
            }
            $bots = count(array_filter($seated, fn($p) => !empty($p['bot'])));
            if ($bots === count($seated)) {
                fail('Mindestens ein Mensch muss mitspielen');
            }
            // Gewichtsdatei inzwischen weg oder Bots ausgeschaltet: Das Spiel bliebe beim ersten Bot-Zug hängen
            if ($bots && tbot_offered() === 0) {
                fail('Die Bots können hier gerade nicht mitspielen – bitte zuerst entfernen', 409);
            }
            // Hat sich inzwischen jemand hingesetzt/ist aufgestanden? Dann erst neu prüfen lassen
            // (sonst könnte z. B. ein 3er-Spiel ohne Deckwahl mit dem 2er-Deck starten).
            if (($in['players'] ?? null) !== count($seated)) {
                fail('Die Mitspielenden haben sich gerade geändert – bitte noch einmal prüfen.', 409);
            }
            // 2 Personen: 32 + 1 Joker, ab 4 Personen: 52 + 2 Joker, bei 3 Personen wird gewählt
            $n = count($seated);
            if ($n === 2) {
                $resolved = '32';
            } elseif ($n >= 4) {
                $resolved = '52';
            } else {
                $resolved = (string)$s['deck'];   // gemeinsam gewählt (Aktion 'deck')
                if (!in_array($resolved, ['32', '52'], true)) {
                    fail('Bitte ein Kartenspiel wählen');
                }
            }
            $s = tb_init();
            $s['deck'] = $resolved;
            // Reihenfolge bei jedem Spiel neu auslosen (nicht nach Sitzplätzen); wer vorne steht, beginnt
            shuffle($seated);
            $s['players'] = $seated;
            $s['starter'] = $seated[0]['seat'];
            tb_deal($s);
            return;

        case 'bid':
        case 'challenge':
            if ($s['phase'] !== 'bidding' || !$meActive || !in_array($seat, $s['turn'], true)) {
                fail('Du bist nicht am Zug');
            }
            // Gebot/Zweifel gilt nur für die Runde, die die Person gesehen hat (nicht nach einem Neubeginn)
            if (($in['deal'] ?? null) !== ($s['deal'] ?? 0)) {
                fail('Die Runde hat gerade neu begonnen – bitte noch einmal prüfen.', 409);
            }
            $last = $s['bids'] ? end($s['bids']) : null;

            if ($op === 'bid') {
                $bid = tb_valid_bid($in['bid'] ?? null, $s['deck']);
                if (!$bid) {
                    fail('Ungültiges Gebot');
                }
                if ($last && tb_cmp($bid, $last['bid']) <= 0) {
                    fail('Das Gebot muss höher sein als das vorige');
                }
                $s['bids'][] = ['seat' => $seat, 'bid' => $bid];
                $s['turn'] = [tb_next($s, $seat)];
                return;
            }

            if (!$last) {
                fail('Es gibt noch keine Ansage zum Anzweifeln');
            }
            $all = array_merge(...array_values($s['hands']));
            $true = tb_bid_true($last['bid'], $all);
            tb_cred_update($s, $seat, $true);
            $loser = $true ? $seat : $last['seat'];
            $winner = $true ? $last['seat'] : $seat;
            $lp = &tb_player($s, $loser);
            $gameOver = $lp['cards'] >= TB_MAX_CARDS;
            if (!$gameOver) {
                $lp['cards']++;
            }
            $s['reveal'] = [
                'challenger' => $seat,
                'challenged' => $last['seat'],
                'bid'        => $last['bid'],
                'true'       => $true,
                'loser'      => $loser,
                'winner'     => $winner,
                'hands'      => $s['hands'],
            ];
            array_unshift($s['history'], [
                'round' => $s['round'], 'bid' => $last['bid'], 'challenger' => $seat,
                'challenged' => $last['seat'], 'true' => $true, 'loser' => $loser,
            ]);
            $s['history'] = array_slice($s['history'], 0, 30);
            $s['starter'] = $winner;
            $s['turn'] = [];
            if ($gameOver) {
                $s['proposal'] = null;
                $s['phase'] = 'over';
                $s['over'] = true;
                $s['loser'] = $loser;
            } else {
                $s['phase'] = 'reveal';
            }
            return;

        case 'next':
            if ($s['phase'] === 'bidding' && ($in['round'] ?? null) === $s['round'] - 1) {
                return;   // jemand anderes war schneller – kein Fehler
            }
            if ($s['phase'] !== 'reveal') {
                fail('Die Runde läuft schon');
            }
            if (($in['round'] ?? null) !== $s['round']) {
                return;   // verspätete Anfrage zu einer früheren Auflösung: diese Auflösung nicht überspringen
            }
            if (!$me) {
                fail('Du spielst in diesem Spiel nicht mit');
            }
            tb_deal($s);
            return;

        case 'remove':
            // Jemand ist weg: aus dem laufenden Spiel nehmen, Runde neu austeilen
            if (!$meActive) {
                fail('Nur aktive Mitspielende können jemanden entfernen', 403);
            }
            $target = is_int($in['seat'] ?? null) ? $in['seat'] : -1;
            $tp = &tb_player($s, $target);
            if (!$tp || $tp['out'] || !$running) {
                fail('Ungültiger Platz');
            }
            if (!empty($tp['joining'])) {
                // war nur für die nächste Runde angemeldet
                $tp['out'] = true;
                $tp['joining'] = false;
                return;
            }
            $tp['out'] = true;
            // Wer schon aufgenommen ist und beim nächsten Austeilen einsteigt, zählt mit
            $remaining = count(array_filter($s['players'], fn($p) => !$p['out']));
            if ($remaining < 2) {
                $s['proposal'] = null;
                $s['phase'] = 'over';
                $s['over'] = true;
                $s['turn'] = [];
                $s['reveal'] = null;
                return;
            }
            tb_check_proposal($room);   // die entfernte Person muss nicht mehr zustimmen
            if ($s['phase'] === 'bidding' && count(tb_active($s)) < 2) {
                // Nur noch eine Person mit Karten, aber schon Aufgenommene, die auf die nächste Runde
                // warten: neu austeilen (die Aufgenommenen hatten ja noch keine Karten)
                if ($s['starter'] === $target) {
                    $s['starter'] = tb_next($s, $target);
                }
                $lastReveal = $s['lastReveal'];
                tb_deal($s);
                $s['lastReveal'] = $lastReveal;
            } elseif ($s['phase'] === 'bidding') {
                // Die Karten der entfernten Person fallen weg; alle anderen behalten ihre Karten (kein
                // Neuausteilen – sonst könnte man sich durch Entfernen und Wiederaufnehmen neue Karten
                // verschaffen). Die Gebote beginnen neu, weil sich die Kartenzahl im Spiel geändert hat
                // (sonst könnte das letzte Gebot allein durch das Entfernen falsch werden). Die bisherigen
                // Gebote bleiben zur Information sichtbar – sie haben ja schon etwas verraten.
                unset($s['hands'][(string)$target]);
                if ($s['bids']) {
                    $s['prevBids'][] = ['removed' => $target, 'bids' => $s['bids']];
                }
                if ($s['starter'] === $target) {
                    $s['starter'] = tb_next($s, $target);
                }
                $s['bids'] = [];
                $s['turn'] = [$s['starter']];
                $s['deal'] = ($s['deal'] ?? 0) + 1;
            } elseif ($s['starter'] === $target) {
                $s['starter'] = tb_next($s, $target);
            }
            return;

        case 'propose':
            // Jemanden (wieder) ins laufende Spiel aufnehmen
            if (!$meActive || !$running) {
                fail('Nur aktive Mitspielende können jemanden aufnehmen');
            }
            if ($s['proposal']) {
                fail('Es läuft schon eine Abstimmung');
            }
            $target = is_int($in['seat'] ?? null) ? $in['seat'] : -1;
            $cards = is_int($in['cards'] ?? null) ? $in['cards'] : 0;
            if (!isset($room['seats'][$target]) || $room['seats'][$target] === null) {
                fail('Ungültiger Platz');
            }
            $tp = tb_player($s, $target);
            if ($tp && !$tp['out']) {
                fail('Diese Person spielt schon mit');
            }
            if ($cards < 1 || $cards > TB_MAX_CARDS) {
                fail('Ungültige Kartenzahl');
            }
            $count = count(array_filter($s['players'], fn($p) => !$p['out']));
            if ($s['deck'] === '32' && $count >= 5) {
                fail('Mit 32 Karten können höchstens 5 Personen mitspielen');
            }
            $s['proposal'] = [
                'seat' => $target, 'name' => $room['seats'][$target]['name'],
                'token' => $room['seats'][$target]['token'],   // nur genau diese Person wird aufgenommen
                'cards' => $cards, 'by' => $seat, 'yes' => [$seat],
            ] + (!empty($room['seats'][$target]['bot']) ? ['bot' => true] : []);
            $s['proposalResult'] = null;
            tb_check_proposal($room);
            return;

        case 'vote':
            if (!$s['proposal']) {
                fail('Es gibt gerade keine Abstimmung');
            }
            if (!$meActive) {
                fail('Nur aktive Mitspielende können abstimmen');
            }
            if (($in['yes'] ?? null) !== true) {
                $s['proposalResult'] = ['round' => $s['round'], 'name' => $s['proposal']['name'], 'accepted' => false, 'by' => $seat,
                                        'withdrawn' => $seat === $s['proposal']['by']] + (!empty($s['proposal']['bot']) ? ['bot' => true] : []);
                $s['proposal'] = null;
                return;
            }
            if (!in_array($seat, $s['proposal']['yes'], true)) {
                $s['proposal']['yes'][] = $seat;
            }
            tb_check_proposal($room);
            return;

        case 'deck':
            // Deckwahl vor dem Start (bei 3 Personen) – gilt für alle am Tisch
            if ($s['phase'] !== 'lobby' && $s['phase'] !== 'over') {
                fail('Das Spiel läuft schon');
            }
            $deck = $in['deck'] ?? null;
            if (!in_array($deck, ['32', '52'], true)) {
                fail('Ungültiges Kartenspiel');
            }
            $s['deck'] = $deck;
            return;

        case 'lobby':
            // Neues Spiel vorbereiten (Plätze können wieder gewechselt werden).
            // Ein laufendes Spiel abbrechen dürfen nur aktive Mitspielende.
            if ($running && !$meActive && !tb_orphaned($room)) {
                fail('Nur aktive Mitspielende können das Spiel abbrechen', 403);
            }
            $s = tb_init();
            return;

        default:
            fail('Unbekannte Aktion');
    }
}
