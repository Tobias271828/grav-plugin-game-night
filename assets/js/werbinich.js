/* Wer bin ich? – Begriffe aussuchen und erraten. Wer welchen Begriff sehen darf, entscheidet der Server (classes/WerBinIch.php). */
'use strict';

(function () {
    const MAX_SEATS = 12;
    const MAX_TERM = 60;

    // Gesicht in Wolke (😶 + Nullbreite-Verbinder + 🌫️, erst seit Emoji 13.1 / 2021): Kennt das Gerät die
    // Verbindung, ist das Ganze ein Zeichen, so breit wie 😶 allein; sonst stehen zwei Zeichen (oder ein
    // Kästchen) nebeneinander – dann nur das Gesicht ohne Mund 😶.
    const ICON = (() => {
        const FACE = '\u{1F636}', CLOUD = FACE + '\u200D\u{1F32B}\uFE0F';
        try {
            const x = document.createElement('canvas').getContext('2d');
            x.font = '32px sans-serif';
            const w = s => x.measureText(s).width;
            const one = Math.abs(w(CLOUD) - w(FACE)) < 0.15 * w(FACE) && w(CLOUD) < 0.75 * (w(FACE) + w('\u{1F32B}\uFE0F'));
            return one ? CLOUD : FACE;
        } catch (e) {
            return FACE;
        }
    })();

    let busy = false;
    // Eingabefeld für den eigenen Begriff: Text und „offen“ bleiben beim Neuaufbau erhalten
    let draft = { key: '', text: '', open: false, example: '' };
    let held = false;   // Neuaufbau aufgeschoben, solange jemand tippt

    // Beispiele im leeren Eingabefeld: berühmte Mathematiker*innen (je Eingabefeld zufällig eine);
    // [deutsch, englisch], wo sich die Schreibweise unterscheidet
  const EXAMPLES = [['Euklid', 'Euclid'], 'Hypatia', 'Pythagoras', 'Fibonacci', 'Pierre de Fermat',
        'Isaac Newton', 'Gottfried Wilhelm Leibniz', 'Leonhard Euler', 'Joseph-Louis Lagrange',
        'Sophie Germain', ['Carl Friedrich Gauß', 'Carl Friedrich Gauss'],
        'Augustin-Louis Cauchy', 'Niels Henrik Abel', 'Évariste Galois', 'Ada Lovelace',
        'Bernhard Riemann', 'Georg Cantor', ['Sofja Kowalewskaja', 'Sofya Kovalevskaya'],
        'David Hilbert', 'Emmy Noether', 'Srinivasa Ramanujan',
        'Paul Erdős', 'Katherine Johnson', 'Alexander Grothendieck',
        'Andrew Wiles', 'Grigori Perelman', 'Maryam Mirzakhani', 'Terence Tao'];
    const randomExample = () => {
        const e = EXAMPLES[Math.floor(Math.random() * EXAMPLES.length)];
        return Array.isArray(e) ? t(e[0], e[1]) : e;
    };

    const myPick = state => (state.picks || []).find(p => p.by === state.meSeat) || null;
    const pickFor = (state, target) => (state.picks || []).find(p => p.target === target) || null;
    const isGuessed = (state, seat) => (state.guessed || []).includes(seat);
    const activePlayers = state => (state.players || []).filter(p => !p.out);
    const inputKey = (state, pick) => `${state.game}:${pick.target}:${state.phase}:${isGuessed(state, pick.target)}`;

    registerGame({
        id: 'werbinich',
        name: t('Wer bin ich?', 'Who am I?'),
        tableName: t('Wer bin ich', 'Who am I'),
        icon: ICON,
        desc: t('Alle kennen dich, nur du nicht. Wer bist du?', 'Everyone knows you, except you. Who are you?'),
        seats: Array.from({ length: MAX_SEATS }, (_, i) => t('Platz ', 'Seat ') + (i + 1)),
        seatInfo: t(`2–${MAX_SEATS} Personen`, `2–${MAX_SEATS} players`),
        flexibleSeats: true,
        ownSeats: true,
        ownControls: true,
        rules: LANG === 'en' ? `
            <p>Everyone gets a secret term (e.g. a famous person, an animal or an object) that all others can see – only they themselves cannot.
            By asking questions they try to find out who or what they are.</p>
            <p><b>Choosing terms:</b> everyone picks another player and thinks of a term for them. Exactly one person chooses a term for each player;
            players who are already taken cannot be picked again. As soon as all terms are entered, the game starts.</p>
            <p><b>Guessing:</b> the page shows everyone the terms of the others. Questions and answers happen in the video call, e.g. taking turns
            with yes/no questions. Whoever has guessed their own term taps “Guessed it!” – then it is shown to them as well and disappears from the
            others’ list of terms still to guess. The game ends when everyone has guessed their term.</p>`
            : `
            <p>Jede Person bekommt einen geheimen Begriff (z. B. eine berühmte Person, ein Tier oder einen Gegenstand), den alle anderen sehen –
            nur sie selbst nicht. Durch Fragen versucht sie herauszufinden, wer oder was sie ist.</p>
            <p><b>Begriffe aussuchen:</b> Jede Person wählt eine andere Person aus und denkt sich für sie einen Begriff aus. Für jede Person sucht
            genau eine andere einen Begriff aus; wer schon vergeben ist, kann nicht mehr gewählt werden. Sobald alle Begriffe eingetragen sind, geht es los.</p>
            <p><b>Raten:</b> Die Seite zeigt allen die Begriffe der anderen. Gefragt und geantwortet wird in der Videokonferenz, z. B. reihum mit
            Ja-/Nein-Fragen. Wer den eigenen Begriff erraten hat, tippt auf „Erraten!“ – dann wird er auch dort angezeigt und verschwindet bei den
            anderen aus der Liste der noch zu ratenden Begriffe. Das Spiel endet, wenn alle ihren Begriff erraten haben.</p>`,

        // „Am Zug“ heißt hier: Ich muss noch einen Begriff aussuchen
        isMyTurn(state) {
            if (state.phase !== 'choosing' || state.meSeat === null || state.meSeat === undefined) return false;
            const pick = myPick(state);
            return !pick || !pick.done;
        },

        notice(state) {
            const nameOf = seat => ((state.players || []).find(p => p.seat === seat) || {}).name || '?';
            const g = state.guessed || [];
            if (state.phase === 'over' && state.complete) {
                return { key: 'over-' + state.game, text: t('Alle haben ihren Begriff erraten!', 'Everyone has guessed their term!') };
            }
            if (state.phase === 'playing' && g.length) {
                const last = g[g.length - 1];
                if (last === state.meSeat) return null;
                const pick = pickFor(state, last);
                return {
                    key: 'guess-' + state.game + '-' + g.length,
                    text: t(`${nameOf(last)} hat erraten: ${pick && pick.term}`, `${nameOf(last)} guessed: ${pick && pick.term}`),
                };
            }
            if (state.phase === 'playing') {
                return { key: 'play-' + state.game, text: t('Alle Begriffe stehen – los geht’s!', 'All terms are set – let’s go!') };
            }
            return null;
        },

        // Während man den Begriff eintippt, die Ansicht nicht neu aufbauen (sonst klappt am Handy die Tastatur zu)
        holdRender(state) {
            const el = document.getElementById('wb-term');
            if (!el || document.activeElement !== el) return false;
            const pick = myPick(state);
            if (!pick || el.dataset.key !== inputKey(state, pick)) return false;
            held = true;
            return true;
        },

        init() {
            return { phase: 'lobby' };   // der Server legt den echten Zustand an
        },

        render(root, ctx) {
            held = false;
            const { state, room } = ctx;
            const nameOf = seat => ((state.players || []).find(p => p.seat === seat) || {}).name
                || (room.seats[seat] && room.seats[seat].name) || '?';
            const doAction = async (op, data) => {
                if (busy) return false;
                busy = true;
                try { return await ctx.action(op, data); }
                finally { busy = false; }
            };

            if (state.phase === 'lobby') return fill(root, renderLobby(ctx, nameOf, doAction));
            if (state.phase === 'choosing') return fill(root, renderChoosing(ctx, nameOf, doAction));
            if (state.phase === 'playing') return fill(root, renderPlaying(ctx, nameOf, doAction));
            return fill(root, renderOver(ctx, nameOf, doAction));
        },
    });

    // ------------------------------------------------------------------ Lobby

    function renderLobby(ctx, nameOf, doAction) {
        const { room, mySeat } = ctx;
        const seated = room.seats.map((s, i) => (s ? i : null)).filter(i => i !== null);
        const n = seated.length;

        return h('div', { class: 'tb' },
            h('div', { class: 'tb-status' }, t('Wer spielt mit?', 'Who is playing?')),
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
            mySeat !== null ? h('button', {
                class: 'btn primary tb-big', disabled: n < 2,
                // Personenzahl mitschicken: hat sich inzwischen jemand hingesetzt, lehnt der Server ab
                onclick: () => doAction('start', { players: n }),
            }, n < 2 ? t('Warte auf mindestens 2 Personen …', 'Waiting for at least 2 players …')
                : t(`Spiel starten (${n} Personen)`, `Start game (${n} players)`)) : null,
        );
    }

    // ------------------------------------------------------------------ Begriffe aussuchen

    /** Bliebe nach meiner Wahl für die letzte übrige Person nur sie selbst? (prüft der Server ebenso) */
    function wouldStick(state, me, target) {
        const seats = activePlayers(state).map(p => p.seat);
        const writers = seats.filter(s => s !== me && !myPickOf(state, s));
        const targets = seats.filter(s => s !== target && !pickFor(state, s));
        return writers.length === 1 && targets.length === 1 && writers[0] === targets[0];
    }
    const myPickOf = (state, seat) => (state.picks || []).find(p => p.by === seat) || null;

    function renderChoosing(ctx, nameOf, doAction) {
        const { state } = ctx;
        const me = state.meSeat ?? null;
        const pick = myPick(state);
        const players = state.players || [];
        const doneCount = activePlayers(state).filter(p => (myPickOf(state, p.seat) || {}).done).length;

        let mine;
        if (me === null) {
            mine = h('p', { class: 'muted small' }, t('Du schaust zu. Gleich werden die Begriffe ausgesucht …', 'You are watching. The terms are being chosen …'));
        } else if (!pick) {
            const others = activePlayers(state).filter(p => p.seat !== me);
            // Ist eine freie Person gesperrt, bleibt genau eine übrig – kurz erklären, warum
            const free = others.filter(p => !pickFor(state, p.seat));
            const forced = free.some(p => wouldStick(state, me, p.seat)) ? free.find(p => !wouldStick(state, me, p.seat)) : null;
            mine = h('div', { class: 'tb-composer' },
                h('div', { class: 'tb-label' }, t('Für wen suchst du einen Begriff aus?', 'Who are you choosing a term for?')),
                h('div', { class: 'tb-chips' }, others.map(p => {
                    const taken = pickFor(state, p.seat);
                    return h('button', {
                        class: 'tb-chip', disabled: !!taken || wouldStick(state, me, p.seat),
                        onclick: () => doAction('pick', { target: p.seat }),
                    }, nameOf(p.seat), taken ? h('span', { class: 'small' }, t(' · vergeben', ' · taken')) : null);
                })),
                forced ? h('p', { class: 'small muted', style: { margin: 0 } },
                    t(`Damit niemand leer ausgeht, bleibt für dich nur ${nameOf(forced.seat)}.`,
                      `So that nobody is left out, only ${nameOf(forced.seat)} is left for you.`)) : null);
        } else {
            mine = renderTermBox(ctx, pick, nameOf, doAction, true);
        }

        const list = h('div', { class: 'tb-players' }, players.filter(p => !p.out).sort((a, b) => a.seat - b.seat).map(p => {
            const pk = myPickOf(state, p.seat);
            return h('div', { class: 'tb-player wb-row' + (p.seat === me ? ' me' : '') + (pk && pk.done ? ' wb-done' : '') },
                h('span', { class: 'tb-pname' }, nameOf(p.seat), p.seat === me ? t(' (du)', ' (you)') : ''),
                h('span', { class: 'tb-pbid small' },
                    !pk ? t('wählt noch aus …', 'still choosing …')
                    : pk.done ? t(`✓ fertig (für ${nameOf(pk.target)})`, `✓ done (for ${nameOf(pk.target)})`)
                    : t(`schreibt für ${nameOf(pk.target)} …`, `writing for ${nameOf(pk.target)} …`)),
                removeBtn(state, p, me, nameOf, doAction,
                    t('Die Begriffe für und von dieser Person verfallen.', 'The terms for and by this person are dropped.')));
        }));

        return h('div', { class: 'tb tb-game' },
            h('div', { class: 'tb-main' },
                me !== null && pick && pick.done
                    ? h('div', { class: 'tb-status' }, t('Warte auf die anderen …', 'Waiting for the others …'))
                    : h('div', { class: 'tb-status' + (me !== null ? ' mine' : '') }, t('Begriffe aussuchen', 'Choose terms')),
                h('div', { class: 'tb-info small muted' }, t(`${doneCount} von ${activePlayers(state).length} fertig`, `${doneCount} of ${activePlayers(state).length} done`)),
                mine),
            h('div', { class: 'tb-side' }, list, extras(ctx, doAction)));
    }

    /**
     * Eigener Begriff: anzeigen oder eingeben/ändern. Beim Aussuchen ($choosing) auch die Person wechseln;
     * beim Raten steckt das Kästchen in der Karte der Zielperson.
     */
    function renderTermBox(ctx, pick, nameOf, doAction, choosing) {
        const { state, room } = ctx;
        const key = inputKey(state, pick);
        if (draft.key !== room.id + ':' + key) draft = { key: room.id + ':' + key, text: pick.term || '', open: !pick.done, example: randomExample() };
        if (!pick.done) draft.open = true;
        const target = nameOf(pick.target);

        const reopen = () => { draft.open = true; draft.text = pick.term || ''; renderRoom(); focusInput(); };
        const unpick = () => {
            if (pick.done && !confirm(t('Dein Begriff wird dabei gelöscht. Andere Person wählen?', 'Your term will be deleted. Choose another person?'))) return;
            doAction('unpick');
        };
        const changeLink = choosing ? h('button', { class: 'link muted', onclick: unpick }, t('andere Person wählen', 'choose another person')) : null;

        if (!draft.open && !choosing) {
            return h('button', { class: 'link small', onclick: reopen }, t('ändern', 'change'));
        }
        if (!draft.open) {
            return h('div', { class: choosing ? 'tb-composer' : 'wb-edit' },
                choosing ? [h('div', { class: 'tb-label' }, t(`Dein Begriff für ${target}`, `Your term for ${target}`)),
                            h('div', { class: 'wb-term' }, '✓ ', pick.term)] : null,
                h('div', { class: 'wb-links' },
                    h('button', { class: 'link', onclick: reopen }, t('Begriff ändern', 'Change term')), changeLink));
        }

        const save = async () => {
            const input = document.getElementById('wb-term');
            const text = input.value.trim();
            if (!text) { toast(t('Bitte einen Begriff eingeben', 'Please enter a term')); return; }
            input.blur();
            if (await doAction('term', { term: text })) {
                draft.open = false;
                renderRoom();
            }
        };
        const input = h('input', {
            id: 'wb-term', class: 'wb-input', type: 'text', maxlength: MAX_TERM, value: draft.text, 'data-key': key,
            autocomplete: 'off', enterkeyhint: 'done', 'aria-label': t(`Begriff für ${target}`, `Term for ${target}`),
            placeholder: t('z. B. ', 'e.g. ') + draft.example,
            oninput: e => { draft.text = e.target.value; },
            onkeydown: e => { if (e.key === 'Enter') { e.preventDefault(); save(); } },
            // Wurde der Neuaufbau beim Tippen aufgeschoben, jetzt nachholen (kurz warten, damit ein Klick auf „Speichern“ ankommt)
            onblur: () => setTimeout(() => {
                if (held && document.activeElement !== document.getElementById('wb-term') && current && current.room) renderRoom();
            }, 300),
        });

        return h('div', { class: choosing ? 'tb-composer' : 'wb-edit' },
            choosing ? h('div', { class: 'tb-label' }, t(`Dein Begriff für ${target}`, `Your term for ${target}`)) : null,
            input,
            h('button', { class: 'btn primary tb-big', onclick: save }, pick.done ? t('Änderung speichern', 'Save change') : t('Begriff speichern', 'Save term')),
            h('div', { class: 'wb-links' },
                pick.done ? h('button', { class: 'link muted', onclick: () => { draft.open = false; renderRoom(); } }, t('abbrechen', 'cancel')) : null,
                changeLink),
            choosing ? h('p', { class: 'small muted', style: { margin: '.4rem 0 0' } },
                t(`${target} sieht den Begriff nicht – alle anderen erst, wenn das Raten beginnt.`,
                  `${target} will not see the term – everyone else only once guessing starts.`)) : null);
    }

    function focusInput() {
        const el = document.getElementById('wb-term');
        if (el) { el.focus(); el.setSelectionRange(el.value.length, el.value.length); }
    }

    // ------------------------------------------------------------------ Raten

    function renderPlaying(ctx, nameOf, doAction) {
        const { state } = ctx;
        const me = state.meSeat ?? null;
        const guessed = state.guessed || [];
        const iGuessed = me !== null && guessed.includes(me);

        const status = me === null ? h('div', { class: 'tb-status' }, t('Es wird geraten …', 'Guessing in progress …'))
            : iGuessed ? h('div', { class: 'tb-status' }, t('Du hast deinen Begriff erraten! 🎉', 'You guessed your term! 🎉'))
            : h('div', { class: 'tb-status mine' }, t('Wer bist du? Rate deinen Begriff!', 'Who are you? Guess your term!'));

        // Wer noch rät, nach Platz sortiert – bei allen dieselbe Reihenfolge, z. B. um reihum zu fragen
        const open = activePlayers(state).filter(p => !guessed.includes(p.seat)).sort((a, b) => a.seat - b.seat);
        const list = h('div', { class: 'wb-list' }, open.map(p => {
            const pk = pickFor(state, p.seat);
            const mine = p.seat === me;
            const edit = pk && pk.by === me ? renderTermBox(ctx, pk, nameOf, doAction, false) : null;
            const editing = edit && edit.tagName !== 'BUTTON';   // Eingabefeld statt „ändern“-Link
            const by = pk ? (pk.by === me ? t('von dir', 'by you') : t(`von ${nameOf(pk.by)}`, `by ${nameOf(pk.by)}`)) : '';
            return h('div', { class: 'wb-line' + (mine ? ' me' : '') },
                h('span', { class: 'wb-name' }, nameOf(p.seat), mine ? t(' (du)', ' (you)') : ''),
                h('span', { class: 'wb-val' },
                    mine ? h('span', { class: 'wb-term wb-secret' }, '? ? ?')
                        : me !== null && !editing ? h('span', { class: 'wb-term' }, pk && pk.term !== null ? pk.term : '?') : null,
                    ' ',
                    by ? h('span', { class: 'wb-by small muted' }, by, edit && !editing ? [' · ', edit] : null) : null),
                h('span', { class: 'wb-act' },
                    mine ? h('button', {
                        class: 'btn primary small',
                        onclick: () => {
                            if (confirm(t('Hast du deinen Begriff erraten? Danach wird er dir angezeigt.', 'Have you guessed your term? It will be shown to you afterwards.'))) doAction('guessed');
                        },
                    }, t('Erraten!', 'Guessed it!'))
                    : removeBtn(state, p, me, nameOf, doAction,
                        t('Der Begriff für diese Person verfällt, ihr eigener Begriff bleibt im Spiel.', 'The term for this person is dropped, the term they chose stays in the game.'))),
                editing ? edit : null);
        }));

        return h('div', { class: 'tb tb-game' },
            h('div', { class: 'tb-main' },
                status,
                open.length ? [
                    h('div', { class: 'tb-label', style: { marginBottom: '-.3rem' } }, t('Noch zu erraten – reihum fragen', 'Still to guess – ask in turn')),
                    list,
                ] : null,
                me === null ? h('p', { class: 'muted small' }, t('Die Begriffe sehen nur Mitspielende.', 'Only players can see the terms.')) : null),
            h('div', { class: 'tb-side' }, guessedList(state, nameOf), extras(ctx, doAction)));
    }

    function guessedList(state, nameOf) {
        const guessed = state.guessed || [];
        if (!guessed.length) return null;
        return h('details', { class: 'tb-history', open: true },
            h('summary', {}, t('Schon erraten', 'Already guessed')),
            h('ol', {}, guessed.map(seat => h('li', {}, h('b', {}, nameOf(seat) + ': '), (pickFor(state, seat) || {}).term ?? '?'))));
    }

    // ------------------------------------------------------------------ Spielende

    function renderOver(ctx, nameOf, doAction) {
        const { state, room, mySeat } = ctx;
        const guessed = state.guessed || [];
        // erst in der Reihenfolge des Erratens, dann der Rest
        const picks = [...(state.picks || [])].sort((a, b) =>
            ((guessed.indexOf(a.target) + 1) || 99) - ((guessed.indexOf(b.target) + 1) || 99) || a.target - b.target);

        return h('div', { class: 'tb' },
            h('div', { class: 'tb-status' + (state.complete ? '' : ' over') },
                state.complete ? t('Alle haben ihren Begriff erraten! 🎉', 'Everyone has guessed their term! 🎉') : t('Spiel beendet', 'Game over')),
            picks.length ? h('div', { class: 'wb-list' }, picks.map(p => {
                const n = guessed.indexOf(p.target) + 1;
                return h('div', { class: 'wb-line' + (p.target === state.meSeat ? ' me' : '') },
                    h('span', { class: 'wb-name' }, nameOf(p.target), p.target === state.meSeat ? t(' (du)', ' (you)') : ''),
                    h('span', { class: 'wb-val' },
                        p.term !== null ? h('span', { class: 'wb-term' }, p.term) : h('span', { class: 'wb-term wb-secret' }, '? ? ?'),
                        h('span', { class: 'small muted' }, ' ' + t(`von ${nameOf(p.by)}`, `by ${nameOf(p.by)}`))),
                    h('span', { class: 'wb-act small' + (n ? ' wb-ok' : ' muted') }, n ? t(`✓ ${n}.`, `✓ #${n}`) : t('nicht erraten', 'not guessed')));
            })) : null,
            mySeat !== null ? h('button', { class: 'btn primary tb-big', onclick: () => doAction('lobby') }, t('Neues Spiel', 'New game'))
                : room.seats.some(s => !s) ? h('button', { class: 'btn primary tb-big', onclick: () => ctx.sit(room.seats.findIndex(s => !s)) }, t('Mitmachen', 'Join'))
                : null);
    }

    // ------------------------------------------------------------------ gemeinsam

    function removeBtn(state, p, me, nameOf, doAction, consequence) {
        if (me === null || p.seat === me) return null;
        return h('button', {
            class: 'link muted tb-remove', title: t('Aus dem Spiel nehmen (z. B. wenn jemand weg ist)', 'Remove from game (e.g. if someone left)'),
            onclick: () => { if (confirm(t(`${nameOf(p.seat)} aus diesem Spiel nehmen?`, `Remove ${nameOf(p.seat)} from this game?`) + ' ' + consequence)) doAction('remove', { seat: p.seat }); },
        }, '×');
    }

    function extras(ctx, doAction) {
        const { state, room, mySeat } = ctx;
        const me = state.meSeat ?? null;
        return h('div', { class: 'tb-extras' },
            mySeat === null && room.seats.some(s => !s) ? h('button', {
                class: 'btn primary', onclick: () => ctx.sit(room.seats.findIndex(s => !s)),
            }, t('Mitmachen', 'Join')) : null,
            mySeat !== null && me === null ? h('p', { class: 'notice small' }, t('Du spielst beim nächsten Spiel mit.', 'You will play in the next game.')) : null,
            me !== null || (mySeat !== null && state.orphaned) ? h('button', {
                class: 'link muted', onclick: () => { if (confirm(t('Spiel für alle abbrechen? Alle Begriffe gehen verloren.', 'Abort the game for everyone? All terms will be lost.'))) doAction('lobby'); },
            }, t('Spiel abbrechen', 'Abort game')) : null);
    }
})();
