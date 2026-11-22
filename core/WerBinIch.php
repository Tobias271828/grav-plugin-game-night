<?php
/*
 * Wer bin ich? – läuft (wie Trombone) komplett auf dem Server, damit niemand den eigenen Begriff
 * zu sehen bekommt: Der Server liefert einen Begriff nie an die Person aus, für die er gedacht ist
 * (erst, wenn sie ihn erraten hat).
 *
 * Ablauf: lobby → choosing → playing → over
 *   choosing: Jede Person reserviert eine andere Person („pick“) und schreibt ihr einen Begriff („term“).
 *             Für jede Person schreibt genau eine andere. Eine Reservierung, nach der für die letzte
 *             übrige Person nur noch sie selbst bliebe, lehnt der Server ab.
 *   playing:  Alle sehen die Begriffe der anderen; wer den eigenen erraten hat, meldet „guessed“.
 *   over:     Alle haben erraten (oder es sind zu wenige Personen übrig).
 */

namespace GameNight;

\defined('GAME_NIGHT') or die();

const WB_MAX_TERM = 60;

function wb_init(int $game = 0): array
{
    return [
        'phase'    => 'lobby',          // lobby | choosing | playing | over
        'game'     => $game,            // zählt die Spiele an diesem Tisch (für Hinweise)
        'players'  => [],               // [{seat, name, token, out}]
        'picks'    => [],               // Platz der schreibenden Person => {target, term} (wird gefiltert ausgeliefert)
        'guessed'  => [],               // Plätze in der Reihenfolge, in der sie erraten haben
        'complete' => false,            // alle haben erraten (sonst: vorzeitig beendet)
        'over'     => false,
    ];
}

/** Die Person, die zu diesem Platz UND diesem Browser-Token gehört (auch wenn sie entfernt wurde). */
function wb_me(array $s, ?int $seat, string $token): ?array
{
    if ($seat === null || $token === '') {
        return null;
    }
    foreach ($s['players'] as $p) {
        if ($p['seat'] === $seat && isset($p['token']) && hash_equals($p['token'], $token)) {
            return $p;
        }
    }
    return null;
}

/** Siehe orphaned() in Api.php: mitspielend = noch nicht ausgestiegen. */
function wb_orphaned(array $room): bool
{
    return orphaned($room, fn($p) => !$p['out']);
}

/** Plätze aller (noch) Mitspielenden. */
function wb_active(array $s): array
{
    return array_values(array_map(fn($p) => $p['seat'], array_filter($s['players'], fn($p) => !$p['out'])));
}

/** Wer schreibt für $target? */
function wb_writer_for(array $s, int $target): ?int
{
    foreach ($s['picks'] as $by => $pick) {
        if ($pick['target'] === $target) {
            return (int)$by;
        }
    }
    return null;
}

/**
 * Sackgasse: Ist nur noch eine Person ohne Auswahl übrig und ist sie zugleich die einzige,
 * für die noch niemand schreibt, müsste sie sich selbst wählen. Solange mindestens zwei übrig
 * sind, geht es immer auf.
 */
function wb_stuck(array $s): bool
{
    $seats = wb_active($s);
    $writers = array_values(array_filter($seats, fn($p) => !isset($s['picks'][$p])));
    $targets = array_values(array_diff($seats, array_column($s['picks'], 'target')));
    return count($writers) === 1 && $writers === $targets;
}

/** Alle Begriffe geschrieben? Dann geht es los. */
function wb_check_ready(array &$s): void
{
    $seats = wb_active($s);
    foreach ($seats as $p) {
        if (!isset($s['picks'][$p]) || $s['picks'][$p]['term'] === '' || wb_writer_for($s, $p) === null) {
            return;
        }
    }
    $s['phase'] = 'playing';
}

/** Haben alle (noch Mitspielenden) ihren Begriff erraten? */
function wb_check_over(array &$s): void
{
    foreach (wb_active($s) as $p) {
        if (wb_writer_for($s, $p) !== null && !in_array($p, $s['guessed'], true)) {
            return;
        }
    }
    $s['phase'] = 'over';
    $s['over'] = true;
    $s['complete'] = true;
}

function wb_finish_early(array &$s): void
{
    $s['phase'] = 'over';
    $s['over'] = true;
}

/** Was eine bestimmte Person sehen darf. */
function wb_view(array $s, ?int $seat, string $token): array
{
    $me = wb_me($s, $seat, $token);
    $mine = $me ? $me['seat'] : null;
    $entries = [];
    foreach ($s['picks'] as $by => $pick) {
        $by = (int)$by;
        $target = $pick['target'];
        // Erratene Begriffe sehen alle. Sonst: beim Aussuchen nur die schreibende Person,
        // danach alle, die mitspielen (oder mitgespielt haben) – außer der Zielperson selbst.
        $visible = in_array($target, $s['guessed'], true)
            || ($s['phase'] === 'choosing' && $by === $mine)
            || ($s['phase'] !== 'choosing' && $mine !== null && $target !== $mine);
        $entries[] = [
            'by'     => $by,
            'target' => $target,
            'done'   => $pick['term'] !== '',
            'term'   => $visible ? $pick['term'] : null,
        ];
    }
    $s['picks'] = $entries;
    $s['meSeat'] = $me && !$me['out'] ? $mine : null;   // spiele ich (mit diesem Browser) mit?
    foreach ($s['players'] as &$p) {
        unset($p['token']);
    }
    unset($p);
    return $s;
}

