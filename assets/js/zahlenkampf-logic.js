/* Zahlenkampfspiel (Rithmomachie) – reine Spiellogik, ohne Oberfläche.
 *
 * Regeln: Fulkes erste Variante in der Rekonstruktion von Mark Waks
 * (https://jducoeur.org/game-hist/game-recon-rhyth1.html), Grundlagen nach „Rhythmomachy Basics“.
 *
 * Brett: 16 Reihen (r) × 8 Spalten (c). Seite 0 („Gerade“) hat ihr Lager in den Reihen 0–5,
 * Seite 1 („Ungerade“) in den Reihen 10–15; die Steine stehen anfangs in den Reihen 2–5 bzw. 10–13.
 * Formen: rd = Runde, tri = Dreieck, sq = Quadrat, K = König (Pyramide aus Teilen).
 */
'use strict';

const ZK = (function () {
    const ROWS = 16, COLS = 8;
    const ORTH = [[-1, 0], [1, 0], [0, -1], [0, 1]];
    const DIAG = [[-1, -1], [-1, 1], [1, -1], [1, 1]];
    const KNIGHT = [[1, 2], [2, 1], [-1, 2], [-2, 1], [1, -2], [2, -1], [-1, -2], [-2, -1]];
    const LONG_KNIGHT = [[1, 3], [3, 1], [-1, 3], [-3, 1], [1, -3], [3, -1], [-1, -3], [-3, -1]];
    const STEP = { tri: 2, sq: 3 };   // gerade Züge: genau so viele Felder, ohne zu überspringen

    // Aufstellung nach Fulke (vom eigenen hinteren Rand aus gezählt, Spalten von links aus Sicht von „Gerade“);
    // in dieser Variante zwei Reihen weiter vorne (Reihen 3–6 statt 1–4)
    const LAYOUT = [
        // Gerade: [Reihe, Spalte, Form, Wert]
        [[2, 0, 'sq', 289], [2, 1, 'sq', 169], [2, 6, 'sq', 81], [2, 7, 'sq', 25],
         [3, 0, 'sq', 153], [3, 1, 'K', 91], [3, 2, 'tri', 49], [3, 3, 'tri', 42], [3, 4, 'tri', 20], [3, 5, 'tri', 25], [3, 6, 'sq', 45], [3, 7, 'sq', 15],
         [4, 0, 'tri', 81], [4, 1, 'tri', 72], [4, 2, 'rd', 64], [4, 3, 'rd', 36], [4, 4, 'rd', 16], [4, 5, 'rd', 4], [4, 6, 'tri', 6], [4, 7, 'tri', 9],
         [5, 2, 'rd', 8], [5, 3, 'rd', 6], [5, 4, 'rd', 4], [5, 5, 'rd', 2]],
        // Ungerade
        [[13, 0, 'sq', 49], [13, 1, 'sq', 121], [13, 6, 'sq', 225], [13, 7, 'sq', 361],
         [12, 0, 'sq', 28], [12, 1, 'sq', 66], [12, 2, 'tri', 36], [12, 3, 'tri', 30], [12, 4, 'tri', 56], [12, 5, 'tri', 64], [12, 6, 'sq', 120], [12, 7, 'K', 190],
         [11, 0, 'tri', 16], [11, 1, 'tri', 12], [11, 2, 'rd', 9], [11, 3, 'rd', 25], [11, 4, 'rd', 49], [11, 5, 'rd', 81], [11, 6, 'tri', 90], [11, 7, 'tri', 100],
         [10, 2, 'rd', 3], [10, 3, 'rd', 5], [10, 4, 'rd', 7], [10, 5, 'rd', 9]],
    ];
    // Die Könige (Pyramiden) bestehen aus Teilen; ihr Wert ist die Summe
    const KING_PARTS = [
        [{ shape: 'sq', v: 36 }, { shape: 'sq', v: 25 }, { shape: 'tri', v: 16 }, { shape: 'tri', v: 9 }, { shape: 'rd', v: 4 }, { shape: 'rd', v: 1 }],
        [{ shape: 'sq', v: 64 }, { shape: 'sq', v: 49 }, { shape: 'tri', v: 36 }, { shape: 'tri', v: 25 }, { shape: 'rd', v: 16 }],
    ];
    const DEFAULT_SETTINGS = { triumph: true, bodies: 12, goods: 600 };
    const camp = side => (side === 0 ? [0, 5] : [10, 15]);   // Lager: die hinteren sechs Reihen

    function initState(settings = DEFAULT_SETTINGS) {
        const stones = [];
        for (const side of [0, 1]) {
            for (const [r, c, shape, v] of LAYOUT[side]) {
                const s = { id: stones.length, side, shape, v, r, c, fly: false, conv: false };
                if (shape === 'K') s.parts = KING_PARTS[side].map(p => ({ ...p }));
                stones.push(s);
            }
        }
        return {
            phase: 'setup',                 // setup (Siegbedingungen einstellen) | play
            settings: { ...settings },
            stones,
            taken: [{ n: 0, sum: 0 }, { n: 0, sum: 0 }],   // von jeder Seite geschlagen: Anzahl und Summe
            turn: [0],                      // Gerade beginnt
            over: false,
            winner: null,
            end: null,                      // { type: 'triumph', level, values, kinds } | { type: 'bodies' | 'goods' | 'stuck' } | { type: 'resign', seat }
            last: null,                     // { from: [r, c], to: [r, c], fly }
            captured: [],                   // Felder der zuletzt geschlagenen Steine (für Hervorhebung)
            triumph: null,                  // Ids der Steine eines Triumphs
            log: [],                        // Zugprotokoll als Daten (Text erst in der Oberfläche)
            nextId: stones.length,
        };
    }

    const inBoard = (r, c) => r >= 0 && r < ROWS && c >= 0 && c < COLS;
    const value = s => (s.shape === 'K' ? s.parts.reduce((x, p) => x + p.v, 0) : s.v);
    const shapes = s => (s.shape === 'K' ? [...new Set(s.parts.map(p => p.shape))] : [s.shape]);
    const zone = r => (r <= 5 ? 0 : r >= 10 ? 1 : null);   // Lager von Seite 0/1, sonst Niemandsland

    function grid(state) {
        const g = Array.from({ length: ROWS }, () => Array(COLS).fill(null));
        for (const s of state.stones) {
            // Koordinaten aus dem Spielstand nie ungeprüft als Index benutzen (z. B. "__proto__")
            if (Number.isInteger(s.r) && Number.isInteger(s.c) && inBoard(s.r, s.c)) g[s.r][s.c] = s;
        }
        return g;
    }

    /** Felder, die ein Stein mit einem geraden (nicht fliegenden) Zug erreicht; das Ziel selbst darf besetzt sein. */
    function reachSquares(s, g) {
        const out = [];
        for (const shape of shapes(s)) {
            if (shape === 'rd') {
                for (const [dr, dc] of DIAG) if (inBoard(s.r + dr, s.c + dc)) out.push([s.r + dr, s.c + dc]);
                continue;
            }
            const k = STEP[shape];
            for (const [dr, dc] of ORTH) {
                let ok = true;
                for (let a = 1; a < k; a++) {
                    const r = s.r + dr * a, c = s.c + dc * a;
                    if (!inBoard(r, c) || g[r][c] !== null) { ok = false; break; }
                }
                const r = s.r + dr * k, c = s.c + dc * k;
                if (ok && inBoard(r, c)) out.push([r, c]);
            }
        }
        return out;
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
     * Welche gegnerischen Steine (bzw. Königsteile) fallen, nachdem `side` gezogen hat? Geschlagen wird ohne Ziehen:
     * Gleichheit (ein eigener Stein mit gleichem Wert erreicht ihn), Addition/Subtraktion/Multiplikation/Division
     * (zwei eigene Steine, die ihn beide erreichen), Einschließen (alle vier Nachbarfelder waagrecht/senkrecht eigene Steine).
     * Den König schlägt Gleichheit nie; mit den anderen Arten fällt er ganz (Gesamtwert) oder ein Teil (Teilwert).
     * Gibt [{ id, part (Index oder null), why }] zurück.
     */
    function findCaptures(state, side) {
        const g = grid(state);
        const mine = state.stones.filter(s => s.side === side);
        const out = [];
        for (const t of state.stones) {
            if (t.side === side) continue;
            const atk = mine.filter(s => reaches(s, t, g)).map(value);
            const pairWhy = v => {
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
            let why = null, part = null;
            const surrounded = ORTH.every(([dr, dc]) => {
                const r = t.r + dr, c = t.c + dc;
                return inBoard(r, c) && g[r][c] && g[r][c].side === side;
            });
            if (surrounded) {
                why = { t: 'obl' };
            } else if (t.shape !== 'K') {
                why = atk.includes(t.v) ? { t: 'eq', a: t.v } : pairWhy(t.v);
            } else {
                why = pairWhy(value(t));
                for (let i = 0; !why && i < t.parts.length; i++) {
                    why = pairWhy(t.parts[i].v);
                    if (why) part = i;
                }
            }
            if (why) out.push({ id: t.id, part, why });
        }
        return out;
    }

    /** Freies Feld für einen Gefangenen: hinterste Reihe des eigenen Lagers, sonst die nächste. */
    function prisonSquare(state, side, g) {
        const rows = side === 0 ? [0, 1, 2, 3, 4, 5] : [15, 14, 13, 12, 11, 10];
        for (const r of rows) for (let c = 0; c < COLS; c++) if (g[r][c] === null) return [r, c];
        return null;
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
     * Triumph (nur nachdem der gegnerische König ganz geschlagen ist): im gegnerischen Lager eigene Steine, die
     * nicht fliegend angekommen sind – drei in einer Reihe mit einer Proportion (großer Triumph), vier in einer Reihe
     * oder im Quadrat mit zwei (größerer) bzw. allen drei Proportionen (größter Triumph).
     */
    function findTriumph(state, side) {
        const enemy = 1 - side;
        if (state.stones.some(s => s.side === enemy && s.shape === 'K')) return null;
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

    /** Alle Züge eines Steins: [{ r, c, fly }]. Fliegende Züge, nach denen etwas fallen würde, sind nicht erlaubt. */
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

    /** Kann `side` überhaupt ziehen? Erst die (billigen) geraden Züge prüfen, nur notfalls die fliegenden. */
    function hasAnyMove(state, side) {
        const g = grid(state);
        const own = state.stones.filter(s => s.side === side);
        return own.some(s => reachSquares(s, g).some(([r, c]) => g[r][c] === null)) || own.some(s => movesFor(state, s).length);
    }

    const brief = s => ({ v: value(s), shape: s.shape });

    /** Nach dem Zug: Schläge ausführen, Gefangene einsetzen, Sieg prüfen, Zug weitergeben. Verändert state. */
    function finishTurn(state, side, entry) {
        const caps = findCaptures(state, side);
        for (const cap of caps) {
            const t = state.stones.find(s => s.id === cap.id);
            const pos = [t.r, t.c];
            let prisoner = null;
            if (cap.part !== null) {
                const [p] = t.parts.splice(cap.part, 1);
                entry.caps.push({ stone: { v: p.v, shape: p.shape, of: 'K' }, why: cap.why });
                state.taken[side].n++;
                state.taken[side].sum += p.v;
                prisoner = { shape: p.shape, v: p.v };
                if (!t.parts.length) state.stones = state.stones.filter(s => s.id !== t.id);
            } else {
                entry.caps.push({ stone: brief(t), why: cap.why });
                state.taken[side].n++;
                state.taken[side].sum += value(t);
                state.stones = state.stones.filter(s => s.id !== t.id);
                if (t.shape !== 'K') prisoner = { shape: t.shape, v: t.v };   // ein ganzer König wechselt nicht die Seite
            }
            state.captured.push(pos);
            // Gefangene wechseln die Farbe und stellen sich hinten im eigenen Lager auf
            const sq = prisoner && prisonSquare(state, side, grid(state));
            if (sq) state.stones.push({ id: state.nextId++, side, shape: prisoner.shape, v: prisoner.v, r: sq[0], c: sq[1], fly: false, conv: true });
        }
        state.log.push(entry);
        if (state.log.length > 200) state.log.shift();

        const st = state.settings;
        const tri = st.triumph ? findTriumph(state, side) : null;
        let end = null;
        if (tri) {
            state.triumph = tri.ids;
            end = { type: 'triumph', level: tri.level, kinds: tri.kinds, values: tri.values };
        } else if (st.bodies > 0 && state.taken[side].n >= st.bodies) {
            end = { type: 'bodies', n: state.taken[side].n };
        } else if (st.goods > 0 && state.taken[side].sum >= st.goods) {
            end = { type: 'goods', sum: state.taken[side].sum };
        } else if (!hasAnyMove(state, 1 - side)) {
            end = { type: 'stuck' };
        }
        if (end) {
            state.over = true;
            state.winner = side;
            state.end = end;
            return state;
        }
        state.turn = [1 - side];
        return state;
    }

    /** Zug ausführen. Gibt den neuen Zustand oder null (nicht erlaubt) zurück. */
    function applyMove(prev, side, stoneId, r, c) {
        if (prev.phase !== 'play' || prev.over || prev.turn[0] !== side) return null;
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
        return finishTurn(state, side, { side, stone: brief(stone), fly: mv.fly, caps: [] });
    }

    /** Spiel nach dem Einstellen der Siegbedingungen beginnen. */
    function start(prev) {
        const state = initState(prev.settings);
        state.phase = 'play';
        return state;
    }

    return {
        ROWS, COLS, DEFAULT_SETTINGS, KING_PARTS, initState, start, movesFor, findCaptures, findTriumph,
        proportions, applyMove, value, zone, camp,
    };
})();

if (typeof module !== 'undefined') module.exports = ZK;
