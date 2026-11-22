/* Zahlenkampfspiel / Rithmomachia – Oberfläche (Logik in zahlenkampf-logic.js) */
'use strict';

(function () {
    const SIDES = [t('Gerade', 'Evens'), t('Ungerade', 'Odds')];
    const SHAPE_NAME = LANG === 'en'
        ? { rd: 'round', tri: 'triangle', sq: 'square', P: 'pyramid' }
        : { rd: 'Runde', tri: 'Dreieck', sq: 'Quadrat', P: 'Pyramide' };
    const SHAPE_CLASS = { rd: 'round', tri: 'tri', sq: 'square', P: 'pyramid' };
    const MOVE_TXT = LANG === 'en'
        ? { rd: '1 square diagonally', tri: '2 squares straight or flying like a knight', sq: '3 squares straight or flying like a long knight' }
        : { rd: '1 Feld diagonal', tri: '2 Felder gerade oder fliegend wie der Springer', sq: '3 Felder gerade oder fliegend wie ein langer Springer' };
    const PROG_NAME = LANG === 'en' ? { arith: 'arithmetic', geo: 'geometric', harm: 'harmonic' } : { arith: 'arithmetisch', geo: 'geometrisch', harm: 'harmonisch' };

    // Ein Pyramiden-Emoji gibt es nicht – deshalb eine kleine Stufenpyramide wie die Pyramide auf dem Brett
    function pyramidIcon() {
        // Fester SVG-Text (keine Benutzereingaben); der Browser ordnet <svg> beim Einlesen selbst richtig ein.
        const wrap = document.createElement('span');
        wrap.innerHTML = '<svg viewBox="0 0 32 32" class="icon-svg" aria-hidden="true">'
            + '<path d="M2 29 H30 V24 H27 V19 H24 V14 H21 V9 H18 V4 H14 V9 H11 V14 H8 V19 H5 V24 H2 Z"'
            + ' fill="#e7b84a" stroke="#8a6a1f" stroke-width="1.5" stroke-linejoin="miter"/></svg>';
        return wrap.firstChild;
    }

    const stoneName = s => (s.of === 'P' ? t(`Pyramidenteil ${s.v}`, `pyramid part ${s.v}`) : s.shape === 'P' ? t(`Pyramide ${s.v}`, `pyramid ${s.v}`) : String(s.v));

    function whyText(w) {
        return {
            eq: t('Gleichheit', 'equality'),
            add: `${w.a} + ${w.b}`,
            sub: `${w.a} − ${w.b}`,
            mul: `${w.a} × ${w.b}`,
            div: `${w.a} : ${w.b}`,
            obl: t('eingeschlossen', 'surrounded'),
        }[w.t] || '';
    }

    /** Ein Eintrag des Zugprotokolls als Satz in der Seitensprache. */
    function logText(e) {
        let text = t(`${stoneName(e.stone)} zieht`, `${stoneName(e.stone)} moves`);
        for (const c of e.caps || []) text += t(` · schlägt ${stoneName(c.stone)} (${whyText(c.why)})`, ` · captures ${stoneName(c.stone)} (${whyText(c.why)})`);
        for (const c of e.lost || []) text += t(` · verliert ${stoneName(c.stone)} (${whyText(c.why)})`, ` · loses ${stoneName(c.stone)} (${whyText(c.why)})`);
        return text;
    }

    function endText(end) {
        if (!end) return '';
        if (end.type === 'triumph') {
            return t('Triumph', 'Triumph') + `: ${end.values.join(' – ')} (${PROG_NAME[end.kind]})!`;
        }
        if (end.type === 'bodies') return t(`Sieg nach Körpern: ${end.n} Steine geschlagen.`, `Victory of bodies: ${end.n} men captured.`);
        if (end.type === 'goods') return t(`Sieg nach Gütern: Steine im Wert von ${end.sum} geschlagen.`, `Victory of goods: men worth ${end.sum} captured.`);
        if (end.type === 'stuck') return t('Die Gegenseite kann nicht mehr ziehen.', 'The opponent cannot move any more.');
        if (end.type === 'resign') return t(`${SIDES[end.seat]} gibt auf.`, `${SIDES[end.seat]} resign.`);
        return '';
    }

    // lokale Auswahl (gilt nur für diesen Tisch und Stand)
    let sel = { version: '', stone: null };

    function stoneEl(s, extra = '') {
        const v = ZK.value(s);
        const title = s.shape === 'P'
            ? t(`Pyramide ${v} aus ${s.parts.map(p => `${SHAPE_NAME[p.shape]} ${p.v}`).join(', ')}; zieht wie ihre Teile`,
                `Pyramid ${v} made of ${s.parts.map(p => `${SHAPE_NAME[p.shape]} ${p.v}`).join(', ')}; moves like its parts`)
            : `${v} – ${SHAPE_NAME[s.shape]}, ${MOVE_TXT[s.shape]}`;
        const long = v >= 100 ? 'long' : '';   // dreistellig: kleinere Schrift
        return h('span', { class: `zk-stone side${s.side} ${SHAPE_CLASS[s.shape]} ${long} ${extra}`, title }, h('span', { class: 'zk-num' }, v));
    }

    /** Fortschrittsbalken: Wert / Ziel */
    function bar(label, now, goal) {
        const pct = Math.min(100, Math.round(100 * now / goal));
        return h('div', { class: 'zk-bar' },
            h('span', { class: 'zk-bar-label' }, label),
            h('span', { class: 'zk-bar-track', title: `${now} / ${goal}` }, h('span', { class: 'zk-bar-fill', style: { width: pct + '%' } })),
            h('span', { class: 'zk-bar-num' }, `${now} / ${goal}`));
    }

    registerGame({
        id: 'zahlenkampf',
        name: t('Zahlenkampfspiel', 'Rithmomachia'),
        icon: pyramidIcon,
        desc: t('Gewinne durch Harmonie!', 'Win through harmony!'),
        wip: true,   // Regeln werden noch erprobt
        seats: SIDES,
        rules: rulesHtml(),

        init() {
            return ZK.initState();
        },

        resign(state, seat) {
            state.over = true;
            state.winner = 1 - seat;
            state.end = { type: 'resign', seat };
            return state;
        },

        render(root, ctx) {
            fill(root, renderBoard(root, ctx));
        },
    });

    function renderBoard(root, ctx) {
        const { state, mySeat } = ctx;
        const side = state.turn[0];
        const mine = ctx.isMyTurn;
        // Hochkant, eigenes Lager unten; Zuschauende sehen „Gerade“ unten
        const bottom = mySeat !== null ? mySeat : 0;

        const selKey = ctx.room.id + ':' + ctx.version;
        if (sel.version !== selKey) sel = { version: selKey, stone: null };
        const rerender = () => { root.replaceChildren(); Games.zahlenkampf.render(root, ctx); };

        const byPos = new Map(state.stones.map(s => [s.r * ZK.COLS + s.c, s]));
        const selStone = sel.stone !== null ? state.stones.find(s => s.id === sel.stone) : null;

        // Ziele mit Vorschau, was dabei geschlagen würde
        const targets = new Map();
        if (mine && selStone) {
            for (const m of ZK.movesFor(state, selStone)) {
                targets.set(m.r * ZK.COLS + m.c, { ...m, preview: ZK.applyMove(state, side, selStone.id, m.r, m.c) });
            }
        }
        const clickCell = (r, c) => {
            if (!mine) return;
            const key = r * ZK.COLS + c;
            const tg = targets.get(key);
            if (tg && tg.preview) { ctx.submit(tg.preview); return; }
            const s = byPos.get(key);
            sel.stone = s && s.side === side && s.id !== sel.stone ? s.id : null;
            rerender();
        };

        const capturedAt = new Set((state.captured || []).map(([r, c]) => r * ZK.COLS + c));
        const board = h('div', { class: 'zk-board' });
        for (let i = 0; i < ZK.ROWS; i++) {
            for (let j = 0; j < ZK.COLS; j++) {
                const r = bottom === 0 ? ZK.ROWS - 1 - i : i;
                const c = bottom === 0 ? j : ZK.COLS - 1 - j;
                const key = r * ZK.COLS + c;
                const s = byPos.get(key);
                const tg = targets.get(key);
                const z = ZK.zone(r);
                const cls = ['zk-cell', 'home' + z];
                if (i === ZK.ROWS / 2 - 1) cls.push('campline');   // Grenze der beiden Lager
                const last = state.last;
                if (last && ((last.from[0] === r && last.from[1] === c) || (last.to[0] === r && last.to[1] === c))) cls.push('lastmove');
                if (capturedAt.has(key)) cls.push('lastcap');
                let title = null;
                if (tg) {
                    cls.push('target');
                    const entry = tg.preview && tg.preview.log[tg.preview.log.length - 1];
                    if (entry && (entry.caps.length || entry.lost.length)) cls.push('capture');
                    if (tg.preview && tg.preview.over) cls.push('winning');
                    title = tg.preview && tg.preview.over ? endText(tg.preview.end)
                        : entry && (entry.caps.length || entry.lost.length) ? logText(entry) : null;
                }
                const extra = [
                    s && sel.stone === s.id ? 'selected' : '',
                    s && mine && s.side === side ? 'own' : '',
                    s && state.triumph && state.triumph.includes(s.id) ? 'harmony' : '',
                ].join(' ');
                board.append(h('div', { class: cls.join(' '), title, onclick: () => clickCell(r, c) }, s ? stoneEl(s, extra) : null));
            }
        }

        let status = '';
        if (state.over) status = endText(state.end) + (state.winner !== null && state.winner !== undefined ? t(` ${ctx.label(state.winner)} gewinnt.`, ` ${ctx.label(state.winner)} wins.`) : '');
        else if (!ctx.allSeated) status = '';
        else if (mine) status = t('Du bist dran: Stein antippen, dann Zielfeld wählen.', 'Your turn: tap a man, then choose a target square.');
        else status = t(`${ctx.label(side)} ist dran …`, `${ctx.label(side)} to move …`);

        // Stand der Siegbedingungen: Körper (Hälfte der gegnerischen Steine), Güter (¾ der gegnerischen Summe), Triumph
        const score = s => {
            const tk = state.taken[s];
            const pyramidGone = !state.stones.some(x => x.side === 1 - s && x.shape === 'P');
            return h('div', { class: 'zk-score small' },
                h('b', {}, SIDES[s] + t(' hat geschlagen', ' captured')),
                bar(t('Körper', 'Bodies'), tk.n, ZK.GOAL[s].n),
                bar(t('Güter', 'Goods'), tk.sum, ZK.GOAL[s].sum),
                pyramidGone ? h('div', { class: 'zk-tri' }, t('Pyramide geschlagen – Triumph möglich', 'Pyramid captured – triumph possible')) : null);
        };

        const log = h('ol', { class: 'zk-log', reversed: true, start: state.log.length },
            state.log.slice(-12).reverse().map(e => h('li', {}, h('b', {}, SIDES[e.side] + ': '), logText(e))));

        return [
            h('p', { class: 'status' + (mine ? ' my-turn' : '') }, status),
            score(1 - bottom),
            h('div', { class: 'zk-scroll' }, board),
            score(bottom),
            h('div', { class: 'zk-legend small muted' },
                mySeat !== null ? t('Dein Lager ist unten.', 'Your camp is at the bottom.') : t('Gerade unten, Ungerade oben.', 'Evens at the bottom, Odds at the top.'),
                t(' Rote Zielfelder schlagen etwas.', ' Red target squares capture something.')),
            state.log.length ? h('details', { class: 'zk-logbox', open: true }, h('summary', {}, t('Letzte Züge', 'Recent moves')), log) : null,
        ];
    }

    function rulesHtml() {
        const goal = ZK.GOAL;
        return LANG === 'en' ? `
            <p>Board of 8 × 16 squares; each side's <b>camp</b> is its half (8 × 8). <b>Evens</b> (light) play from the bottom and begin,
            <b>Odds</b> (dark) from the top. Each side has 8 rounds (●), 8 triangles (▲) and 8 squares (■, one of them is the pyramid).</p>
            <p><b>Moving</b> (always onto a free square): rounds 1 square diagonally; triangles exactly 2 squares and squares exactly 3 squares
            straight (horizontally or vertically), never jumping over a man. Triangles may also <b>fly</b> like a chess knight, squares like a
            “long knight” (1 + 3); flying may jump over men and is an ordinary move in every respect. The <b>pyramid</b>
            (Evens 91 = 36 + 25 + 16 + 9 + 4 + 1, Odds 190 = 64 + 49 + 36 + 25 + 16) moves like any of the shapes it still contains.</p>
            <p><b>Capturing</b> happens without moving. After every move, first the opponent's men that can now be captured are removed,
            then – if the rule still holds – the mover's own. A man <i>reaches</i> a square if it could move there (straight or flying) were the square free.</p>
            <ul>
              <li><b>Equality:</b> a man of the other side with the same value reaches it.</li>
              <li><b>Addition, subtraction, multiplication, division:</b> two men of the other side both reach it, and their sum, difference, product or quotient is its value (division only without remainder).</li>
              <li><b>Surrounding:</b> all target squares of its straight moves (without flying) are occupied or off the board, and at least one of them is occupied by the other side.</li>
              <li>A whole <b>pyramid</b> never falls by equality – only by a calculation with its total value or by surrounding. Single parts fall by equality or a calculation with their value; the number on the pyramid is the sum of its remaining parts, and it loses the moves of shapes it no longer contains.</li>
            </ul>
            <p>Captured men leave the board. <b>Victory:</b></p>
            <ul>
              <li><b>Triumph</b> – once the enemy pyramid is completely captured: a line of at least 3 men without gaps (horizontal, vertical or diagonal)
              lying entirely in the enemy camp, whose two end men are yours (the men in between may belong to either side) and whose values together
              form a progression (order does not matter): arithmetic (4, 6, 8 or 2, 4, 6, 8), geometric (2, 4, 8) or harmonic (3, 4, 6 – the reciprocals are arithmetic).</li>
              <li><b>Bodies</b> – half of the enemy men captured (${goal[0].n}).</li>
              <li><b>Goods</b> – ¾ of the enemy's total value captured (Evens ${goal[0].sum}, Odds ${goal[1].sum}).</li>
            </ul>
            <p>A player who cannot move loses.</p>
            <p class="muted small">Source of our rules, for reference: Fulke's first variant as reconstructed by Mark Waks
            (<a href="https://jducoeur.org/game-hist/game-recon-rhyth1.html" target="_blank" rel="noopener">jducoeur.org</a>), setup as in Fulke's picture;
            our group has changed some details (flying, triumph, captured men).</p>`
            : `
            <p>Brett mit 8 × 16 Feldern; das <b>Lager</b> jeder Seite ist ihre Hälfte (8 × 8). <b>Gerade</b> (hell) spielt von unten und beginnt,
            <b>Ungerade</b> (dunkel) von oben. Jede Seite hat 8 Runde (●), 8 Dreiecke (▲) und 8 Quadrate (■, eines davon ist die Pyramide).</p>
            <p><b>Ziehen</b> (immer auf ein freies Feld): Runde 1 Feld diagonal; Dreiecke genau 2 und Quadrate genau 3 Felder gerade (waagrecht oder senkrecht),
            ohne einen Stein zu überspringen. Dreiecke dürfen außerdem wie der Springer im Schach <b>fliegen</b>, Quadrate wie ein
            „langer Springer“ (1 + 3); fliegend darf man überspringen, sonst ist Fliegen ein ganz normaler Zug. Die <b>Pyramide</b>
            (Gerade 91 = 36 + 25 + 16 + 9 + 4 + 1, Ungerade 190 = 64 + 49 + 36 + 25 + 16) zieht wie jede Form, die sie noch enthält.</p>
            <p><b>Geschlagen</b> wird ohne zu ziehen. Nach jedem Zug fallen zuerst die Steine der Gegenseite, die jetzt geschlagen werden können,
            danach – falls die Regel dann noch gilt – die eigenen. Ein Stein <i>erreicht</i> ein Feld, wenn er dorthin ziehen oder fliegen könnte, wäre es frei.</p>
            <ul>
              <li><b>Gleichheit:</b> Ein Stein der anderen Seite mit gleichem Wert erreicht ihn.</li>
              <li><b>Addition, Subtraktion, Multiplikation, Division:</b> Zwei Steine der anderen Seite erreichen ihn beide, und Summe, Differenz, Produkt oder Quotient ihrer Werte ergibt seinen Wert (Division nur ohne Rest).</li>
              <li><b>Einschließen:</b> Alle Zielfelder seiner geraden Züge (ohne Fliegen) sind besetzt oder außerhalb des Bretts, und mindestens eines davon ist von der anderen Seite besetzt.</li>
              <li>Die ganze <b>Pyramide</b> fällt nie durch Gleichheit – nur durch eine Rechenart mit ihrem Gesamtwert oder durch Einschließen. Einzelne Teile fallen durch Gleichheit oder eine Rechenart mit ihrem Wert; auf der Pyramide steht die Summe der verbliebenen Teile, und sie verliert die Züge der Formen, die sie nicht mehr enthält.</li>
            </ul>
            <p>Geschlagene Steine verlassen das Brett. <b>Sieg:</b></p>
            <ul>
              <li><b>Triumph</b> – sobald die gegnerische Pyramide ganz geschlagen ist: eine lückenlose Reihe aus mindestens 3 Steinen (waagrecht, senkrecht oder diagonal)
              ganz im gegnerischen Lager, deren beide Endsteine dir gehören (die Steine dazwischen dürfen beiden Seiten gehören) und deren Werte zusammen eine
              Progression bilden (Reihenfolge egal): arithmetisch (4, 6, 8 oder 2, 4, 6, 8), geometrisch (2, 4, 8) oder harmonisch (3, 4, 6 – die Kehrwerte sind arithmetisch).</li>
              <li><b>Körper</b> – die Hälfte der gegnerischen Steine geschlagen (${goal[0].n}).</li>
              <li><b>Güter</b> – ¾ der gegnerischen Gesamtsumme geschlagen (Gerade ${goal[0].sum}, Ungerade ${goal[1].sum}).</li>
            </ul>
            <p>Wer nicht mehr ziehen kann, verliert.</p>
            <p class="muted small">Ursprung unserer Regeln, zum Nachschauen: Fulkes erste Variante in der Rekonstruktion von Mark Waks
            (<a href="https://jducoeur.org/game-hist/game-recon-rhyth1.html" target="_blank" rel="noopener">jducoeur.org</a>), Aufstellung wie in Fulkes Bild;
            unsere Runde hat einige Einzelheiten geändert (Fliegen, Triumph, geschlagene Steine).</p>`;
    }
})();