/** Spielaktion ausführen. Verändert $room['state'] oder bricht mit fail() ab. */
function wb_act(array &$room, int $seat, string $token, string $op, array $in): void
{
    $s = &$room['state'];
    $me = wb_me($s, $seat, $token);
    $mine = $me && !$me['out'] ? $me['seat'] : null;   // null für Zuschauende und Entfernte
    $running = in_array($s['phase'], ['choosing', 'playing'], true);

    switch ($op) {
        case 'start':
            if ($running) {
                fail('Das Spiel läuft schon');
            }
            $seated = [];
            foreach ($room['seats'] as $i => $p) {
                if ($p !== null) {
                    $seated[] = ['seat' => $i, 'name' => $p['name'], 'token' => $p['token'], 'out' => false];
                }
            }
            if (count($seated) < 2) {
                fail('Mindestens zwei Personen müssen mitspielen');
            }
            if (($in['players'] ?? null) !== count($seated)) {
                fail('Die Mitspielenden haben sich gerade geändert – bitte noch einmal prüfen.', 409);
            }
            $s = wb_init($s['game'] + 1);
            $s['players'] = $seated;
            $s['phase'] = 'choosing';
            return;

        case 'pick':
            // Person reservieren, für die man einen Begriff aussucht (ein früherer Begriff verfällt)
            if ($s['phase'] !== 'choosing') {
                fail('Die Begriffe stehen schon fest');
            }
            if ($mine === null) {
                fail('Du spielst in diesem Spiel nicht mit');
            }
            $target = is_int($in['target'] ?? null) ? $in['target'] : -1;
            if ($target === $mine || !in_array($target, wb_active($s), true)) {
                fail('Ungültige Person');
            }
            $writer = wb_writer_for($s, $target);
            if ($writer === $mine) {
                return;
            }
            if ($writer !== null) {
                fail('Für diese Person sucht schon jemand anderes einen Begriff aus', 409);
            }
            $s['picks'][$mine] = ['target' => $target, 'term' => ''];
            if (wb_stuck($s)) {
                fail('Dann bliebe für eine Person niemand übrig – bitte eine andere Person wählen', 409);   // nichts gespeichert
            }
            return;

        case 'unpick':
            if ($s['phase'] !== 'choosing') {
                fail('Die Begriffe stehen schon fest');
            }
            if ($mine === null) {
                fail('Du spielst in diesem Spiel nicht mit');
            }
            unset($s['picks'][$mine]);   // kann keine Sackgasse erzeugen
            return;

        case 'term':
            // Begriff speichern; ändern geht auch noch während des Ratens, solange er nicht erraten ist
            if (!$running) {
                fail('Unbekannte Aktion');
            }
            if ($mine === null) {
                fail('Du spielst in diesem Spiel nicht mit');
            }
            $pick = $s['picks'][$mine] ?? null;
            if ($pick === null) {
                fail('Wähle zuerst, für wen du einen Begriff aussuchst');
            }
            if (in_array($pick['target'], $s['guessed'], true)) {
                fail('Dieser Begriff ist schon erraten');
            }
            $term = clean_text(param($in, 'term'), WB_MAX_TERM);
            if ($term === '') {
                fail('Bitte einen Begriff eingeben');
            }
            $s['picks'][$mine]['term'] = $term;
            if ($s['phase'] === 'choosing') {
                wb_check_ready($s);
            }
            return;

        case 'guessed':
            if ($s['phase'] !== 'playing') {
                fail('Gerade wird nicht geraten');
            }
            if ($mine === null) {
                fail('Du spielst in diesem Spiel nicht mit');
            }
            if (wb_writer_for($s, $mine) === null) {
                fail('Für dich gibt es keinen Begriff');
            }
            if (!in_array($mine, $s['guessed'], true)) {   // doppelt geklickt: kein Fehler
                $s['guessed'][] = $mine;
                wb_check_over($s);
            }
            return;

        case 'remove':
            // Jemand ist weg: aus dem laufenden Spiel nehmen
            if ($mine === null || !$running) {
                fail('Nur aktive Mitspielende können jemanden entfernen', 403);
            }
            $target = is_int($in['seat'] ?? null) ? $in['seat'] : -1;
            if ($target === $mine || !in_array($target, wb_active($s), true)) {
                fail('Ungültiger Platz');
            }
            foreach ($s['players'] as &$p) {
                if ($p['seat'] === $target) {
                    $p['out'] = true;
                }
            }
            unset($p);
            // Begriff für die entfernte Person verfällt
            $writer = wb_writer_for($s, $target);
            if ($writer !== null) {
                unset($s['picks'][$writer]);
            }
            if ($s['phase'] === 'choosing') {
                // Ihr eigener Begriff ist noch nicht im Spiel: Die Zielperson bekommt jemand anderes
                unset($s['picks'][$target]);
            } else {
                // Beim Raten bleibt ihr Begriff für die andere Person bestehen
                $s['guessed'] = array_values(array_diff($s['guessed'], [$target]));
            }
            if (count(wb_active($s)) < 2) {
                wb_finish_early($s);
                return;
            }
            if ($s['phase'] === 'choosing') {
                if (wb_stuck($s)) {
                    // Eine andere Reservierung auflösen (möglichst eine ohne fertigen Begriff), dann geht es wieder auf
                    $release = null;
                    foreach ($s['picks'] as $by => $pick) {
                        if ($release === null || $pick['term'] === '') {
                            $release = $by;
                        }
                    }
                    unset($s['picks'][$release]);
                }
                wb_check_ready($s);
            } else {
                wb_check_over($s);
            }
            return;

        case 'lobby':
            // Neues Spiel vorbereiten; ein laufendes abbrechen dürfen nur Mitspielende
            if ($running && $mine === null && !wb_orphaned($room)) {
                fail('Nur aktive Mitspielende können das Spiel abbrechen', 403);
            }
            $s = wb_init($s['game']);
            return;

        default:
            fail('Unbekannte Aktion');
    }
}
