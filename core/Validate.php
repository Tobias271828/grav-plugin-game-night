<?php
/*
 * Game Night – Strukturprüfung für Spielstände, die der Browser schickt (Vier in einer Reihe,
 * Schach zu viert, Zahlenkampf). Die Spielregeln selbst prüft der Server nicht; diese Prüfung
 * verhindert aber, dass jemand einen Stand speichert, der die Browser der anderen einfriert
 * (riesige Zahlen, endlose Zuglisten) oder ihnen schadet (z. B. „__proto__“ als Koordinate).
 */

namespace GameNight;

\defined('GAME_NIGHT') or die();

const STATE_MAX_DEPTH = 8;
const STATE_MAX_NODES = 10000;
const STATE_MAX_NUMBER = 1000000;
const STATE_MAX_STRING = 100;

/** Allgemeine Grenzen für beliebige Spielstände; zählt die Knoten in $nodes mit. */
function valid_tree($value, int $depth, int &$nodes): bool
{
    if (++$nodes > STATE_MAX_NODES || $depth > STATE_MAX_DEPTH) {
        return false;
    }
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            // Schlüssel: Zahlen (Listen) oder einfache Bezeichner – kein __proto__, constructor & Co.
            if (is_string($key) && (!preg_match('/^[a-zA-Z][a-zA-Z0-9]{0,30}$/', $key) || $key === 'constructor')) {
                return false;
            }
            if (!valid_tree($item, $depth + 1, $nodes)) {
                return false;
            }
        }
        return true;
    }
    if (is_int($value) || is_float($value)) {
        return is_finite((float)$value) && abs($value) <= STATE_MAX_NUMBER;
    }
    if (is_string($value)) {
        return strlen($value) <= STATE_MAX_STRING;
    }
    return is_bool($value) || $value === null;
}

/** Liste (fortlaufende Indizes ab 0) mit höchstens $max Einträgen, die alle $each erfüllen. */
function is_list_of($value, int $max, callable $each): bool
{
    if (!is_array($value) || count($value) > $max || ($value && array_keys($value) !== range(0, count($value) - 1))) {
        return false;
    }
    foreach ($value as $item) {
        if (!$each($item)) {
            return false;
        }
    }
    return true;
}

function int_in($v, int $min, int $max): bool
{
    return is_int($v) && $v >= $min && $v <= $max;
}

/** Prüft einen vom Browser geschickten Spielstand von $game mit $seats Plätzen. */
function valid_state(string $game, $state, int $seats): bool
{
    $nodes = 0;
    if (!is_array($state) || !valid_tree($state, 0, $nodes)) {
        return false;
    }
    // Wer am Zug ist: Liste gültiger Plätze (auch für vom Server angelegte Startzustände)
    if (isset($state['turn']) && !is_list_of($state['turn'], $seats, fn($s) => int_in($s, 0, $seats - 1))) {
        return false;
    }
    if (isset($state['over']) && !is_bool($state['over'])) {
        return false;
    }
    switch ($game) {
        case 'connect4':
            return is_list_of($state['board'] ?? null, 42, fn($v) => int_in($v, -1, 1)) && count($state['board']) === 42
                && (($state['last'] ?? null) === null || int_in($state['last'], 0, 41))
                && (($state['line'] ?? null) === null || is_list_of($state['line'], 42, fn($v) => int_in($v, 0, 41)));

        case 'handbrain':
            return is_list_of($state['moves'] ?? null, 400, fn($m) => is_string($m) && preg_match('/^[A-Za-z0-9+#=\-]{1,10}$/', $m))
                && in_array($state['phase'] ?? null, ['choose', 'move'], true)
                && in_array($state['piece'] ?? null, [null, 'p', 'n', 'b', 'r', 'q', 'k'], true);

        case 'zahlenkampf':
            $kinds = ['b', 'm', 'sp1', 'sp2', 'st1', 'st2', 'P'];
            $piece = fn($s) => is_array($s) && int_in($s['v'] ?? null, 1, 100000)
                && in_array($s['kind'] ?? null, $kinds, true)
                && (!isset($s['base']) || int_in($s['base'], 1, 100000));
            $stone = fn($s) => $piece($s) && int_in($s['id'] ?? null, 0, 100000) && int_in($s['side'] ?? null, 0, 1)
                && int_in($s['r'] ?? null, 0, 7) && int_in($s['c'] ?? null, 0, 15) && is_bool($s['conv'] ?? false);
            return is_list_of($state['stones'] ?? null, 48, $stone)
                && is_list_of($state['reserve'] ?? null, 2, fn($r) => is_list_of($r, 48, $piece)) && count($state['reserve']) === 2
                && is_list_of($state['captured'] ?? [], 48, fn($v) => int_in($v, 0, 100000))
                && (($state['harmony'] ?? null) === null || is_list_of($state['harmony'], 48, fn($v) => int_in($v, 0, 100000)))
                && is_list_of($state['log'] ?? [], 200, 'is_array');
    }
    return true;   // Spiele mit Server-Logik: Zustand kommt nie vom Browser
}
