<?php
/*
 * Tests für die Mathekonferenz (eingebunden von api-test.php, nachdem core/Api.php geladen ist):
 * 1. Spiellogik direkt mit gezielt gebauten Situationen,
 * 2. komplette Spiele über die API mit Prüfung, dass niemand mehr sieht, als er*sie darf.
 */

use function GameNight\kf_init;
use function GameNight\kf_maybe_dawn;
use function GameNight\kf_maybe_resolve_day;
use function GameNight\kf_check_win;

if (!defined('GAME_NIGHT')) {
    exit;
}

/** Spielstand mit diesen Rollen (Platz = Index), mitten in Nacht $round. */
function kf_state(array $roles, int $round = 2): array
{
    $s = kf_init(1);
    foreach ($roles as $i => $r) {
        $s['players'][] = ['seat' => $i, 'name' => "S$i", 'token' => str_pad((string)$i, 32, 'a'), 'role' => $r,
                           'team' => $r === 'konstr' ? 'konstr' : 'konf', 'alive' => true, 'shield' => $r === 'logik', 'converted' => false];
    }
    $s['round'] = $round;
    $s['phase'] = 'night';
    $s['night'] = ['proposals' => [], 'acts' => []];
    $s['day1Done'] = $round > 1;
    return $s;
}

function kf_alive_seats(array $s): array
{
    return GameNight\kf_alive($s);
}

section('Mathekonferenz: Spiellogik');

$s = kf_state(['konstr', 'numerik', 'mathe', 'mathe', 'mathe', 'mathe']);
$s['night']['victim'] = 2;
$s['night']['acts']['numerik'] = 2;
kf_maybe_dawn($s);
check($s['phase'] === 'day' && in_array(2, kf_alive_seats($s), true), 'Numeriker*in schützt vor den Konstruktivist*innen', $s['phase']);
check($s['protectLast'] === 2, 'geschützte Person wird für die nächste Nacht gemerkt');

$s = kf_state(['konstr', 'numerik', 'mathe', 'mathe', 'mathe', 'mathe']);
$s['night']['victim'] = 3;
$s['night']['acts']['numerik'] = 2;
kf_maybe_dawn($s);
$last = end($s['log']);
check(!in_array(3, kf_alive_seats($s), true) && $last['cause'] === 'konstr' && $last['role'] === 'mathe', 'ungeschütztes Opfer verlässt die Konferenz (Rolle wird bekannt)', $last);

$s = kf_state(['konstr', 'ki', 'mathe', 'mathe', 'mathe', 'mathe']);
$s['night']['victim'] = 2;
$s['night']['acts'] += ['kiHeal' => 2, 'kiGap' => 0, 'ki' => true];
kf_maybe_dawn($s);
check(in_array(2, kf_alive_seats($s), true), 'KI-Nerd rettet das Opfer');
check(($s['winner']['party'] ?? null) === 'konf', 'KI-Nerd findet die Lücke bei der letzten Konstruktivist*in → Konferenz gewinnt', $s['winner']);

$s = kf_state(['konstr', 'logik', 'mathe', 'mathe', 'mathe', 'mathe']);
$s['night']['victim'] = 1;
kf_maybe_dawn($s);
check(in_array(1, kf_alive_seats($s), true) && !$s['players'][1]['shield'], 'Logiker*in übersteht den ersten Angriff');
$s['phase'] = 'night';
$s['night'] = ['proposals' => [], 'acts' => [], 'victim' => 1];
kf_maybe_dawn($s);
check(!in_array(1, kf_alive_seats($s), true), '… aber nicht den zweiten');

$s = kf_state(['konstr', 'konstr', 'mathe', 'mathe', 'physik', 'mathe', 'mathe', 'mathe']);
$s['coauthors'] = [2, 4];
$s['night']['victim'] = 2;
kf_maybe_dawn($s);
$alive = kf_alive_seats($s);
check(!in_array(2, $alive, true) && !in_array(4, $alive, true), 'Koautor*in geht mit', $alive);
check($s['phase'] === 'shot' && $s['shots'] === [4] && $s['after'] === 'day', 'Physiker*in reißt danach jemanden mit', [$s['phase'], $s['shots']]);

