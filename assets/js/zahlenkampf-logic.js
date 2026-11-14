/* Zahlenkampfspiel (Rithmomachie) – reine Spiellogik, ohne Oberfläche.
 *
 * Brett: 8 Zeilen × 16 Spalten. Seite 0 („Gerade“) links (Spalten 0–7),
 * Seite 1 („Ungerade“) rechts (Spalten 8–15).
 */
'use strict';

const ZK = (function () {
    const ROWS = 8, COLS = 16;
    const DIRS = [[-1, 0], [1, 0], [0, -1], [0, 1]];

    // Steinarten: b = Grundzahl, m = multiplex, sp1/sp2 = superparticulares, st1/st2 = superpartientes
    const VALUE = {
        b: n => n,
        m: n => n * n,
        sp1: n => n * (n + 1),
        sp2: n => (n + 1) * (n + 1),
        st1: n => (n + 1) * (2 * n + 1),
        st2: n => (2 * n + 1) * (2 * n + 1),
    };
    const RANGE = { b: 1, m: 2, sp1: 3, sp2: 3, st1: 4, st2: 4 };
    const BASES = [[2, 4, 6, 8], [3, 5, 7, 9]];
    // Pyramide: Seite 0 → 91 (Kette 6, st1, Basis 6² = 36), Seite 1 → 190 (Kette 9, st1, Basis 8² = 64)
    const PYRAMID = [{ chain: 2, kind: 'st1', base: 36 }, { chain: 3, kind: 'st1', base: 64 }];

    // Aufstellung je Seite: [Abstand von der eigenen Grundlinie (0 = hinten), Zeile, Kette, Art]
    const LAYOUT = [
        [3, 2, 0, 'b'], [3, 3, 1, 'b'], [3, 4, 2, 'b'], [3, 5, 3, 'b'],
        [2, 0, 0, 'sp2'], [2, 1, 0, 'sp1'], [2, 2, 0, 'm'], [2, 3, 1, 'm'],
        [2, 4, 2, 'm'], [2, 5, 3, 'm'], [2, 6, 3, 'sp1'], [2, 7, 3, 'sp2'],
        [1, 0, 0, 'st1'], [1, 1, 0, 'st2'], [1, 2, 1, 'sp2'], [1, 3, 1, 'sp1'],
        [1, 4, 2, 'sp1'], [1, 5, 2, 'sp2'], [1, 6, 3, 'st2'], [1, 7, 3, 'st1'],
        [0, 2, 1, 'st1'], [0, 3, 1, 'st2'], [0, 4, 2, 'st2'], [0, 5, 2, 'st1'],
    ];

    function initState() {
        const stones = [];
        for (const side of [0, 1]) {
            for (const [depth, row, chain, kind] of LAYOUT) {
                const n = BASES[side][chain];
                const pyr = PYRAMID[side].chain === chain && PYRAMID[side].kind === kind;
                stones.push({
                    id: stones.length,
                    side,
                    v: VALUE[kind](n),
                    kind: pyr ? 'P' : kind,
                    base: pyr ? PYRAMID[side].base : undefined,
                    r: row,
                    c: side === 0 ? depth : COLS - 1 - depth,
                    conv: false,            // durch Wiedereinsetzen übergelaufen?
                });
            }
        }
        return {
            stones,                         // Steine auf dem Brett
            reserve: [[], []],              // erbeutete Steine je Seite (wieder einsetzbar)
            turn: [0],
            over: false,
            winner: null,
            end: null,                      // { type: 'harmony', kind, values } | { type: 'stuck' } | { type: 'resign', seat }
            last: null,                     // { from:[r,c]|null, to:[r,c] }
            captured: [],                   // Ids der zuletzt geschlagenen Steine (für Hervorhebung)
            log: [],                        // Zugprotokoll als Daten (Text erst in der Oberfläche)
            nextId: stones.length,
        };
    }

    const inBoard = (r, c) => r >= 0 && r < ROWS && c >= 0 && c < COLS;
    const homeSide = c => (c < COLS / 2 ? 0 : 1);

    function grid(state) {
        const g = Array.from({ length: ROWS }, () => Array(COLS).fill(null));
        for (const s of state.stones) {
            // Koordinaten aus dem Spielstand nie ungeprüft als Index benutzen (z. B. "__proto__")
            if (Number.isInteger(s.r) && Number.isInteger(s.c) && inBoard(s.r, s.c)) g[s.r][s.c] = s;
        }
        return g;
    }

    function isPrime(n) {
        if (!Number.isSafeInteger(n) || n < 2 || n > 1e7) return false;   // echte Werte sind klein
        for (let d = 2; d * d <= n; d++) if (n % d === 0) return false;
        return true;
    }

    /** Werte, mit denen ein Stein geschlagen werden kann (Pyramide auch über ihre Basis). */
    function targetValues(stone) {
        return stone.kind === 'P' ? [stone.v, stone.base] : [stone.v];
    }

    function ranges(stone) {
        return stone.kind === 'P' ? [1, 2, 3, 4] : [RANGE[stone.kind]];
    }

    /**
     * Alle Zielfelder eines Steins: genau k Schritte waagrecht/senkrecht,
     * gerade oder mit einem rechten Winkel, über freie Felder.
     * Ein besetztes Zielfeld ist nur bei einer „Begegnung“ (gleicher Wert) erlaubt.
     */
    function movesFor(state, stone, g = grid(state)) {
        const result = new Map();
        const free = (r, c) => inBoard(r, c) && g[r][c] === null;
        const consider = (r, c) => {
            if (!inBoard(r, c)) return;
            const t = g[r][c];
            if (t === null) result.set(r * COLS + c, { r, c, capture: null });
            else if (t.side !== stone.side && targetValues(t).includes(stone.v)) result.set(r * COLS + c, { r, c, capture: t.id });
        };
        for (const k of ranges(stone)) {
            for (const [dr, dc] of DIRS) {
                let r = stone.r, c = stone.c, ok = true;
                for (let a = 1; a <= k && ok; a++) {
                    r += dr; c += dc;
                    if (a === k) { consider(r, c); break; }
                    if (!free(r, c)) { ok = false; break; }
                    // Abbiegen nach a Schritten, Rest (k - a) senkrecht dazu
                    for (const [er, ec] of DIRS) {
                        if (er * dr + ec * dc !== 0) continue;   // nur senkrecht abbiegen
                        let rr = r, cc = c;
                        for (let b = 1; b <= k - a; b++) {
                            rr += er; cc += ec;
                            if (b === k - a) consider(rr, cc);
                            else if (!free(rr, cc)) break;
                        }
                    }
                }
            }
        }
        return [...result.values()];
    }

    /** Freie Felder der eigenen Hälfte für das Wiedereinsetzen. */
    function dropSquares(state, side) {
        const g = grid(state);
        const out = [];
        for (let r = 0; r < ROWS; r++) for (let c = 0; c < COLS; c++) {
            if (homeSide(c) === side && g[r][c] === null) out.push({ r, c });
        }
        return out;
    }

    /**
     * Welche gegnerischen Steine fallen, nachdem `side` gezogen hat?
     * Gibt Liste { id, why } zurück; why = { t: 'assault', a, n } | { t: 'sum' | 'prod', nums } | { t: 'prime' }.
     */
    function findCaptures(state, side) {
        const g = grid(state);
        const out = [];
        for (const t of state.stones) {
            if (t.side === side) continue;
            const vals = targetValues(t);
            let why = null;

            // Angriff aus der Ferne: Wert × Anzahl freier Felder dazwischen = Zielwert
            for (const [dr, dc] of DIRS) {
                if (why) break;
                let r = t.r + dr, c = t.c + dc, between = 0;
                while (inBoard(r, c) && g[r][c] === null) { between++; r += dr; c += dc; }
                if (!inBoard(r, c) || between === 0) continue;
                const a = g[r][c];
                if (a.side === side && vals.includes(a.v * between)) {
                    why = { t: 'assault', a: a.v, n: between };
                }
            }

            // Belagerung: Summe oder Produkt der angrenzenden eigenen Steine
            const neigh = [];
            let neighFields = 0;
            for (const [dr, dc] of DIRS) {
                const r = t.r + dr, c = t.c + dc;
                if (!inBoard(r, c)) continue;
                neighFields++;
                if (g[r][c] && g[r][c].side === side) neigh.push(g[r][c].v);
            }
            if (!why && neigh.length >= 2) {
                const sum = neigh.reduce((x, y) => x + y, 0);
                const prod = neigh.reduce((x, y) => x * y, 1);
                if (vals.includes(sum)) why = { t: 'sum', nums: neigh };
                else if (vals.includes(prod)) why = { t: 'prod', nums: neigh };
            }

            // Primzahl: rundum von eigenen Steinen eingeschlossen
            if (!why && t.kind !== 'P' && isPrime(t.v) && neigh.length === neighFields) {
                why = { t: 'prime' };
            }

            if (why) out.push({ id: t.id, why });
        }
        return out;
    }

    /** Drei eigene Steine nebeneinander (Linie, auch diagonal) im gegnerischen Lager, die eine Harmonie bilden. */
    function findHarmony(state, side) {
        const g = grid(state);
        const enemyHalf = c => homeSide(c) !== side;
        for (const s of state.stones) {
            if (s.side !== side || !enemyHalf(s.c)) continue;
            for (const [dr, dc] of [[0, 1], [1, 0], [1, 1], [1, -1]]) {
                const trio = [s];
                for (let i = 1; i < 3; i++) {
                    const r = s.r + dr * i, c = s.c + dc * i;
                    if (!inBoard(r, c) || !enemyHalf(c) || !g[r][c] || g[r][c].side !== side) break;
                    trio.push(g[r][c]);
                }
                if (trio.length < 3) continue;
                const [a, b, c] = trio.map(x => x.v).sort((x, y) => x - y);
                if (a === b || b === c) continue;
                if (a + c === 2 * b) return { kind: 'arith', values: [a, b, c], ids: trio.map(x => x.id) };
                if (a * (c - b) === c * (b - a) && trio.some(x => x.conv)) {
                    return { kind: 'harm', values: [a, b, c], ids: trio.map(x => x.id) };
                }
            }
        }
        return null;
    }

    const brief = stone => ({ v: stone.v, kind: stone.kind });

    /** Nach Zug/Einsetzen: Schläge ausführen, Sieg prüfen, Zug weitergeben. Verändert state. */
    function finishTurn(state, side, entry) {
        const caps = findCaptures(state, side);
        for (const cap of caps) {
            const idx = state.stones.findIndex(s => s.id === cap.id);
            const [stone] = state.stones.splice(idx, 1);
            state.reserve[side].push({ v: stone.v, kind: stone.kind, base: stone.base });
            state.captured.push(stone.id);
            entry.caps.push({ stone: brief(stone), why: cap.why });
        }
        state.log.push(entry);
        if (state.log.length > 200) state.log.shift();

        const harmony = findHarmony(state, side);
        if (harmony) {
            state.over = true;
            state.winner = side;
            state.harmony = harmony.ids;
            state.end = { type: 'harmony', kind: harmony.kind, values: harmony.values };
            return state;
        }
        const enemy = 1 - side;
        const enemyCanMove = state.stones.some(s => s.side === enemy && movesFor(state, s).length) || state.reserve[enemy].length > 0;
        if (!enemyCanMove) {
            state.over = true;
            state.winner = side;
            state.end = { type: 'stuck' };
            return state;
        }
        state.turn = [enemy];
        return state;
    }

    /** Zug ausführen. Gibt neuen Zustand oder null (illegal) zurück. */
    function applyMove(prev, side, stoneId, r, c) {
        const state = JSON.parse(JSON.stringify(prev));
        const stone = state.stones.find(s => s.id === stoneId);
        if (!stone || stone.side !== side) return null;
        const mv = movesFor(state, stone).find(m => m.r === r && m.c === c);
        if (!mv) return null;
        state.captured = [];
        state.harmony = null;
        const entry = { side, act: 'move', stone: brief(stone), caps: [] };
        if (mv.capture !== null) {
            const idx = state.stones.findIndex(s => s.id === mv.capture);
            const [t] = state.stones.splice(idx, 1);
            state.reserve[side].push({ v: t.v, kind: t.kind, base: t.base });
            state.captured.push(t.id);
            entry.caps.push({ stone: brief(t), why: { t: 'encounter' } });
        }
        state.last = { from: [stone.r, stone.c], to: [r, c] };
        stone.r = r;
        stone.c = c;
        return finishTurn(state, side, entry);
    }

    /** Erbeuteten Stein (Index in reserve[side]) auf ein freies Feld der eigenen Hälfte setzen. */
    function applyDrop(prev, side, reserveIdx, r, c) {
        const state = JSON.parse(JSON.stringify(prev));
        const item = state.reserve[side][reserveIdx];
        if (!item || !dropSquares(state, side).some(q => q.r === r && q.c === c)) return null;
        state.reserve[side].splice(reserveIdx, 1);
        state.captured = [];
        state.harmony = null;
        const stone = { id: state.nextId++, side, v: item.v, kind: item.kind, base: item.base, r, c, conv: true };
        state.stones.push(stone);
        state.last = { from: null, to: [r, c] };
        return finishTurn(state, side, { side, act: 'drop', stone: brief(stone), caps: [] });
    }

    return {
        ROWS, COLS, initState, movesFor, dropSquares, findCaptures, findHarmony,
        applyMove, applyDrop, isPrime, homeSide,
    };
})();

if (typeof module !== 'undefined') module.exports = ZK;
