/* Game Night – Lobby, Räume, Abfrage des Servers */
'use strict';

const API = CONFIG.api;                // vom Template gesetzt: <Seite>/api
const LOBBY = CONFIG.lobby !== '0';    // false: keine Tischliste, Tische nur per Link
const POLL_MS = 1000;
const POLL_HIDDEN_MS = 5000;
const BACKOFF_MS = 10000;              // nach „zu viele Anfragen“ (429)
const RECENT_MAX = 20;
const PROJECT_URL = 'https://github.com/Tobias271828/grav-plugin-game-night';
// Spiele, die diese Instanz anbietet (leer = alle). Bestehende Tische anderer Spiele lassen sich weiter öffnen.
const OFFERED = (CONFIG.games || '').split(',').filter(Boolean);

const me = {
    token: store.get('token'),
    name: store.get('name', ''),
};
if (!/^[0-9a-f]{32}$/.test(me.token || '')) {   // fehlt oder unbrauchbar: neu erzeugen
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    me.token = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    store.set('token', me.token);
}

async function api(action, data = {}) {
    let res;
    try {
        res = await fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, token: me.token, lang: LANG, ...data }),
            // hängt eine Anfrage, nicht ewig warten (sonst bleiben Knöpfe stumm)
            signal: AbortSignal.timeout ? AbortSignal.timeout(15000) : undefined,
        });
    } catch (e) {
        throw new Error(t('Keine Verbindung zum Server – bitte noch einmal versuchen.', 'No connection to the server – please try again.'));
    }
    let json;
    try { json = await res.json(); } catch (e) { throw new Error(t('Server antwortet nicht korrekt', 'Unexpected server response') + ' (' + res.status + ')'); }
    if (!res.ok || json.error) {
        const err = new Error(json.error || (t('Fehler ', 'Error ') + res.status));
        err.status = res.status;
        throw err;
    }
    return json;
}

// ---------------------------------------------------------------------------
// Zuletzt besuchte Tische (nur in diesem Browser; ohne Tischliste der einzige Weg zurück)

const RECENT_KEY = 'recent.' + API;   // je Instanz getrennt

function recentRooms() {
    try {
        const list = JSON.parse(store.get(RECENT_KEY, '[]'));
        return Array.isArray(list) ? list.filter(r => r && /^[a-z0-9]{6,16}$/.test(r.id)) : [];
    } catch (e) {
        return [];
    }
}

function rememberRoom(room) {
    const list = recentRooms().filter(r => r.id !== room.id);
    list.unshift({ id: room.id, game: room.game, title: room.title, seen: Math.floor(Date.now() / 1000) });
    store.set(RECENT_KEY, JSON.stringify(list.slice(0, RECENT_MAX)));
}

function forgetRoom(id) {
    store.set(RECENT_KEY, JSON.stringify(recentRooms().filter(r => r.id !== id)));
}

// ---------------------------------------------------------------------------
// Routing

let current = null;   // { id, room, mySeat, timer }
let rulesOpen = false;

