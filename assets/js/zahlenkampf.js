/* Zahlenkampfspiel / Rithmomachia – Oberfläche (Logik in zahlenkampf-logic.js) */
'use strict';

(function () {
    const SIDES = [t('Gerade', 'Evens'), t('Ungerade', 'Odds')];
    const KIND_NAME = LANG === 'en'
        ? { b: 'base number', m: 'multiple', sp1: 'superparticular', sp2: 'superparticular', st1: 'superpartient', st2: 'superpartient' }
        : { b: 'Grundzahl', m: 'Vielfaches', sp1: 'Überteilige', sp2: 'Überteilige', st1: 'Mehrfachteilige', st2: 'Mehrfachteilige' };

    // Ein Pyramiden-Emoji gibt es nicht – deshalb eine kleine Stufenpyramide wie die Pyramiden-Steine
    function pyramidIcon() {
        // Fester SVG-Text (keine Benutzereingaben); der Browser ordnet <svg> beim Einlesen selbst richtig ein.
        // Stufenpyramide: nur waagrechte und senkrechte Kanten (alle Ecken 90°)
        const wrap = document.createElement('span');
        wrap.innerHTML = '<svg viewBox="0 0 32 32" class="icon-svg" aria-hidden="true">'
            + '<path d="M2 29 H30 V24 H27 V19 H24 V14 H21 V9 H18 V4 H14 V9 H11 V14 H8 V19 H5 V24 H2 Z"'
            + ' fill="#e7b84a" stroke="#8a6a1f" stroke-width="1.5" stroke-linejoin="miter"/></svg>';
        return wrap.firstChild;
    }

    const stoneName = s => (s.kind === 'P' ? t(`Pyramide ${s.v}`, `pyramid ${s.v}`) : String(s.v));

    function whyText(w) {
        if (w.t === 'encounter') return t('Begegnung', 'encounter');
        if (w.t === 'assault') return t(`Angriff: ${w.a} × ${w.n} = ${w.a * w.n}`, `assault: ${w.a} × ${w.n} = ${w.a * w.n}`);
        if (w.t === 'sum') return t('Belagerung: ', 'siege: ') + w.nums.join(' + ') + ' = ' + w.nums.reduce((x, y) => x + y, 0);
        if (w.t === 'prod') return t('Belagerung: ', 'siege: ') + w.nums.join(' × ') + ' = ' + w.nums.reduce((x, y) => x * y, 1);
        return t('Primzahl eingeschlossen', 'prime surrounded');
    }

    /** Ein Eintrag des Zugprotokolls als Satz in der Seitensprache. */
    function logText(e) {
        let text = e.act === 'drop'
            ? t(`${stoneName(e.stone)} wird eingesetzt`, `${stoneName(e.stone)} is brought back in`)
            : t(`${stoneName(e.stone)} zieht`, `${stoneName(e.stone)} moves`);
        for (const c of e.caps) {
            text += t(` · schlägt ${stoneName(c.stone)} (${whyText(c.why)})`, ` · captures ${stoneName(c.stone)} (${whyText(c.why)})`);
        }
        return text;
    }

    function endText(end) {
        if (!end) return '';
        if (end.type === 'harmony') {
            const v = end.values.join(' – ');
            return end.kind === 'arith'
                ? t(`Sieg durch arithmetische Harmonie ${v}!`, `Victory by arithmetic harmony ${v}!`)
                : t(`Sieg durch harmonische Harmonie ${v}!`, `Victory by harmonic progression ${v}!`);
        }
        if (end.type === 'stuck') return t('Die Gegenseite kann nicht mehr ziehen.', 'The opponent cannot move anymore.');
        if (end.type === 'resign') return t(`${SIDES[end.seat]} gibt auf.`, `${SIDES[end.seat]} resign.`);
        return '';
    }
    const SHAPE = { b: 'round small', m: 'round', sp1: 'tri', sp2: 'tri', st1: 'square', st2: 'square', P: 'pyramid' };
    const RANGE_TXT = { b: '1', m: '2', sp1: '3', sp2: '3', st1: '4', st2: '4', P: '1–4' };

    // lokale Auswahl: Stein auf dem Brett oder Stein aus dem Vorrat
    let sel = { version: 0, stone: null, drop: null };

    // Ab dieser Breite sehen auch Zuschauende das Brett hochkant (quer passt es nur mit Scrollen)
    const NARROW = '(max-width: 700px)';
    let lastRender = null;
    if (window.matchMedia) {
        const mq = window.matchMedia(NARROW);
        const onChange = () => { if (lastRender && lastRender.root.isConnected) lastRender.game.render(lastRender.root, lastRender.ctx); };
        if (mq.addEventListener) mq.addEventListener('change', onChange); else if (mq.addListener) mq.addListener(onChange);
    }

    function stoneEl(s, extra = '') {
        const title = s.kind === 'P'
            ? t(`Pyramide ${s.v} (schlagbar auch über ihre Basis ${s.base}), zieht ${RANGE_TXT.P} Felder`,
                `Pyramid ${s.v} (can also be captured via its base ${s.base}), moves ${RANGE_TXT.P} squares`)
            : t(`${s.v} – ${KIND_NAME[s.kind]}, zieht ${RANGE_TXT[s.kind]} Felder`, `${s.v} – ${KIND_NAME[s.kind]}, moves ${RANGE_TXT[s.kind]} squares`)
              + (ZK.isPrime(s.v) ? t(', Primzahl', ', prime') : '');
        return h('span', { class: `zk-stone side${s.side} ${SHAPE[s.kind]} ${s.conv ? 'conv' : ''} ${extra}`, title },
            h('span', { class: 'zk-num' }, s.v));
    }

    registerGame({
        id: 'zahlenkampf',
        name: t('Zahlenkampfspiel', 'Rithmomachia'),
        icon: pyramidIcon,
        desc: t('Gewinne durch Harmonie!', 'Win through harmony!'),
        wip: true,   // Regeln müssen noch exakt festgelegt werden
        seats: SIDES,
        rules: LANG === 'en' ? `<p>Board of 8 × 16 squares. <b>Evens</b> (light stones, base numbers 2, 4, 6, 8) play on the left and start,
            <b>Odds</b> (dark stones, base numbers 3, 5, 7, 9) play on the right. Each side has 24 stones:
            base number <i>n</i> (●&nbsp;small), <i>n²</i> (●), the superparticulars <i>n(n+1)</i> and <i>(n+1)²</i> (▲)
            and the superpartients <i>(n+1)(2n+1)</i> and <i>(2n+1)²</i> (■). The pyramids are 91 (Evens) and 190 (Odds).</p>
            <p><b>Moving:</b> base numbers exactly 1 square, multiples exactly 2, superparticulars exactly 3, superpartients exactly 4 –
            horizontally/vertically, straight or with one right-angle turn, only across empty squares. The pyramid moves 1 to 4 squares.
            Instead of moving you may place a <b>captured stone</b> on an empty square of your own half; it then plays for you.</p>
            <p><b>Capturing</b> (applied automatically after your move, to all enemy stones):</p>
            <ul>
              <li><b>Encounter:</b> a stone that moves exactly onto an enemy stone of equal value captures it.</li>
              <li><b>Assault:</b> if one of your stones and an enemy stone are in the same row/column with only empty squares between them and
                  <i>your value × number of squares between = enemy value</i>, the enemy stone is captured (e.g. 2 with 8 squares between captures 16).</li>
              <li><b>Siege:</b> if at least two of your stones border an enemy stone (horizontally/vertically) and
                  the sum or product of <i>all</i> your bordering stones equals its value, it is captured.</li>
              <li><b>Primes</b> are also captured when all neighbouring squares (horizontally/vertically) are occupied by your stones.</li>
              <li><b>Pyramids</b> are captured like other stones, but also when the value of their base matches (36 for 91, 64 for 190).</li>
            </ul>
            <p><b>Victory:</b> the first to place three of their own stones directly next to each other in a line in the <b>enemy camp</b>
            (horizontally, vertically or diagonally) whose values form an <b>arithmetic</b> (e.g. 4, 6, 8) or <b>harmonic</b> progression
            (e.g. 3, 4, 6: (6−4):(4−3) = 6:3) wins. A harmonic progression must include at least one captured stone that was brought back in.
            A player who cannot move loses.</p>
            <p class="small muted">Simplified version based on the German Wikipedia article “Zahlenkampfspiel”. Announcing the first harmony stone is omitted.
            Tip: a red target square means the move captures something – hover over it for details.</p>` : `<p>Brett mit 8 × 16 Feldern. <b>Gerade</b> (helle Steine, Grundzahlen 2, 4, 6, 8) spielt links und beginnt,
            <b>Ungerade</b> (dunkle Steine, Grundzahlen 3, 5, 7, 9) spielt rechts. Jede Seite hat 24 Steine:
            Grundzahl <i>n</i> (●&nbsp;klein), <i>n²</i> (●), die Überteiligen <i>n(n+1)</i> und <i>(n+1)²</i> (▲)
            sowie die Mehrfachteiligen <i>(n+1)(2n+1)</i> und <i>(2n+1)²</i> (■). Die Pyramiden sind 91 (Gerade) und 190 (Ungerade).</p>
            <p><b>Ziehen:</b> Grundzahlen genau 1 Feld, Vielfache genau 2, Überteilige genau 3, Mehrfachteilige genau 4 –
            waagrecht/senkrecht, gerade oder mit einem rechten Winkel, nur über freie Felder. Die Pyramide zieht 1 bis 4 Felder.
            Statt zu ziehen darf man einen <b>erbeuteten Stein</b> auf ein freies Feld der eigenen Hälfte setzen; er spielt dann für einen.</p>
            <p><b>Schlagen</b> (wird nach deinem Zug automatisch ausgeführt, für alle gegnerischen Steine):</p>
            <ul>
              <li><b>Begegnung:</b> Zieht ein Stein genau auf einen gegnerischen Stein mit gleichem Wert, schlägt er ihn.</li>
              <li><b>Angriff:</b> Stehen ein eigener und ein gegnerischer Stein in einer Reihe/Spalte mit nur freien Feldern dazwischen und gilt
                  <i>eigener Wert × Anzahl Felder dazwischen = gegnerischer Wert</i>, fällt der gegnerische Stein (z. B. 2 mit 8 Feldern Abstand schlägt 16).</li>
              <li><b>Belagerung:</b> Grenzen mindestens zwei eigene Steine (waagrecht/senkrecht) an einen gegnerischen und ergibt
                  die Summe oder das Produkt <i>aller</i> angrenzenden eigenen Steine dessen Wert, fällt er.</li>
              <li><b>Primzahlen</b> fallen außerdem, wenn alle Nachbarfelder (waagrecht/senkrecht) mit eigenen Steinen besetzt sind.</li>
              <li><b>Pyramiden</b> fallen wie andere Steine, aber auch, wenn der Wert ihrer Basis passt (36 bei der 91, 64 bei der 190).</li>
            </ul>
            <p><b>Sieg:</b> Wer zuerst drei eigene Steine im <b>gegnerischen Lager</b> direkt nebeneinander in eine Linie bringt
            (waagrecht, senkrecht oder diagonal), deren Werte eine <b>arithmetische</b> (z. B. 4, 6, 8) oder <b>harmonische</b> Reihe
            (z. B. 3, 4, 6: (6−4):(4−3) = 6:3) bilden, gewinnt. Für die harmonische Reihe muss mindestens ein erbeuteter, wieder eingesetzter Stein dabei sein.
            Wer nicht mehr ziehen kann, verliert.</p>
            <p class="small muted">Vereinfachte Fassung nach dem Wikipedia-Artikel „Zahlenkampfspiel“. Die Ansage des ersten Harmonie-Steins entfällt.
            Tipp: Ein rotes Zielfeld bedeutet, dass der Zug etwas schlägt – fahr mit der Maus darüber für Details.</p>`,

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
            const { state, mySeat } = ctx;
            const side = state.turn[0];
            const mine = ctx.isMyTurn;
            lastRender = { root, ctx, game: this };

            // Spielende: hochkant, eigenes Lager unten (wie beim Schach).
            // Zuschauende: quer wie gehabt, auf schmalen Bildschirmen hochkant mit „Gerade“ unten.
            const narrow = !!(window.matchMedia && window.matchMedia(NARROW).matches);
            const vertical = mySeat !== null || narrow;
            const bottom = mySeat !== null ? mySeat : 0;

            const selKey = ctx.room.id + ':' + ctx.version;   // Auswahl gilt nur für diesen Tisch und Stand
            if (sel.version !== selKey) sel = { version: selKey, stone: null, drop: null };
            const rerender = () => { root.replaceChildren(); this.render(root, ctx); };

            const byPos = new Map(state.stones.map(s => [s.r * ZK.COLS + s.c, s]));
            const selStone = sel.stone !== null ? state.stones.find(s => s.id === sel.stone) : null;

            // Ziele mit Vorschau, was dabei geschlagen würde
            const targets = new Map();
            if (mine && selStone) {
                for (const m of ZK.movesFor(state, selStone)) {
                    const ns = ZK.applyMove(state, side, selStone.id, m.r, m.c);
                    targets.set(m.r * ZK.COLS + m.c, { ...m, preview: ns });
                }
            } else if (mine && sel.drop !== null) {
                for (const q of ZK.dropSquares(state, side)) {
                    targets.set(q.r * ZK.COLS + q.c, { ...q, preview: ZK.applyDrop(state, side, sel.drop, q.r, q.c) });
                }
            }

            const clickCell = (r, c) => {
                if (!mine) return;
                const key = r * ZK.COLS + c;
                const tg = targets.get(key);
                if (tg && tg.preview) { ctx.submit(tg.preview); return; }
                const s = byPos.get(key);
                sel.stone = s && s.side === side && s.id !== sel.stone ? s.id : null;
                sel.drop = null;
                rerender();
            };

            // Anzeige-Reihenfolge der Felder: quer 8 Zeilen × 16 Spalten, hochkant um 90° gedreht
            // (16 Zeilen × 8 Spalten), sodass das Lager der Seite `bottom` unten liegt.
            const cells = [];
            if (vertical) {
                for (let i = 0; i < ZK.COLS; i++) {
                    for (let j = 0; j < ZK.ROWS; j++) {
                        cells.push(bottom === 0
                            ? { r: j, c: ZK.COLS - 1 - i, mid: i === ZK.COLS / 2 }
                            : { r: ZK.ROWS - 1 - j, c: i, mid: i === ZK.COLS / 2 });
                    }
                }
            } else {
                for (let r = 0; r < ZK.ROWS; r++) {
                    for (let c = 0; c < ZK.COLS; c++) cells.push({ r, c, mid: c === ZK.COLS / 2 });
                }
            }

            const board = h('div', { class: 'zk-board' + (vertical ? ' vertical' : '') });
            for (const { r, c, mid } of cells) {
                const key = r * ZK.COLS + c;
                const s = byPos.get(key);
                const tg = targets.get(key);
                const cls = ['zk-cell', ZK.homeSide(c) === 0 ? 'home0' : 'home1'];
                if (mid) cls.push(vertical ? 'midh' : 'mid');
                const last = state.last;
                if (last && ((last.from && last.from[0] === r && last.from[1] === c) || (last.to[0] === r && last.to[1] === c))) cls.push('lastmove');
                if (tg) {
                    cls.push('target');
                    const capturedNow = tg.preview ? tg.preview.captured.length : 0;
                    if (capturedNow) cls.push('capture');
                    if (tg.preview && tg.preview.over) cls.push('winning');
                }
                let title = null;
                if (tg && tg.preview) {
                    const entry = tg.preview.log[tg.preview.log.length - 1];
                    title = tg.preview.over ? endText(tg.preview.end) : (tg.preview.captured.length ? logText(entry) : null);
                }
                const extra = [
                    s && sel.stone === s.id ? 'selected' : '',
                    s && mine && s.side === side ? 'own' : '',
                    s && state.harmony && state.harmony.includes(s.id) ? 'harmony' : '',
                ].join(' ');
                board.append(h('div', { class: cls.join(' '), title, onclick: () => clickCell(r, c) },
                    s ? stoneEl(s, extra) : null));
            }

            const reserve = s => h('div', { class: 'zk-reserve' },
                h('span', { class: 'small muted' }, t(`Beute von ${SIDES[s]}:`, `Captured by ${SIDES[s]}:`)),
                state.reserve[s].length ? state.reserve[s].map((item, i) => {
                    const st = { ...item, side: s, conv: true };
                    const active = mine && s === side;
                    return h('button', {
                        class: 'zk-res-btn' + (sel.drop === i && active ? ' selected' : ''),
                        disabled: !active,
                        title: active ? t('Klicken und dann auf ein freies Feld deiner Hälfte setzen', 'Click, then place it on an empty square of your half') : null,
                        onclick: () => { sel.drop = sel.drop === i ? null : i; sel.stone = null; rerender(); },
                    }, stoneEl(st));
                }) : h('span', { class: 'small muted' }, '–'));

            let status = '';
            if (state.over) status = endText(state.end) + (state.winner !== null && state.winner !== undefined ? t(` ${ctx.label(state.winner)} gewinnt.`, ` ${ctx.label(state.winner)} wins.`) : '');
            else if (!ctx.allSeated) status = '';
            else if (mine) status = t('Du bist dran: Stein anklicken, dann Zielfeld wählen.', 'Your turn: click a stone, then choose a target square.');
            else status = t(`${ctx.label(side)} ist dran …`, `${ctx.label(side)} to move …`);

            const log = h('ol', { class: 'zk-log', reversed: true, start: state.log.length },
                state.log.slice(-12).reverse().map(e => h('li', {}, h('b', {}, SIDES[e.side] + ': '), logText(e))));

            fill(root,
                h('p', { class: 'status' + (mine ? ' my-turn' : '') }, status),
                // Beute der Seite unten steht auch unten (dort setzt man sie wieder ein)
                reserve(vertical ? 1 - bottom : 0),
                h('div', { class: 'zk-scroll' }, board),
                reserve(vertical ? bottom : 1),
                h('div', { class: 'zk-legend small muted' },
                    mySeat !== null ? t('Dein Lager ist unten.', 'Your camp is at the bottom.')
                        : vertical ? t('Gerade unten, Ungerade oben.', 'Evens at the bottom, Odds at the top.')
                        : t('Gerade links, Ungerade rechts.', 'Evens on the left, Odds on the right.'),
                    t(' Gestrichelte Steine sind wieder eingesetzte Beute.', ' Dashed stones are captured stones brought back in.')),
                state.log.length ? h('details', { class: 'zk-logbox', open: true }, h('summary', {}, t('Letzte Züge', 'Recent moves')), log) : null,
            );
        },
    });
})();
