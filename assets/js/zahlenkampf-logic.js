/* Zahlenkampfspiel (Rithmomachie) – reine Spiellogik, ohne Oberfläche.
 *
 * Regeln: Fulkes erste Variante in der Rekonstruktion von Mark Waks
 * (https://jducoeur.org/game-hist/game-recon-rhyth1.html), Grundlagen nach „Rhythmomachy Basics“,
 * Aufstellung nach Fulkes Bild; offene Punkte so, wie die Spielrunde es festgelegt hat.
 *
 * Brett: 16 Reihen (r) × 8 Spalten (c). Seite 0 („Gerade“) hat ihr Lager in den Reihen 0–7,
 * Seite 1 („Ungerade“) in den Reihen 8–15.
 * Formen: rd = Runde, tri = Dreieck, sq = Quadrat, P = Pyramide (aus Teilen).
 */
'use strict';

const ZK = (function () {
    const ROWS = 16, COLS = 8;
    const ORTH = [[-1, 0], [1, 0], [0, -1], [0, 1]];
    const DIAG = [[-1, -1], [-1, 1], [1, -1], [1, 1]];
    const KNIGHT = [[1, 2], [2, 1], [-1, 2], [-2, 1], [1, -2], [2, -1], [-1, -2], [-2, -1]];
    const LONG_KNIGHT = [[1, 3], [3, 1], [-1, 3], [-3, 1], [1, -3], [3, -1], [-1, -3], [-3, -1]];
    const STEP = { tri: 2, sq: 3 };   // gerade Züge: genau so viele Felder, ohne zu überspringen

    // Aufstellung nach Fulkes Bild: [Reihe, Spalte, Form, Wert]; Spalten von links aus Sicht von „Gerade“
    const LAYOUT = [
        [[0, 0, 'sq', 289], [0, 1, 'sq', 169], [0, 6, 'sq', 81], [0, 7, 'sq', 25],
         [1, 0, 'sq', 153], [1, 1, 'P', 91], [1, 2, 'tri', 49], [1, 3, 'tri', 42], [1, 4, 'tri', 20], [1, 5, 'tri', 25], [1, 6, 'sq', 45], [1, 7, 'sq', 15],
         [2, 0, 'tri', 81], [2, 1, 'tri', 72], [2, 2, 'rd', 64], [2, 3, 'rd', 36], [2, 4, 'rd', 16], [2, 5, 'rd', 4], [2, 6, 'tri', 6], [2, 7, 'tri', 9],
         [3, 2, 'rd', 8], [3, 3, 'rd', 6], [3, 4, 'rd', 4], [3, 5, 'rd', 2]],
        [[15, 0, 'sq', 49], [15, 1, 'sq', 121], [15, 6, 'sq', 225], [15, 7, 'sq', 361],
         [14, 0, 'sq', 28], [14, 1, 'sq', 66], [14, 2, 'tri', 36], [14, 3, 'tri', 30], [14, 4, 'tri', 56], [14, 5, 'tri', 64], [14, 6, 'sq', 120], [14, 7, 'P', 190],
         [13, 0, 'tri', 16], [13, 1, 'tri', 12], [13, 2, 'rd', 9], [13, 3, 'rd', 25], [13, 4, 'rd', 49], [13, 5, 'rd', 81], [13, 6, 'tri', 90], [13, 7, 'tri', 100],
         [12, 2, 'rd', 3], [12, 3, 'rd', 5], [12, 4, 'rd', 7], [12, 5, 'rd', 9]],
    ];
    // Die Pyramiden bestehen aus Teilen; ihr Wert ist die Summe der verbliebenen Teile
    const PYRAMID_PARTS = [
        [{ shape: 'sq', v: 36 }, { shape: 'sq', v: 25 }, { shape: 'tri', v: 16 }, { shape: 'tri', v: 9 }, { shape: 'rd', v: 4 }, { shape: 'rd', v: 1 }],
        [{ shape: 'sq', v: 64 }, { shape: 'sq', v: 49 }, { shape: 'tri', v: 36 }, { shape: 'tri', v: 25 }, { shape: 'rd', v: 16 }],
    ];
    const TOTAL = LAYOUT.map(l => l.reduce((n, [, , , v]) => n + v, 0));   // 1312 und 1752
    // Siegziele für jede Seite: Körper = Hälfte der gegnerischen Steine, Güter = ¾ der gegnerischen Summe
    const GOAL = [0, 1].map(side => ({ n: LAYOUT[1 - side].length / 2, sum: Math.ceil(TOTAL[1 - side] * 3 / 4) }));

    function initState() {
        const stones = [];
        for (const side of [0, 1]) {
            for (const [r, c, shape, v] of LAYOUT[side]) {
                const s = { id: stones.length, side, shape, v, r, c, fly: false };
                if (shape === 'P') s.parts = PYRAMID_PARTS[side].map(p => ({ ...p }));
                stones.push(s);
            }
        }
        return {
            stones,
            taken: [{ n: 0, sum: 0 }, { n: 0, sum: 0 }],   // von jeder Seite geschlagen: Anzahl und Summe
            turn: [0],                      // Gerade beginnt
            over: false,
            winner: null,
            end: null,                      // { type: 'triumph', level, kinds, values } | { type: 'bodies' | 'goods' | 'stuck' } | { type: 'resign', seat }
            last: null,                     // { from: [r, c], to: [r, c], fly }
            captured: [],                   // Felder der zuletzt geschlagenen Steine (für Hervorhebung)
            triumph: null,                  // Ids der Steine eines Triumphs
            log: [],                        // Zugprotokoll als Daten (Text erst in der Oberfläche)
        };
    }

    const inBoard = (r, c) => r >= 0 && r < ROWS && c >= 0 && c < COLS;
    const value = s => (s.shape === 'P' ? s.parts.reduce((x, p) => x + p.v, 0) : s.v);
    const shapes = s => (s.shape === 'P' ? [...new Set(s.parts.map(p => p.shape))] : [s.shape]);
    const zone = r => (r < ROWS / 2 ? 0 : 1);   // Lager von Seite 0 bzw. 1

    function grid(state) {
        const g = Array.from({ length: ROWS }, () => Array(COLS).fill(null));
        for (const s of state.stones) {
            // Koordinaten aus dem Spielstand nie ungeprüft als Index benutzen (z. B. "__proto__")
            if (Number.isInteger(s.r) && Number.isInteger(s.c) && inBoard(s.r, s.c)) g[s.r][s.c] = s;
        }
        return g;
    }

    /** Zielfelder der gewöhnlichen Züge (ohne Rücksicht auf Weg und Brettrand): Runde diagonal, Dreieck 2, Quadrat 3 gerade. */
    function targetSquares(s) {
        const out = [];
        for (const shape of shapes(s)) {
            if (shape === 'rd') for (const [dr, dc] of DIAG) out.push([s.r + dr, s.c + dc, shape, dr, dc]);
            else for (const [dr, dc] of ORTH) out.push([s.r + dr * STEP[shape], s.c + dc * STEP[shape], shape, dr, dc]);
        }
        return out;
    }

    /** Felder, die ein Stein mit einem gewöhnlichen Zug erreicht (Weg frei, nicht über den Rand); das Ziel darf besetzt sein. */
    function reachSquares(s, g) {
        return targetSquares(s).filter(([r, c, shape, dr, dc]) => {
            if (!inBoard(r, c)) return false;
            for (let a = 1; shape !== 'rd' && a < STEP[shape]; a++) {
                if (g[s.r + dr * a][s.c + dc * a] !== null) return false;
            }
            return true;
        }).map(([r, c]) => [r, c]);
    }

    /** Fliegende Züge: Dreieck wie der Springer, Quadrat wie ein langer Springer (darf überspringen). */
    function flySquares(s) {
        const out = [];
        for (const shape of shapes(s)) {
            const vecs = shape === 'tri' ? KNIGHT : shape === 'sq' ? LONG_KNIGHT : [];
            for (const [dr, dc] of vecs) if (inBoard(s.r + dr, s.c + dc)) out.push([s.r + dr, s.c + dc]);
        }
        return out;
    }

    const reaches = (s, t, g) => reachSquares(s, g).some(([r, c]) => r === t.r && c === t.c);

    /**
     * Welche Steine der Gegenseite schlägt `side` in dieser Stellung? Geschlagen wird ohne Ziehen:
     * - Gleichheit: ein eigener Stein mit gleichem Wert erreicht ihn;
     * - Addition/Subtraktion/Multiplikation/Division: zwei eigene Steine erreichen ihn beide;
     * - Einschließen: alle Zielfelder seiner gewöhnlichen Züge sind besetzt oder außerhalb des Bretts,
     *   und mindestens einer der blockierenden Steine gehört `side`.
     * Die ganze Pyramide fällt nie durch Gleichheit (nur durch die Rechenarten mit ihrem Gesamtwert oder durch
     * Einschließen); einzelne Teile fallen durch Gleichheit oder eine Rechenart mit ihrem Wert.
     * Gibt [{ id, part (Index oder null), why }] zurück.
     */
    function findCaptures(state, side) {
        const g = grid(state);
        const mine = state.stones.filter(s => s.side === side);
        const out = [];
        for (const t of state.stones) {
            if (t.side === side) continue;
            const atk = mine.filter(s => reaches(s, t, g)).map(value);
            const byValue = (v, equality) => {
                if (equality && atk.includes(v)) return { t: 'eq', a: v };
                for (let i = 0; i < atk.length; i++) {
                    for (let j = i + 1; j < atk.length; j++) {
                        const [a, b] = atk[i] >= atk[j] ? [atk[i], atk[j]] : [atk[j], atk[i]];
                        if (a + b === v) return { t: 'add', a, b };
                        if (a - b === v) return { t: 'sub', a, b };
                        if (a * b === v) return { t: 'mul', a, b };
                        if (b > 0 && a % b === 0 && a / b === v) return { t: 'div', a, b };
                    }
                }
                return null;
            };
            const blockers = targetSquares(t).map(([r, c]) => (inBoard(r, c) ? g[r][c] : 'edge'));
            const enclosed = blockers.every(b => b !== null) && blockers.some(b => b !== 'edge' && b.side === side);
            let why = null, part = null;
            if (enclosed) {
                why = { t: 'obl' };
            } else if (t.shape !== 'P') {
                why = byValue(t.v, true);
            } else {
                why = byValue(value(t), false);
                for (let i = 0; !why && i < t.parts.length; i++) {
                    why = byValue(t.parts[i].v, true);
                    if (why) part = i;
                }
            }
            if (why) out.push({ id: t.id, part, why });
        }
        return out;
    }

    /** Schläge von `side` ausführen (Steine verschwinden vom Brett); gibt die Protokolleinträge zurück. */
    function applyCaptures(state, side) {
        const caps = findCaptures(state, side);
        const entries = [];
        for (const cap of caps) {
            const t = state.stones.find(s => s.id === cap.id);
            if (!t) continue;
            state.captured.push([t.r, t.c]);
            if (cap.part !== null) {
                const [p] = t.parts.splice(cap.part, 1);
                entries.push({ stone: { v: p.v, shape: p.shape, of: 'P' }, why: cap.why });
                state.taken[side].sum += p.v;
                if (!t.parts.length) {   // letzter Teil: die Pyramide ist ganz geschlagen
                    state.taken[side].n++;
                    state.stones = state.stones.filter(s => s.id !== t.id);
                }
            } else {
                entries.push({ stone: { v: value(t), shape: t.shape }, why: cap.why });
                state.taken[side].n++;
                state.taken[side].sum += value(t);
                state.stones = state.stones.filter(s => s.id !== t.id);
            }
        }
        return entries;
    }

    // ------------------------------------------------------------------ Proportionen und Triumph

    /** Proportionen dreier verschiedener Werte: arithmetisch, geometrisch, harmonisch. */
    function proportions(vals) {
        const [a, b, c] = [...vals].sort((x, y) => x - y);
        const kinds = [];
        if (a === b || b === c) return kinds;
        if (b - a === c - b) kinds.push('arith');
        if (b * b === a * c) kinds.push('geo');
        if (c * (b - a) === a * (c - b)) kinds.push('harm');
        return kinds;
    }

    function kindsOf(vals) {
        if (vals.length === 3) return proportions(vals);
        const set = new Set();
        for (let skip = 0; skip < vals.length; skip++) proportions(vals.filter((_, i) => i !== skip)).forEach(k => set.add(k));
        return [...set];
    }

    /**
     * Triumph (nur nachdem die gegnerische Pyramide ganz geschlagen ist): im gegnerischen Lager eigene Steine, die
     * nicht fliegend angekommen sind – drei in einer Reihe mit einer Proportion (großer Triumph), vier in einer Reihe
     * oder im Quadrat mit zwei (größerer) bzw. allen drei Proportionen (größter Triumph).
     */
    function findTriumph(state, side) {
        const enemy = 1 - side;
        if (state.stones.some(s => s.side === enemy && s.shape === 'P')) return null;
        const g = grid(state);
        const ok = (r, c) => inBoard(r, c) && zone(r) === enemy && g[r][c] && g[r][c].side === side && !g[r][c].fly;
        let best = null;
        const consider = cells => {
            if (!cells.every(([r, c]) => ok(r, c))) return;
            const stones = cells.map(([r, c]) => g[r][c]);
            const kinds = kindsOf(stones.map(value));
            const level = cells.length === 3 ? (kinds.length ? 1 : 0) : (kinds.length >= 3 ? 3 : kinds.length >= 2 ? 2 : 0);
            if (level && (!best || level > best.level)) best = { level, kinds, values: stones.map(value), ids: stones.map(s => s.id) };
        };
        for (let r = 0; r < ROWS; r++) {
            for (let c = 0; c < COLS; c++) {
                for (const [dr, dc] of [[0, 1], [1, 0], [1, 1], [1, -1]]) {
                    for (const n of [3, 4]) consider(Array.from({ length: n }, (_, i) => [r + dr * i, c + dc * i]));
                }
                consider([[r, c], [r, c + 1], [r + 1, c], [r + 1, c + 1]]);
            }
        }
        return best;
    }

    // ------------------------------------------------------------------ Züge

    /** Alle Züge eines Steins: [{ r, c, fly }]. Fliegend darf man nichts schlagen. */
    function movesFor(state, stone) {
        const g = grid(state);
        const out = [];
        for (const [r, c] of reachSquares(stone, g)) if (g[r][c] === null) out.push({ r, c, fly: false });
        for (const [r, c] of flySquares(stone)) {
            if (g[r][c] !== null || out.some(m => m.r === r && m.c === c)) continue;
            const moved = { ...state, stones: state.stones.map(s => (s.id === stone.id ? { ...s, r, c } : s)) };
            if (!findCaptures(moved, stone.side).length) out.push({ r, c, fly: true });
        }
        return out;
    }

    /** Kann `side` überhaupt ziehen? Erst die (billigen) gewöhnlichen Züge prüfen, nur notfalls die fliegenden. */
    function hasAnyMove(state, side) {
        const g = grid(state);
        const own = state.stones.filter(s => s.side === side);
        return own.some(s => reachSquares(s, g).some(([r, c]) => g[r][c] === null)) || own.some(s => movesFor(state, s).length);
    }

    /** Sieg für `side` nach Körpern oder Gütern? */
    function countVictory(state, side) {
        const tk = state.taken[side];
        if (tk.n >= GOAL[side].n) return { type: 'bodies', n: tk.n };
        if (tk.sum >= GOAL[side].sum) return { type: 'goods', sum: tk.sum };
        return null;
    }

    /**
     * Nach dem Zug: zuerst fallen die Steine der Gegenseite, die jetzt geschlagen werden können, danach – falls
     * die Schlagregel dann noch gilt – die eigenen. Dann Sieg prüfen und Zug weitergeben. Verändert state.
     */
    function finishTurn(state, side, entry) {
        entry.caps = applyCaptures(state, side);
        entry.lost = applyCaptures(state, 1 - side);
        state.log.push(entry);
        if (state.log.length > 200) state.log.shift();

        let winner = side;
        let end = null;
        const tri = findTriumph(state, side);
        if (tri) {
            state.triumph = tri.ids;
            end = { type: 'triumph', level: tri.level, kinds: tri.kinds, values: tri.values };
        } else {
            end = countVictory(state, side);
            if (!end && (end = countVictory(state, 1 - side))) winner = 1 - side;   // durch die Gegenschläge
        }
        if (!end && !hasAnyMove(state, 1 - side)) end = { type: 'stuck' };
        if (end) {
            state.over = true;
            state.winner = winner;
            state.end = end;
            return state;
        }
        state.turn = [1 - side];
        return state;
    }

    /** Zug ausführen. Gibt den neuen Zustand oder null (nicht erlaubt) zurück. */
    function applyMove(prev, side, stoneId, r, c) {
        if (prev.over || prev.turn[0] !== side) return null;
        const state = JSON.parse(JSON.stringify(prev));
        const stone = state.stones.find(s => s.id === stoneId);
        if (!stone || stone.side !== side) return null;
        const mv = movesFor(state, stone).find(m => m.r === r && m.c === c);
        if (!mv) return null;
        state.captured = [];
        state.triumph = null;
        state.last = { from: [stone.r, stone.c], to: [r, c], fly: mv.fly };
        stone.r = r;
        stone.c = c;
        stone.fly = mv.fly;
        return finishTurn(state, side, { side, stone: { v: value(stone), shape: stone.shape }, fly: mv.fly });
    }

    return {
        ROWS, COLS, GOAL, TOTAL, PYRAMID_PARTS, initState, movesFor, findCaptures, findTriumph,
        proportions, applyMove, value, zone,
    };
})();

if (typeof module !== 'undefined') module.exports = ZK;
