/* Game Night – gemeinsame Hilfsfunktionen und Spiele-Register */
'use strict';

const Games = {};

/** Einstellungen der Instanz aus den data-Attributen von #app (siehe Template bzw. standalone/index.php). */
const CONFIG = (document.getElementById('app') || {}).dataset || {};

/** Sprache der Seite (über den Sprachumschalter); alles außer Englisch wird Deutsch. */
const LANG = CONFIG.lang === 'de' ? 'de' : 'en';

/**
 * Standalone-Version (Sprache per ?lang=…): Eine ausdrücklich gewählte Sprache in diesem Browser merken.
 * Öffnet man später eine Adresse ohne ?lang= (z. B. einen geteilten Tisch-Link) und hat der Server nach
 * der Browsersprache anders entschieden, mit der gemerkten Sprache neu laden.
 * (In Grav steckt die Sprache in der URL, darum kümmert sich das Theme.)
 */
const LANG_RELOAD = (() => {
    if (!CONFIG.langParam) return false;
    try {
        const chosen = new URLSearchParams(location.search).get('lang');
        if (chosen === 'de' || chosen === 'en') {
            localStorage.setItem('game-night.lang', chosen);
            return false;
        }
        const saved = localStorage.getItem('game-night.lang');
        if ((saved === 'de' || saved === 'en') && saved !== LANG) {
            const url = new URL(location.href);
            url.searchParams.set('lang', saved);
            location.replace(url.href);
            return true;
        }
    } catch (e) { /* kein localStorage: dann eben Browsersprache */ }
    return false;
})();

/** Text in der Seitensprache: t('Deutsch', 'English') */
function t(de, en) {
    return LANG === 'en' ? en : de;
}

/**
 * Spiel registrieren. Pflichtfelder: id, name, icon (Text oder Funktion, die ein Element liefert), desc,
 * seats (Platznamen), rules (HTML), init(vorherigerStand?), render(root, ctx).
 * Optional: resign(state, seat), seatInfo (Text statt Platzliste in der Übersicht),
 * flexibleSeats (Spiel startet ohne volle Plätze), ownSeats / ownControls (Spiel zeigt Plätze bzw.
 * Knöpfe selbst), isMyTurn(state, seat), notice(state) (Ereignis-Hinweis), attention(state) (z. B. Abstimmung),
 * wip (in Arbeit: Kennzeichen in der Übersicht).
 */
function registerGame(game) {
    Games[game.id] = game;
}

/** Kleines DOM-Hilfsmittel: h('div', {class: 'x', onclick: f}, 'Text', kind, ...) */
function h(tag, attrs, ...children) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
        if (v === null || v === undefined || v === false) continue;
        if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
        else if (k === 'class') el.className = v;
        else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
        else if (k === 'html') el.innerHTML = v;
        else el.setAttribute(k, v === true ? '' : v);
    }
    for (const c of children.flat()) {
        if (c === null || c === undefined || c === false) continue;
        el.append(c instanceof Node ? c : document.createTextNode(String(c)));
    }
    return el;
}

function toast(msg, ms = 3500) {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.hidden = false;
    clearTimeout(toast._t);
    toast._t = setTimeout(() => { el.hidden = true; }, ms);
}

const store = {
    get(key, fallback = null) {
        try { const v = localStorage.getItem('game-night.' + key); return v === null ? fallback : v; }
        catch (e) { return fallback; }
    },
    set(key, value) {
        try { localStorage.setItem('game-night.' + key, value); } catch (e) { /* egal */ }
    },
};

/** Spielsymbol als DOM-Knoten: entweder Text (Emoji) oder eine Funktion, die ein Element liefert. */
function gameIcon(game) {
    if (!game) return '?';
    return typeof game.icon === 'function' ? game.icon() : game.icon;
}

function clone(obj) {
    return JSON.parse(JSON.stringify(obj));
}

/** Inhalt eines Elements ersetzen; null/false-Einträge werden übersprungen. */
function fill(el, ...children) {
    el.replaceChildren(...children.flat().filter(c => c !== null && c !== undefined && c !== false));
}
