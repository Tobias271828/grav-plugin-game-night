<?php
/*
 * Mathekonferenz – Spiel mit geheimen Rollen (Mafia-Prinzip), läuft komplett auf dem Server:
 * Jede Person sieht nur die eigene Rolle (Konstruktivist*innen zusätzlich einander), die Rollen
 * Ausgeschiedener und welche Rollen unter den Verbliebenen noch vorkommen. Kein Spielleiter: Die
 * Nacht endet, sobald alle mit Nachtaktion gewählt haben; der Tag, sobald alle abgestimmt haben.
 *
 * Ablauf: lobby → night → (shot) → day → (shot) → night … → over
 *   night: Konstruktivist*innen (Vorschlagen/Auswählen), Lean-Profi, Numeriker*in, Reviewer 2,
 *          in der ersten Nacht Betreuer*in und Doktorand*in – alle gleichzeitig; der KI-Nerd erst,
 *          wenn die Konstruktivist*innen gewählt haben (er sieht ihr Opfer).
 *   shot:  Die Physiker*in hat die Konferenz verlassen und reißt einen Beweis mit.
 *   day:   offene Abstimmung; die meisten Stimmen gehen, bei Gleichstand niemand.
 */

namespace GameNight;

\defined('GAME_NIGHT') or die();

const KF_MAX_PLAYERS = 20;
const KF_MIN_PLAYERS = 4;
// Sonderrollen in der Reihenfolge, in der sie bei wachsender Personenzahl dazukommen
const KF_SPECIALS = ['lean', 'ki', 'numerik', 'betreuer', 'hausmeister', 'physik', 'logik', 'reviewer', 'doktorand'];
const KF_ROLES = ['konstr', 'mathe', 'lean', 'ki', 'numerik', 'betreuer', 'hausmeister', 'physik', 'logik', 'reviewer', 'doktorand'];

function kf_init(int $game = 0, ?array $config = null): array
{
    return [
        'phase'   => 'lobby',   // lobby | night | shot | day | over
        'game'    => $game,
        'config'  => $config,   // null = Vorschlag passend zur Personenzahl; sonst {konstr, roles}
        'players' => [],        // [{seat, name, token, role, team, alive, shield, converted}]
        'round'   => 0,         // Nacht n und danach Tag n
        'night'   => null,      // {proposals: {seat: [ziele]}, victim (fehlt = offen, null = keine Aktion), acts: {seat: …}}
        'votes'   => [],        // Tag: Platz => Ziel (-1 = niemand)
        'mark'    => null,      // Reviewer 2: Platz mit +2 Stimmen am folgenden Tag
        'protectLast' => null,  // Numeriker*in: in der vorigen Nacht geschützt
        'potions' => ['heal' => true, 'gap' => true],   // KI-Nerd: jeweils einmal im Spiel
        'coauthors' => null,    // [a, b]
        'vorbild' => null,      // Doktorand*in: Vorbild
        'lean'    => [],        // Ergebnisse des Lean-Profis: [{round, target, konstr}]
        'shots'   => [],        // Physiker*in muss noch mitreißen: [Platz]
        'removals' => [],       // Herausnehmen Abwesender: Platz => [Plätze, die bestätigt haben]
        'after'   => null,      // nach den Schüssen weiter mit 'day' oder 'night'
        'day1Done' => false,    // erste Abstimmung vorbei (Hausmeister*in)
        'log'     => [],        // öffentlich: [{round, at: 'night'|'day', type, seat, role, …}]
        'winner'  => null,      // {party, seats}
        'over'    => false,
    ];
}

/** Vorschlag: etwa eine Konstruktivist*in pro 4 Personen, dann Sonderrollen in fester Reihenfolge. */
function kf_default_config(int $n): array
{
    $konstr = $n <= 6 ? 1 : ($n <= 9 ? 2 : ($n <= 12 ? 3 : ($n <= 16 ? 4 : 5)));
    $specials = array_slice(KF_SPECIALS, 0, max(0, min(count(KF_SPECIALS), $n - $konstr)));
    return ['konstr' => $konstr, 'roles' => $specials];
}

