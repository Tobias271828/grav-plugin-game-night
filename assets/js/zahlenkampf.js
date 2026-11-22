/* Zahlenkampfspiel / Rithmomachia – Oberfläche (Logik in zahlenkampf-logic.js) */
'use strict';

(function () {
    const SIDES = [t('Gerade', 'Evens'), t('Ungerade', 'Odds')];
    const SHAPE_NAME = LANG === 'en'
        ? { rd: 'round', tri: 'triangle', sq: 'square', K: 'king' }
        : { rd: 'Runde', tri: 'Dreieck', sq: 'Quadrat', K: 'König' };
    const SHAPE_CLASS = { rd: 'round', tri: 'tri', sq: 'square', K: 'pyramid' };
    const MOVE_TXT = LANG === 'en'
        ? { rd: '1 square diagonally', tri: '2 squares straight or flying like a knight', sq: '3 squares straight or flying like a long knight' }
        : { rd: '1 Feld diagonal', tri: '2 Felder gerade oder fliegend wie der Springer', sq: '3 Felder gerade oder fliegend wie ein langer Springer' };
    const PROP_NAME = LANG === 'en' ? { arith: 'arithmetic', geo: 'geometric', harm: 'harmonic' } : { arith: 'arithmetisch', geo: 'geometrisch', harm: 'harmonisch' };

    // Ein Pyramiden-Emoji gibt es nicht – deshalb eine kleine Stufenpyramide wie der König auf dem Brett
    function pyramidIcon() {
        // Fester SVG-Text (keine Benutzereingaben); der Browser ordnet <svg> beim Einlesen selbst richtig ein.
        const wrap = document.createElement('span');
        wrap.innerHTML = '<svg viewBox="0 0 32 32" class="icon-svg" aria-hidden="true">'
            + '<path d="M2 29 H30 V24 H27 V19 H24 V14 H21 V9 H18 V4 H14 V9 H11 V14 H8 V19 H5 V24 H2 Z"'
            + ' fill="#e7b84a" stroke="#8a6a1f" stroke-width="1.5" stroke-linejoin="miter"/></svg>';
        return wrap.firstChild;
    }

    const stoneName = s => (s.of === 'K' ? t(`Königsteil ${s.v}`, `king's part ${s.v}`) : s.shape === 'K' ? t(`König ${s.v}`, `king ${s.v}`) : String(s.v));

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
        let text = e.fly ? t(`${stoneName(e.stone)} fliegt`, `${stoneName(e.stone)} flies`) : t(`${stoneName(e.stone)} zieht`, `${stoneName(e.stone)} moves`);
        for (const c of e.caps) text += t(` · schlägt ${stoneName(c.stone)} (${whyText(c.why)})`, ` · captures ${stoneName(c.stone)} (${whyText(c.why)})`);
        return text;
    }

    function endText(end) {
        if (!end) return '';
        if (end.type === 'triumph') {
            const name = [null, t('Großer Triumph', 'Great triumph'), t('Größerer Triumph', 'Greater triumph'), t('Größter Triumph', 'Greatest triumph')][end.level];
            return `${name}: ${end.values.join(' – ')} (${end.kinds.map(k => PROP_NAME[k]).join(', ')})!`;
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
        const title = s.shape === 'K'
            ? t(`König ${ZK.value(s)} aus ${s.parts.map(p => `${SHAPE_NAME[p.shape]} ${p.v}`).join(', ')}; zieht wie seine Teile`,
                `King ${ZK.value(s)} made of ${s.parts.map(p => `${SHAPE_NAME[p.shape]} ${p.v}`).join(', ')}; moves like its parts`)
            : `${s.v} – ${SHAPE_NAME[s.shape]}, ${MOVE_TXT[s.shape]}` + (s.conv ? t(' (Gefangene*r)', ' (prisoner)') : '');
        const long = (s.shape === 'K' ? ZK.value(s) : s.v) >= 100 ? 'long' : '';   // dreistellig: kleinere Schrift
        return h('span', { class: `zk-stone side${s.side} ${SHAPE_CLASS[s.shape]} ${s.conv ? 'conv' : ''} ${long} ${extra}`, title },
            h('span', { class: 'zk-num' }, s.shape === 'K' ? ZK.value(s) : s.v));
    }

    registerGame({
        id: 'zahlenkampf',
        name: t('Zahlenkampfspiel', 'Rithmomachia'),
        icon: pyramidIcon,
        desc: t('Gewinne durch Harmonie!', 'Win through harmony!'),
        wip: true,   // Regeln werden noch erprobt
        seats: SIDES,
        rules: rulesHtml(),

        init(prev) {
            return ZK.initState(prev && prev.settings ? prev.settings : undefined);
        },

        isMyTurn(state, seat) {
            return state.phase === 'play' && !state.over && seat !== null && (state.turn || []).includes(seat);
        },

        canResign(state) {
            return state.phase === 'play';
        },

        resign(state, seat) {
            state.over = true;
            state.winner = 1 - seat;
            state.end = { type: 'resign', seat };
            return state;
        },

        render(root, ctx) {
            const { state } = ctx;
            if (state.phase === 'setup') return fill(root, renderSetup(ctx));
            return fill(root, renderBoard(root, ctx));
        },
    });

    // ------------------------------------------------------------------ Siegbedingungen vor dem Start

    function renderSetup(ctx) {
        const { state, mySeat } = ctx;
        const st = state.settings;
        const canEdit = mySeat !== null;
        const change = patch => ctx.submit({ ...state, settings: { ...st, ...patch } }, 'reset');
        const stepper = (key, step, min, max, unit) => h('span', { class: 'kf-stepper zk-stepper' },
            h('button', { class: 'btn small', disabled: !canEdit || st[key] <= min, onclick: () => change({ [key]: Math.max(min, st[key] - step) }) }, '−'),
            h('b', {}, st[key], ' ', unit),
            h('button', { class: 'btn small', disabled: !canEdit || st[key] >= max, onclick: () => change({ [key]: Math.min(max, st[key] + step) }) }, '+'));
        const row = (on, label, desc, toggle, extra) => h('div', { class: 'zk-setting' },
            h('button', { class: 'tb-chip' + (on ? ' on' : ''), disabled: !canEdit, onclick: toggle }, label),
            h('span', { class: 'small muted' }, desc),
            on && extra ? extra : null);
        const none = !st.triumph && !st.bodies && !st.goods;
        return h('div', { class: 'tb' },
            h('div', { class: 'tb-status' }, t('Wie wird gewonnen?', 'How do you win?')),
            h('div', { class: 'tb-deck zk-settings' },
                h('div', { class: 'tb-label' }, t('Siegbedingungen (wer zuerst eine erfüllt, gewinnt)', 'Victory conditions (first to meet one wins)')),
                row(st.triumph, t('Triumph', 'Triumph'),
                    t('Gegnerischen König schlagen, dann drei Steine in Proportion im gegnerischen Lager', 'Capture the enemy king, then three men in proportion in the enemy camp'),
                    () => change({ triumph: !st.triumph })),
                row(st.bodies > 0, t('Körper', 'Bodies'), t('Eine Zahl an Steinen schlagen', 'Capture a number of men'),
                    () => change({ bodies: st.bodies ? 0 : ZK.DEFAULT_SETTINGS.bodies }), stepper('bodies', 1, 1, 30, t('Steine', 'men'))),
                row(st.goods > 0, t('Güter', 'Goods'), t('Steine mit einer Summe an Punkten schlagen', 'Capture men worth a number of points'),
                    () => change({ goods: st.goods ? 0 : ZK.DEFAULT_SETTINGS.goods }), stepper('goods', 50, 50, 2000, t('Punkte', 'points'))),
                none ? h('p', { class: 'small error', style: { margin: '.3rem 0 0' } }, t('Bitte mindestens eine Siegbedingung wählen.', 'Please choose at least one victory condition.')) : null),
            mySeat !== null ? h('button', {
                class: 'btn primary tb-big', disabled: !ctx.allSeated || none,
                onclick: () => ctx.submit(ZK.start(state), 'reset'),
            }, ctx.allSeated ? t('Spiel starten', 'Start game') : t('Warte auf die zweite Person …', 'Waiting for the second player …')) : null);
    }

    // ------------------------------------------------------------------ Brett

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
                const cls = ['zk-cell', z === 0 ? 'home0' : z === 1 ? 'home1' : 'neutral'];
                const below = bottom === 0 ? r - 1 : r + 1;   // Reihe, die darunter angezeigt wird
                if (i < ZK.ROWS - 1 && ZK.zone(below) !== z) cls.push('campline');
                const last = state.last;
                if (last && ((last.from[0] === r && last.from[1] === c) || (last.to[0] === r && last.to[1] === c))) cls.push('lastmove');
                if (capturedAt.has(key)) cls.push('lastcap');
                let title = null;
                if (tg) {
                    cls.push('target');
                    if (tg.fly) cls.push('fly');
                    if (tg.preview && tg.preview.captured.length) cls.push('capture');
                    if (tg.preview && tg.preview.over) cls.push('winning');
                    const entry = tg.preview && tg.preview.log[tg.preview.log.length - 1];
                    title = tg.preview && tg.preview.over ? endText(tg.preview.end) : entry && entry.caps.length ? logText(entry) : (tg.fly ? t('fliegen', 'fly') : null);
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

        // Stand der Siegbedingungen
        const st = state.settings;
        const score = s => {
            const tk = state.taken[s];
            const parts = [t(`${tk.n} ${tk.n === 1 ? 'Stein' : 'Steine'}`, `${tk.n} ${tk.n === 1 ? 'man' : 'men'}`) + (st.bodies ? ` / ${st.bodies}` : ''),
                           t(`${tk.sum} Punkte`, `${tk.sum} points`) + (st.goods ? ` / ${st.goods}` : '')];
            const kingGone = !state.stones.some(x => x.side === 1 - s && x.shape === 'K');
            if (st.triumph && kingGone) parts.push(t('König geschlagen – Triumph möglich', 'king captured – triumph possible'));
            return h('div', { class: 'zk-score small' }, h('b', {}, SIDES[s] + t(' hat geschlagen: ', ' captured: ')), parts.join(' · '));
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
                t(' Gestrichelte Steine sind Gefangene, die jetzt für die andere Seite spielen; gestrichelte Zielfelder sind fliegende Züge.',
                  ' Dashed men are prisoners now playing for the other side; dashed target squares are flying moves.')),
            state.log.length ? h('details', { class: 'zk-logbox', open: true }, h('summary', {}, t('Letzte Züge', 'Recent moves')), log) : null,
        ];
    }

    function rulesHtml() {
        return LANG === 'en' ? `
            <p>Fulke's first variant as reconstructed by Mark Waks
            (<a href="https://jducoeur.org/game-hist/game-recon-rhyth1.html" target="_blank" rel="noopener">jducoeur.org</a>).
            Board of 8 × 16 squares; each side's <b>camp</b> are its back six rows. <b>Evens</b> (light) play from the bottom and begin,
            <b>Odds</b> (dark) from the top. Each side has 8 rounds (●), 8 triangles (▲) and 8 squares (■, one of them is the king).</p>
            <p><b>Moving:</b> rounds 1 square diagonally; triangles exactly 2 squares and squares exactly 3 squares straight
            (horizontally or vertically), never jumping over a man. Triangles may also <b>fly</b> like a chess knight, squares like a
            “long knight” (1 + 3); flying may jump, but a flying move must not capture anything. The <b>king</b> (pyramid: Evens 91 = 36 + 25 + 16 + 9 + 4 + 1,
            Odds 190 = 64 + 49 + 36 + 25 + 16) moves like any of the shapes it still contains.</p>
            <p><b>Capturing</b> happens without moving, automatically after your move, for every enemy man that a man of yours could reach
            with a straight move:</p>
            <ul>
              <li><b>Equality:</b> one of your men with the same value reaches it.</li>
              <li><b>Addition, subtraction, multiplication, division:</b> two of your men reach it and their sum, difference, product or quotient is its value.</li>
              <li><b>Surrounding:</b> all four neighbouring squares (horizontally/vertically) are occupied by your men.</li>
              <li>The <b>king</b> cannot be taken by equality; otherwise he falls whole (total value) or loses a part (its value) and with it that shape's moves.</li>
            </ul>
            <p>A captured man changes colour and is placed on the rearmost free square of your camp; from there it plays for you.</p>
            <p><b>Victory</b> (agreed before the game): <b>Triumph</b> – once the enemy king is completely captured, three of your men
            (that did not arrive flying) in a line in the enemy camp whose values form an arithmetic (4, 6, 8), geometric (2, 4, 8)
            or harmonic (3, 4, 6) proportion (four men in a line or square with two or three proportions: greater/greatest triumph);
            <b>Bodies</b> – capture a number of men; <b>Goods</b> – capture men worth a number of points. A player who cannot move loses.</p>`
            : `
            <p>Fulkes erste Variante in der Rekonstruktion von Mark Waks
            (<a href="https://jducoeur.org/game-hist/game-recon-rhyth1.html" target="_blank" rel="noopener">jducoeur.org</a>).
            Brett mit 8 × 16 Feldern; das <b>Lager</b> jeder Seite sind ihre hinteren sechs Reihen. <b>Gerade</b> (hell) spielt von unten und beginnt,
            <b>Ungerade</b> (dunkel) von oben. Jede Seite hat 8 Runde (●), 8 Dreiecke (▲) und 8 Quadrate (■, eines davon ist der König).</p>
            <p><b>Ziehen:</b> Runde 1 Feld diagonal; Dreiecke genau 2 und Quadrate genau 3 Felder gerade (waagrecht oder senkrecht),
            ohne einen Stein zu überspringen. Dreiecke dürfen außerdem wie der Springer im Schach <b>fliegen</b>, Quadrate wie ein
            „langer Springer“ (1 + 3); fliegend darf man überspringen, aber nichts schlagen. Der <b>König</b> (Pyramide: Gerade 91 = 36 + 25 + 16 + 9 + 4 + 1,
            Ungerade 190 = 64 + 49 + 36 + 25 + 16) zieht wie jede Form, die er noch enthält.</p>
            <p><b>Geschlagen</b> wird ohne zu ziehen, automatisch nach deinem Zug, bei jedem gegnerischen Stein, den ein eigener Stein mit
            einem geraden Zug erreichen könnte:</p>
            <ul>
              <li><b>Gleichheit:</b> Ein eigener Stein mit gleichem Wert erreicht ihn.</li>
              <li><b>Addition, Subtraktion, Multiplikation, Division:</b> Zwei eigene Steine erreichen ihn, und Summe, Differenz, Produkt oder Quotient ihrer Werte ergibt seinen Wert.</li>
              <li><b>Einschließen:</b> Alle vier Nachbarfelder (waagrecht/senkrecht) sind mit eigenen Steinen besetzt.</li>
              <li>Den <b>König</b> schlägt Gleichheit nicht; sonst fällt er ganz (Gesamtwert) oder verliert einen Teil (dessen Wert) und damit die Züge dieser Form.</li>
            </ul>
            <p>Ein geschlagener Stein wechselt die Farbe und kommt auf das hinterste freie Feld des eigenen Lagers; von dort spielt er für einen.</p>
            <p><b>Sieg</b> (vor dem Spiel vereinbart): <b>Triumph</b> – ist der gegnerische König ganz geschlagen, drei eigene (nicht fliegend angekommene)
            Steine in einer Reihe im gegnerischen Lager, deren Werte eine arithmetische (4, 6, 8), geometrische (2, 4, 8) oder harmonische (3, 4, 6)
            Proportion bilden (vier Steine in einer Reihe oder im Quadrat mit zwei bzw. drei Proportionen: größerer bzw. größter Triumph);
            <b>Körper</b> – eine Zahl an Steinen schlagen; <b>Güter</b> – Steine mit einer Summe an Punkten schlagen. Wer nicht mehr ziehen kann, verliert.</p>`;
    }
})();
