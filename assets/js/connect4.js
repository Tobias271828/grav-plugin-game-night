/* Vier in einer Reihe / Four in a Row (interne Spiel-ID bleibt „connect4“, damit alte Tische weiter funktionieren) */
'use strict';

(function () {
    const COLS = 7, ROWS = 6;
    const SEATS = [t('Rot', 'Red'), t('Gelb', 'Yellow')];

    function winLine(board, idx) {
        const p = board[idx];
        const r0 = Math.floor(idx / COLS), c0 = idx % COLS;
        for (const [dr, dc] of [[0, 1], [1, 0], [1, 1], [1, -1]]) {
            const line = [idx];
            for (const s of [1, -1]) {
                let r = r0 + dr * s, c = c0 + dc * s;
                while (r >= 0 && r < ROWS && c >= 0 && c < COLS && board[r * COLS + c] === p) {
                    line.push(r * COLS + c);
                    r += dr * s; c += dc * s;
                }
            }
            if (line.length >= 4) return line;
        }
        return null;
    }

    registerGame({
        id: 'connect4',
        name: t('Vier in einer Reihe', 'Four in a Row'),
        icon: '🔴',
        desc: t('Vier in einer Reihe – gewonnen!',
                'Four in a row – you win!'),
        seats: SEATS,
        rules: t(`<p>Abwechselnd wird ein Stein in eine Spalte geworfen. Er fällt nach unten.
                Wer zuerst vier eigene Steine waagrecht, senkrecht oder diagonal in eine Reihe bringt, gewinnt.
                Ist das Brett voll, endet das Spiel unentschieden. In der nächsten Runde beginnt die andere Farbe.</p>`,
                 `<p>Players take turns dropping a disc into a column; it falls to the lowest free space.
                The first to line up four of their own discs horizontally, vertically or diagonally wins.
                If the board is full, the game is a draw. The other colour starts the next round.</p>`),

        init(prev) {
            const starter = prev && prev.starter === 0 ? 1 : 0;
            return { board: Array(COLS * ROWS).fill(-1), turn: [starter], starter, over: false, last: null, line: null, end: null };
        },

        // Ergebnis wird sprachneutral gespeichert: end = { type: 'win' | 'draw' | 'resign', seat }
        resign(state, seat) {
            state.over = true;
            state.end = { type: 'resign', seat };
            return state;
        },

        endText(end, ctx) {
            if (!end) return '';
            if (end.type === 'win') return t(`${ctx.label(end.seat)} gewinnt!`, `${ctx.label(end.seat)} wins!`);
            if (end.type === 'draw') return t('Unentschieden – das Brett ist voll.', 'Draw – the board is full.');
            const other = ctx.label(1 - end.seat);
            return t(`${ctx.label(end.seat)} gibt auf – ${other} gewinnt.`, `${ctx.label(end.seat)} resigns – ${other} wins.`);
        },

        render(root, ctx) {
            const { state } = ctx;
            const player = state.turn[0];

            const drop = col => {
                if (!ctx.isMyTurn) return;
                let row = ROWS - 1;
                while (row >= 0 && state.board[row * COLS + col] !== -1) row--;
                if (row < 0) return;
                const ns = clone(state);
                const idx = row * COLS + col;
                ns.board[idx] = player;
                ns.last = idx;
                const line = winLine(ns.board, idx);
                if (line) {
                    ns.over = true;
                    ns.line = line;
                    ns.end = { type: 'win', seat: player };
                } else if (!ns.board.includes(-1)) {
                    ns.over = true;
                    ns.end = { type: 'draw' };
                } else {
                    ns.turn = [1 - player];
                }
                ctx.submit(ns);
            };

            const grid = h('div', { class: 'c4-board' + (ctx.isMyTurn ? ' can-play' : '') });
            for (let c = 0; c < COLS; c++) {
                const col = h('div', { class: 'c4-col', onclick: () => drop(c) });
                for (let r = 0; r < ROWS; r++) {
                    const idx = r * COLS + c;
                    const v = state.board[idx];
                    col.append(h('div', {
                        class: 'c4-cell' + (v === 0 ? ' red' : v === 1 ? ' yellow' : '')
                            + (idx === state.last ? ' last' : '')
                            + (state.line && state.line.includes(idx) ? ' win' : ''),
                    }));
                }
                grid.append(col);
            }

            let status;
            if (state.over) status = this.endText(state.end, ctx);
            else if (!ctx.allSeated) status = '';
            else if (ctx.isMyTurn) status = t('Du bist dran – klick auf eine Spalte.', 'Your turn – click a column.');
            else status = t(`${ctx.label(player)} ist dran …`, `${ctx.label(player)} to move …`);

            fill(root, h('p', { class: 'status' + (ctx.isMyTurn ? ' my-turn' : '') },
                !state.over ? h('span', { class: 'c4-dot ' + (player === 0 ? 'red' : 'yellow') }) : null, status), grid);
        },
    });
})();