/** Konfiguration prüfen: gibt eine Fehlermeldung zurück oder null. */
function kf_config_error(array $c, int $n): ?string
{
    if ($n < KF_MIN_PLAYERS) {
        return 'Mindestens ' . KF_MIN_PLAYERS . ' Personen müssen mitspielen';
    }
    if ($c['konstr'] < 1 || 2 * $c['konstr'] >= $n) {
        return 'Ungültige Zahl an Konstruktivist*innen';
    }
    if ($c['konstr'] + count($c['roles']) > $n) {
        return 'Zu viele Rollen für die Zahl der Mitspielenden';
    }
    return null;
}

function kf_clean_config($in): ?array
{
    if (!is_array($in) || !is_int($in['konstr'] ?? null) || !is_array($in['roles'] ?? null)) {
        return null;
    }
    $roles = [];
    foreach (KF_SPECIALS as $r) {   // feste Reihenfolge, keine Doppelten, nur bekannte
        if (in_array($r, $in['roles'], true)) {
            $roles[] = $r;
        }
    }
    if (count($roles) !== count($in['roles'])) {
        return null;
    }
    return ['konstr' => $in['konstr'], 'roles' => $roles];
}

function kf_me(array $s, ?int $seat, string $token): ?array
{
    if ($seat === null || $token === '') {
        return null;
    }
    foreach ($s['players'] as $p) {
        if ($p['seat'] === $seat && hash_equals($p['token'], $token)) {
            return $p;
        }
    }
    return null;
}

/** Siehe orphaned() in Api.php: mitspielend = noch auf der Konferenz. */
function kf_orphaned(array $room): bool
{
    return orphaned($room, fn($p) => $p['alive']);
}

function &kf_player(array &$s, int $seat): ?array
{
    foreach ($s['players'] as &$p) {
        if ($p['seat'] === $seat) {
            return $p;
        }
    }
    $none = null;
    return $none;
}

function kf_alive(array $s): array
{
    return array_values(array_map(fn($p) => $p['seat'], array_filter($s['players'], fn($p) => $p['alive'])));
}

/** Platz der lebenden Person mit dieser Rolle (jede Sonderrolle gibt es höchstens einmal). */
function kf_role_seat(array $s, string $role): ?int
{
    foreach ($s['players'] as $p) {
        if ($p['alive'] && $p['role'] === $role) {
            return $p['seat'];
        }
    }
    return null;
}

function kf_team_alive(array $s, string $team): array
{
    return array_values(array_map(fn($p) => $p['seat'], array_filter($s['players'], fn($p) => $p['alive'] && $p['team'] === $team)));
}

/**
 * Wer muss in dieser Nacht noch etwas tun? [Aufgabe => Platz] (Konstruktivist*innen: Platz der ersten).
 * Der KI-Nerd ist erst dran, wenn die Konstruktivist*innen gewählt haben.
 */
function kf_night_tasks(array $s): array
{
    $n = $s['night'];
    $tasks = [];
    $konstr = kf_team_alive($s, 'konstr');
    if ($konstr && !array_key_exists('victim', $n)) {
        $tasks['konstr'] = $konstr[0];
    }
    foreach (['lean', 'numerik', 'reviewer'] as $role) {
        $seat = kf_role_seat($s, $role);
        if ($seat !== null && !array_key_exists($role, $n['acts'])) {   // null = „keine Aktion“
            $tasks[$role] = $seat;
        }
    }
    if ($s['round'] === 1) {
        foreach (['betreuer', 'doktorand'] as $role) {
            $seat = kf_role_seat($s, $role);
            if ($seat !== null && !array_key_exists($role, $n['acts'])) {
                $tasks[$role] = $seat;
            }
        }
    }
    $ki = kf_role_seat($s, 'ki');
    if ($ki !== null && ($s['potions']['heal'] || $s['potions']['gap']) && !array_key_exists('ki', $n['acts'])) {
        $tasks['ki'] = $ki;   // erst wählbar, wenn 'victim' feststeht (siehe kf_act)
    }
    return $tasks;
}