$s = kf_state(['konstr', 'doktorand', 'mathe', 'mathe', 'mathe', 'mathe']);
$s['vorbild'] = 3;
$s['night']['victim'] = 3;
kf_maybe_dawn($s);
check($s['players'][1]['team'] === 'konstr' && $s['players'][1]['converted'], 'Doktorand*in wechselt zu den Konstruktivist*innen, wenn das Vorbild geht');

// Tag: Gleichstand, Reviewer 2, Hausmeister*in
$s = kf_state(['konstr', 'mathe', 'mathe', 'mathe', 'mathe', 'mathe']);
$s['phase'] = 'day';
$s['night'] = null;
$s['votes'] = [0 => 1, 1 => 0, 2 => 1, 3 => 0, 4 => -1, 5 => -1];
kf_maybe_resolve_day($s);
check(count(kf_alive_seats($s)) === 6 && $s['phase'] === 'night', 'Gleichstand: niemand geht', end($s['log']));
$s = kf_state(['konstr', 'reviewer', 'mathe', 'mathe', 'mathe', 'mathe']);
$s['phase'] = 'day';
$s['mark'] = 3;
$s['votes'] = [0 => 3, 1 => 2, 2 => 0, 3 => 5, 4 => 1, 5 => 2];   // ohne Markierung ginge Platz 2 (2 Stimmen)
kf_maybe_resolve_day($s);
check(!in_array(3, kf_alive_seats($s), true) && in_array(2, kf_alive_seats($s), true), 'Reviewer 2: +2 Stimmen entscheiden die Abstimmung', end($s['log']));
$s = kf_state(['konstr', 'hausmeister', 'mathe', 'mathe', 'mathe', 'mathe'], 1);
$s['phase'] = 'day';
$s['votes'] = [0 => 1, 1 => 0, 2 => 1, 3 => 1, 4 => 1, 5 => 1];
kf_maybe_resolve_day($s);
check(($s['winner']['party'] ?? null) === 'hausmeister' && $s['over'], 'Hausmeister*in am ersten Tag zerredet → gewinnt allein, Spiel endet', $s['winner']);
$s = kf_state(['konstr', 'hausmeister', 'mathe', 'mathe', 'mathe', 'mathe'], 2);
$s['phase'] = 'day';
$s['votes'] = [0 => 1, 1 => 0, 2 => 1, 3 => 1, 4 => 1, 5 => 1];
kf_maybe_resolve_day($s);
check($s['winner'] === null && $s['phase'] === 'night', 'ab dem zweiten Tag ist Hausmeister*in normal dabei');

// Frühes Ende für die Konstruktivist*innen
$s = kf_state(['konstr', 'konstr', 'mathe', 'mathe', 'mathe']);
$s['night']['victim'] = 2;
kf_maybe_dawn($s);
check(($s['winner']['party'] ?? null) === 'konstr', '2 gegen 2: Konstruktivist*innen gewinnen sofort (Gleichstand rettet sie)', $s['winner']);
$s = kf_state(['konstr', 'konstr', 'mathe', 'physik', 'mathe']);
$s['night']['victim'] = 2;
kf_maybe_dawn($s);
check($s['winner'] === null, '… aber nicht, solange die Physiker*in noch mitreißen kann');
$s = kf_state(['konstr', 'konstr', 'mathe', 'ki', 'mathe']);
$s['night'] += ['victim' => 2];
$s['night']['acts']['ki'] = true;
kf_maybe_dawn($s);
check($s['winner'] === null, '… und nicht, solange der KI-Nerd noch eine Lücke finden kann');
$s['potions']['gap'] = false;
check(kf_check_win($s) && $s['winner']['party'] === 'konstr', 'ohne diese Gegenwehr: Konstruktivist*innen gewinnen');
$s = kf_state(['konstr', 'mathe', 'mathe']);
$s['coauthors'] = [0, 2];
$s['night']['victim'] = 1;
kf_maybe_dawn($s);
check(($s['winner']['party'] ?? null) === 'koautor' && $s['winner']['seats'] === [0, 2], 'Koautor*innen aus verschiedenen Parteien gewinnen als letzte zwei', $s['winner']);

