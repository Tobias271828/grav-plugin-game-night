/* Trombone – Bluff-Kartenspiel. Mischen, Austeilen und Auswerten passiert auf dem Server (classes/Trombone.php). */
'use strict';

(function () {
    const MAX_SEATS = 8;
    const MAX_CARDS = 6;
    const NEED = [[1], [2], [2, 2], [3], [3, 2], [4], [5], [6]];

    const CATS = LANG === 'en'
        ? ['High card', 'Pair', 'Two pair', 'Three of a kind', 'Full house', 'Four of a kind', 'Five of a kind', 'Six of a kind']
        : ['Hohe Karte', 'Paar', 'Doppelpaar', 'Drilling', 'Full House', 'Vierling', 'Fünfling', 'Sechsling'];
    const FACE = LANG === 'en' ? { 11: 'J', 12: 'Q', 13: 'K', 14: 'A' } : { 11: 'B', 12: 'D', 13: 'K', 14: 'A' };
    const SUIT = { S: '♠', H: '♥', D: '♦', C: '♣' };

    // Posaunen-Emoji (U+1FA8A, erst seit Emoji 17.0 / 2025): auf Geräten ohne passende Schrift
    // erscheint sonst ein leeres Kästchen, dann nehmen wir die Trompete.
    const TROMBONE_ICON = (() => {
        try {
            const c = document.createElement('canvas');
            c.width = c.height = 32;
            const x = c.getContext('2d', { willReadFrequently: true });
            const draw = ch => {
                x.clearRect(0, 0, 32, 32);
                x.font = '24px sans-serif';
                x.textBaseline = 'top';
                x.fillText(ch, 0, 0);
                return x.getImageData(0, 0, 32, 32).data.join();
            };
            return draw('\u{1FA8A}') !== draw('\u{10FFFD}') ? '\u{1FA8A}' : '🎺';
        } catch (e) {
            return '🎺';
        }
    })();

    const rankLabel = r => FACE[r] || String(r);
    // Deutscher Genitiv von Namen: „Annas“, aber „Tobias’“, „Max’“, „Fritz’“
    const genitive = name => name + (/(s|ß|x|z|ce)$/i.test(name) ? '’' : 's');
    const ranksOf = deck => (deck === '32' ? [7, 8, 9, 10, 11, 12, 13, 14] : [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14]);

    // „3 7er und 2 Asse“ / „three 7s and two aces“
    function many(n, r) {
        if (LANG === 'en') {
            const words = ['', 'one', 'two', 'three', 'four', 'five', 'six'];
            const names = { 11: 'jacks', 12: 'queens', 13: 'kings', 14: 'aces' };
            return `${words[n]} ${names[r] || r + 's'}`;
        }
        const names = { 11: 'Buben', 12: 'Damen', 13: 'Könige', 14: 'Asse' };
        return `${n} ${names[r] || r + 'er'}`;
    }
    function one(r) {
        if (LANG === 'en') return { 11: 'a jack', 12: 'a queen', 13: 'a king', 14: 'an ace' }[r] || (r === 8 ? 'an 8' : `a ${r}`);
        return { 11: 'ein Bube', 12: 'eine Dame', 13: 'ein König', 14: 'ein Ass' }[r] || `eine ${r}`;
    }

    function bidPhrase(bid) {
        const need = NEED[bid.t];
        if (bid.t === 0) return one(bid.a);
        let s = many(need[0], bid.a);
        if (need[1]) s += t(' und ', ' and ') + many(need[1], bid.b);
        return s;
    }

    function cmp(x, y) {
        return (x.t - y.t) || (x.a - y.a) || ((x.b || 0) - (y.b || 0));
    }
    const twoRanks = cat => cat === 2 || cat === 4;
    function valid(cat, a, b) {
        if (!twoRanks(cat)) return true;
        return cat === 2 ? a > b : a !== b;
    }
    const higher = (bid, last) => !last || cmp(bid, last) > 0;

    function cardEl(code, opts = {}) {
        if (code === 'X') {
            return h('span', { class: 'tb-card joker' + (opts.hit ? ' hit' : '') + (opts.small ? ' small' : '') },
                h('span', { class: 'tb-rank' }, '★'), h('span', { class: 'tb-suit' }, 'Joker'));
        }
        const r = parseInt(code, 10), s = code.slice(-1);
        return h('span', { class: `tb-card ${s === 'H' || s === 'D' ? 'red' : 'black'}` + (opts.hit ? ' hit' : '') + (opts.small ? ' small' : '') },
            h('span', { class: 'tb-rank' }, rankLabel(r)), h('span', { class: 'tb-suit' }, SUIT[s]));
    }
    const rankOf = c => (c === 'X' ? 99 : parseInt(c, 10));
    const sortHand = hand => [...hand].sort((x, y) => rankOf(x) - rankOf(y) || x.localeCompare(y));

    // lokaler Zustand des Gebots-Baukastens; wird bei jedem neuen Gebot zurückgesetzt
    let comp = { key: '', cat: null, a: null, b: null, busy: false };
    let addPanel = { open: false, seat: null, cards: null };   // „Person aufnehmen“

    registerGame({
        id: 'trombone',
        name: 'Trombone',
        icon: TROMBONE_ICON,
        desc: t('Vereinfachte Pokerwertung; es zählt, was alle zusammen haben. Immer höher bieten oder anzweifeln.',
                'Simplified poker ranking; what counts is what everyone holds together. Always bid higher or challenge.'),
        seats: Array.from({ length: MAX_SEATS }, (_, i) => t('Platz ', 'Seat ') + (i + 1)),
        seatInfo: t('2–8 Personen', '2–8 players'),
        flexibleSeats: true,
        ownSeats: true,
        ownControls: true,
        rules: LANG === 'en' ? `
            <p><b>Deck:</b> 2 players: 32 cards (7 to ace) + 1 joker; from 4 players: 52 cards + 2 jokers; with 3 players you choose before the start.
            Jokers are wild and count as any rank. Six of a kind can only be bid with 52 cards + 2 jokers.</p>
            <p><b>Cards:</b> in the first round everyone gets one card. Whoever loses a round gets one card more in all following rounds.
            Whoever already has six cards and loses a round loses the game.</p>
            <p><b>Bidding:</b> the winner of the previous round starts (in the first round a random player). Going round in turn (the order is drawn at random when the game starts and shown in the list of players), everyone must bid
            something higher. You only see your own cards, but you bet on what is contained in <i>all cards dealt together</i>.
            Instead of bidding higher you can call <b>“Trombone!”</b> to challenge the previous bid. Then all cards are revealed:
            if the bid was wrong, the challenged player loses the round; otherwise the challenger loses.</p>
            <p><b>Ranking</b> (no straights, no suits):<br>
            High card &lt; Pair &lt; Two pair &lt; Three of a kind &lt; Full house &lt; Four of a kind &lt; Five of a kind &lt; Six of a kind<br>
            2 &lt; 3 &lt; … &lt; 10 &lt; Jack &lt; Queen &lt; King &lt; Ace.<br>
            Full house and two pair are compared by the three of a kind (or the higher pair) first, then by the other pair:
            “three 8s and two 2s” beats “three 7s and two aces”.</p>`
            : `
            <p><b>Karten:</b> Bei 2 Personen 32 Blatt (7 bis Ass) + 1 Joker, ab 4 Personen 52 Blatt + 2 Joker, bei 3 Personen wird vor dem Start gewählt.
            Joker sind beliebig und zählen als jeder Rang. Ein Sechsling kann nur mit 52 Blatt + 2 Jokern geboten werden.</p>
            <p>In der ersten Runde erhält jede Person eine Karte. Wer eine Runde verliert, bekommt in allen folgenden Runden eine Karte mehr.
            Wer sechs Karten hat und eine Runde verliert, hat das Spiel verloren.</p>
            <p><b>Bieten:</b> Die Runde beginnt, wer die vorige gewonnen hat (in der ersten Runde jemand Zufälliges). Reihum (die Reihenfolge wird zu Spielbeginn ausgelost und steht in der Liste der Mitspielenden) muss man immer
            etwas Höheres bieten. Man sieht nur die eigenen Karten, wettet aber auf das, was in <i>allen ausgeteilten Karten zusammen</i> steckt.
            Statt zu erhöhen kann man <b>„Trombone!“</b> rufen und damit die vorige Ansage anzweifeln. Dann werden alle Karten aufgedeckt:
            War die Ansage falsch, verliert die angezweifelte Person die Runde, sonst die zweifelnde.</p>
            <p><b>Wertung</b> (keine Straßen, keine Farben):<br>
            Hohe Karte &lt; Paar &lt; Doppelpaar &lt; Drilling &lt; Full House &lt; Vierling &lt; Fünfling &lt; Sechsling<br>
            2 &lt; 3 &lt; … &lt; 10 &lt; Bube &lt; Dame &lt; König &lt; Ass.<br>
            Beim Full House (bzw. Doppelpaar) zählt zuerst der Drilling (bzw. das höhere Paar), dann das andere Paar:
            „3 8er und 2 2er“ schlägt „3 7er und 2 Asse“.</p>`,

        // Am Zug ist nur, wer mit diesem Browser wirklich mitspielt (state.meSeat kommt vom Server)
        isMyTurn(state) {
            return state.phase === 'bidding' && state.meSeat !== null && state.meSeat !== undefined
                && (state.turn || []).includes(state.meSeat);
        },

        // Hinweis an alle (außer der Person, die angezweifelt hat), sobald „Trombone!“ gerufen wurde
        notice(state) {
            const r = state.reveal;
            if (!r || (state.phase !== 'reveal' && state.phase !== 'over') || r.challenger === state.meSeat) return null;
            const name = ((state.players || []).find(p => p.seat === r.challenger) || {}).name || '?';
            return { key: 'reveal-' + state.round, text: t(`${name} ruft Trombone!`, `${name} calls Trombone!`) };
        },

        // Braucht das Spiel gerade meine Reaktion (außer „am Zug“)? → Hinweis wie beim Zug
        attention(state) {
            const prop = state.proposal;
            const me = (state.players || []).find(p => p.seat === state.meSeat && !p.out && !p.joining);
            return !!(prop && me && !prop.yes.includes(me.seat));
        },

        init() {
            return { phase: 'lobby' };   // der Server legt den echten Zustand an
        },

        render(root, ctx) {
            const { state, room, mySeat } = ctx;
            const players = state.players || [];
            const nameOf = seat => (players.find(p => p.seat === seat) || {}).name || (room.seats[seat] && room.seats[seat].name) || '?';
            // Spiele ich mit? Das entscheidet der Server (Platz UND Browser-Token), nicht nur der Platz.
            const meSeat = state.meSeat ?? null;
            const meP = players.find(p => p.seat === meSeat && !p.out);   // dabei (auch wenn erst ab nächster Runde)
            const meActive = meP && !meP.joining;                         // spielt in dieser Runde mit

            const doAction = async (op, data) => {
                if (comp.busy) return;
                comp.busy = true;
                await ctx.action(op, data);
                comp.busy = false;
            };

            if (state.phase === 'lobby') return fill(root, renderLobby(ctx, nameOf, doAction));

            const last = state.bids && state.bids.length ? state.bids[state.bids.length - 1] : null;
            const turnSeat = state.turn && state.turn[0];
            const myTurn = ctx.isMyTurn && state.phase === 'bidding';
            const totalCards = players.filter(p => !p.out && !p.joining).reduce((n, p) => n + p.cards, 0);

            // --- Status oben
            let status;
            if (state.phase === 'bidding') {
                status = myTurn ? h('div', { class: 'tb-status mine' }, t('Du bist dran!', 'Your turn!'))
                    : h('div', { class: 'tb-status' }, t(`${nameOf(turnSeat)} ist dran …`, `${nameOf(turnSeat)} to play …`));
            } else if (state.phase === 'reveal') {
                status = h('div', { class: 'tb-status' }, t(`Runde ${state.round} ist vorbei`, `Round ${state.round} is over`));
            } else {
                status = state.loser !== null && state.loser !== undefined
                    ? h('div', { class: 'tb-status over' }, t(`${nameOf(state.loser)} verliert das Spiel!`, `${nameOf(state.loser)} loses the game!`))
                    : h('div', { class: 'tb-status over' }, t('Spiel beendet', 'Game over'));
            }

            const info = h('div', { class: 'tb-info small muted' },
                t(`Runde ${state.round}`, `Round ${state.round}`), ' · ',
                state.deck === '32' ? t('32 Karten + 1 Joker', '32 cards + 1 joker') : t('52 Karten + 2 Joker', '52 cards + 2 jokers'), ' · ',
                t(`${totalCards} Karten im Spiel`, `${totalCards} cards in play`));

            // --- Aktuelle Ansage und eigene Karten in einem Kasten (auf dem Handy bleibt so das Bieten ohne Scrollen erreichbar)
            let current = null, hand = null;
            if (state.phase === 'bidding') {
                const myCards = meActive && state.myHand
                    ? h('div', { class: 'tb-hand' }, h('div', { class: 'tb-label' }, t('Deine Karten', 'Your cards')),
                        h('div', { class: 'tb-cards' }, sortHand(state.myHand).map(c => cardEl(c))))
                    : null;
                current = h('div', { class: 'tb-current' },
                    h('div', { class: 'tb-curline' },
                        h('span', { class: 'tb-label' }, t('Aktuelle Ansage', 'Current bid')),
                        last ? [h('span', { class: 'tb-bid' }, bidPhrase(last.bid)), h('span', { class: 'small muted' }, t('von ', 'by ') + nameOf(last.seat))]
                             : h('span', { class: 'tb-bid muted' }, t('noch keine', 'none yet'))),
                    myCards);
                if (!myCards) {
                    hand = meP ? h('p', { class: 'notice' }, t(`Du bist aufgenommen und steigst mit der nächsten Runde ein (${meP.cards} ${meP.cards === 1 ? 'Karte' : 'Karten'}).`,
                                                            `You have been added and join with the next round (${meP.cards} ${meP.cards === 1 ? 'card' : 'cards'}).`))
                        : h('p', { class: 'muted small' }, t('Du schaust zu.', 'You are watching.'));
                }
            }

            // --- Aktionen
            let actions = null;
            if (myTurn) {
                const key = ctx.room.id + ':' + state.round + ':' + state.bids.length;
                if (comp.key !== key) comp = { key, cat: null, a: null, b: null, busy: false };
                // Bieten braucht man öfter als „Trombone!“ – deshalb zuerst (auf dem Handy ohne Scrollen erreichbar)
                actions = h('div', { class: 'tb-actions' },
                    renderComposer(root, ctx, state, last, doAction),
                    last ? h('button', {
                        class: 'tb-trombone', onclick: () => doAction('challenge', { deal: state.deal ?? 0 }),
                    }, h('b', {}, TROMBONE_ICON + ' Trombone!'), h('span', {}, t(`${genitive(nameOf(last.seat))} Ansage anzweifeln`, `Challenge ${nameOf(last.seat)}’s bid`))) : null);
            }

            // --- Auflösung
            const reveal = state.reveal ? renderReveal(state, state.reveal, nameOf, meSeat) : null;
            // Wer die Auflösung verpasst hat (jemand war mit „Nächste Runde“ schneller), kann sie hier nachsehen
            const lastReveal = state.phase === 'bidding' && state.lastReveal
                ? h('details', { class: 'tb-history tb-last' },
                    h('summary', {}, t('Auflösung der vorigen Runde', 'Previous round’s reveal')),
                    renderReveal(state, state.lastReveal, nameOf, meSeat))
                : null;
            let next = null;
            if (state.phase === 'reveal' && meP) {
                next = h('button', { class: 'btn primary tb-big', onclick: () => doAction('next', { round: state.round }) }, t('Nächste Runde', 'Next round'));
            } else if (state.phase === 'reveal') {
                next = h('p', { class: 'muted small' }, t('Gleich geht es mit der nächsten Runde weiter …', 'The next round starts soon …'));
            }

            // --- Mitspielende
            const playerList = h('div', { class: 'tb-players' }, players.map(p => {
                const pBids = (state.bids || []).filter(b => b.seat === p.seat);
                const lastBid = pBids.length ? pBids[pBids.length - 1].bid : null;
                const active = !p.out && state.phase === 'bidding' && p.seat === turnSeat;
                return h('div', { class: 'tb-player' + (active ? ' active' : '') + (p.out ? ' out' : '') + (p.joining ? ' joining' : '') + (p.seat === meSeat ? ' me' : '') },
                    h('span', { class: 'tb-pname' }, nameOf(p.seat), p.seat === meSeat ? t(' (du)', ' (you)') : ''),
                    h('span', { class: 'tb-backs', title: t(`${p.cards} Karten`, `${p.cards} cards`) },
                        Array.from({ length: p.cards }, () => h('span', { class: 'tb-back' })),
                        h('span', { class: 'small muted' }, ` ${p.cards}/${MAX_CARDS}`)),
                    h('span', { class: 'tb-pbid small' },
                        p.out ? t('raus', 'out') : p.joining ? t('steigt nächste Runde ein', 'joins next round')
                            : lastBid && state.phase === 'bidding' ? bidPhrase(lastBid) : ''),
                    meActive && !p.out && p.seat !== meSeat && state.phase !== 'over' ? h('button', {
                        class: 'link muted tb-remove', title: t('Aus dem Spiel nehmen (z. B. wenn jemand weg ist)', 'Remove from game (e.g. if someone left)'),
                        onclick: () => {
                            const msg = state.phase === 'bidding'
                                ? t(`${nameOf(p.seat)} aus diesem Spiel nehmen? Die Karten dieser Person fallen weg, die Ansagen dieser Runde beginnen neu.`,
                                    `Remove ${nameOf(p.seat)} from this game? Their cards drop out and the bids of this round start over.`)
                                : t(`${nameOf(p.seat)} aus diesem Spiel nehmen?`, `Remove ${nameOf(p.seat)} from this game?`);
                            if (confirm(msg)) doAction('remove', { seat: p.seat });
                        },
                    }, '×') : null,
                );
            }));

            // --- Gebote dieser Runde (und, ausgegraut, die vor dem Entfernen einer Person)
            const bidList = bids => h('ol', {}, bids.map(b => h('li', {}, h('b', {}, nameOf(b.seat) + ': '), bidPhrase(b.bid))));
            const history = state.phase === 'bidding' && (state.bids.length || (state.prevBids || []).length)
                ? [
                    ...(state.prevBids || []).map(pb => h('details', { class: 'tb-history old', open: true },
                        h('summary', {}, t(`Ansagen vor dem Entfernen von ${nameOf(pb.removed)}`, `Bids before ${nameOf(pb.removed)} was removed`)),
                        bidList(pb.bids))),
                    state.bids.length ? h('details', { class: 'tb-history', open: true }, h('summary', {}, t('Ansagen dieser Runde', 'Bids this round')),
                        bidList(state.bids)) : null,
                ]
                : null;

            const rounds = state.history && state.history.length
                ? h('details', { class: 'tb-history' }, h('summary', {}, t('Bisherige Runden', 'Previous rounds')),
                    h('ul', {}, state.history.map(r => h('li', {},
                        `${t('Runde', 'Round')} ${r.round}: ${nameOf(r.challenger)} → ${nameOf(r.challenged)} (${bidPhrase(r.bid)}) – `,
                        r.true ? t('stimmte', 'was true') : t('geblufft', 'was a bluff'),
                        `, ${nameOf(r.loser)} ${t('verliert', 'loses')}`))))
                : null;

            // --- Spielende
            let overBox = null;
            if (state.phase === 'over') {
                overBox = h('div', { class: 'tb-over' },
                    mySeat !== null ? h('button', { class: 'btn primary tb-big', onclick: () => doAction('lobby') }, t('Neues Spiel', 'New game')) : null);
            }

            // Abbrechen / Zuschauende, die mitspielen wollen
            const running = state.phase === 'bidding' || state.phase === 'reveal';
            const extras = h('div', { class: 'tb-extras' },
                mySeat === null && room.seats.some(s => !s) ? h('button', {
                    class: 'btn primary', onclick: () => ctx.sit(room.seats.findIndex(s => !s)),
                }, t('Mitmachen', 'Join')) : null,
                mySeat !== null && !meP ? h('p', { class: 'notice small' }, running
                    ? t('Du sitzt am Tisch. Die Mitspielenden können dich über „Person aufnehmen“ ins laufende Spiel aufnehmen – sonst bist du beim nächsten Spiel dabei.',
                        'You are at the table. The players can add you to the running game via “Add player” – otherwise you will play in the next game.')
                    : t('Du spielst beim nächsten Spiel mit.', 'You will play in the next game.')) : null,
                (meActive || (mySeat !== null && state.orphaned)) && state.phase !== 'over' ? h('button', {
                    class: 'link muted', onclick: () => { if (confirm(t('Spiel für alle abbrechen?', 'Abort the game for everyone?'))) doAction('lobby'); },
                }, t('Spiel abbrechen', 'Abort game')) : null,
            );

            // Handy: alles untereinander; ab Tablet-Querformat/Computer zwei Spalten
            fill(root, h('div', { class: 'tb tb-game' },
                h('div', { class: 'tb-main' }, status, renderProposal(state, nameOf, mySeat, meSeat, meActive, doAction), info, current, reveal, next, overBox, hand, actions),
                h('div', { class: 'tb-side' }, playerList,
                    meActive && running && !state.proposal ? renderAddPanel(root, ctx, state, nameOf, doAction) : null,
                    history, lastReveal, rounds, extras)));
        },
    });

    // ------------------------------------------------------------------ Personen ins laufende Spiel aufnehmen

    /** Wer sitzt am Tisch, spielt aber gerade nicht mit? */
    function candidates(state, room) {
        return room.seats.map((s, i) => (s ? i : null)).filter(i => i !== null
            && !(state.players || []).some(p => p.seat === i && !p.out));
    }

    function renderAddPanel(root, ctx, state, nameOf, doAction) {
        const redraw = () => Games.trombone.render(root, ctx);
        if (!addPanel.open) {
            return h('button', { class: 'btn', onclick: () => { addPanel = { open: true, seat: null, cards: null }; redraw(); } },
                t('+ Person aufnehmen', '+ Add player'));
        }
        const cands = candidates(state, ctx.room);
        if (addPanel.seat !== null && !cands.includes(addPanel.seat)) addPanel.seat = null;
        const playing = (state.players || []).filter(p => !p.out).length;
        const full = state.deck === '32' && playing >= 5;
        const ready = addPanel.seat !== null && addPanel.cards !== null;

        return h('div', { class: 'tb-add' },
            h('div', { class: 'tb-add-head' },
                h('b', {}, t('Person aufnehmen', 'Add player')),
                h('button', { class: 'link muted', onclick: () => { addPanel.open = false; redraw(); } }, t('schließen', 'close'))),
            full ? h('p', { class: 'small' }, t('Mit 32 Karten können höchstens 5 Personen mitspielen.', 'With 32 cards at most 5 people can play.'))
            : !cands.length ? h('p', { class: 'small muted' },
                t('Die Person muss zuerst den Link öffnen und auf „Mitmachen“ tippen. Danach erscheint sie hier.',
                  'The person first has to open the link and tap “Join”. Then they appear here.'))
            : [
                h('div', { class: 'tb-label' }, t('Wer?', 'Who?')),
                h('div', { class: 'tb-chips' }, cands.map(i => h('button', {
                    class: 'tb-chip' + (addPanel.seat === i ? ' on' : ''), onclick: () => { addPanel.seat = i; redraw(); },
                }, nameOf(i)))),
                h('div', { class: 'tb-label' }, t('Mit wie vielen Karten?', 'With how many cards?')),
                h('div', { class: 'tb-ranks' }, [1, 2, 3, 4, 5, 6].map(k => h('button', {
                    class: 'tb-rankbtn' + (addPanel.cards === k ? ' on' : ''), onclick: () => { addPanel.cards = k; redraw(); },
                }, k))),
                h('button', {
                    class: 'btn primary tb-big', disabled: !ready, style: { marginTop: '.6rem' },
                    onclick: async () => { const d = { seat: addPanel.seat, cards: addPanel.cards }; addPanel.open = false; await doAction('propose', d); },
                }, ready ? t(`${nameOf(addPanel.seat)} mit ${addPanel.cards} vorschlagen`, `Propose ${nameOf(addPanel.seat)} with ${addPanel.cards}`)
                         : t('Person und Kartenzahl wählen', 'Choose person and number of cards')),
                h('p', { class: 'small muted', style: { margin: '.4rem 0 0' } },
                    t('Alle aktiven Mitspielenden müssen zustimmen.', 'All active players have to agree.')),
            ]);
    }

    function renderProposal(state, nameOf, mySeat, meSeat, meActive, doAction) {
        const prop = state.proposal;
        const res = state.proposalResult;
        if (!prop) {
            if (!res || res.round !== state.round) return null;
            return res.accepted
                ? h('p', { class: 'notice small' }, t(`${res.name} wurde aufgenommen.`, `${res.name} has been added.`))
                : h('p', { class: 'notice small' }, res.withdrawn
                    ? t(`${nameOf(res.by)} hat den Vorschlag, ${res.name} aufzunehmen, zurückgezogen.`, `${nameOf(res.by)} withdrew the proposal to add ${res.name}.`)
                    : res.by !== null && res.by !== undefined
                    ? t(`${nameOf(res.by)} hat die Aufnahme von ${res.name} abgelehnt.`, `${nameOf(res.by)} declined adding ${res.name}.`)
                    : t(`${res.name} wurde nicht aufgenommen.`, `${res.name} was not added.`));
        }
        const voters = (state.players || []).filter(p => !p.out && !p.joining);
        const missing = voters.filter(p => !prop.yes.includes(p.seat));
        const iVoted = prop.yes.includes(meSeat);
        const cards = prop.cards === 1 ? t('1 Karte', '1 card') : t(`${prop.cards} Karten`, `${prop.cards} cards`);

        return h('div', { class: 'tb-proposal' },
            h('div', {}, mySeat === prop.seat
                ? t(`${nameOf(prop.by)} möchte dich mit ${cards} aufnehmen.`, `${nameOf(prop.by)} wants to add you with ${cards}.`)
                : t(`${nameOf(prop.by)} möchte ${prop.name} mit ${cards} aufnehmen.`, `${nameOf(prop.by)} wants to add ${prop.name} with ${cards}.`)),
            h('div', { class: 'small muted' },
                t(`Zustimmung: ${prop.yes.length} von ${voters.length}`, `Agreed: ${prop.yes.length} of ${voters.length}`),
                missing.length ? t(' · es fehlt: ', ' · waiting for: ') + missing.map(p => nameOf(p.seat)).join(', ') : ''),
            meActive ? h('div', { class: 'tb-vote' },
                !iVoted ? h('button', { class: 'btn primary', onclick: () => doAction('vote', { yes: true }) }, t('Zustimmen', 'Agree')) : null,
                h('button', { class: 'btn', onclick: () => doAction('vote', { yes: false }) },
                    prop.by === meSeat ? t('Zurückziehen', 'Withdraw') : iVoted ? t('Doch ablehnen', 'Decline after all') : t('Ablehnen', 'Decline')),
            ) : null);
    }

    // ------------------------------------------------------------------ Lobby

    function renderLobby(ctx, nameOf, doAction) {
        const { room, mySeat, state } = ctx;
        const seated = room.seats.map((s, i) => (s ? i : null)).filter(i => i !== null);
        const n = seated.length;
        // 2 Personen: 32 + 1 Joker, ab 4: 52 + 2 Joker, bei 3 Personen entscheidet die Runde
        const fixedDeck = n === 3 ? null : n <= 2 ? '32' : '52';
        const deckName = d => (d === '32' ? t('32 Karten + 1 Joker', '32 cards + 1 joker') : t('52 Karten + 2 Joker', '52 cards + 2 jokers'));

        // Die Deckwahl liegt auf dem Server, damit alle am Tisch dieselbe Wahl sehen
        const deckChoice = state.deck === '32' || state.deck === '52' ? state.deck : null;
        const deckBtn = val => h('button', {
            class: 'tb-chip' + (deckChoice === val ? ' on' : ''), disabled: mySeat === null,
            onclick: () => doAction('deck', { deck: val }),
        }, deckName(val));

        return h('div', { class: 'tb' },
            h('div', { class: 'tb-status' }, t('Wer spielt mit?', 'Who is playing?')),
            h('div', { class: 'tb-players' },
                n ? seated.map(i => h('div', { class: 'tb-player' + (i === mySeat ? ' me' : '') },
                    h('span', { class: 'tb-pname' }, nameOf(i), i === mySeat ? t(' (du)', ' (you)') : ''),
                    i !== mySeat && mySeat !== null ? h('button', {
                        class: 'link muted tb-remove', title: t('Platz freigeben', 'Free seat'),
                        onclick: () => { if (confirm(t(`${nameOf(i)} entfernen?`, `Remove ${nameOf(i)}?`))) ctx.kick(i); },
                    }, '×') : null))
                  : h('p', { class: 'muted' }, t('Noch niemand.', 'Nobody yet.'))),
            mySeat === null
                ? (room.seats.some(s => !s)
                    ? h('button', { class: 'btn primary tb-big', onclick: () => ctx.sit(room.seats.findIndex(s => !s)) }, t('Mitspielen', 'Join'))
                    : h('p', { class: 'muted' }, t('Der Tisch ist voll.', 'The table is full.')))
                : h('button', { class: 'link', onclick: () => ctx.stand() }, t('doch nicht mitspielen', 'leave')),
            h('div', { class: 'tb-deck' },
                h('div', { class: 'tb-label' }, t('Kartenspiel', 'Deck')),
                fixedDeck
                    ? h('div', {}, deckName(fixedDeck), ' ',
                        h('span', { class: 'small muted' }, n <= 2 ? t('(bei 2 Personen)', '(for 2 players)') : t('(ab 4 Personen)', '(from 4 players)')))
                    : [h('p', { class: 'small muted', style: { margin: '0 0 .4rem' } }, t('Bei 3 Personen entscheidet ihr:', 'With 3 players you decide:')),
                       h('div', { class: 'tb-chips' }, deckBtn('32'), deckBtn('52'))]),
            mySeat !== null ? h('button', {
                class: 'btn primary tb-big', disabled: n < 2 || (n === 3 && !deckChoice),
                // Personenzahl mitschicken: hat sich inzwischen jemand hingesetzt, lehnt der Server ab
                onclick: () => doAction('start', { players: n }),
            }, n < 2 ? t('Warte auf mindestens 2 Personen …', 'Waiting for at least 2 players …')
                : n === 3 && !deckChoice ? t('Erst Kartenspiel wählen', 'Choose a deck first')
                : t(`Spiel starten (${n} Personen)`, `Start game (${n} players)`)) : null,
        );
    }

    // ------------------------------------------------------------------ Gebot zusammenstellen

    function renderComposer(root, ctx, state, last, doAction) {
        const ranks = ranksOf(state.deck);
        const redraw = () => Games.trombone.render(root, ctx);
        const lastBid = last ? last.bid : null;

        const anyHigher = (cat, a = null) => ranks.some(x => (a === null || x === a) && (twoRanks(cat)
            ? ranks.some(y => valid(cat, x, y) && higher({ t: cat, a: x, b: y }, lastBid))
            : higher({ t: cat, a: x, b: null }, lastBid)));

        // Sechsling gibt es nur mit 52 Karten + 2 Jokern
        const cats = state.deck === '32' ? CATS.slice(0, 7) : CATS;
        const catBtns = h('div', { class: 'tb-chips tb-cats' }, cats.map((name, cat) => h('button', {
            class: 'tb-chip' + (comp.cat === cat ? ' on' : ''), disabled: !anyHigher(cat),
            onclick: () => { comp.cat = cat; comp.a = null; comp.b = null; redraw(); },
        }, name.replace('Doppelpaar', 'Doppel\u00ADpaar'))));   // weiches Trennzeichen: passt auch auf schmale Handys

        let rankRows = [];
        if (comp.cat !== null) {
            const cat = comp.cat;
            // Überschrift nur, wo zwei Ränge gewählt werden (welcher ist welcher?)
            const firstLabel = cat === 2 ? t('Höheres Paar', 'Higher pair')
                : cat === 4 ? t('Drilling aus', 'Three of') : null;
            rankRows.push(h('div', { class: 'tb-rankrow' },
                firstLabel ? h('span', { class: 'tb-label' }, firstLabel) : null,
                h('div', { class: 'tb-ranks' + (ranks.length === 8 ? ' r8' : '') }, ranks.map(r => h('button', {
                    class: 'tb-rankbtn' + (comp.a === r ? ' on' : ''), disabled: !anyHigher(cat, r),
                    onclick: () => { comp.a = r; comp.b = null; redraw(); },
                }, rankLabel(r))))));
            if (twoRanks(cat) && comp.a !== null) {
                rankRows.push(h('div', { class: 'tb-rankrow' },
                    h('span', { class: 'tb-label' }, cat === 2 ? t('Niedrigeres Paar', 'Lower pair') : t('Paar aus', 'Pair of')),
                    h('div', { class: 'tb-ranks' + (ranks.length === 8 ? ' r8' : '') }, ranks.map(r => h('button', {
                        class: 'tb-rankbtn' + (comp.b === r ? ' on' : ''),
                        disabled: !valid(cat, comp.a, r) || !higher({ t: cat, a: comp.a, b: r }, lastBid),
                        onclick: () => { comp.b = r; redraw(); },
                    }, rankLabel(r))))));
            }
        }

        const ready = comp.cat !== null && comp.a !== null && (!twoRanks(comp.cat) || comp.b !== null);
        const bid = ready ? { t: comp.cat, a: comp.a, b: twoRanks(comp.cat) ? comp.b : null } : null;

        return h('div', { class: 'tb-composer' },
            h('div', { class: 'tb-label' }, last ? t('Höher bieten:', 'Bid higher:') : t('Erste Ansage:', 'Opening bid:')),
            catBtns, rankRows,
            h('button', {
                class: 'btn primary tb-big', disabled: !ready,
                onclick: () => doAction('bid', { bid, deal: state.deal ?? 0 }),
            }, ready ? t('Ansagen: ', 'Bid: ') + bidPhrase(bid) : t('Ansage wählen …', 'Choose your bid …')));
    }

    // ------------------------------------------------------------------ Auflösung

    /** Aufgedeckte Hände in der Spielreihenfolge (wie die Liste der Mitspielenden), nicht nach Sitzplatz */
    function handsInOrder(state, hands) {
        const order = (state.players || []).map(p => String(p.seat)).filter(seat => seat in hands);
        return [...order, ...Object.keys(hands).filter(seat => !order.includes(seat))].map(seat => [seat, hands[seat]]);
    }

    function renderReveal(state, r, nameOf, meSeat) {
        const bid = r.bid;
        const hitRanks = [bid.a, NEED[bid.t][1] ? bid.b : null];
        const isHit = c => c === 'X' || hitRanks.includes(parseInt(c, 10));

        const loserMore = state.phase === 'over' && state.loser === r.loser
            ? t(`${nameOf(r.loser)} hatte schon ${MAX_CARDS} Karten und verliert damit das Spiel.`, `${nameOf(r.loser)} already had ${MAX_CARDS} cards and loses the game.`)
            : t(`${nameOf(r.loser)} verliert die Runde und bekommt künftig eine Karte mehr. ${nameOf(r.winner)} beginnt die nächste Runde.`,
                `${nameOf(r.loser)} loses the round and gets one more card from now on. ${nameOf(r.winner)} starts the next round.`);

        return h('div', { class: 'tb-reveal ' + (r.true ? 'true' : 'false') },
            h('div', {}, t(`${nameOf(r.challenger)} zweifelt an:`, `${nameOf(r.challenger)} challenges:`)),
            h('div', { class: 'tb-bid' }, bidPhrase(bid), h('span', { class: 'small muted' }, t(' von ', ' by ') + nameOf(r.challenged))),
            h('div', { class: 'tb-verdict' }, r.true ? t('Die Ansage stimmt!', 'The bid is true!') : t('Geblufft – die Ansage stimmt nicht!', 'Bluff – the bid is false!')),
            h('div', { class: 'tb-loser' }, loserMore),
            h('div', { class: 'tb-allhands' }, handsInOrder(state, r.hands).map(([seat, hand]) => h('div', { class: 'tb-onehand' + (+seat === meSeat ? ' me' : '') },
                h('span', { class: 'tb-pname small' }, nameOf(+seat)),
                h('span', { class: 'tb-cards' }, sortHand(hand).map(c => cardEl(c, { small: true, hit: isHit(c) })))))),
        );
    }
})();