/** Person verlässt die Konferenz (mit Folgen: Koautor*in, Physiker*in, Vorbild der Doktorand*in). */
function kf_leave(array &$s, int $seat, string $cause, string $at): void
{
    $p = &kf_player($s, $seat);
    if ($p === null || !$p['alive']) {
        return;
    }
    $p['alive'] = false;
    unset($s['removals'][$seat]);   // offene Anfrage zum Herausnehmen erledigt sich
    $s['log'][] = ['round' => $s['round'], 'at' => $at, 'type' => 'leave', 'seat' => $seat, 'role' => $p['role'],
                   'converted' => !empty($p['converted']), 'cause' => $cause];
    $role = $p['role'];
    unset($p);
    if ($cause === 'quit') {
        return;   // freiwillig gegangen (Anschlusstermin …): keine Folgen
    }
    if ($role === 'physik') {
        $s['shots'][] = $seat;
    }
    if ($s['vorbild'] === $seat) {
        $d = kf_role_seat($s, 'doktorand');
        if ($d !== null) {
            $dp = &kf_player($s, $d);
            $dp['team'] = 'konstr';
            $dp['converted'] = true;   // geheim: Das merken nur die Konstruktivist*innen
            unset($dp);
        }
    }
    if ($s['coauthors'] && in_array($seat, $s['coauthors'], true)) {
        $other = $s['coauthors'][0] === $seat ? $s['coauthors'][1] : $s['coauthors'][0];
        kf_leave($s, $other, 'koautor', $at);
    }
}

/**
 * Gewonnen? Die Konstruktivist*innen gewinnen früh, sobald sie (bei bestem Spiel) keine andere Partei
 * mehr gewinnen lassen müssen: Sie sind mindestens so viele wie die anderen (Gleichstand = niemand geht)
 * plus ein Polster für jede noch mögliche Gegenwehr außerhalb der Abstimmung.
 */
function kf_check_win(array &$s): bool
{
    $alive = kf_alive($s);
    $pair = $s['coauthors'];
    $mixed = $pair && kf_player($s, $pair[0])['team'] !== kf_player($s, $pair[1])['team']
             && in_array($pair[0], $alive, true) && in_array($pair[1], $alive, true);
    $win = null;
    if (!$alive) {
        $win = ['party' => 'none', 'seats' => []];
    } elseif ($mixed && count($alive) === 2) {
        $win = ['party' => 'koautor', 'seats' => $pair];
    } elseif (!kf_team_alive($s, 'konstr')) {
        $win = ['party' => 'konf', 'seats' => array_values(array_map(fn($p) => $p['seat'],
            array_filter($s['players'], fn($p) => $p['team'] !== 'konstr' && !($mixed && in_array($p['seat'], $pair, true)))))];
    } else {
        $w = count(array_filter(kf_team_alive($s, 'konstr'), fn($x) => !($mixed && in_array($x, $pair, true))));
        $o = count($alive) - $w;
        $threat = 0;
        if (kf_role_seat($s, 'ki') !== null && $s['potions']['gap']) {
            $threat += 1;   // findet einmal eine Lücke
        }
        if (kf_role_seat($s, 'physik') !== null) {
            $threat += 1;   // reißt beim Gehen jemanden mit
        }
        if (kf_role_seat($s, 'reviewer') !== null) {
            $threat += 2;   // +2 Stimmen gegen eine Person
        }
        if ($pair && !$mixed && kf_player($s, $pair[0])['team'] === 'konstr'
            && in_array($pair[0], $alive, true) && in_array($pair[1], $alive, true)) {
            $threat += 1;   // zwei Konstruktivist*innen als Koautor*innen: eine Abstimmung trifft beide
        }
        $hausmeister = !$s['day1Done'] && kf_role_seat($s, 'hausmeister') !== null;
        if ($w > 0 && !$hausmeister && $w >= $o + $threat) {
            $win = ['party' => 'konstr', 'seats' => array_values(array_map(fn($p) => $p['seat'],
                array_filter($s['players'], fn($p) => $p['team'] === 'konstr' && !($mixed && in_array($p['seat'], $pair, true)))))];
        }
    }
    if ($win === null) {
        return false;
    }
    $s['winner'] = $win;
    $s['phase'] = 'over';
    $s['over'] = true;
    return true;
}

function kf_start_night(array &$s): void
{
    $s['round']++;
    $s['phase'] = 'night';
    $s['night'] = ['proposals' => [], 'acts' => []];
    $s['votes'] = [];
    $s['mark'] = null;
    kf_maybe_dawn($s);   // z. B. niemand mehr mit Nachtaktion
}

function kf_start_day(array &$s): void
{
    $s['phase'] = 'day';
    $s['votes'] = [];
    if ($s['mark'] !== null && !in_array($s['mark'], kf_alive($s), true)) {
        $s['mark'] = null;
    }
}

/** Nach Schüssen/Abgängen: Physiker*in zuerst, dann Sieg prüfen, dann weiter. */
function kf_continue(array &$s, string $next): void
{
    if ($s['shots']) {
        $s['phase'] = 'shot';
        $s['after'] = $next;
        return;
    }
    if (kf_check_win($s)) {
        return;
    }
    $next === 'day' ? kf_start_day($s) : kf_start_night($s);
}