// ---------------------------------------------------------------------------
section('Mathekonferenz: komplette Spiele über die API');

/** Ein Spiel mit $n Personen durchspielen; prüft nach jedem Schritt, was jede Person sieht. */
function kf_play(int $n, ?array $config, int $seed): array
{
    mt_srand($seed);
    [, $json] = api('party', ['action' => 'create', 'game' => 'konferenz']);
    $room = $json['id'];
    $tok = [];
    for ($i = 0; $i < $n; $i++) {
        $tok[$i] = token();
        api('party', ['action' => 'sit', 'room' => $room, 'seat' => $i, 'name' => "K$i", 'token' => $tok[$i]]);
    }
    if ($config) {
        [$code] = api('party', ['action' => 'game', 'room' => $room, 'op' => 'config', 'config' => $config, 'token' => $tok[0]]);
        check($code === 200, 'Rollenverteilung angepasst', $code);
    }
    [$code, $json] = api('party', ['action' => 'game', 'room' => $room, 'op' => 'start', 'players' => $n, 'token' => $tok[0]]);
    check($code === 200 && $json['room']['state']['phase'] === 'night', "Mathekonferenz startet mit $n Personen", $json);
    $leak = [];
    for ($step = 0; $step < 400; $step++) {
        $views = [];
        foreach ($tok as $i => $t) {
            [, $j, $raw] = api('party', ['action' => 'get', 'room' => $room, 'token' => $t]);
            $views[$i] = $j['room']['state'];
            foreach ($tok as $other) {
                if (str_contains($raw, $other)) {
                    $leak[] = "Token in Antwort an K$i";
                }
            }
            // Sichtbare Rollen: eigene, ausgeschiedene, am Ende alle – Konstruktivist*innen zusätzlich einander
            $mineKonstr = ($views[$i]['me']['konstr'] ?? false) && ($views[$i]['me']['alive'] ?? false);
            foreach ($views[$i]['players'] as $p) {
                if ($p['role'] !== null && $p['seat'] !== $i && $p['alive'] && !$views[$i]['over']
                    && !($mineKonstr && $p['konstr'])) {
                    $leak[] = "K$i sieht die Rolle von K{$p['seat']}";
                }
            }
            if (isset($views[$i]['me']['lean']) && $views[$i]['me']['role'] !== 'lean') {
                $leak[] = "K$i sieht Lean-Ergebnisse";
            }
        }
        [, $spect, $rawSpect] = api('party', ['action' => 'get', 'room' => $room, 'token' => token()]);
        foreach ($spect['room']['state']['players'] as $p) {
            if ($p['role'] !== null && $p['alive'] && !$spect['room']['state']['over']) {
                $leak[] = 'Zuschauende sehen eine Rolle';
            }
        }
        $st = $views[0];
        if ($st['over']) {
            break;
        }
        $alive = array_values(array_map(fn($p) => $p['seat'], array_filter($st['players'], fn($p) => $p['alive'])));
        $did = false;
        foreach ($views as $i => $v) {
            $me = $v['me'] ?? [];
            if ($st['phase'] === 'night') {
                foreach ($me['tasks'] ?? [] as $task) {
                    $others = array_values(array_diff($alive, [$i]));
                    $pick = $others[mt_rand(0, count($others) - 1)];
                    if ($task === 'konstr') {
                        $targets = array_values(array_filter($others, fn($x) => !($v['players'][$x]['konstr'] ?? false)));
                        $pick = $targets[mt_rand(0, count($targets) - 1)];
                        api('party', ['action' => 'game', 'room' => $room, 'op' => 'propose', 'target' => $pick, 'token' => $tok[$i]]);
                        $r = api('party', ['action' => 'game', 'room' => $room, 'op' => 'choose', 'target' => $pick, 'token' => $tok[$i]]);
                    } elseif ($task === 'betreuer') {
                        $r = api('party', ['action' => 'game', 'room' => $room, 'op' => 'act', 'target' => $alive[0], 'second' => $alive[1], 'token' => $tok[$i]]);
                    } elseif ($task === 'ki') {
                        $what = mt_rand(0, 3) === 0 && ($me['potions']['gap'] ?? false) ? 'gap' : 'done';
                        $r = api('party', ['action' => 'game', 'room' => $room, 'op' => 'act', 'what' => $what, 'target' => $pick, 'token' => $tok[$i]]);
                        if ($what === 'gap') {
                            api('party', ['action' => 'game', 'room' => $room, 'op' => 'act', 'what' => 'done', 'token' => $tok[$i]]);
                        }
                    } elseif ($task === 'numerik' && $pick === ($me['protectLast'] ?? null)) {
                        $r = api('party', ['action' => 'game', 'room' => $room, 'op' => 'noact', 'token' => $tok[$i]]);
                    } else {
                        $r = api('party', ['action' => 'game', 'room' => $room, 'op' => 'act', 'target' => $pick, 'token' => $tok[$i]]);
                    }
                    if ($r[0] !== 200) {
                        $leak[] = "Nachtaktion $task abgelehnt: " . ($r[1]['error'] ?? $r[0]);
                    }
                    $did = true;
                    break 2;   // neu einlesen (Konstruktivist*innen: nur eine Person handelt)
                }
            } elseif ($st['phase'] === 'day' && ($me['alive'] ?? false) && !array_key_exists($i, $st['votes'])) {
                $target = $alive[mt_rand(0, count($alive) - 1)];
                $r = api('party', ['action' => 'game', 'room' => $room, 'op' => 'vote', 'round' => $st['round'],
                                   'target' => $target === $i ? -1 : $target, 'token' => $tok[$i]]);
                $did = $r[0] === 200;
                break;
            } elseif ($st['phase'] === 'shot' && $st['shooter'] === $i) {
                $others = array_values(array_diff($alive, [$i]));
                api('party', ['action' => 'game', 'room' => $room, 'op' => 'shoot', 'target' => $others[0], 'token' => $tok[$i]]);
                $did = true;
                break;
            }
        }
        if (!$did) {
            $leak[] = "Spiel hängt in Phase {$st['phase']}";
            break;
        }
    }
    check(!$leak, "keine Geheimnisse verraten, kein Hängenbleiben ($n Personen)", array_slice(array_unique($leak), 0, 5));
    check($views[0]['over'] && is_array($views[0]['winner']), "Spiel endet mit einem Ergebnis ($n Personen)", $views[0]['winner'] ?? $views[0]['phase']);
    return [$room, $tok, $views[0]];
}

