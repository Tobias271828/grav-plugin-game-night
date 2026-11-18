/* Game Night – Mathekonferenz: geheime Rollen (Mafia-Prinzip). Die Logik läuft komplett auf dem Server
   (core/Konferenz.php); hier wird nur angezeigt, was die eigene Person sehen darf, und es werden Aktionen geschickt. */
'use strict';

(() => {
    const MAX_SEATS = 20;
    const MIN_PLAYERS = 4;
    const SPECIALS = ['lean', 'ki', 'numerik', 'betreuer', 'hausmeister', 'physik', 'logik', 'reviewer', 'doktorand'];

    // Rollen: Name, Kurzbeschreibung (für die eigene Rolle und die Regeln)
    const ROLES = {
        konstr: {
            name: t('Konstruktivist*in', 'Constructivist'), plural: t('Konstruktivist*innen', 'Constructivists'),
            desc: t('Ihr lehnt gängige Beweistechniken ab. Jede Nacht verunglimpft ihr gemeinsam einen fremden Beweis. Ihr gewinnt, sobald euch die Konferenz nicht mehr aufhalten kann.',
                    'You reject common proof techniques. Every night you jointly discredit someone else\'s proof. You win as soon as the conference can no longer stop you.'),
        },
        mathe: {
            name: t('Mathematiker*in', 'Mathematician'), plural: t('Mathematiker*innen', 'Mathematicians'),
            desc: t('Keine Sonderfähigkeit – findet in der Diskussion heraus, wer die Konstruktivist*innen sind.',
                    'No special ability – find out in the discussion who the constructivists are.'),
        },
        lean: {
            name: 'Lean-Profi', plural: 'Lean-Profis',
            desc: t('Formalisiert jede Nacht einen fremden Beweis in Lean. Fehlen Axiome, stammt er von Konstruktivist*innen.',
                    'Formalises someone else\'s proof in Lean every night. If axioms are missing, it was written by a constructivist.'),
        },
        ki: {
            name: t('KI-Nerd', 'AI nerd'), plural: t('KI-Nerds', 'AI nerds'),
            desc: t('Hat eine sehr gute KI: einmal im Spiel einen verunglimpften Beweis über Nacht reparieren und einmal im Spiel gezielt eine Lücke in einem fremden Beweis finden.',
                    'Has a very good AI: once per game repair a discredited proof overnight, and once per game find a gap in someone else\'s proof.'),
        },
        numerik: {
            name: t('Numeriker*in', 'Numerical analyst'), plural: t('Numeriker*innen', 'Numerical analysts'),
            desc: t('Rechnet jede Nacht einen Beweis nach – kein Gegenbeispiel bis 10¹⁰⁰, also „muss“ er stimmen: Den können die Konstruktivist*innen in dieser Nacht nicht verunglimpfen. Nicht zweimal hintereinander derselbe.',
                    'Checks a proof numerically every night – no counterexample up to 10¹⁰⁰, so it “must” be true: the constructivists cannot discredit it that night. Not the same one twice in a row.'),
        },
        betreuer: {
            name: t('Betreuer*in', 'Supervisor'), plural: t('Betreuer*innen', 'Supervisors'),
            desc: t('Macht in der ersten Nacht zwei Personen zu Koautor*innen (auch sich selbst). Verlässt eine*r die Konferenz, geht die andere Person mit.',
                    'Makes two people co-authors in the first night (possibly themselves). If one leaves the conference, the other leaves too.'),
        },
        hausmeister: {
            name: t('Hausmeister*in', 'Caretaker'), plural: t('Hausmeister*innen', 'Caretakers'),
            desc: t('Hat eigentlich kein Interesse an der Konferenz: Wird der eigene Beweis in der ersten Diskussion zerredet, ist frei – und allein gewonnen. Sonst normal Teil der Konferenz.',
                    'Isn\'t really interested in the conference: if their proof gets talked down in the first discussion, they get the days off – and win alone. Otherwise a normal part of the conference.'),
        },
        physik: {
            name: t('Physiker*in', 'Physicist'), plural: t('Physiker*innen', 'Physicists'),
            desc: t('Arbeitet mit viel „Intuition“: Wer die Konferenz verlässt, reißt sofort einen fremden Beweis mit, der darauf aufbaut.',
                    'Works with a lot of “intuition”: when leaving the conference, immediately takes down someone else\'s proof that builds on it.'),
        },
        logik: {
            name: t('Logiker*in', 'Logician'), plural: t('Logiker*innen', 'Logicians'),
            desc: t('Alles formal sauber: Der erste Angriff der Konstruktivist*innen prallt ab.',
                    'Everything formally clean: the constructivists\' first attack bounces off.'),
        },
        reviewer: {
            name: 'Reviewer 2', plural: 'Reviewer 2',
            desc: t('Markiert jede Nacht eine Person – sie startet in der nächsten Abstimmung mit 2 Stimmen gegen sich.',
                    'Marks one person every night – they start the next vote with 2 votes against them.'),
        },
        doktorand: {
            name: t('Doktorand*in', 'PhD student'), plural: t('Doktorand*innen', 'PhD students'),
            desc: t('Wählt in der ersten Nacht ein Vorbild. Verlässt das Vorbild die Konferenz, wechselt die Doktorand*in enttäuscht zu den Konstruktivist*innen.',
                    'Chooses a role model in the first night. If the role model leaves the conference, the PhD student is disappointed and joins the constructivists.'),
        },
    };
    const roleName = r => (ROLES[r] || {}).name || r;

    // Nachtaufgaben: Überschrift und Knopf in der Personenliste
    const TASKS = {
        konstr: { title: t('Welchen Beweis verunglimpft ihr heute Nacht?', 'Whose proof do you discredit tonight?') },
        lean: { title: t('Welchen Beweis formalisierst du heute Nacht?', 'Whose proof do you formalise tonight?'), btn: t('formalisieren', 'formalise') },
        numerik: { title: t('Welchen Beweis rechnest du heute Nacht nach?', 'Which proof do you check numerically tonight?'), btn: t('nachrechnen', 'check') },
        reviewer: { title: t('Wen markierst du für die nächste Abstimmung (+2 Stimmen)?', 'Whom do you mark for the next vote (+2 votes)?'), btn: t('markieren', 'mark') },
        doktorand: { title: t('Wer ist dein Vorbild?', 'Who is your role model?'), btn: t('Vorbild', 'role model') },
        betreuer: { title: t('Welche zwei Personen werden Koautor*innen?', 'Which two people become co-authors?') },
        ki: { title: t('Deine KI ist bereit.', 'Your AI is ready.') },
    };

    let busy = false;
    let pick = { key: '', betreuer: [], gap: false };   // lokale Auswahl (zurückgesetzt je Nacht)
    const openState = {};
    const keepOpen = (name, byDefault = false) => ({ open: openState[name] ?? byDefault, ontoggle: e => { openState[name] = e.target.open; } });

    registerGame({
        id: 'konferenz',
        name: t('Mathekonferenz', 'Maths conference'),
        icon: '🎓',
        desc: t('Geheime Rollen: Wer verunglimpft nachts heimlich die Beweise der anderen?',
                'Hidden roles: who secretly discredits the others\' proofs at night?'),
        seats: Array.from({ length: MAX_SEATS }, (_, i) => t('Platz ', 'Seat ') + (i + 1)),
        seatInfo: t(`${MIN_PLAYERS}–${MAX_SEATS} Personen`, `${MIN_PLAYERS}–${MAX_SEATS} players`),
        wip: true,
        flexibleSeats: true,
        ownSeats: true,
        ownControls: true,
        rules: rulesHtml(),

        isMyTurn(state) {
            const me = state.me;
            if (!me || !me.alive) return state.phase === 'shot' && me && state.shooter === me.seat;
            if (state.phase === 'night') return (me.tasks || []).length > 0;
            if (state.phase === 'day') return !Object.prototype.hasOwnProperty.call(state.votes || {}, me.seat);
            return false;
        },

        // Hinweis, sobald die Nacht vorbei ist bzw. abgestimmt wurde
        notice(state) {
            const log = state.log || [];
            const last = log[log.length - 1];
            if (!last || state.phase === 'lobby') return null;
            const nameOf = seat => ((state.players || []).find(p => p.seat === seat) || {}).name || '?';
            return { key: `${state.game}-${log.length}`, text: eventText(last, nameOf, true) };
        },

        init() {
            return { phase: 'lobby' };
        },

        render(root, ctx) {
            const { state } = ctx;
            const doAction = async (op, data) => {
                if (busy) return false;
                busy = true;
                try { return await ctx.action(op, data); }
                finally { busy = false; }
            };
            if (state.phase === 'lobby') return fill(root, renderLobby(ctx, doAction));
            return fill(root, renderGame(ctx, doAction));
        },
    });

    // ------------------------------------------------------------------ Vor dem Start

    function renderLobby(ctx, doAction) {
        const { room, mySeat, state } = ctx;
        const seated = room.seats.map((s, i) => (s ? i : null)).filter(i => i !== null);
        const n = seated.length;
        const nameOf = i => (room.seats[i] && room.seats[i].name) || '?';
        const config = state.config || state.suggest || { konstr: 1, roles: [] };
        const custom = !!state.config;
        const mathe = n - config.konstr - config.roles.length;
        const setConfig = c => doAction('config', { config: c });
        const canEdit = mySeat !== null;

        const problems = n < MIN_PLAYERS ? t(`Es braucht mindestens ${MIN_PLAYERS} Personen.`, `At least ${MIN_PLAYERS} players are needed.`)
            : mathe < 0 ? t('Mehr Rollen als Personen – bitte Rollen abwählen.', 'More roles than players – please deselect roles.')
            : 2 * config.konstr >= n ? t('Zu viele Konstruktivist*innen für so wenige Personen.', 'Too many constructivists for so few players.')
            : null;

        const stepper = h('div', { class: 'kf-stepper' },
            h('button', { class: 'btn small', disabled: !canEdit || config.konstr <= 1, onclick: () => setConfig({ ...config, konstr: config.konstr - 1 }) }, '−'),
            h('b', {}, config.konstr),
            h('button', { class: 'btn small', disabled: !canEdit || 2 * (config.konstr + 1) >= n || mathe <= 0, onclick: () => setConfig({ ...config, konstr: config.konstr + 1 }) }, '+'),
            h('span', {}, ' ', ROLES.konstr.plural));
        const chips = h('div', { class: 'tb-chips kf-roles' }, SPECIALS.map(r => {
            const on = config.roles.includes(r);
            return h('button', {
                class: 'tb-chip' + (on ? ' on' : ''), title: ROLES[r].desc,
                disabled: !canEdit || (!on && mathe <= 0),
                onclick: () => setConfig({ ...config, roles: on ? config.roles.filter(x => x !== r) : [...config.roles, r] }),
            }, roleName(r));
        }));

        return h('div', { class: 'tb' },
            h('div', { class: 'tb-status' }, t('Wer kommt zur Konferenz?', 'Who is coming to the conference?')),
            h('div', { class: 'tb-players' },
                n ? seated.map(i => h('div', { class: 'tb-player wb-row' + (i === mySeat ? ' me' : '') },
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
            h('div', { class: 'tb-deck kf-config' },
                h('div', { class: 'tb-label' }, t('Rollen', 'Roles')),
                stepper,
                chips,
                h('p', { class: 'small muted', style: { margin: '.3rem 0 0' } },
                    t(`Dazu ${Math.max(0, mathe)} ${mathe === 1 ? ROLES.mathe.name : ROLES.mathe.plural}`, `Plus ${Math.max(0, mathe)} ${mathe === 1 ? ROLES.mathe.name : ROLES.mathe.plural}`),
                    custom && canEdit ? [' · ', h('button', { class: 'link', onclick: () => doAction('config', { reset: true }) }, t('Vorschlag übernehmen', 'use suggestion'))] : null),
                problems ? h('p', { class: 'small error', style: { margin: '.3rem 0 0' } }, problems) : null),
            mySeat !== null ? h('button', {
                class: 'btn primary tb-big', disabled: !!problems,
                onclick: () => doAction('start', { players: n }),
            }, t(`Konferenz eröffnen (${n} Personen)`, `Open the conference (${n} players)`)) : null,
        );
    }

    // ------------------------------------------------------------------ Laufendes Spiel

    function renderGame(ctx, doAction) {
        const { state, room, mySeat } = ctx;
        const players = state.players || [];
        const nameOf = seat => (players.find(p => p.seat === seat) || {}).name || '?';
        const me = state.me || null;
        const alive = players.filter(p => p.alive);
        const myTasks = (me && me.alive && state.phase === 'night' && me.tasks) || [];
        const task = myTasks[0] || null;
        const key = `${room.id}:${state.game}:${state.round}:${state.phase}`;
        if (pick.key !== key) pick = { key, betreuer: [], gap: false };
        const redraw = () => Games.konferenz.render(document.querySelector('.game-area'), ctx);

        // --- Kopf
        let status;
        if (state.phase === 'over') {
            // grün für die Gewinner*innen, rot für alle anderen Mitspielenden, neutral für Zuschauende
            const won = me && state.winner && state.winner.seats.includes(me.seat);
            status = h('div', { class: 'tb-status' + (me ? (won ? ' mine' : ' over') : '') }, winnerText(state.winner, nameOf));
        }
        else if (state.phase === 'night') status = h('div', { class: 'tb-status' + (task ? ' mine' : '') }, '🌙 ', t(`Nacht ${state.round}`, `Night ${state.round}`));
        else if (state.phase === 'day') status = h('div', { class: 'tb-status' + (ctx.isMyTurn ? ' mine' : '') }, '☀️ ', t(`Tag ${state.round}: Wessen Beweis wird zerredet?`, `Day ${state.round}: whose proof gets talked down?`));
        else status = h('div', { class: 'tb-status' }, t('Ein Beweis wird mitgerissen …', 'A proof is being taken down …'));

        // --- Was ist passiert? (letzte Nacht bzw. letzte Abstimmung)
        const news = recentNews(state, nameOf);

        // --- Eigene Rolle
        const myRole = me ? renderMyRole(state, me, nameOf) : (mySeat !== null
            ? h('p', { class: 'notice small' }, t('Du bist beim nächsten Spiel dabei.', 'You will play in the next game.'))
            : h('p', { class: 'muted small' }, t('Du schaust zu.', 'You are watching.')));

        // --- Aufgabe
        let taskBox = null;
        let rowAction = () => null;   // Knopf je Person in der Liste
        if (state.phase === 'night' && me && me.alive) {
            if (!task) {
                taskBox = h('p', { class: 'muted small' }, t('Die Nacht läuft – warte, bis alle mit Nachtaktion fertig sind …', 'The night is on – wait until everyone with a night action is done …'));
            } else {
                const parts = [h('div', { class: 'tb-label' }, TASKS[task].title)];
                const noAct = h('button', { class: 'link muted', onclick: () => doAction('noact') }, t('diese Nacht keine Aktion durchführen', 'no action tonight'));
                if (task === 'konstr') {
                    parts.push(h('p', { class: 'small muted kf-hint' }, t('Erst „vorschlagen“ (sehen alle Konstruktivist*innen), dann „auswählen“ – wer auswählt, entscheidet für alle.',
                        'First “propose” (all constructivists see it), then “choose” – whoever chooses decides for everyone.')));
                    const mineProps = (me.proposals || {})[me.seat] || [];
                    rowAction = p => (p.alive && !p.konstr && p.seat !== me.seat)
                        ? (mineProps.includes(p.seat)
                            ? h('button', { class: 'btn small primary', onclick: () => doAction('choose', { target: p.seat }) }, t('auswählen', 'choose'))
                            : h('button', { class: 'btn small', onclick: () => doAction('propose', { target: p.seat }) }, t('vorschlagen', 'propose')))
                        : null;
                } else if (task === 'betreuer') {
                    rowAction = p => p.alive ? h('button', {
                        class: 'btn small' + (pick.betreuer.includes(p.seat) ? ' primary' : ''),
                        onclick: () => {
                            pick.betreuer = pick.betreuer.includes(p.seat) ? pick.betreuer.filter(x => x !== p.seat) : [...pick.betreuer, p.seat].slice(-2);
                            redraw();
                        },
                    }, t('Koautor*in', 'co-author')) : null;
                    parts.push(h('button', {
                        class: 'btn primary tb-big', disabled: pick.betreuer.length !== 2,
                        onclick: () => doAction('act', { target: pick.betreuer[0], second: pick.betreuer[1] }),
                    }, pick.betreuer.length === 2 ? t(`${nameOf(pick.betreuer[0])} und ${nameOf(pick.betreuer[1])} verbinden`, `Pair ${nameOf(pick.betreuer[0])} and ${nameOf(pick.betreuer[1])}`)
                        : t('Zwei Personen wählen …', 'Choose two people …')));
                } else if (task === 'ki') {
                    const victim = me.victim;
                    parts.push(h('p', { class: 'small' }, victim === null || victim === undefined
                        ? t('Die Konstruktivist*innen haben heute Nacht niemanden angegriffen.', 'The constructivists did not attack anyone tonight.')
                        : t(`Die Konstruktivist*innen verunglimpfen gerade den Beweis von ${nameOf(victim)}.`, `The constructivists are discrediting ${nameOf(victim)}'s proof right now.`)));
                    const pot = me.potions || {};
                    const btns = [];
                    if (pot.heal && victim !== null && victim !== undefined) {
                        btns.push(h('button', { class: 'btn', onclick: () => doAction('act', { what: 'heal' }) }, t(`Beweis von ${nameOf(victim)} reparieren`, `Repair ${nameOf(victim)}'s proof`)));
                    } else if (me.kiHeal !== null && me.kiHeal !== undefined) {
                        btns.push(h('span', { class: 'small' }, t(`✓ Beweis von ${nameOf(me.kiHeal)} repariert`, `✓ ${nameOf(me.kiHeal)}'s proof repaired`)));
                    }
                    if (pot.gap) {
                        btns.push(h('button', { class: 'btn' + (pick.gap ? ' primary' : ''), onclick: () => { pick.gap = !pick.gap; redraw(); } },
                            pick.gap ? t('Lücke suchen: Person in der Liste wählen …', 'Find a gap: choose a person in the list …') : t('Lücke in einem Beweis finden', 'Find a gap in a proof')));
                        if (pick.gap) rowAction = p => (p.alive && p.seat !== me.seat) ? h('button', { class: 'btn small primary', onclick: () => doAction('act', { what: 'gap', target: p.seat }) }, t('Lücke', 'gap')) : null;
                    } else if (me.kiGap !== null && me.kiGap !== undefined) {
                        btns.push(h('span', { class: 'small' }, t(`✓ Lücke bei ${nameOf(me.kiGap)} gefunden`, `✓ found a gap in ${nameOf(me.kiGap)}'s proof`)));
                    }
                    parts.push(h('div', { class: 'kf-buttons' }, btns));
                    parts.push(h('button', { class: 'btn primary tb-big', onclick: () => doAction('act', { what: 'done' }) }, t('Fertig für diese Nacht', 'Done for tonight')));
                } else {
                    const excluded = task === 'numerik' ? me.protectLast : me.seat;
                    rowAction = p => (p.alive && p.seat !== excluded)
                        ? h('button', { class: 'btn small primary', onclick: () => doAction('act', { target: p.seat }) }, TASKS[task].btn) : null;
                    if (task === 'numerik' && me.protectLast !== null && me.protectLast !== undefined) {
                        parts.push(h('p', { class: 'small muted kf-hint' }, t(`Nicht noch einmal ${nameOf(me.protectLast)} (letzte Nacht).`, `Not ${nameOf(me.protectLast)} again (last night).`)));
                    }
                }
                if (task !== 'ki') parts.push(noAct);
                taskBox = h('div', { class: 'tb-composer kf-task' }, parts);
            }
        } else if (state.phase === 'day' && me && me.alive) {
            const myVote = (state.votes || {})[me.seat];
            taskBox = h('div', { class: 'tb-composer kf-task' },
                h('div', { class: 'tb-label' }, t('Diskutiert im Videogespräch und stimmt dann ab', 'Discuss in the video call, then vote')),
                h('p', { class: 'small muted kf-hint' }, t('Die meisten Stimmen gehen; bei Gleichstand geht niemand. Ausgewertet wird, sobald alle abgestimmt haben – bis dahin lässt sich die Stimme ändern.',
                    'Most votes leave; on a tie nobody does. Counted as soon as everyone has voted – until then you can change your vote.')),
                h('button', { class: 'btn' + (myVote === -1 ? ' primary' : ''), onclick: () => doAction('vote', { target: -1, round: state.round }) }, t('Niemanden', 'Nobody')));
            rowAction = p => (p.alive && p.seat !== me.seat)
                ? h('button', { class: 'btn small' + (myVote === p.seat ? ' primary' : ''), onclick: () => doAction('vote', { target: p.seat, round: state.round }) }, t('Stimme', 'vote'))
                : null;
        } else if (state.phase === 'shot' && me && state.shooter === me.seat) {
            taskBox = h('div', { class: 'tb-composer kf-task' }, h('div', { class: 'tb-label' }, t('Wessen Beweis baut auf deiner „Intuition“ auf? Du reißt ihn mit.', 'Whose proof builds on your “intuition”? You take it down with you.')));
            rowAction = p => (p.alive && p.seat !== me.seat) ? h('button', { class: 'btn small primary', onclick: () => doAction('shoot', { target: p.seat }) }, t('mitreißen', 'take down')) : null;
        }

        // --- Personenliste
        const votes = state.phase === 'day' ? (state.votes || {}) : {};
        const tally = {};
        for (const target of Object.values(votes)) tally[target] = (tally[target] || 0) + 1;
        const props = (me && me.proposals) || {};
        const list = h('div', { class: 'tb-players kf-list' }, players.map(p => {
            const proposedBy = Object.entries(props).filter(([, ts]) => ts.includes(p.seat)).map(([by]) => nameOf(+by));
            const info = [];
            if (!p.alive) info.push(roleName(p.role) + (p.konstr && p.role !== 'konstr' ? t(' (Konstruktivist*in)', ' (constructivist)') : ''));
            else if (p.role && p.seat !== (me && me.seat)) info.push(roleName(p.role));   // Mitkonstruktivist*innen / Spielende
            if (proposedBy.length) info.push(t('vorgeschlagen von ', 'proposed by ') + proposedBy.join(', '));
            if (state.phase === 'day' && p.alive) {
                const n = (tally[p.seat] || 0) + (state.mark === p.seat ? 2 : 0);
                if (n) info.push(t(`${n} ${n === 1 ? 'Stimme' : 'Stimmen'}`, `${n} ${n === 1 ? 'vote' : 'votes'}`) + (state.mark === p.seat ? ' (Reviewer 2: +2)' : ''));
                if (Object.prototype.hasOwnProperty.call(votes, p.seat)) info.push(t('hat abgestimmt', 'has voted'));
            }
            const isMe = me && p.seat === me.seat;
            return h('div', { class: 'kf-row' + (p.alive ? '' : ' out') + (isMe ? ' me' : '') + (p.alive && p.konstr && !isMe ? ' konstr' : '') },
                h('span', { class: 'kf-name' }, p.name, isMe ? t(' (du)', ' (you)') : ''),
                h('span', { class: 'kf-info small' }, info.join(' · ')),
                h('span', { class: 'kf-act' }, rowAction(p)));
        }));

        // --- Verbleibende Rollen
        const remaining = Object.entries(state.remaining || {}).sort((a, b) => b[1] - a[1]).map(([r, n]) => `${n}× ${n === 1 ? roleName(r) : (ROLES[r] || {}).plural || r}`);
        const remainingBox = state.phase !== 'over' ? h('p', { class: 'small muted kf-remaining' }, t('Noch auf der Konferenz: ', 'Still at the conference: '), remaining.join(', ')) : null;

        // --- Verlauf
        const log = state.log || [];
        const history = log.length ? h('details', { class: 'tb-history', ...keepOpen('log') }, h('summary', {}, t('Verlauf', 'History')),
            h('ul', {}, log.map(e => h('li', {}, `${e.at === 'day' ? t('Tag', 'Day') : t('Nacht', 'Night')} ${e.round}: `, eventText(e, nameOf, false))))) : null;

        // --- Unten: gehen, abbrechen, neues Spiel
        const running = state.phase !== 'over';
        const extras = h('div', { class: 'tb-extras' },
            mySeat === null && room.seats.some(s => !s) ? h('button', { class: 'btn primary', onclick: () => ctx.sit(room.seats.findIndex(s => !s)) }, t('Mitmachen', 'Join')) : null,
            !running && mySeat !== null ? h('button', { class: 'btn primary tb-big', onclick: () => doAction('lobby') }, t('Neues Spiel', 'New game')) : null,
            running && me && me.alive ? h('button', {
                class: 'link muted', onclick: () => {
                    if (confirm(t('Die Konferenz vorzeitig verlassen? Deine Rolle wird dann bekannt.', 'Leave the conference early? Your role will be revealed.'))) doAction('leave');
                },
            }, t('Konferenz verlassen', 'Leave the conference')) : null,
            running && me && me.alive ? h('button', {
                class: 'link muted', onclick: () => {
                    const others = alive.filter(p => p.seat !== me.seat);
                    const name = prompt(t('Wer ist nicht mehr da? (Name)', 'Who is gone? (name)'), '');
                    const p = others.find(o => name && o.name.toLowerCase() === name.trim().toLowerCase());
                    if (name && !p) toast(t('Diesen Namen gibt es nicht', 'No such name'));
                    if (p && confirm(t(`${p.name} aus der Konferenz nehmen? Die Rolle wird dann bekannt.`, `Remove ${p.name} from the conference? Their role will be revealed.`))) doAction('remove', { seat: p.seat });
                },
            }, t('Abwesende Person herausnehmen', 'Remove an absent person')) : null,
            running && ((me && me.alive) || (mySeat !== null && state.orphaned)) ? h('button', {
                class: 'link muted', onclick: () => { if (confirm(t('Spiel für alle abbrechen?', 'Abort the game for everyone?'))) doAction('lobby'); },
            }, t('Spiel abbrechen', 'Abort game')) : null,
        );

        return h('div', { class: 'tb tb-game' },
            h('div', { class: 'tb-main' }, status, news, myRole, taskBox, list, remainingBox),
            h('div', { class: 'tb-side' }, history, extras));
    }

    function renderMyRole(state, me, nameOf) {
        const r = ROLES[me.role] || {};
        const lines = [];
        if (me.converted) lines.push(t('Dein Vorbild hat die Konferenz verlassen – du gehörst jetzt zu den Konstruktivist*innen.', 'Your role model left the conference – you are now one of the constructivists.'));
        if (me.konstr) {
            const mates = (state.players || []).filter(p => p.konstr && p.seat !== me.seat && p.alive).map(p => p.name);
            lines.push(mates.length ? t('Mit dir: ', 'With you: ') + mates.join(', ') : t('Außer dir gibt es keine Konstruktivist*innen (mehr).', 'There are no other constructivists (any more).'));
        }
        if (me.coauthors) {
            const [a, b] = me.coauthors;
            lines.push(me.coauthors.includes(me.seat)
                ? t(`Koautor*in: ${nameOf(a === me.seat ? b : a)}`, `Co-author: ${nameOf(a === me.seat ? b : a)}`)
                : t(`Koautor*innen: ${nameOf(a)} und ${nameOf(b)}`, `Co-authors: ${nameOf(a)} and ${nameOf(b)}`));
        }
        if (me.vorbild !== null && me.vorbild !== undefined) lines.push(t('Vorbild: ', 'Role model: ') + nameOf(me.vorbild));
        if (me.potions) {
            const left = [me.potions.heal ? t('Reparatur', 'repair') : null, me.potions.gap ? t('Lücke finden', 'find a gap') : null].filter(Boolean);
            lines.push(left.length ? t('Noch möglich: ', 'Still available: ') + left.join(', ') : t('KI aufgebraucht', 'AI used up'));
        }
        const lean = (me.lean || []).map(x => h('li', {}, t(`Nacht ${x.round}: `, `Night ${x.round}: `), h('b', {}, nameOf(x.target)), ' – ',
            x.konstr ? t('es fehlen Axiome: Konstruktivist*in!', 'axioms missing: constructivist!') : t('alle Axiome da', 'all axioms present')));
        return h('div', { class: 'tb-current kf-me' + (me.alive ? '' : ' out') },
            h('div', { class: 'tb-curline' },
                h('span', { class: 'tb-label' }, me.alive ? t('Deine Rolle', 'Your role') : t('Du hast die Konferenz verlassen', 'You left the conference')),
                h('span', { class: 'tb-bid' }, r.name || me.role)),
            h('p', { class: 'small kf-desc' }, r.desc || ''),
            lines.length ? h('p', { class: 'small kf-lines' }, lines.join(' · ')) : null,
            lean.length ? h('ul', { class: 'small kf-lean' }, lean) : null);
    }

    // ------------------------------------------------------------------ Texte

    function recentNews(state, nameOf) {
        const log = state.log || [];
        if (!log.length || state.phase === 'over') return null;
        // Ereignisse seit der letzten Phase: am Tag die der Nacht davor, nachts die der letzten Abstimmung
        const at = state.phase === 'night' ? 'day' : 'night';
        const round = state.phase === 'night' ? state.round - 1 : state.round;
        const events = log.filter(e => e.round === round && e.at === at && e.type === 'leave');
        const vote = log.find(e => e.round === round && e.type === 'vote');
        let lines = events.map(e => eventText(e, nameOf, false));
        if (at === 'night' && !lines.length && state.phase === 'day') lines = [t('Heute Nacht hat niemand die Konferenz verlassen.', 'Nobody left the conference tonight.')];
        if (at === 'day' && vote && vote.out === null) lines.unshift(t('Bei der Abstimmung ist niemand gegangen.', 'Nobody left in the vote.'));
        if (!lines.length) return null;
        return h('div', { class: 'tb-proposal kf-news' }, lines.map(l => h('div', {}, l)));
    }

    function eventText(e, nameOf, short) {
        if (e.type === 'vote') {
            return e.out === null ? t('Abstimmung: niemand geht.', 'Vote: nobody leaves.') : t(`Abstimmung: ${nameOf(e.out)} geht.`, `Vote: ${nameOf(e.out)} leaves.`);
        }
        const who = nameOf(e.seat);
        const role = roleName(e.role) + (e.converted ? t(', zuletzt bei den Konstruktivist*innen', ', lastly with the constructivists') : '');
        const why = {
            konstr: t(`Die Konstruktivist*innen haben den Beweis von ${who} verunglimpft.`, `The constructivists discredited ${who}'s proof.`),
            gap: t(`Eine KI hat eine Lücke im Beweis von ${who} gefunden.`, `An AI found a gap in ${who}'s proof.`),
            koautor: t(`Der gemeinsame Beweis ist dahin – ${who} geht als Koautor*in mit.`, `The joint paper is gone – ${who} leaves as a co-author.`),
            physik: t(`Der Beweis von ${who} baute auf der „Intuition“ der Physiker*in auf.`, `${who}'s proof built on the physicist's “intuition”.`),
            vote: t(`Der Beweis von ${who} wurde in der Diskussion zerredet.`, `${who}'s proof was talked down in the discussion.`),
            quit: t(`${who} verlässt die Konferenz vorzeitig.`, `${who} leaves the conference early.`),
        }[e.cause] || '';
        return short ? `${why} (${role})` : `${why} – ${t('Rolle', 'role')}: ${role}`;
    }

    function winnerText(w, nameOf) {
        if (!w) return t('Spiel beendet', 'Game over');
        return {
            konf: t('Die Konferenz gewinnt – alle Konstruktivist*innen sind gegangen! 🎉', 'The conference wins – all constructivists have left! 🎉'),
            konstr: t('Die Konstruktivist*innen gewinnen – die Konferenz kann sie nicht mehr aufhalten.', 'The constructivists win – the conference can no longer stop them.'),
            koautor: t(`Die Koautor*innen ${w.seats.map(nameOf).join(' und ')} gewinnen gemeinsam! 💞`, `The co-authors ${w.seats.map(nameOf).join(' and ')} win together! 💞`),
            hausmeister: t(`${nameOf(w.seats[0])} hat als Hausmeister*in ab jetzt frei – und allein gewonnen! 🧹`, `${nameOf(w.seats[0])} gets the days off as caretaker – and wins alone! 🧹`),
            none: t('Niemand ist mehr da – niemand gewinnt.', 'Nobody is left – nobody wins.'),
        }[w.party] || t('Spiel beendet', 'Game over');
    }

    function rulesHtml() {
        const roles = ['konstr', ...SPECIALS, 'mathe'].map(r => `<li><b>${ROLES[r].name}:</b> ${ROLES[r].desc}</li>`).join('');
        return LANG === 'en' ? `
            <p>Everyone is at a maths conference, as themselves, and has brought the proof of a conjecture. Whoever's proof no longer goes down well
            leaves the conference. Everyone gets a secret role; the constructivists know each other.</p>
            <p><b>Night:</b> everyone with a night action chooses at the same time (the AI nerd only after the constructivists).
            <b>Day:</b> discuss in the video call, then vote openly. Most votes leave, a tie means nobody leaves.
            Whoever leaves reveals their role; everyone sees which roles are still at the conference.</p>
            <p><b>Winning:</b> the conference wins when all constructivists have left; the constructivists win as soon as the others can no longer stop them
            (they are at least as many as the others plus one for each remaining counter: AI gap, physicist, two for Reviewer 2).
            Co-authors from different sides win if they are the last two.</p>
            <ul>${roles}</ul>`
            : `
            <p>Alle sind als sie selbst auf einer Mathekonferenz und haben den Beweis einer Vermutung mitgebracht. Wessen Beweis nicht (mehr) gut ankommt,
            verlässt die Konferenz. Jede Person hat eine geheime Rolle; die Konstruktivist*innen kennen sich untereinander.</p>
            <p><b>Nacht:</b> Alle mit Nachtaktion wählen gleichzeitig (der KI-Nerd erst nach den Konstruktivist*innen).
            <b>Tag:</b> Im Videogespräch diskutieren, dann offen abstimmen. Die meisten Stimmen gehen, bei Gleichstand niemand.
            Wer geht, deckt die Rolle auf; alle sehen, welche Rollen noch auf der Konferenz sind.</p>
            <p><b>Gewinnen:</b> Die Konferenz gewinnt, wenn alle Konstruktivist*innen gegangen sind; die Konstruktivist*innen, sobald die anderen sie nicht mehr aufhalten
            können (sie sind mindestens so viele wie die anderen plus eins je verbliebener Gegenwehr: KI-Lücke, Physiker*in, zwei für Reviewer 2).
            Koautor*innen aus verschiedenen Lagern gewinnen, wenn sie die letzten zwei sind.</p>
            <ul>${roles}</ul>`;
    }
})();