/** Alle Nachtaktionen erledigt? Dann wird es Morgen. */
function kf_maybe_dawn(array &$s): void
{
    if ($s['phase'] !== 'night' || kf_night_tasks($s)) {
        return;
    }
    $n = $s['night'];
    $victim = $n['victim'] ?? null;
    $acts = $n['acts'];
    $s['protectLast'] = $acts['numerik'] ?? null;
    $s['mark'] = $acts['reviewer'] ?? null;
    $saved = false;
    if ($victim !== null && in_array($victim, kf_alive($s), true)) {
        $p = &kf_player($s, $victim);
        if (($acts['numerik'] ?? null) === $victim || ($acts['kiHeal'] ?? null) === $victim) {
            $saved = true;
        } elseif ($p['role'] === 'logik' && $p['shield']) {
            $p['shield'] = false;   // formal so sauber, dass der erste Angriff abprallt
            $saved = true;
        }
        unset($p);
        if (!$saved) {
            kf_leave($s, $victim, 'konstr', 'night');
        }
    }
    if (isset($acts['kiGap'])) {
        kf_leave($s, $acts['kiGap'], 'gap', 'night');
    }
    $s['night'] = null;
    kf_continue($s, 'day');
}

/** Abstimmung auswerten, sobald alle Verbliebenen abgestimmt haben. */
function kf_maybe_resolve_day(array &$s): void
{
    if ($s['phase'] !== 'day') {
        return;
    }
    $alive = kf_alive($s);
    foreach ($alive as $seat) {
        if (!array_key_exists($seat, $s['votes'])) {
            return;
        }
    }
    // nur Stimmen von und für Verbliebene (wer tagsüber gegangen ist, zählt nicht mehr); -1 = niemand
    $count = [];
    foreach ($s['votes'] as $by => $target) {
        if (in_array((int)$by, $alive, true) && ($target === -1 || in_array($target, $alive, true))) {
            $count[$target] = ($count[$target] ?? 0) + 1;
        }
    }
    if ($s['mark'] !== null && in_array($s['mark'], $alive, true)) {
        $count[$s['mark']] = ($count[$s['mark']] ?? 0) + 2;
    }
    arsort($count);
    $top = array_keys($count, max($count ?: [0]), true);
    $out = count($top) === 1 && $top[0] !== -1 ? $top[0] : null;
    $s['log'][] = ['round' => $s['round'], 'at' => 'day', 'type' => 'vote', 'votes' => $s['votes'], 'mark' => $s['mark'], 'out' => $out];
    $firstDay = !$s['day1Done'];
    $s['day1Done'] = true;
    if ($out !== null) {
        $isHausmeister = kf_player($s, $out)['role'] === 'hausmeister';
        kf_leave($s, $out, 'vote', 'day');
        if ($firstDay && $isHausmeister) {
            // hat die übrigen Tage frei – und allein gewonnen
            $s['winner'] = ['party' => 'hausmeister', 'seats' => [$out]];
            $s['phase'] = 'over';
            $s['over'] = true;
            return;
        }
    }
    $s['votes'] = [];
    kf_continue($s, 'night');
}

/** Ziel einer Aktion: Platz einer lebenden Person (optional nicht man selbst). */
function kf_target(array $s, array $in, ?int $notSeat = null, string $key = 'target'): int
{
    $t = is_int($in[$key] ?? null) ? $in[$key] : -1;
    if (!in_array($t, kf_alive($s), true) || $t === $notSeat) {
        fail('Ungültige Person');
    }
    return $t;
}