function route() {
    stopPolling();
    const m = location.hash.match(/^#r=([a-z0-9]+)/);
    // Am Tisch steht der Name im Tischkopf; die Kopfzeile darüber blendet das CSS auf dem Handy aus
    document.querySelector('.sa')?.classList.toggle('in-room', !!m);
    if (m) openRoom(m[1]);
    else showLobby();
    keepHashInLangLinks();
}

/** Beim Sprachwechsel am selben Tisch bleiben: Raum-Anker an die Links des Sprachumschalters hängen. */
function keepHashInLangLinks() {
    // Nur Links auf dieselbe Seite (Sprachumschalter), nicht z. B. Impressum: Die Tisch-ID ist ohne
    // Tischliste das einzige Geheimnis des Tisches und soll nicht an andere Seiten gehen.
    document.querySelectorAll('.footer a[href]').forEach(a => {
        let url;
        try { url = new URL(a.getAttribute('href'), location.href); } catch (e) { return; }
        if (url.origin !== location.origin || url.pathname.replace(/^\/(de|en)(?=\/)/, '') !== location.pathname.replace(/^\/(de|en)(?=\/)/, '')) return;
        a.setAttribute('href', a.getAttribute('href').split('#')[0] + location.hash);
    });
}
window.addEventListener('hashchange', route);
document.addEventListener('visibilitychange', () => {
    if (!document.hidden && current && !inBackoff(current)) poll(true);
});

/** Nach „zu viele Anfragen“ (429) eine Weile gar nicht fragen – auch nicht beim Zurückkehren in den Tab. */
function inBackoff(cur) {
    return cur.backoffUntil && Date.now() < cur.backoffUntil;
}

function renderWhoami() {
    // in der Kopfzeile und (Handy) im Tischkopf
    document.querySelectorAll('.whoami').forEach(el => el.replaceChildren(
        me.name ? h('span', {}, t('Du bist ', 'You are '), h('b', {}, me.name), ' ') : '',
        h('button', { class: 'link', onclick: askName }, me.name ? t('ändern', 'change') : t('Namen festlegen', 'Set your name')),
    ));
}

function askName() {
    const name = prompt(t('Wie heißt du?', 'What is your name?'), me.name || '');
    if (name === null) return false;
    const clean = name.trim().slice(0, 24);
    if (!clean) return false;
    me.name = clean;
    store.set('name', clean);
    renderWhoami();
    // Sitzt man gerade an einem Tisch, den neuen Namen dort übernehmen
    if (current && current.mySeat !== null && current.mySeat !== undefined) act('sit', { seat: current.mySeat, name: clean });
    return true;
}

function ensureName() {
    return me.name ? true : askName();
}

// ---------------------------------------------------------------------------
// Lobby

let lobbyTimer = null;

async function showLobby() {
    const app = document.getElementById('app');
    const list = h('div', { class: 'room-list' }, h('p', { class: 'muted' }, t('Lade Tische …', 'Loading tables …')));

    // Ein Spiel = eine Zeile, die als Ganzes ein Knopf ist (große Tippfläche)
    const gameCards = Object.values(Games).filter(g => !OFFERED.length || OFFERED.includes(g.id)).map(g =>
        h('button', { class: 'game-card', title: t('Neuen Tisch anlegen', 'Create a new table'), onclick: () => createRoom(g.id) },
            h('span', { class: 'game-icon' }, gameIcon(g)),
            h('span', { class: 'game-text' },
                h('span', { class: 'game-name' }, g.name, g.wip ? h('span', { class: 'badge' }, t('in Arbeit', 'work in progress')) : null),
                h('span', { class: 'game-desc' }, g.desc),
                h('span', { class: 'game-seats' }, g.seatInfo || (g.seats.length + t(' Personen', ' players')))),
            // auf dem Handy nur „+“ (siehe CSS), damit die Beschreibung Platz hat
            h('span', { class: 'game-go', 'aria-hidden': 'true' }, h('span', { class: 'game-go-text' }, t('Neuer Tisch', 'New table'))),
        ));

    app.replaceChildren(
        LOBBY ? h('section', {},
            h('h2', {}, t('Offene Tische', 'Open tables')),
            list,
        ) : recentSection(),
        h('section', {},
            h('h2', {}, t('Neues Spiel', 'New game')),
            h('div', { class: 'game-grid' }, gameCards),
        ),
        // Hinweis zur Entstehung und zu verwendeter Software (nur auf der Übersicht)
        h('footer', { class: 'sa-note' },
            // jeder Satz in eigener Zeile (bricht auf schmalen Bildschirmen für sich um)
            h('p', {},
                h('span', { class: 'sa-note-line' },
                    h('a', { href: PROJECT_URL, rel: 'noopener', target: '_blank' }, 'Game Night'),
                    t(' ist freie Software (MIT-Lizenz). Sie wurde mit Unterstützung von KI (Claude von Anthropic) entwickelt; den Quelltext hat Tobias Schnieders selbst durchgesehen.',
                      ' is free software (MIT license). It was developed with the help of AI (Claude by Anthropic); Tobias Schnieders reviewed the source code personally.')),
                /^(https?:|mailto:|\/)/i.test(CONFIG.contact || '') ? h('span', { class: 'sa-note-line' },
                    t('Über Hinweise auf Fehler, Anmerkungen und Verbesserungsvorschläge freue ich mich – gerne ',
                      'I am happy to receive bug reports, comments and suggestions – feel free to send them '),
                    h('a', { href: CONFIG.contact }, t('per E-Mail', 'by e-mail')), '.') : null),
            h('p', {},
                t('Die Schachregeln prüft ', 'Chess rules are checked by '),
                h('a', { href: 'https://github.com/jhlywa/chess.js', rel: 'noopener', target: '_blank' }, 'chess.js'),
                t(' von Jeff Hlywa (BSD-Lizenz).', ' by Jeff Hlywa (BSD license).')),
        ),
    );

    if (!LOBBY) return;
    const load = async () => {
        try {
            const { rooms } = await api('list');
            renderRoomList(list, rooms);
        } catch (e) {
            list.replaceChildren(h('p', { class: 'error' }, e.message));
        }
    };
    await load();
    clearInterval(lobbyTimer);
    if (location.hash.startsWith('#r=')) return;   // inzwischen an einen Tisch gewechselt
    lobbyTimer = setInterval(() => { if (!document.hidden && !location.hash.startsWith('#r=')) load(); }, 5000);
}

/** Ohne Tischliste: die Tische, die man in diesem Browser schon geöffnet hat. */
function recentSection() {
    const rooms = recentRooms();
    return h('section', {},
        h('h2', {}, t('Deine Tische', 'Your tables')),
        h('p', { class: 'muted small' },
            t('Tische sind hier nur über ihren Link erreichbar. Leg unten einen an und schick den Link an deine Mitspielenden.',
              'Tables can only be reached via their link. Create one below and send the link to the people you want to play with.')),
        rooms.length ? h('div', { class: 'room-list' }, rooms.map(r => {
            const g = Games[r.game];
            return h('div', { class: 'card room-row' },
                h('span', { class: 'game-icon small' }, gameIcon(g)),
                h('a', { class: 'room-main', href: '#r=' + r.id }, h('b', {}, r.title || (g ? g.name : r.game))),
                h('span', { class: 'muted small' }, timeAgo(r.seen)),
                h('button', {
                    class: 'link muted', title: t('Aus dieser Liste entfernen', 'Remove from this list'),
                    onclick: e => { forgetRoom(r.id); e.target.closest('.room-row').remove(); },
                }, '×'),
            );
        })) : h('p', { class: 'muted' }, t('Noch keine.', 'None yet.')),
    );
}

function renderRoomList(list, rooms) {
    if (!rooms.length) {
        list.replaceChildren(h('p', { class: 'muted' }, t('Noch keine Tische. Leg unten einen an!', 'No tables yet. Create one below!')));
        return;
    }
    list.replaceChildren(...rooms.map(r => {
        const g = Games[r.game];
        const seated = r.players.filter(Boolean);
        const free = r.players.length - seated.length;
        return h('a', { class: 'card room-row', href: '#r=' + r.id },
            h('span', { class: 'game-icon small' }, gameIcon(g)),
            h('span', { class: 'room-main' },
                h('b', {}, r.title || (g ? g.name : r.game)),
                h('span', { class: 'muted small' },
                    seated.length ? seated.join(', ') : t('noch niemand', 'nobody yet'),
                    free ? ' · ' + (free === 1 ? t('1 Platz frei', '1 seat free') : t(`${free} Plätze frei`, `${free} seats free`)) : '',
                    r.over ? t(' · beendet', ' · finished') : '',
                    ' · ' + timeAgo(r.updated)),
            ),
        );
    }));
}

function timeAgo(ts) {
    const s = Math.max(0, Math.floor(Date.now() / 1000 - ts));
    if (s < 60) return t('gerade eben', 'just now');
    if (s < 3600) return t(`vor ${Math.floor(s / 60)} min`, `${Math.floor(s / 60)} min ago`);
    if (s < 86400) return t(`vor ${Math.floor(s / 3600)} h`, `${Math.floor(s / 3600)} h ago`);
    const d = Math.floor(s / 86400);
    return d === 1 ? t('vor 1 Tag', '1 day ago') : t(`vor ${d} Tagen`, `${d} days ago`);
}

async function createRoom(gameId) {
    if (!ensureName()) return;
    const g = Games[gameId];
    const title = prompt(t('Name des Tisches (optional):', 'Table name (optional):'), (g.tableName || g.name) + t(' mit ', ' with ') + me.name);
    if (title === null) return;
    try {
        const { id } = await api('create', { game: gameId, title, state: g.init() });
        await api('sit', { room: id, seat: 0, name: me.name });
        location.hash = '#r=' + id;
    } catch (e) {
        toast(e.message);
    }
}

// ---------------------------------------------------------------------------
// Raum

async function openRoom(id) {
    clearInterval(lobbyTimer);
    current = { id, room: null, mySeat: null, timer: null, noticeKey: undefined, pending: false };
    wasMyTurn = null;
    document.getElementById('app').replaceChildren(h('p', { class: 'muted' }, t('Lade Tisch …', 'Loading table …')));
    await poll(true);
}

function stopPolling() {
    if (current) clearTimeout(current.timer);
    current = null;
    notifyTurn(false);
}

async function poll(force = false) {
    const cur = current;
    if (!cur) return;
    clearTimeout(cur.timer);
    if (inBackoff(cur)) {   // z. B. nach einem 429 auf eine Aktion: erst nach der Pause wieder fragen
        cur.timer = setTimeout(poll, cur.backoffUntil - Date.now());
        return;
    }
    if (cur.inFlight) {   // es läuft schon eine Abfrage; danach geht es normal weiter
        cur.again = cur.again || force;
        return;
    }
    cur.inFlight = true;
    try {
        const res = await api('get', { room: cur.id, since: force || !cur.room ? 0 : cur.room.version });
        if (cur !== current) return;
        setConnState(true);
        if (!res.unchanged) applyRoom(cur, res);
    } catch (e) {
        if (cur !== current) return;
        if (e.status === 404 || e.status === 400) {
            forgetRoom(cur.id);
            notifyTurn(false);
            document.getElementById('app').replaceChildren(
                h('p', {}, t('Diesen Tisch gibt es nicht (mehr).', 'This table does not exist (anymore).')),
                h('a', { class: 'btn', href: '#' }, t('Zur Übersicht', 'Back to overview')));
            return;
        }
        setConnState(false);
        if (e.status === 429) cur.backoffUntil = Date.now() + BACKOFF_MS;
    } finally {
        cur.inFlight = false;
    }
    if (cur === current) {
        const again = cur.again && !inBackoff(cur);
        cur.again = false;
        cur.timer = again ? setTimeout(() => poll(true), 0)
            : setTimeout(poll, inBackoff(cur) ? BACKOFF_MS : document.hidden ? POLL_HIDDEN_MS : POLL_MS);
    }
}

function setConnState(ok) {
    const el = document.querySelector('.conn');
    if (el) el.classList.toggle('bad', !ok);
}

/** Antwort des Servers für den Tisch cur übernehmen – nur, wenn dieser Tisch noch geöffnet ist. */
function applyRoom(cur, res) {
    if (cur !== current || !res.room || res.room.id !== cur.id) return;
    // Kommt eine ältere Antwort erst nach einer neueren an, nicht zurückspringen
    if (current.room && res.room.version < current.room.version) return;
    if (!current.room) rememberRoom(res.room);
    current.room = res.room;
    current.mySeat = res.mySeat;
    setConnState(true);
    renderRoom();
}

// Hinweis, wenn man an die Reihe kommt oder etwas passiert (Tab-Titel, Vibration auf dem Handy)
const baseTitle = document.title;
let wasMyTurn = null;   // null = Tisch gerade erst geöffnet (dann nicht vibrieren)
function vibrate(pattern) {
    // Browser erlauben Vibration erst, nachdem man einmal auf die Seite getippt hat
    if (navigator.userActivation && !navigator.userActivation.hasBeenActive) return;
    try { navigator.vibrate && navigator.vibrate(pattern); } catch (e) { /* egal */ }
}
function notifyTurn(myTurn, notice = null, attention = false) {
    const needsMe = myTurn || attention;
    if (needsMe && wasMyTurn === false) vibrate(150);
    wasMyTurn = needsMe;
    document.title = (myTurn ? '● ' + t('Du bist dran', 'Your turn') + ' – '
        : attention ? '● ' + t('Bitte abstimmen', 'Please vote') + ' – '
        : notice ? '● ' + notice.text + ' – ' : '') + baseTitle;
}

/** Spiel-Ereignis (z. B. „Trombone!“) melden – nur wenn es neu ist, nicht schon beim Öffnen des Tisches. */
function notifyEvent(notice) {
    const key = notice ? notice.key : null;
    if (current.noticeKey !== undefined && key && key !== current.noticeKey) {
        vibrate([120, 80, 120]);
        toast(notice.text);
    }
    current.noticeKey = key;
}

async function act(action, data) {
    const cur = current;
    if (!cur) return false;
    try {
        const res = await api(action, { room: cur.id, ...data });
        applyRoom(cur, res);
        return true;
    } catch (e) {
        if (cur !== current) return false;   // inzwischen woanders: Fehler dieses Tisches nicht mehr zeigen
        toast(e.message);
        if (e.status === 429) cur.backoffUntil = Date.now() + BACKOFF_MS;
        else poll(true);
        return false;
    }
}

/**
 * Tisch anzeigen. Bei „Vier in einer Reihe“, Schach und Zahlenkampf schickt der Browser der Person am Zug den neuen
 * Stand; ist der kaputt (Fehler oder Manipulation), soll nicht die Seite aller anderen hängen bleiben.
 */
function renderRoom() {
    if (!current || !current.room) return;   // z. B. nach einem await, wenn man den Tisch schon verlassen hat
    try {
        renderRoomUnsafe();
    } catch (e) {
        console.error(e);
        const { room, mySeat } = current;
        const game = Games[room.game];
        fill(document.getElementById('app'),
            h('div', { class: 'room-head' }, h('a', { href: '#', class: 'link' }, t('← Übersicht', '← Overview'))),
            h('p', { class: 'error' }, t('Der Spielstand dieses Tisches ist beschädigt und kann nicht angezeigt werden.',
                                         'The game state of this table is damaged and cannot be displayed.')),
            game && game.init && mySeat !== null ? h('button', {
                class: 'btn', onclick: () => act('move', { state: game.init(), version: room.version, kind: 'reset' }),
            }, t('Neues Spiel starten', 'Start a new game')) : null,
        );
    }
}

function renderRoomUnsafe() {
    const { room, mySeat } = current;
    const game = Games[room.game];
    const app = document.getElementById('app');
    const allSeated = game.flexibleSeats || room.seats.every(Boolean);
    const state = room.state;

    const playerName = i => room.seats[i] ? room.seats[i].name : null;

    const ctx = {
        room, state, mySeat, allSeated,
        version: room.version,
        playerName,
        label: i => playerName(i) ? `${playerName(i)} (${game.seats[i]})` : game.seats[i],
        isMyTurn: game.isMyTurn ? game.isMyTurn(state, mySeat)
            : mySeat !== null && allSeated && !state.over && (state.turn || []).includes(mySeat),
        submit: async (newState, kind = 'move') => {
            const cur = current;
            if (cur.pending) return false;   // Doppelklick: der erste Zug ist noch unterwegs
            cur.pending = true;
            try { return await act('move', { state: newState, version: room.version, kind }); }
            finally { cur.pending = false; }
        },
        // für Spiele mit Server-Logik
        action: (op, data = {}) => act('game', { op, ...data }),
        sit: seat => { if (ensureName()) return act('sit', { seat, name: me.name }); },
        stand: () => act('stand'),
        kick: seat => act('kick', { seat }),
    };
    const notice = game.notice ? game.notice(state, mySeat) : null;
    notifyTurn(ctx.isMyTurn, notice, !!(game.attention && game.attention(state, mySeat)));
    notifyEvent(notice);
    // Tippt man gerade etwas (z. B. einen Begriff), nicht neu aufbauen – sonst gehen Fokus und Handy-Tastatur
    // verloren. Das Spiel holt den Neuaufbau danach selbst nach (renderRoom()).
    if (game.holdRender && game.holdRender(state, mySeat)) return;

    const seats = game.ownSeats ? null : h('div', { class: 'seats' }, room.seats.map((s, i) => {
        const mine = i === mySeat;
        const active = allSeated && !state.over && (state.turn || []).includes(i);   // erst hervorheben, wenn das Spiel läuft
        return h('div', { class: 'seat' + (mine ? ' mine' : '') + (active ? ' active' : '') },
            h('span', { class: 'seat-label' }, game.seats[i]),
            s ? h('b', {}, s.name, mine ? t(' (du)', ' (you)') : '') : h('span', { class: 'muted' }, t('frei', 'free')),
            mine ? h('button', { class: 'link', onclick: () => act('stand') }, t('aufstehen', 'leave seat')) : null,
            !s ? h('button', { class: 'btn small', onclick: () => { if (ensureName()) act('sit', { seat: i, name: me.name }); } }, t('hinsetzen', 'take seat')) : null,
            s && !mine && mySeat !== null ? h('button', {
                class: 'link muted', title: t('Platz freigeben (z. B. wenn jemand weg ist)', 'Free this seat (e.g. if someone left)'),
                onclick: () => { if (confirm(t(`${s.name} vom Platz „${game.seats[i]}“ entfernen?`, `Remove ${s.name} from seat “${game.seats[i]}”?`))) act('kick', { seat: i }); },
            }, '×') : null,
        );
    }));

    const board = h('div', { class: 'game-area' });
    game.render(board, ctx);

    const controls = h('div', { class: 'controls' },
        mySeat !== null && allSeated && !state.over && !game.ownControls ? h('button', {
            class: 'btn', onclick: () => {
                if (!confirm(t('Wirklich aufgeben?', 'Really resign?'))) return;
                const ns = game.resign(clone(state), mySeat);
                ctx.submit(ns, 'resign');
            },
        }, t('Aufgeben', 'Resign')) : null,
        mySeat !== null && !game.ownControls ? h('button', {
            class: state.over ? 'btn primary' : 'btn',   // nach Spielende die naheliegende nächste Aktion
            onclick: () => {
                if (state.over || confirm(t('Neues Spiel starten? Der aktuelle Stand geht verloren.', 'Start a new game? The current game will be lost.'))) ctx.submit(game.init(state), 'reset');
            },
        }, state.over ? t('Neue Runde', 'New round') : t('Neu starten', 'Restart')) : null,
        h('button', {
            class: 'btn', onclick: () => {
                navigator.clipboard?.writeText(location.href).then(() => toast(t('Link kopiert', 'Link copied')), () => toast(location.href, 8000));
            },
        }, t('Link kopieren', 'Copy link')),
        h('button', {
            class: 'link muted', onclick: async () => {
                if (!confirm(t('Tisch endgültig löschen?', 'Delete this table permanently?'))) return;
                try { await api('delete', { room: current.id }); location.hash = ''; }
                catch (e) { toast(e.message); }
            },
        }, t('Tisch löschen', 'Delete table')),
    );

    // Scrollposition und Fokus bei Neuaufbau halten
    const scroll = window.scrollY;
    fill(app, 
        h('div', { class: 'room-head' },
            h('a', { href: '#', class: 'link' }, t('← Übersicht', '← Overview')),
            h('h2', {}, h('span', { class: 'game-icon tiny' }, gameIcon(game)), room.title || game.name),
            h('span', { class: 'room-right' },
                h('span', { class: 'whoami' }),
                h('span', { class: 'conn', title: t('Verbindung', 'Connection') })),
        ),
        seats,
        !allSeated && !state.over && !game.flexibleSeats ? h('p', { class: 'notice' }, t('Warte, bis alle Plätze besetzt sind.', 'Waiting until all seats are taken.')) : null,
        board,
        controls,
        game.rules ? h('details', { class: 'rules', open: rulesOpen, ontoggle: e => { rulesOpen = e.target.open; } }, h('summary', {}, t('Spielregeln', 'Rules')), h('div', { html: game.rules })) : null,
    );
    renderWhoami();
    window.scrollTo(0, scroll);
}

// ---------------------------------------------------------------------------

// Erst starten, wenn auch der Fußbereich (Sprachumschalter) des Themes geladen ist
function start() {
    if (LANG_RELOAD) return;   // Seite lädt gleich in der gemerkten Sprache neu
    renderWhoami();
    route();
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
else start();
