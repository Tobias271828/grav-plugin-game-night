/* Schach zu viert / Hand and Brain chess: 2 gegen 2, je Team eine Person wählt die Figurenart, die andere zieht */
'use strict';

(function () {
    const SEATS = LANG === 'en'
        ? ['White – Brain', 'White – Hand', 'Black – Brain', 'Black – Hand']
        : ['Weiß – Wählen', 'Weiß – Ziehen', 'Schwarz – Wählen', 'Schwarz – Ziehen'];
    const PIECE_NAMES = LANG === 'en'
        ? { p: 'Pawn', n: 'Knight', b: 'Bishop', r: 'Rook', q: 'Queen', k: 'King' }
        : { p: 'Bauer', n: 'Springer', b: 'Läufer', r: 'Turm', q: 'Dame', k: 'König' };
    // „Zieh einen Springer!“ / „Zieh eine Dame!“
    const WITH_ARTICLE = LANG === 'en'
        ? { p: 'a pawn', n: 'a knight', b: 'a bishop', r: 'a rook', q: 'the queen', k: 'the king' }
        : { p: 'einen Bauern', n: 'einen Springer', b: 'einen Läufer', r: 'einen Turm', q: 'eine Dame', k: 'den König' };
    const GLYPH = { p: '♟\uFE0E', n: '♞\uFE0E', b: '♝\uFE0E', r: '♜\uFE0E', q: '♛\uFE0E', k: '♚\uFE0E' };
    const ORDER = ['p', 'n', 'b', 'r', 'q', 'k'];
    const TEAM = { w: t('Weiß', 'White'), b: t('Schwarz', 'Black') };

    const chooserOf = color => color === 'w' ? 0 : 2;
    const moverOf = color => color === 'w' ? 1 : 3;
    const colorOfSeat = seat => seat < 2 ? 'w' : 'b';

    const MAX_PLIES = 400;   // höchstens so viele Halbzüge (wie im Server, core/Validate.php)

    function load(moves) {
        // Der Server lässt höchstens 400 Halbzüge zu; ein ungültiger Zug heißt: Spielstand kaputt (Anzeige fängt das ab)
        if (!Array.isArray(moves) || moves.length > MAX_PLIES) throw new Error('invalid moves');
        const game = new Chess();
        for (const san of moves) {
            if (typeof san !== 'string' || !game.move(san)) throw new Error('invalid move');
        }
        return game;
    }

    // Auswahl der ziehenden Person (lokal, gilt nur für den aktuellen Stand)
    let sel = { version: 0, from: null, promo: null };

    registerGame({
        id: 'handbrain',
        name: t('Schach zu viert', 'Chess for four'),
        icon: '♞',
        desc: t('Eine Person pro Team wählt die Figurenart, die andere zieht.',
                'In each team, one person chooses the piece type, the other one moves.'),
        seats: SEATS,
        rules: LANG === 'en' ? `<p>Normal chess on one board, but each colour is played by a team of two:</p>
            <ul>
              <li>The <b>Brain</b> only names a piece type (e.g. “knight”).</li>
              <li>The <b>Hand</b> must then move a piece of that type – which one and where is up to them.</li>
            </ul>
            <p>Only piece types that currently have a legal move are offered (so when in check, only moves that get out of check).
            All normal chess rules apply, including castling (piece type king), en passant and promotion.
            Of course, no conferring within the team 😉. To swap roles, just change seats for the next round.</p>` : `<p>Normales Schach auf einem Brett, aber pro Farbe spielt ein Team aus zwei Personen:</p>
            <ul>
              <li>Die <b>wählende Person</b> sagt nur eine Figurenart an (z. B. „Springer“).</li>
              <li>Die <b>ziehende Person</b> muss dann eine Figur dieser Art ziehen – welche und wohin, entscheidet sie selbst.</li>
            </ul>
            <p>Angeboten werden nur Figurenarten, die gerade einen legalen Zug haben (im Schach also nur Züge, die das Schach aufheben).
            Es gelten alle normalen Schachregeln inklusive Rochade (Figurenart König), en passant und Umwandlung.
            Absprachen im Team sind natürlich nicht erlaubt 😉. Wer die Rollen tauschen will, setzt sich in der nächsten Runde einfach um.</p>`,

        init() {
            return { moves: [], phase: 'choose', piece: null, turn: [0], over: false, end: null, last: null };
        },

        resign(state, seat) {
            const c = colorOfSeat(seat);
            state.over = true;
            state.end = { type: 'resign', color: c };
            return state;
        },

        render(root, ctx) {
            const { state, mySeat } = ctx;
            const game = load(state.moves);
            const color = game.turn();
            const legal = game.moves({ verbose: true });
            const flip = mySeat !== null && colorOfSeat(mySeat) === 'b';

            const selKey = ctx.room.id + ':' + ctx.version;   // Auswahl gilt nur für diesen Tisch und Stand
            if (sel.version !== selKey) sel = { version: selKey, from: null, promo: null };

            const iChoose = ctx.isMyTurn && state.phase === 'choose';
            const iMove = ctx.isMyTurn && state.phase === 'move';
            const movable = iMove ? legal.filter(m => m.piece === state.piece) : [];
            const targets = sel.from ? movable.filter(m => m.from === sel.from) : [];

            const rerender = () => { root.replaceChildren(); this.render(root, ctx); };

            const doMove = (from, to, promotion) => {
                const g = load(state.moves);
                const mv = g.move({ from, to, promotion });
                if (!mv) return;
                const ns = clone(state);
                ns.moves.push(mv.san);
                ns.last = { from, to };
                ns.phase = 'choose';
                ns.piece = null;
                const next = g.turn();
                ns.turn = [chooserOf(next)];
                if (g.in_checkmate()) {
                    ns.over = true;
                    ns.end = { type: 'mate', color };
                } else if (g.in_stalemate()) {
                    ns.over = true;
                    ns.end = { type: 'draw', reason: 'stalemate' };
                } else if (g.in_threefold_repetition()) {
                    ns.over = true;
                    ns.end = { type: 'draw', reason: 'threefold' };
                } else if (g.insufficient_material()) {
                    ns.over = true;
                    ns.end = { type: 'draw', reason: 'material' };
                } else if (g.in_draw()) {
                    ns.over = true;
                    ns.end = { type: 'draw', reason: 'fifty' };
                } else if (ns.moves.length >= MAX_PLIES) {
                    // Obergrenze des Servers (sonst ließe sich nicht weiterspielen)
                    ns.over = true;
                    ns.end = { type: 'draw', reason: 'limit' };
                }
                ctx.submit(ns);
            };

            const clickSquare = sq => {
                if (!iMove) return;
                const tg = targets.find(m => m.to === sq);
                if (tg) {
                    if (tg.flags.includes('p')) { sel.promo = { from: sel.from, to: sq }; rerender(); }
                    else doMove(sel.from, sq);
                    return;
                }
                sel.from = movable.some(m => m.from === sq) ? sq : null;
                sel.promo = null;
                rerender();
            };

            // Brett
            const board = game.board();
            const inCheckKing = game.in_check() ? findKing(board, color) : null;
            const boardEl = h('div', { class: 'chess-board' });
            for (let i = 0; i < 8; i++) {
                for (let j = 0; j < 8; j++) {
                    const r = flip ? 7 - i : i, c = flip ? 7 - j : j;
                    const sq = 'abcdefgh'[c] + (8 - r);
                    const p = board[r][c];
                    const cls = ['sq', (r + c) % 2 ? 'dark' : 'light'];
                    if (state.last && (state.last.from === sq || state.last.to === sq)) cls.push('lastmove');
                    if (sel.from === sq) cls.push('selected');
                    if (movable.some(m => m.from === sq)) cls.push('movable');
                    if (targets.some(m => m.to === sq)) cls.push(p ? 'target capture' : 'target');
                    if (inCheckKing === sq) cls.push('check');
                    boardEl.append(h('div', { class: cls.join(' '), onclick: () => clickSquare(sq) },
                        p ? h('span', { class: 'piece ' + p.color }, GLYPH[p.type]) : null,
                        j === 0 ? h('span', { class: 'coord rank' }, 8 - r) : null,
                        i === 7 ? h('span', { class: 'coord file' }, 'abcdefgh'[c]) : null,
                    ));
                }
            }

            // Status
            let status = '';
            if (state.over) status = endText(state.end);
            else if (!ctx.allSeated) status = '';
            else if (iChoose) status = t('Du wählst: Welche Figur soll ziehen?', 'Your call: which piece type moves?');
            else if (iMove) status = t(`Zieh ${WITH_ARTICLE[state.piece]}!`, `Move ${WITH_ARTICLE[state.piece]}!`);
            else if (state.phase === 'choose') status = t(`${ctx.label(chooserOf(color))} wählt eine Figurenart …`, `${ctx.label(chooserOf(color))} is choosing a piece type …`);
            else status = t(`${ctx.label(moverOf(color))} zieht ${WITH_ARTICLE[state.piece]} …`, `${ctx.label(moverOf(color))} is moving ${WITH_ARTICLE[state.piece]} …`);
            if (!state.over && game.in_check()) status = t('Schach! ', 'Check! ') + status;

            // Knöpfe zum Wählen der Figurenart
            let chooser = null;
            if (iChoose) {
                chooser = h('div', { class: 'piece-choice' }, ORDER.map(pt => {
                    // Umwandlungen (Dame/Turm/Läufer/Springer) zählen als ein Zug
                    const n = new Set(legal.filter(m => m.piece === pt).map(m => m.from + m.to)).size;
                    return h('button', {
                        class: 'btn piece-btn', disabled: n === 0,
                        title: n === 1 ? t('1 möglicher Zug', '1 possible move')
                            : n ? t(`${n} mögliche Züge`, `${n} possible moves`) : t('keine legalen Züge', 'no legal moves'),
                        onclick: () => {
                            const ns = clone(state);
                            ns.phase = 'move';
                            ns.piece = pt;
                            ns.turn = [moverOf(color)];
                            ctx.submit(ns);
                        },
                    }, h('span', { class: 'piece ' + color }, GLYPH[pt]), PIECE_NAMES[pt]);
                }));
            } else if (state.phase === 'move' && !state.over) {
                chooser = h('div', { class: 'piece-choice' },
                    h('span', { class: 'chosen' }, t('Angesagt: ', 'Called: '), h('span', { class: 'piece ' + color }, GLYPH[state.piece]), ' ', PIECE_NAMES[state.piece]));
            }

            const promo = sel.promo ? h('div', { class: 'piece-choice' }, t('Umwandeln in: ', 'Promote to: '),
                ['q', 'r', 'b', 'n'].map(pt => h('button', {
                    class: 'btn piece-btn', onclick: () => doMove(sel.promo.from, sel.promo.to, pt),
                }, h('span', { class: 'piece ' + color }, GLYPH[pt]), PIECE_NAMES[pt]))) : null;

            // Zugliste
            const list = h('ol', { class: 'movelist' });
            for (let i = 0; i < state.moves.length; i += 2) {
                list.append(h('li', {}, state.moves[i], state.moves[i + 1] ? '  ' + state.moves[i + 1] : ''));
            }

            fill(root,
                h('p', { class: 'status' + (ctx.isMyTurn ? ' my-turn' : '') }, status),
                chooser, promo,
                h('div', { class: 'chess-wrap' }, boardEl,
                    h('div', { class: 'side-panel' }, h('h4', {}, t('Züge', 'Moves')), state.moves.length ? list : h('p', { class: 'muted small' }, t('noch keine', 'none yet')))),
            );
        },
    });

    function endText(end) {
        if (!end) return '';
        const other = c => (c === 'w' ? 'b' : 'w');
        if (end.type === 'mate') return t(`Schachmatt! Team ${TEAM[end.color]} gewinnt.`, `Checkmate! Team ${TEAM[end.color]} wins.`);
        if (end.type === 'resign') return t(`Team ${TEAM[end.color]} gibt auf – Team ${TEAM[other(end.color)]} gewinnt.`,
                                            `Team ${TEAM[end.color]} resigns – Team ${TEAM[other(end.color)]} wins.`);
        return {
            stalemate: t('Patt – unentschieden.', 'Stalemate – draw.'),
            threefold: t('Dreifache Stellungswiederholung – unentschieden.', 'Threefold repetition – draw.'),
            material: t('Nicht genug Material – unentschieden.', 'Insufficient material – draw.'),
            fifty: t('50-Züge-Regel – unentschieden.', 'Fifty-move rule – draw.'),
            limit: t(`${MAX_PLIES / 2} Züge gespielt – unentschieden.`, `${MAX_PLIES / 2} moves played – draw.`),
        }[end.reason] || '';
    }

    function findKing(board, color) {
        for (let r = 0; r < 8; r++) for (let c = 0; c < 8; c++) {
            const p = board[r][c];
            if (p && p.type === 'k' && p.color === color) return 'abcdefgh'[c] + (8 - r);
        }
        return null;
    }
})();