/** Was eine bestimmte Person sehen darf. */
function kf_view(array $s, ?int $seat, string $token): array
{
    $me = kf_me($s, $seat, $token);
    $over = $s['phase'] === 'over';
    $mine = $me['seat'] ?? null;
    $konstrMe = $me && $me['alive'] && $me['team'] === 'konstr';
    $v = [
        'phase' => $s['phase'], 'game' => $s['game'], 'round' => $s['round'], 'over' => $s['over'],
        'config' => $s['config'], 'log' => $s['log'], 'winner' => $s['winner'],
        'mark' => $s['phase'] === 'day' ? $s['mark'] : null,   // öffentlich, aber nicht wer markiert hat
        'votes' => $s['phase'] === 'day' ? $s['votes'] : [],
        'shooter' => $s['phase'] === 'shot' ? ($s['shots'][0] ?? null) : null,
        'removals' => (object)($s['removals'] ?? []),   // Platz => [Plätze, die das Herausnehmen bestätigt haben]
        'players' => [], 'remaining' => [],
    ];
    foreach ($s['players'] as $p) {
        $show = $over || !$p['alive'] || $p['seat'] === $mine || ($konstrMe && $p['team'] === 'konstr');
        $v['players'][] = [
            'seat' => $p['seat'], 'name' => $p['name'], 'alive' => $p['alive'],
            'role' => $show ? $p['role'] : null,
            'konstr' => $show && ($over || $konstrMe || !$p['alive']) ? $p['team'] === 'konstr' : null,
        ];
        if ($p['alive']) {
            $v['remaining'][$p['role']] = ($v['remaining'][$p['role']] ?? 0) + 1;
        }
    }
    // Eigene Rolle und Wissen (nur für die Person selbst, auch nach dem Ausscheiden)
    if ($me) {
        $my = ['seat' => $mine, 'role' => $me['role'], 'alive' => $me['alive'], 'konstr' => $me['team'] === 'konstr',
               'converted' => !empty($me['converted'])];
        $role = $me['role'];
        if ($role === 'lean') {
            $my['lean'] = $s['lean'];
        }
        if ($role === 'betreuer' || ($s['coauthors'] && in_array($mine, $s['coauthors'], true))) {
            $my['coauthors'] = $s['coauthors'];
        }
        if ($role === 'doktorand') {
            $my['vorbild'] = $s['vorbild'];
        }
        if ($role === 'ki') {
            $my['potions'] = $s['potions'];
        }
        if ($role === 'numerik') {
            $my['protectLast'] = $s['protectLast'];
        }
        if ($me['alive'] && $s['phase'] === 'night') {
            $n = $s['night'];
            $tasks = kf_night_tasks($s);
            $my['tasks'] = [];
            foreach ($tasks as $task => $taskSeat) {
                $mineTask = $task === 'konstr' ? $konstrMe : $taskSeat === $mine;
                if ($mineTask && !($task === 'ki' && !array_key_exists('victim', $n))) {
                    $my['tasks'][] = $task;
                }
            }
            if ($role === 'ki' && array_key_exists('victim', $n)) {
                $my['victim'] = $n['victim'];
                $my['kiHeal'] = $n['acts']['kiHeal'] ?? null;
                $my['kiGap'] = $n['acts']['kiGap'] ?? null;
            }
            if ($konstrMe) {
                $my['proposals'] = $n['proposals'];
                $my['victim'] = array_key_exists('victim', $n) ? $n['victim'] : false;
            }
            foreach (['numerik', 'reviewer', 'betreuer', 'doktorand'] as $r) {
                if ($role === $r && array_key_exists($r, $n['acts'])) {
                    $my['done'] = $n['acts'][$r];
                }
            }
        }
        $v['me'] = $my;
    }
    return $v;
}