$results = [];
foreach ([[5, null, 1], [8, null, 2], [12, null, 3], [8, ['konstr' => 2, 'roles' => ['lean', 'ki', 'betreuer', 'doktorand', 'physik']], 4], [16, null, 5]] as [$n, $config, $seed]) {
    [$room, $tok, $final] = kf_play($n, $config, $seed);
    $results[] = $final['winner']['party'] ?? '?';
    check($final['round'] >= 1 && count($final['log']) >= 1, "Spiel mit Verlauf ($n Personen)", $final['round']);
    if (getenv('KF_VERBOSE')) {
        echo "    $n Personen: {$final['winner']['party']} nach Runde {$final['round']}, " . count($final['log']) . " Ereignisse\n";
    }
    // am Ende sehen alle alle Rollen
    check(count(array_filter($final['players'], fn($p) => $p['role'] !== null)) === $n, "am Ende sind alle Rollen aufgedeckt ($n Personen)");
}

// Eingaben und Rechte
[, $json] = api('party', ['action' => 'create', 'game' => 'konferenz']);
$kr = $json['id'];
$kt = [token(), token(), token(), token(), token()];
foreach ([0, 1, 2] as $i) {
    api('party', ['action' => 'sit', 'room' => $kr, 'seat' => $i, 'name' => "R$i", 'token' => $kt[$i]]);
}
[$code, $json] = api('party', ['action' => 'game', 'room' => $kr, 'op' => 'start', 'players' => 3, 'token' => $kt[0]]);
check($code === 409, 'Mathekonferenz braucht mindestens 4 Personen', $json);
api('party', ['action' => 'sit', 'room' => $kr, 'seat' => 3, 'name' => 'R3', 'token' => $kt[3]]);
[, $json] = api('party', ['action' => 'get', 'room' => $kr, 'token' => $kt[0]]);
check($json['room']['state']['suggest'] === ['konstr' => 1, 'roles' => ['lean', 'ki', 'numerik']], 'Vorschlag passend zur Personenzahl', $json['room']['state']['suggest']);
foreach ([['konstr' => 1, 'roles' => ['lean', 'lean']], ['konstr' => 1, 'roles' => ['zauberer']], ['konstr' => 'x', 'roles' => []], 'x'] as $bad) {
    [$code] = api('party', ['action' => 'game', 'room' => $kr, 'op' => 'config', 'config' => $bad, 'token' => $kt[0]]);
    check($code === 400, 'ungültige Rollenverteilung abgelehnt: ' . json_encode($bad), $code);
}
api('party', ['action' => 'game', 'room' => $kr, 'op' => 'config', 'config' => ['konstr' => 2, 'roles' => ['lean', 'ki', 'numerik']], 'token' => $kt[0]]);
[$code, $json] = api('party', ['action' => 'game', 'room' => $kr, 'op' => 'start', 'players' => 4, 'token' => $kt[0]]);
check($code === 409, 'zu viele Rollen für 4 Personen → Start abgelehnt', $json);
api('party', ['action' => 'game', 'room' => $kr, 'op' => 'config', 'reset' => true, 'token' => $kt[0]]);
[$code] = api('party', ['action' => 'game', 'room' => $kr, 'op' => 'start', 'players' => 4, 'token' => $kt[0]]);
check($code === 200, 'mit dem Vorschlag startet das Spiel', $code);
[$code] = api('party', ['action' => 'game', 'room' => $kr, 'op' => 'vote', 'round' => 1, 'target' => 1, 'token' => $kt[0]]);
check($code === 409, 'nachts wird nicht abgestimmt', $code);
[$code] = api('party', ['action' => 'game', 'room' => $kr, 'op' => 'act', 'target' => 1, 'token' => $kt[4]]);
check($code === 403, 'Zuschauende können nichts tun', $code);
foreach ($kt as $i => $t) {
    [, $j] = api('party', ['action' => 'get', 'room' => $kr, 'token' => $t]);
    if (($j['room']['state']['me']['role'] ?? null) === 'mathe' || ($i < 4 && ($j['room']['state']['me']['tasks'] ?? []) === [])) {
        [$code] = api('party', ['action' => 'game', 'room' => $kr, 'op' => 'act', 'target' => ($i + 1) % 4, 'token' => $t]);
        check($code === 409, 'ohne Nachtaufgabe keine Nachtaktion', $code);
        break;
    }
}
// „Diese Nacht keine Aktion“ geht für jede Nachtrolle
foreach ($kt as $i => $t) {
    [, $j] = api('party', ['action' => 'get', 'room' => $kr, 'token' => $t]);
    $tasks = $j['room']['state']['me']['tasks'] ?? [];
    if ($tasks && $tasks[0] !== 'ki') {
        if ($tasks[0] !== 'konstr') {
            [$code] = api('party', ['action' => 'game', 'room' => $kr, 'op' => 'propose', 'target' => ($i + 1) % 4, 'token' => $t]);
            check($code === 400, "nur Konstruktivist*innen schlagen vor ({$tasks[0]})", $code);
        }
        [$code, $j] = api('party', ['action' => 'game', 'room' => $kr, 'op' => 'noact', 'token' => $t]);
        check($code === 200, "„keine Aktion“ für {$tasks[0]}", $j);
    }
}
// Freiwillig gehen: Rolle wird bekannt, Spiel läuft weiter bzw. endet
[, $j] = api('party', ['action' => 'game', 'room' => $kr, 'op' => 'leave', 'token' => $kt[3]]);
$p3 = $j['room']['state']['players'][3];
check(!$p3['alive'] && $p3['role'] !== null, 'wer die Konferenz verlässt, ist draußen und die Rolle bekannt', $p3);
[$code] = api('party', ['action' => 'game', 'room' => $kr, 'op' => 'leave', 'token' => $kt[3]]);
check($code === 403, 'wer schon gegangen ist, kann nichts mehr tun', $code);