/** Spielaktion ausführen. Verändert $room['state'] oder bricht mit fail() ab. */
function kf_act(array &$room, int $seat, string $token, string $op, array $in): void
{
    $s = &$room['state'];
    $me = kf_me($s, $seat, $token);
    $mine = $me && $me['alive'] ? $me['seat'] : null;   // null für Zuschauende und Ausgeschiedene
    $running = !in_array($s['phase'], ['lobby', 'over'], true);

    switch ($op) {
        case 'config':
            // Rollenverteilung vor dem Start (gemeinsam, wie die Kartenwahl bei Trombone)
            if ($running) {
                fail('Das Spiel läuft schon');
            }
            if (!empty($in['reset'])) {
                $s['config'] = null;
                return;
            }
            $c = kf_clean_config($in['config'] ?? null);
            if ($c === null || $c['konstr'] < 1 || $c['konstr'] > KF_MAX_PLAYERS) {
                fail('Ungültige Rollenverteilung');
            }
            $s['config'] = $c;
            return;

        case 'start':
            if ($running) {
                fail('Das Spiel läuft schon');
            }
            $players = [];
            foreach ($room['seats'] as $i => $p) {
                if ($p !== null) {
                    $players[] = ['seat' => $i, 'name' => $p['name'], 'token' => $p['token']];
                }
            }
            $n = count($players);
            if (($in['players'] ?? null) !== $n) {
                fail('Die Mitspielenden haben sich gerade geändert – bitte noch einmal prüfen.', 409);
            }
            $config = $s['config'] ?? kf_default_config($n);
            $error = kf_config_error($config, $n);
            if ($error !== null) {
                fail($error, 409);
            }
            $roles = array_merge(array_fill(0, $config['konstr'], 'konstr'), $config['roles'],
                                 array_fill(0, $n - $config['konstr'] - count($config['roles']), 'mathe'));
            shuffle($roles);
            $s = kf_init($s['game'] + 1, $s['config']);
            foreach ($players as $i => $p) {
                $s['players'][] = $p + ['role' => $roles[$i], 'team' => $roles[$i] === 'konstr' ? 'konstr' : 'konf',
                                        'alive' => true, 'shield' => $roles[$i] === 'logik', 'converted' => false];
            }
            kf_start_night($s);
            return;

        case 'propose':   // Konstruktivist*innen: vorschlagen …
        case 'choose':    // … und auswählen
        case 'act':       // alle anderen Nachtrollen
        case 'noact':     // „diese Nacht keine Aktion“
            if ($s['phase'] !== 'night' || $mine === null) {
                fail('Gerade ist keine Nachtaktion möglich', 409);
            }
            $n = &$s['night'];
            $role = $me['team'] === 'konstr' ? 'konstr' : $me['role'];
            $tasks = kf_night_tasks($s);
            $myTask = $role === 'konstr' ? isset($tasks['konstr']) : (($tasks[$role] ?? null) === $mine);
            if (!$myTask) {
                fail('Du hast in dieser Nacht nichts (mehr) zu tun', 409);
            }
            // Konstruktivist*innen: propose/choose/noact; alle anderen Nachtrollen: act/noact
            if ($role === 'konstr' ? $op === 'act' : in_array($op, ['propose', 'choose'], true)) {
                fail('Unbekannte Aktion');
            }
            if ($role === 'konstr') {
                if ($op === 'noact') {
                    $n['victim'] = null;
                } else {
                    $t = kf_target($s, $in, $mine);
                    if (kf_player($s, $t)['team'] === 'konstr') {
                        fail('Ungültige Person');
                    }
                    if ($op === 'propose') {
                        $mineProps = $n['proposals'][$mine] ?? [];
                        if (!in_array($t, $mineProps, true)) {
                            $n['proposals'][$mine] = array_merge($mineProps, [$t]);
                        }
                        return;
                    }
                    if (!in_array($t, $n['proposals'][$mine] ?? [], true)) {
                        fail('Erst vorschlagen, dann auswählen', 409);
                    }
                    $n['victim'] = $t;
                }
            } elseif ($op === 'noact') {
                $n['acts'][$role] = null;
            } else {
                switch ($role) {
                    case 'lean':
                        $t = kf_target($s, $in, $mine);
                        $n['acts']['lean'] = $t;
                        $s['lean'][] = ['round' => $s['round'], 'target' => $t, 'konstr' => kf_player($s, $t)['team'] === 'konstr'];
                        break;
                    case 'numerik':
                        $t = kf_target($s, $in);
                        if ($t === $s['protectLast']) {
                            fail('Nicht zweimal hintereinander denselben Beweis', 409);
                        }
                        $n['acts']['numerik'] = $t;
                        break;
                    case 'reviewer':
                        $n['acts']['reviewer'] = kf_target($s, $in, $mine);
                        break;
                    case 'doktorand':
                        $t = kf_target($s, $in, $mine);
                        $n['acts']['doktorand'] = $t;
                        $s['vorbild'] = $t;
                        break;
                    case 'betreuer':
                        $a = kf_target($s, $in, null, 'target');
                        $b = kf_target($s, $in, null, 'second');
                        if ($a === $b) {
                            fail('Bitte zwei verschiedene Personen wählen');
                        }
                        $n['acts']['betreuer'] = [$a, $b];
                        $s['coauthors'] = [$a, $b];
                        break;
                    case 'ki':
                        if (!array_key_exists('victim', $n)) {
                            fail('Die Konstruktivist*innen haben noch nicht gewählt', 409);
                        }
                        $what = param($in, 'what');
                        if ($what === 'heal') {
                            if (!$s['potions']['heal'] || $n['victim'] === null) {
                                fail('Gerade nicht möglich', 409);
                            }
                            $s['potions']['heal'] = false;
                            $n['acts']['kiHeal'] = $n['victim'];
                            break;   // danach noch „Lücke“ oder „fertig“ – oder, war es der letzte Trank, ist die Nacht vorbei
                        }
                        if ($what === 'gap') {
                            if (!$s['potions']['gap']) {
                                fail('Gerade nicht möglich', 409);
                            }
                            $s['potions']['gap'] = false;
                            $n['acts']['kiGap'] = kf_target($s, $in, $mine);
                            break;
                        }
                        if ($what !== 'done') {
                            fail('Unbekannte Aktion');
                        }
                        $n['acts']['ki'] = true;
                        break;
                    default:
                        fail('Du hast in dieser Nacht nichts zu tun', 409);
                }
            }
            unset($n);
            kf_maybe_dawn($s);
            return;

        case 'vote':
            if ($s['phase'] !== 'day' || $mine === null) {
                fail('Gerade wird nicht abgestimmt', 409);
            }
            $t = is_int($in['target'] ?? null) ? $in['target'] : -2;
            if ($t !== -1) {
                $t = kf_target($s, $in, $mine);
            }
            if (($in['round'] ?? null) !== $s['round']) {
                fail('Die Abstimmung ist schon vorbei – bitte noch einmal prüfen.', 409);
            }
            $s['votes'][$mine] = $t;
            kf_maybe_resolve_day($s);
            return;

        case 'shoot':
            if ($s['phase'] !== 'shot' || !$me || ($s['shots'][0] ?? null) !== $me['seat']) {
                fail('Gerade nicht möglich', 409);
            }
            $t = kf_target($s, $in, $me['seat']);
            array_shift($s['shots']);
            unset($s['removals'][$me['seat']]);
            kf_leave($s, $t, 'physik', $s['after'] === 'day' ? 'night' : 'day');
            if (!$s['shots']) {
                $next = $s['after'];
                $s['after'] = null;
                kf_continue($s, $next);
            }
            return;

        case 'leave':
        case 'remove':
            // Selbst gehen (Anschlusstermin, Bett …) oder eine abwesende Person herausnehmen. Herausnehmen
            // (auch: das Mitreißen einer Physiker*in verfallen lassen, die nicht mehr da ist) geschieht erst,
            // wenn mindestens die Hälfte der übrigen Mitspielenden bestätigt hat.
            if (!$running || $mine === null) {
                fail('Nur Mitspielende können das', 403);
            }
            if ($op === 'remove') {
                $skipShot = $s['phase'] === 'shot' && ($in['seat'] ?? null) === ($s['shots'][0] ?? null);
                $t = $skipShot ? $s['shots'][0] : kf_target($s, $in, $mine, 'seat');
                $alive = kf_alive($s);
                $by = array_values(array_intersect($s['removals'][$t] ?? [], $alive));   // nur noch Mitspielende
                $by = !empty($in['withdraw']) ? array_values(array_diff($by, [$mine])) : array_values(array_unique([...$by, $mine]));
                if (count($by) * 2 < count(array_diff($alive, [$t]))) {
                    if ($by) {
                        $s['removals'][$t] = $by;
                    } else {
                        unset($s['removals'][$t]);
                    }
                    return;
                }
                unset($s['removals'][$t]);
                if ($skipShot) {
                    array_shift($s['shots']);
                    if (!$s['shots']) {
                        $next = $s['after'];
                        $s['after'] = null;
                        kf_continue($s, $next);
                    }
                    return;
                }
            } else {
                $t = $mine;
            }
            kf_leave($s, $t, 'quit', $s['phase'] === 'day' ? 'day' : 'night');
            $s['shots'] = array_values(array_diff($s['shots'], [$t]));
            if ($s['phase'] === 'shot' && !$s['shots']) {
                $next = $s['after'];
                $s['after'] = null;
                kf_continue($s, $next);
            } elseif (!kf_check_win($s)) {
                $s['phase'] === 'night' ? kf_maybe_dawn($s) : kf_maybe_resolve_day($s);
            }
            return;

        case 'lobby':
            // Neues Spiel vorbereiten; ein laufendes abbrechen dürfen nur Mitspielende
            if ($running && $mine === null && !kf_orphaned($room)) {
                fail('Nur aktive Mitspielende können das Spiel abbrechen', 403);
            }
            $s = kf_init($s['game'], $s['config']);
            return;

        default:
            fail('Unbekannte Aktion');
    }
}
