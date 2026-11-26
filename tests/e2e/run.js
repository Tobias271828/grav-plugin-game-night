#!/usr/bin/env node
/*
 * Game Night – End-to-End-Tests im echten Browser (Chromium über puppeteer-core).
 *
 * Startet einen PHP-Entwicklungsserver mit frischem Datenordner und zwei Instanzen
 * (/a/ mit Tischliste, /b/ ohne), spielt jedes Spiel mit mehreren Browser-Kontexten
 * (= Personen mit eigenem localStorage) und prüft nebenbei auf JavaScript-Fehler,
 * Konsolenfehler, CSP-Verstöße und waagrechtes Scrollen auf Handybreite.
 *
 * Aufruf und Umgebungsvariablen: siehe README.md in diesem Ordner. Exit-Code 0 = alles in Ordnung.
 */
'use strict';

const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');

let puppeteer;
try {
    puppeteer = require('puppeteer-core');
} catch (e) {
    console.error('puppeteer-core not found – install it (npm i puppeteer-core) or set NODE_PATH, see README.md');
    process.exit(2);
}

const PLUGIN_DIR = path.resolve(__dirname, '..', '..');
const PORT = Number(process.env.E2E_PORT || 8030);
const BASE = `http://127.0.0.1:${PORT}`;
const CHROME = process.env.CHROME_PATH || '/usr/bin/chromium';
const OUT = process.env.E2E_OUT || fs.mkdtempSync(path.join(os.tmpdir(), 'gn-e2e-out-'));
const ONLY = (process.env.E2E_ONLY || '').split(',').filter(Boolean);
const HEADLESS = !process.env.E2E_HEADFUL;
const TIMEOUT = Number(process.env.E2E_TIMEOUT || 12000);   // Warten auf einen Zustand (Abfrage alle 1 s)
const SUITE_TIMEOUT = 300000;

const MOBILE = { width: 375, height: 740, isMobile: true, hasTouch: true, deviceScaleFactor: 2 };
const DESKTOP = { width: 1200, height: 900 };

const sleep = ms => new Promise(r => setTimeout(r, ms));

// ---------------------------------------------------------------------------
// Ergebnisse

const results = [];      // { suite, ok, failures: [{msg, shots}], ms }
const notes = [];        // Beobachtungen, die keine Fehler sind
let suite = null;        // aktuelle Testgruppe

function fail(msg, shots = []) {
    suite.failures.push({ msg, shots });
    console.log(`    ✗ ${msg}${shots.length ? '\n      screenshots: ' + shots.join(', ') : ''}`);
}

async function check(cond, msg, players = []) {
    if (cond) return true;
    fail(msg, await shootAll(players, 'check'));
    return false;
}

function note(msg) {
    if (!notes.includes(msg)) notes.push(msg);
    console.log(`    · ${msg}`);
}

function step(msg) {
    console.log(`    – ${msg}`);
}

/** Bildschirmfotos an wichtigen Stellen (nur mit E2E_SNAP=1, zum Anschauen des Layouts). */
async function snap(players, label) {
    if (process.env.E2E_SNAP) await shootAll(players, label);
}

let shotNo = 0;
async function shootAll(players, label) {
    const files = [];
    for (const p of players) {
        if (!p || p.closed) continue;
        try { files.push(await p.screenshot(label)); } catch (e) { /* Seite schon zu */ }
    }
    return files;
}

// ---------------------------------------------------------------------------
// PHP-Server

let server = null;
let tmpDir = null;

/**
 * Gewichtsdatei für den Trombone-Bot mit Zufallsgewichten (Format siehe tbot_net_info() in core/TromboneBot.php).
 * Für die Oberfläche ist gleich, wie gut der Bot spielt – er zieht damit regelgerecht.
 */
function writeTestNet(file) {
    const src = fs.readFileSync(path.join(PLUGIN_DIR, 'core', 'TromboneBot.php'), 'utf8');
    const num = name => Number(src.match(new RegExp(`const ${name}\\s*=\\s*(\\d+)`))[1]);
    const sizes = [num('TBOT_INPUTS'), 128, 128, num('TBOT_ACTIONS')];
    const meta = Buffer.from('{}');
    const cred = [5, 0.38, 0.32, 0.35, 0.18, 0.55, 0.45];   // k und die sechs Vorwerte der Glaubwürdigkeit
    const head = Buffer.alloc(10 + 2 * sizes.length + 4 * cred.length + 4);
    head.write('TBN1', 0, 'latin1');
    [num('TBOT_FORMAT_VERSION'), num('TBOT_FEATURE_VERSION'), sizes.length - 1, ...sizes].forEach((v, i) => head.writeUInt16LE(v, 4 + 2 * i));
    cred.forEach((v, i) => head.writeFloatLE(v, 10 + 2 * sizes.length + 4 * i));
    head.writeUInt32LE(meta.length, head.length - 4);
    let floats = 0;
    for (let l = 0; l + 1 < sizes.length; l++) floats += sizes[l] * sizes[l + 1] + sizes[l + 1];
    const weights = Buffer.alloc(4 * floats);
    for (let i = 0; i < floats; i++) weights.writeFloatLE((Math.random() - 0.5) * 0.4, 4 * i);
    fs.writeFileSync(file, Buffer.concat([head, meta, weights]));
}

async function startServer() {
    tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'gn-e2e-'));
    writeTestNet(`${tmpDir}/trombone-net.bin`);
    const common = { requests_per_minute: 0, creates_per_hour: 0, rooms_per_client: 0, max_bots_per_client: 0, max_bots_total: 0, bot_moves_per_minute: 0 };
    const config = {
        state_dir: `${tmpDir}/state`,
        instances: {
            // a: alle Spiele (auch das Zahlenkampfspiel, das standardmäßig aus ist); b: Voreinstellung
            // a bietet Trombone-Bots an (Testnetz, kurze Denkpause – beides setzt nur router.php); b hat keine Gewichtsdatei
            a: { ...common, lobby: true, data_dir: `${tmpDir}/a`, contact_url: 'mailto:test@example.org',
                 net_file: `${tmpDir}/trombone-net.bin`, bot_delay: [300, 700],
                 games: ['trombone', 'werbinich', 'handbrain', 'connect4', 'zahlenkampf', 'konferenz'],
                 footer_links: { de: { Impressum: 'https://example.org/impressum' }, en: { 'Legal notice': 'https://example.org/legal' } } },
            b: { ...common, lobby: false, data_dir: `${tmpDir}/b`, net_file: `${tmpDir}/fehlt.bin` },
        },
    };
    fs.writeFileSync(`${tmpDir}/config.json`, JSON.stringify(config));
    const log = fs.openSync(`${tmpDir}/server.log`, 'a');
    server = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', PLUGIN_DIR, path.join(__dirname, 'router.php')], {
        env: { ...process.env, GN_E2E_CONFIG: `${tmpDir}/config.json`, PHP_CLI_SERVER_WORKERS: '4' },
        stdio: ['ignore', log, log],
    });
    server.on('exit', code => { if (server) console.error(`PHP server exited unexpectedly (${code}), see ${tmpDir}/server.log`); });
    for (let i = 0; i < 100; i++) {
        try {
            const res = await fetch(`${BASE}/a/`);
            if (res.ok) return;
        } catch (e) { /* noch nicht bereit */ }
        await sleep(100);
    }
    throw new Error(`PHP server did not start on port ${PORT} (port in use?), see ${tmpDir}/server.log`);
}

function stopServer() {
    if (server) {
        const s = server;
        server = null;
        s.kill();
    }
    if (tmpDir && !process.env.E2E_KEEP) fs.rmSync(tmpDir, { recursive: true, force: true });
}

// ---------------------------------------------------------------------------
// Eine Person = ein eigener Browser-Kontext

class Player {
    static async create(browser, name, { mobile = false, lang = 'de' } = {}) {
        const p = new Player();
        p.name = name;
        p.lang = lang;
        p.mobile = mobile;
        p.errors = [];          // unerwartete Fehler (pageerror, console.error, CSP, API-Fehler)
        p.allowed = [];         // reguläre Ausdrücke für erwartete Fehler
        p.dialogs = [];
        p.confirmAnswer = true;
        p.closed = false;
        p.context = await browser.createBrowserContext();
        await p.context.overridePermissions(BASE, ['clipboard-read', 'clipboard-write', 'clipboard-sanitized-write']);
        p.page = await p.context.newPage();
        await p.page.setViewport(mobile ? MOBILE : DESKTOP);
        await p.page.setExtraHTTPHeaders({ 'Accept-Language': lang === 'en' ? 'en-US,en;q=0.9' : 'de-DE,de;q=0.9' });
        p.page.setDefaultTimeout(TIMEOUT);
        p.attach();
        return p;
    }

    attach() {
        const page = this.page;
        page.on('pageerror', err => this.error(`pageerror: ${err.message}`));
        page.on('console', msg => {
            const text = msg.text();
            const url = (msg.location() || {}).url || '';
            if (/favicon/.test(text) || /favicon/.test(url)) return;
            if (/Content Security Policy|Refused to/i.test(text)) { this.error(`CSP (console): ${text}`); return; }
            if (msg.type() !== 'error') return;
            // HTTP-Fehler der API werden über 'response' mit Fehlermeldung erfasst
            if (/Failed to load resource/.test(text) && /\/api$/.test(url)) return;
            this.error(`console.error: ${text}${url ? ' @ ' + url : ''}`);
        });
        page.on('response', async res => {
            const url = res.url();
            if (res.status() < 400 || /favicon/.test(url)) return;
            let detail = '';
            try { detail = (await res.json()).error || ''; } catch (e) { /* kein JSON */ }
            this.error(`HTTP ${res.status()} ${url.replace(BASE, '')}${detail ? ': ' + detail : ''}`);
        });
        page.on('dialog', async d => {
            const msg = d.message();
            this.dialogs.push(`${d.type()}: ${msg}${d.defaultValue() ? ` [${d.defaultValue()}]` : ''}`);
            try {
                if (d.type() === 'prompt') {
                    if (/heißt|your name/i.test(msg)) await d.accept(this.name);
                    else if (this.promptAnswer !== undefined) await d.accept(this.promptAnswer);
                    else await d.accept(d.defaultValue());
                } else if (d.type() === 'confirm') {
                    if (this.confirmAnswer) await d.accept(); else await d.dismiss();
                } else {
                    await d.accept();
                }
            } catch (e) { /* Dialog schon weg */ }
        });
        page.exposeFunction('__gnCspViolation', v => this.error(`CSP violation: ${v}`)).catch(() => {});
        page.evaluateOnNewDocument(() => {
            document.addEventListener('securitypolicyviolation', e => {
                if (window.__gnCspViolation) window.__gnCspViolation(`${e.violatedDirective} ${e.blockedURI} ${e.sourceFile}:${e.lineNumber}`);
            });
        });
    }

    error(msg) {
        if (this.allowed.some(re => re.test(msg))) return;
        this.errors.push(msg);
    }

    /** Erwartete Fehler (z. B. 404 nach dem Löschen eines Tisches) nicht als Fehler werten. */
    allow(re) {
        this.allowed.push(re);
    }

    async goto(url) {
        await this.page.goto(url, { waitUntil: 'domcontentloaded' });
        await this.page.waitForFunction(() => document.getElementById('app') && document.getElementById('app').children.length > 0);
    }

    url() {
        return this.page.url();
    }

    async screenshot(label) {
        const file = path.join(OUT, `${String(++shotNo).padStart(3, '0')}-${(suite ? suite.name : 'x').replace(/\W+/g, '_')}-${label}-${this.name}.png`);
        await this.page.screenshot({ path: file, fullPage: true });
        return file;
    }

    async waitFor(fn, ...args) {
        return this.page.waitForFunction(fn, { timeout: TIMEOUT, polling: 100 }, ...args);
    }

    async waitText(text, selector = '#app') {
        try {
            // innerText: nur Sichtbares; textContent zusätzlich wegen text-transform (Großbuchstaben per CSS)
            await this.waitFor((sel, txt) => { const el = document.querySelector(sel); return !!el && (el.innerText.includes(txt) || (el.checkVisibility() && el.textContent.includes(txt))); }, selector, text);
        } catch (e) {
            throw new Error(`${this.name}: text “${text}” did not appear in ${selector}`);
        }
    }

    async hasText(text, selector = '#app') {
        return this.page.evaluate((sel, txt) => { const el = document.querySelector(sel); return !!el && el.innerText.includes(txt); }, selector, text);
    }

    async text(selector = '#app') {
        return this.page.evaluate(sel => { const el = document.querySelector(sel); return el ? el.innerText : ''; }, selector);
    }

    async exists(selector) {
        return this.page.evaluate(sel => !!document.querySelector(sel), selector);
    }

    async count(selector) {
        return this.page.evaluate(sel => document.querySelectorAll(sel).length, selector);
    }

    /**
     * Element anklicken (echter Mausklick an seiner Position). text: Teiltext, den das Element enthalten muss;
     * nth: n-tes passendes Element. Wartet, bis es da und nicht deaktiviert ist.
     */
    async click(selector, text = null, { nth = 0, exact = false, timeout = TIMEOUT } = {}) {
        const desc = `${selector}${text !== null ? ` “${text}”` : ''}${nth ? ` #${nth}` : ''}`;
        return this.clickFn((sel, txt, n, ex) => {
            const els = [...document.querySelectorAll(sel)].filter(e => !e.disabled && e.getClientRects().length
                && (txt === null || (ex ? e.textContent.trim() === txt : e.textContent.includes(txt))));
            return els[n] || null;
        }, desc, { timeout }, selector, text, nth, exact);
    }

    /** Wie click(), aber das Element sucht die Funktion finder (läuft im Browser, gibt Element oder null zurück). */
    async clickFn(finder, desc, { timeout = TIMEOUT } = {}, ...args) {
        let lastErr;
        for (let attempt = 0; attempt < 4; attempt++) {
            let handle;
            try {
                handle = await this.page.waitForFunction(finder, { timeout, polling: 100 }, ...args);
            } catch (e) {
                throw new Error(`${this.name}: nothing clickable: ${desc}`);
            }
            const el = handle.asElement();
            try {
                await el.evaluate(e => e.scrollIntoView({ block: 'center', inline: 'center' }));
                // Liegt etwas darüber (z. B. die Toast-Meldung)? Dann klickt auch ein Mensch daneben.
                const cover = await el.evaluate(e => {
                    const r = e.getBoundingClientRect();
                    const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
                    return top && top !== e && !e.contains(top) ? (top.id || top.className || top.tagName) : null;
                });
                if (cover) {
                    note(`${suite.name}: “${desc}” was covered by “${cover}” (${this.mobile ? 'mobile' : 'desktop'}) – waited before clicking`);
                    await this.page.waitForFunction(e => {
                        const r = e.getBoundingClientRect();
                        const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
                        return !top || top === e || e.contains(top);
                    }, { timeout: 6000, polling: 100 }, el).catch(() => {});
                }
                await el.click();
                return;
            } catch (e) {
                lastErr = e;   // z. B. Element durch Neuaufbau ersetzt: noch einmal suchen
                await sleep(150);
            }
        }
        throw new Error(`${this.name}: clicking ${desc} failed: ${lastErr && lastErr.message}`);
    }

    /** Kein waagrechtes Scrollen der Seite (auf Handybreite). */
    async checkNoHScroll(label) {
        const m = await this.page.evaluate(() => ({
            sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth,
            wide: [...document.querySelectorAll('body *')].filter(e => e.getBoundingClientRect().right > document.documentElement.clientWidth + 1
                && getComputedStyle(e).position !== 'fixed' && !e.closest('.zk-scroll'))
                .slice(0, 3).map(e => e.tagName.toLowerCase() + (e.className ? '.' + String(e.className).trim().split(/\s+/).join('.') : '')),
        }));
        if (m.sw > m.cw) {
            fail(`${label}: horizontal scrolling at ${this.page.viewport().width}px (scrollWidth ${m.sw} > clientWidth ${m.cw}; too wide: ${m.wide.join(', ')})`,
                [await this.screenshot('hscroll')]);
        }
    }

    // --- Lobby und Tische

    async createTable(gameName) {
        await this.waitFor(() => !!document.querySelector('.game-card'));
        const idx = await this.page.evaluate(n => [...document.querySelectorAll('.game-card .game-name')].findIndex(e => e.firstChild.textContent.trim() === n), gameName);
        if (idx < 0) throw new Error(`${this.name}: game card “${gameName}” not found`);
        await this.click('.game-card', null, { nth: idx });
        await this.page.waitForFunction(() => /^#r=[a-z0-9]+$/.test(location.hash), { timeout: TIMEOUT });
        await this.waitFor(() => !!document.querySelector('.room-head'));
        return this.url();
    }

    async roomId() {
        return this.page.evaluate(() => (location.hash.match(/^#r=([a-z0-9]+)/) || [])[1] || null);
    }

    /** Platz i nehmen (Spiele mit Platzliste: Vier in einer Reihe, Schach, Zahlenkampf). */
    async takeSeat(i) {
        await this.click(`.seats .seat:nth-child(${i + 1}) .btn.small`);
        await this.waitFor(n => { const s = document.querySelector(`.seats .seat:nth-child(${n + 1})`); return s && s.classList.contains('mine'); }, i);
    }

    async myTurn() {
        return this.page.evaluate(() => !!document.querySelector('.status.my-turn'));
    }

    async close() {
        this.closed = true;
        await this.context.close().catch(() => {});
    }
}

/** Fehler aller Personen dieser Testgruppe prüfen. */
async function checkErrors(players) {
    for (const p of players) {
        if (p.errors.length) {
            fail(`${p.name}: ${p.errors.length} browser error(s):\n        ${[...new Set(p.errors)].slice(0, 8).join('\n        ')}`);
            p.errors = [];
        }
    }
}

/** Warten, bis eine Bedingung für alle Personen gilt. */
async function all(players, fn) {
    await Promise.all(players.map(fn));
}

// ---------------------------------------------------------------------------
// Testgruppen

const suites = [];
function defineSuite(name, fn) {
    suites.push({ name, fn });
}

// --- Vier in einer Reihe -----------------------------------------------------

defineSuite('connect4', async (browser, players) => {
    const red = await Player.create(browser, 'Rita', { mobile: true });
    const yel = await Player.create(browser, 'Yann');
    players.push(red, yel);

    await red.goto(`${BASE}/a/`);
    await red.checkNoHScroll('lobby (mobile)');
    const url = await red.createTable('Vier in einer Reihe');
    await check(red.dialogs.some(d => /heißt/.test(d)) && red.dialogs.some(d => /Name des Tisches/.test(d)), 'name and table name were asked', [red]);
    await check(await red.count('.seat.active') === 0, 'no seat is highlighted while waiting for players', [red]);
    await yel.goto(url);
    await yel.takeSeat(1);
    await red.waitText('Yann');
    await red.waitFor(() => !!document.querySelector('.seat.active'));
    await check(!(await red.hasText('Warte, bis alle Plätze besetzt sind')), 'waiting notice disappears once both seats are taken', [red]);

    step('play until red wins (column 1 four times)');
    const seq = [[red, 0], [yel, 1], [red, 0], [yel, 1], [red, 0], [yel, 1], [red, 0]];
    for (const [p, col] of seq) {
        const other = p === red ? yel : red;
        await p.waitFor(() => !!document.querySelector('.status.my-turn'));
        await check(!(await other.myTurn()), `only ${p.name} has the turn`, [p, other]);
        // Klick des anderen in derselben Zeit darf nichts bewirken
        await other.click('.c4-col', null, { nth: 3 });
        await p.click('.c4-col', null, { nth: col });
        await p.waitFor(() => !document.querySelector('.status.my-turn'));
    }
    await all(players, p => p.waitText('Rita (Rot) gewinnt!'));
    for (const p of players) {
        await check(await p.count('.c4-cell.win') === 4, `${p.name} sees the four winning discs highlighted`, [p]);
        await check(await p.count('.c4-cell.red') === 4 && await p.count('.c4-cell.yellow') === 3, `${p.name} sees 4 red and 3 yellow discs (no stray disc from the off-turn clicks)`, [p]);
    }
    await red.checkNoHScroll('connect4 game over (mobile)');
    await snap([red], 'c4-over');
    await check(await red.exists('.controls .btn') && (await red.text('.controls')).includes('Neue Runde'), 'button “Neue Runde” is shown after the game', [red]);

    step('“Neue Runde”: yellow starts');
    await yel.click('.controls .btn', 'Neue Runde');
    await all(players, p => p.waitFor(() => !document.querySelector('.c4-cell.red, .c4-cell.yellow')));
    await yel.waitFor(() => !!document.querySelector('.status.my-turn'));
    await red.waitText('Yann (Gelb) ist dran');

    step('round 2: one move, then red resigns');
    await yel.click('.c4-col', null, { nth: 3 });
    await red.waitFor(() => !!document.querySelector('.status.my-turn'));
    await red.click('.controls .btn', 'Aufgeben');
    await all(players, p => p.waitText('Rita (Rot) gibt auf – Yann (Gelb) gewinnt.'));
    await check(red.dialogs.some(d => /Wirklich aufgeben/.test(d)), 'resign asks for confirmation', [red]);

    step('“Neu starten” in a running game asks first; dismissing keeps the game');
    await red.click('.controls .btn', 'Neue Runde');
    await all(players, p => p.waitFor(() => !document.querySelector('.c4-cell.red, .c4-cell.yellow')));
    await red.waitFor(() => !!document.querySelector('.status.my-turn'));
    await red.click('.c4-col', null, { nth: 2 });
    await yel.waitFor(() => !!document.querySelector('.status.my-turn'));
    yel.confirmAnswer = false;
    await yel.click('.controls .btn', 'Neu starten');
    await sleep(1500);
    await check(await yel.count('.c4-cell.red') === 1, '“Neu starten” cancelled → board unchanged', [yel]);
    yel.confirmAnswer = true;
});

// --- Schach zu viert ---------------------------------------------------------

defineSuite('handbrain', async (browser, players) => {
    const wb = await Player.create(browser, 'Wanda', { mobile: true });   // Weiß – Wählen
    const wh = await Player.create(browser, 'Walter');                   // Weiß – Ziehen
    const bb = await Player.create(browser, 'Berta');                    // Schwarz – Wählen
    const bh = await Player.create(browser, 'Bodo', { mobile: true });   // Schwarz – Ziehen
    players.push(wb, wh, bb, bh);
    const chooser = { w: wb, b: bb }, mover = { w: wh, b: bh };

    await wb.goto(`${BASE}/a/`);
    const url = await wb.createTable('Schach zu viert');
    for (const [p, seat] of [[wh, 1], [bb, 2], [bh, 3]]) {
        await p.goto(url);
        await p.takeSeat(seat);
    }
    await all(players, p => p.waitText('Bodo'));

    const NAMES = { p: 'Bauer', n: 'Springer', b: 'Läufer', r: 'Turm', q: 'Dame', k: 'König' };
    // Feldindex auf dem Brett der Person (Schwarz sieht das Brett gedreht)
    const sqIndex = (sq, flip) => {
        const c = sq.charCodeAt(0) - 97, r = 8 - Number(sq[1]);
        return (flip ? 7 - r : r) * 8 + (flip ? 7 - c : c);
    };

    async function halfMove(color, piece, from, to, { capture = false } = {}) {
        const ch = chooser[color], mv = mover[color];
        const others = players.filter(p => p !== ch);
        await ch.waitFor(() => !!document.querySelector('.status.my-turn'));
        for (const p of others) await check(!(await p.myTurn()), `${color} choose ${piece}: only ${ch.name} has the turn (not ${p.name})`, [p]);
        await ch.click('.piece-choice .piece-btn', NAMES[piece]);
        await mv.waitFor(() => !!document.querySelector('.status.my-turn'));
        await check((await mv.text('.status')).includes('Zieh '), `${mv.name} is told which piece to move`, [mv]);
        await check(!(await ch.myTurn()), `${ch.name} no longer has the turn after choosing`, [ch]);
        for (const p of players.filter(q => q !== mv)) await p.waitText(NAMES[piece], '.piece-choice .chosen');
        const flip = color === 'b';
        await mv.click('.chess-board .sq', null, { nth: sqIndex(from, flip) });
        await mv.waitFor(i => document.querySelectorAll('.chess-board .sq')[i].classList.contains('selected'), sqIndex(from, flip));
        const toCls = await mv.page.evaluate(i => document.querySelectorAll('.chess-board .sq')[i].className, sqIndex(to, flip));
        await check(toCls.includes('target'), `${from}-${to} is offered as target`, [mv]);
        if (capture) await check(toCls.includes('capture'), `${from}x${to} is marked as capture`, [mv]);
        await mv.click('.chess-board .sq', null, { nth: sqIndex(to, flip) });
        await mv.waitFor(() => !document.querySelector('.status.my-turn'));
    }

    step('initial position: only pawn and knight can be called');
    await wb.waitFor(() => !!document.querySelector('.status.my-turn'));
    const enabled = await wb.page.evaluate(() => [...document.querySelectorAll('.piece-btn')].filter(b => !b.disabled).map(b => b.textContent.trim()));
    await check(enabled.length === 2 && enabled.some(t => t.includes('Bauer')) && enabled.some(t => t.includes('Springer')),
        `only Bauer and Springer enabled at start (got ${enabled.join(', ')})`, [wb]);
    await wb.checkNoHScroll('chess (mobile, white)');

    step('white mover cannot move a knight when a pawn was called');
    await wb.click('.piece-choice .piece-btn', 'Bauer');
    await wh.waitFor(() => !!document.querySelector('.status.my-turn'));
    await wh.click('.chess-board .sq', null, { nth: sqIndex('g1', false) });
    await sleep(300);
    await check(!(await wh.exists('.chess-board .sq.selected')), 'knight cannot be selected after “Bauer” was called', [wh]);
    await wh.click('.chess-board .sq', null, { nth: sqIndex('e2', false) });
    await wh.waitFor(i => document.querySelectorAll('.chess-board .sq')[i].classList.contains('selected'), sqIndex('e2', false));
    await wh.click('.chess-board .sq', null, { nth: sqIndex('e4', false) });
    await wh.waitFor(() => !document.querySelector('.status.my-turn'));

    step("Scholar's mate: 1.e4 e5 2.Bc4 Nc6 3.Qh5 Nf6 4.Qxf7#");
    await halfMove('b', 'p', 'e7', 'e5');
    await bh.checkNoHScroll('chess (mobile, black)');
    await halfMove('w', 'b', 'f1', 'c4');
    await halfMove('b', 'n', 'b8', 'c6');
    await halfMove('w', 'q', 'd1', 'h5');
    await halfMove('b', 'n', 'g8', 'f6');
    await halfMove('w', 'q', 'h5', 'f7', { capture: true });
    await all(players, p => p.waitText('Schachmatt! Team Weiß gewinnt.'));
    await snap([wb, bh], 'mate');
    for (const p of players) {
        await check((await p.text('.movelist')).includes('Qxf7#'), `${p.name}: move list shows Qxf7#`, [p]);
        await check(!(await p.myTurn()), `${p.name}: nobody has the turn after mate`, [p]);
    }

    step('new round, then 1.e4 d5 2.exd5 Qxd5 (two captures), black resigns');
    await bb.click('.controls .btn', 'Neue Runde');
    await all(players, p => p.waitFor(() => !document.querySelector('.movelist')));
    await halfMove('w', 'p', 'e2', 'e4');
    await halfMove('b', 'p', 'd7', 'd5');
    await halfMove('w', 'p', 'e4', 'd5', { capture: true });
    await halfMove('b', 'q', 'd8', 'd5', { capture: true });
    for (const p of players) await p.waitText('Qxd5', '.movelist');
    await wb.waitFor(() => !!document.querySelector('.status.my-turn'));
    await bh.click('.controls .btn', 'Aufgeben');
    await all(players, p => p.waitText('Team Schwarz gibt auf – Team Weiß gewinnt.'));

    step('promotion: 1.e4 d5 2.exd5 c6 3.dxc6 Nf6 4.cxb7 Nbd7 5.bxa8=Q');
    await wh.click('.controls .btn', 'Neue Runde');
    await all(players, p => p.waitFor(() => !document.querySelector('.movelist')));
    await halfMove('w', 'p', 'e2', 'e4');
    await halfMove('b', 'p', 'd7', 'd5');
    await halfMove('w', 'p', 'e4', 'd5', { capture: true });
    await halfMove('b', 'p', 'c7', 'c6');
    await halfMove('w', 'p', 'd5', 'c6', { capture: true });
    await halfMove('b', 'n', 'g8', 'f6');
    await halfMove('w', 'p', 'c6', 'b7', { capture: true });
    await halfMove('b', 'n', 'b8', 'd7');
    await wb.waitFor(() => !!document.querySelector('.status.my-turn'));
    const pawnBtn = await wb.page.evaluate(() => [...document.querySelectorAll('.piece-btn')].find(b => b.textContent.includes('Bauer')).title);
    step(`pawn button title before promotion: “${pawnBtn}”`);
    await wb.click('.piece-choice .piece-btn', 'Bauer');
    await wh.waitFor(() => !!document.querySelector('.status.my-turn'));
    await wh.click('.chess-board .sq', null, { nth: sqIndex('b7', false) });
    await wh.click('.chess-board .sq', null, { nth: sqIndex('a8', false) });
    await wh.waitText('Umwandeln in:');
    await check(!(await wb.hasText('Umwandeln in:')), 'promotion choice only for the mover', [wb]);
    await wh.click('.piece-choice .piece-btn', 'Dame');
    await all(players, p => p.waitText('bxa8=Q', '.movelist'));
    await bb.waitFor(() => !!document.querySelector('.status.my-turn'));
});

// --- Zahlenkampf ---------------------------------------------------------------

defineSuite('zahlenkampf', async (browser, players) => {
    const ev = await Player.create(browser, 'Eva', { mobile: true });   // Gerade
    const od = await Player.create(browser, 'Otto');                    // Ungerade
    players.push(ev, od);

    await ev.goto(`${BASE}/a/`);
    const url = await ev.createTable('Zahlenkampfspiel');
    await od.goto(url);
    await od.takeSeat(1);
    await ev.waitText('Otto');

    await all(players, p => p.waitFor(() => !!document.querySelector('.zk-board')));
    await ev.checkNoHScroll('zahlenkampf board (mobile)');
    await snap([ev, od], 'zk-start');
    await check(await ev.count('.zk-bar') === 4, 'progress bars for bodies and goods on both sides', [ev]);

    // Zug im Browser der Person mit der Spiellogik (ZK) aussuchen: Schlagzug > zufällig
    let seed = 7;
    const rnd = () => { seed = (seed * 1103515245 + 12345) % 2147483648; return seed / 2147483648; };
    let captures = 0, moves = 0;
    while (moves < 10) {
        const p = moves % 2 === 0 ? ev : od;
        await p.waitFor(() => !!document.querySelector('.status.my-turn') || current.room.state.over);
        if (await p.page.evaluate(() => current.room.state.over)) break;
        const choice = await p.page.evaluate(r0 => {
            /* global current, ZK */
            let r = r0;
            const next = () => { r = (r * 9301 + 49297) % 233280 / 233280; return r; };
            const st = current.room.state, side = st.turn[0];
            const opts = [];
            for (const s of st.stones.filter(x => x.side === side)) {
                for (const m of ZK.movesFor(st, s)) opts.push({ id: s.id, from: [s.r, s.c], to: [m.r, m.c] });
            }
            const pick = o => ({ from: o.from, to: o.to, caps: ZK.applyMove(st, side, o.id, o.to[0], o.to[1]).captured.length, seat: current.mySeat });
            for (const o of opts) if (ZK.applyMove(st, side, o.id, o.to[0], o.to[1]).captured.length) return pick(o);
            return pick(opts[Math.floor(next() * opts.length)]);
        }, rnd());
        // Brett hochkant, eigenes Lager unten (siehe zahlenkampf.js)
        const cellIndex = ([r, c]) => (choice.seat === 0 ? (15 - r) * 8 + c : r * 8 + (7 - c));
        const before = await p.page.evaluate(() => current.room.version);
        await p.click('.zk-board .zk-cell', null, { nth: cellIndex(choice.from) });
        await p.waitFor(() => !!document.querySelector('.zk-stone.selected'));
        const cls = await p.page.evaluate(i => document.querySelectorAll('.zk-board .zk-cell')[i].className, cellIndex(choice.to));
        if (choice.caps) await check(cls.includes('capture'), 'capturing target square is marked red', [p]);
        await p.click('.zk-board .zk-cell', null, { nth: cellIndex(choice.to) });
        await p.waitFor(v => current.room.version > v, before);
        if (choice.caps) captures++;
        moves++;
    }
    step(`${moves} moves played, ${captures} capturing moves`);

    // Schlagen über die Oberfläche: vorbereitete Stellung (zufällig fällt selten etwas), dann zwei Schlagzüge per Klick
    step('prepared position: equality capture (the man leaves the board and is counted), then the 12th man → victory of bodies');
    const odVersion = await od.page.evaluate(() => current.room.version);
    await ev.waitFor(v => current.room.version >= v, odVersion);   // beide Browser auf demselben Stand
    await ev.page.evaluate(async () => {
        const st = ZK.initState();
        st.stones = [[0, 'rd', 36, 7, 3], [0, 'rd', 64, 7, 5], [0, 'rd', 2, 0, 0], [1, 'tri', 36, 9, 5], [1, 'sq', 64, 9, 7], [1, 'rd', 3, 15, 0]]
            .map(([side, shape, v, r, c], id) => ({ id, side, shape, v, r, c }));
        st.taken = [{ n: 10, sum: 500 }, { n: 0, sum: 0 }];   // zwei Schläge bis zum Sieg nach Körpern
        await act('move', { state: st, version: current.room.version, kind: 'reset' });
    });
    await all(players, q => q.waitFor(() => current.room.state.stones.length === 6));
    const play = async (p, from, to) => {
        const seat = await p.page.evaluate(() => current.mySeat);
        const idx = ([r, c]) => (seat === 0 ? (15 - r) * 8 + c : r * 8 + (7 - c));
        const before = await p.page.evaluate(() => current.room.version);
        await p.click('.zk-board .zk-cell', null, { nth: idx(from) });
        await p.waitFor(() => !!document.querySelector('.zk-stone.selected'));
        const cls = await p.page.evaluate(i => document.querySelectorAll('.zk-board .zk-cell')[i].className, idx(to));
        await p.click('.zk-board .zk-cell', null, { nth: idx(to) });
        await all(players, q => q.waitFor(v => current.room.version > v, before));
        return cls;
    };
    let cls = await play(ev, [7, 3], [8, 4]);
    await check(cls.includes('capture'), 'capturing target square is marked red', [ev]);
    await check(await od.page.evaluate(() => current.room.state.stones.length === 5 && current.room.state.taken[0].n === 11),
        'captured man leaves the board and is counted', [od]);
    await check((await od.text('.zk-score')).includes('11 / 12'), 'progress bar shows 11 / 12', [od]);
    captures++;
    await play(od, [15, 0], [14, 1]);
    cls = await play(ev, [7, 5], [8, 6]);
    await check(cls.includes('winning'), 'winning target square is marked', [ev]);
    captures++;

    await sleep(1500);
    const [l1, l2] = await Promise.all([ev, od].map(p => p.text('.zk-log')));
    await check(l1 === l2, 'both players see the same move log', [ev, od]);
    if (captures) await check(l1.includes('schlägt'), 'move log mentions a capture', [ev]);
    const over = await ev.page.evaluate(() => current.room.state.over);
    if (captures >= 2) await check(over && (await ev.text('.status')).includes('Körpern'), 'victory of bodies after 2 captured men', [ev]);
    await ev.checkNoHScroll('zahlenkampf after moves (mobile)');
    await snap([ev, od], 'zk');

    step('new round; resign');
    if (!over) await ev.click('.controls .btn', 'Aufgeben');
    await all(players, q => q.waitFor(() => current.room.state.over));
    await ev.click('.controls .btn.primary', 'Neue Runde');
    await all(players, q => q.waitFor(() => !current.room.state.over && current.room.state.stones.length === 48));
    await od.click('.controls .btn', 'Aufgeben');
    await all(players, q => q.waitText('gibt auf'));
    await check((await ev.text('.status')).includes('gewinnt'), 'winner is named after resign', [ev]);
});

// --- Trombone ------------------------------------------------------------------

defineSuite('trombone', async (browser, players) => {
    const A = await Player.create(browser, 'Anna', { mobile: true });
    const B = await Player.create(browser, 'Ben');
    const C = await Player.create(browser, 'Cleo');
    players.push(A, B, C);

    await A.goto(`${BASE}/a/`);
    const url = await A.createTable('Trombone');
    await A.waitText('Wer spielt mit?');
    for (const p of [B, C]) {
        await p.goto(url);
        await p.click('.tb .btn.primary', 'Mitspielen');
        await p.waitText('(du)');
    }
    await A.waitText('Cleo');
    await check((await A.text('.tb')).includes('Erst Kartenspiel wählen'), 'with 3 players a deck must be chosen first', [A]);
    await A.click('.tb-chip', '32 Karten');
    await B.waitFor(() => !!document.querySelector('.tb-chip.on'));
    await A.click('.tb .btn.primary', 'Spiel starten (3 Personen)');
    await all(players, p => p.waitText('Runde 1'));
    await A.checkNoHScroll('trombone bidding (mobile)');

    const nameOf = new Map(players.map(p => [p, p.name]));
    for (const p of players) {
        await check(await p.count('.tb-hand .tb-card') === 1, `${nameOf.get(p)} has 1 card in round 1`, [p]);
    }

    /** Wer ist gerade dran (sieht die Knöpfe zum Ansagen)? Prüft dabei, dass es nur eine Person ist. */
    async function whoseTurn(active) {
        const deadline = Date.now() + TIMEOUT;
        for (;;) {
            const flags = await Promise.all(active.map(p => p.exists('.tb-actions')));
            const who = active.filter((p, i) => flags[i]);
            if (who.length === 1) return who[0];
            if (who.length > 1) {
                await check(false, `several players can act at once: ${who.map(p => p.name)}`, who);
                return who[0];
            }
            if (Date.now() > deadline) throw new Error('nobody gets the turn in trombone');
            await sleep(150);
        }
    }

    /** Kleinstmögliche Ansage über die Knöpfe zusammenstellen und abgeben. */
    async function makeBid(who) {
        const bidsBefore = await who.page.evaluate(() => current.room.state.bids.length);
        await who.click('.tb-composer .tb-chips .tb-chip');
        await who.waitFor(() => !!document.querySelector('.tb-composer .tb-chip.on'));
        const rows = await who.count('.tb-composer .tb-rankrow');
        for (let row = 0; row < rows; row++) {
            await who.clickFn(r => [...document.querySelectorAll('.tb-composer .tb-rankrow')[r].querySelectorAll('.tb-rankbtn')].find(b => !b.disabled) || null,
                `rank button in row ${row}`, {}, row);
            await who.waitFor(r => !!document.querySelectorAll('.tb-composer .tb-rankrow')[r].querySelector('.tb-rankbtn.on'), row);
        }
        await who.click('.tb-composer .btn.primary', 'Ansagen: ');
        await who.waitFor(n => current.room.state.bids.length > n || current.room.state.phase !== 'bidding', bidsBefore);
    }

    /** Eine Runde: `bids` Ansagen (immer die kleinstmögliche), dann „Trombone!“. Gibt zurück, wer angezweifelt hat. */
    async function playRound(active, { bids = 2 } = {}) {
        for (let made = 0; ; made++) {
            const who = await whoseTurn(active);
            const hasBid = await who.page.evaluate(() => current.room.state.bids.length > 0);
            if (made >= bids && hasBid) {
                const order = await who.page.evaluate(() => {
                    const c = document.querySelector('.tb-composer'), b = document.querySelector('.tb-trombone');
                    return !!(c.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING);
                });
                await check(order, 'bid composer comes before the Trombone button', [who]);
                await who.click('.tb-trombone');
                return who;
            }
            await makeBid(who);
        }
    }

    async function revealAndNext(active, watchers, challenger) {
        // Auflösung dieser Runde (nicht die zugeklappte der vorigen Runde in details.tb-last)
        await all([...active, ...watchers], p => p.waitFor(() => ['reveal', 'over'].includes(current.room.state.phase)
            && [...document.querySelectorAll('.tb-reveal')].some(e => !e.closest('.tb-last'))));
        const verdicts = await Promise.all(active.map(p => p.text('.tb-verdict')));
        await check(!(await active[0].text('.tb-reveal')).includes('Gefunden'), 'reveal has no “Gefunden” line', active.slice(0, 1));
        await snap(active.slice(0, 1), 'reveal');
    await check(new Set(verdicts).size === 1, `all players see the same verdict (${verdicts.join(' | ')})`, active);
        for (const p of active.filter(q => q !== challenger)) {
            // Hinweis „… ruft Trombone!“ als Toast
            const ok = await p.waitFor(n => document.getElementById('toast').textContent.includes(n + ' ruft Trombone!'), challenger.name).then(() => true, () => false);
            await check(ok, `${p.name} gets the notice “${challenger.name} ruft Trombone!”`, [p]);
        }
        const over = await active[0].page.evaluate(() => current.room.state.phase === 'over');
        if (over) return true;
        const round = await active[0].page.evaluate(() => current.room.state.round);
        await active[1].click('.tb-main .btn.primary', 'Nächste Runde');
        await all(active, p => p.waitText(`Runde ${round + 1}`, '.tb-info'));
        return false;
    }

    step('bids of this round: collapsed (last bids are in the player list), stay open once opened; bids start with a capital letter');
    {
        // „Ansagen dieser Runde“ (im Browser gesucht; eval verbietet die CSP der Seite)
        const BOX = 'details.tb-history:not(.old):not(.tb-last)';
        const first = await whoseTurn(players);
        await makeBid(first);
        const w = players.find(p => p !== first);
        await w.waitFor(sel => current.room.state.bids.length === 1 && !!document.querySelector(sel), BOX);
        const st = await w.page.evaluate(sel => ({ open: document.querySelector(sel).open, bid: document.querySelector('.tb-current .tb-bid').textContent }), BOX);
        await check(!st.open, '“Ansagen dieser Runde” is collapsed', [w]);
        await check(/^[A-ZÄÖÜ0-9]/.test(st.bid), `current bid starts with a capital letter (“${st.bid}”)`, [w]);
        await w.page.evaluate(sel => document.querySelector(sel + ' summary').click(), BOX);
        await makeBid(await whoseTurn(players));
        await w.waitFor(() => current.room.state.bids.length === 2);
        await sleep(300);
        await check(await w.page.evaluate(sel => document.querySelector(sel).open, BOX), '“Ansagen dieser Runde” stays open after the next bid', [w]);
    }

    step('round 1: two bids, Trombone!, reveal; everyone clicks “Nächste Runde” at the same time');
    let challenger = await playRound(players);
    await all(players, p => p.waitFor(() => current.room.state.phase === 'reveal' && !!document.querySelector('.tb-main .btn.primary')));
    await Promise.all(players.map(p => p.page.evaluate(() => {
        const b = [...document.querySelectorAll('.tb-main .btn.primary')].find(e => e.textContent.includes('Nächste Runde'));
        if (b) b.click();
    })));
    await all(players, p => p.waitText('Runde 2', '.tb-info'));
    await sleep(2000);
    for (const p of players) await check((await p.text('.tb-info')).includes('Runde 2'), `${p.name}: simultaneous “Nächste Runde” advances exactly one round`, [p]);
    await all(players, p => p.waitFor(() => !!document.querySelector('.tb-last')));

    step('round 2: one bid, then Anna removes Cleo');
    await makeBid(await whoseTurn(players));
    await A.clickFn(() => {
        const row = [...document.querySelectorAll('.tb-side .tb-player')].find(e => e.textContent.includes('Cleo'));
        return row && row.querySelector('.tb-remove');
    }, 'remove Cleo');
    await all(players, p => p.waitText('raus', '.tb-side'));
    await check(A.dialogs.some(d => /Cleo aus diesem Spiel nehmen/.test(d)), 'removing asks for confirmation', [A]);
    await C.waitText('Person aufnehmen', '.tb-extras');
    await check(!(await C.exists('.tb-hand')), 'removed player no longer sees cards', [C]);
    await check(await A.page.evaluate(() => current.room.state.bids.length === 0), 'bids start over after removing', [A]);

    step('Anna proposes to add Cleo again with 2 cards, Ben agrees');
    await A.click('.tb-side .btn', '+ Person aufnehmen');
    await A.click('.tb-add .tb-chip', 'Cleo');
    await A.click('.tb-add .tb-rankbtn', '2', { exact: true });
    await A.click('.tb-add .btn.primary', 'Cleo mit 2 vorschlagen');
    await B.waitText('Anna möchte Cleo mit 2 Karten aufnehmen.');
    await C.waitText('Anna möchte dich mit 2 Karten aufnehmen.');
    await check(!(await C.exists('.tb-vote')), 'the proposed person cannot vote', [C]);
    await B.click('.tb-vote .btn.primary', 'Zustimmen');
    await all(players, p => p.waitText('Cleo wurde aufgenommen.'));
    await C.waitText('steigt nächste Runde ein', '.tb-side');
    await C.waitText('Du bist aufgenommen');

    step('play the round between Anna and Ben, then Cleo plays again with 2 cards');
    challenger = await playRound([A, B], { bids: 1 });
    await revealAndNext([A, B], [C], challenger);
    await C.waitFor(() => !!document.querySelector('.tb-hand'));
    await check(await C.count('.tb-hand .tb-card') === 2, 'Cleo rejoins with 2 cards', [C]);

    step('play rounds until somebody loses the game');
    let rounds = 0, over = false;
    while (!over && rounds < 40) {
        challenger = await playRound(players, { bids: 1 + (rounds % 2) });
        over = await revealAndNext(players, [], challenger);
        rounds++;
        const counts = await A.page.evaluate(() => current.room.state.players.map(p => p.cards));
        if (counts.some(c => c > 6)) { await check(false, `more than 6 cards: ${counts}`, players); break; }
    }
    await check(over, 'game ends with a loser', players);
    await all(players, p => p.waitText('verliert das Spiel!', '.tb-status'));
    await A.checkNoHScroll('trombone game over (mobile)');
    step(`game over after ${rounds + 3} rounds`);

    step('“Neues Spiel” returns to the start screen');
    await B.click('.tb-over .btn.primary', 'Neues Spiel');
    await all(players, p => p.waitText('Wer spielt mit?'));
});

// --- Trombone mit Bots -----------------------------------------------------------

defineSuite('trombone-bot', async (browser, players) => {
    const A = await Player.create(browser, 'Anna', { mobile: true });
    const B = await Player.create(browser, 'Bot');   // ein Mensch, der sich „Bot“ nennt: bekommt kein Abzeichen
    players.push(A, B);
    const badges = (p, sel) => p.count(`${sel} .bot-badge`);
    /** Zeilen der Liste der Mitspielenden: Name (ohne Abzeichen und „(du)“) und ob ein Abzeichen dabeisteht. */
    const rows = p => p.page.evaluate(() => [...document.querySelectorAll('.tb-players .tb-player')].map(r => ({
        name: r.querySelector('.tb-pname').firstChild.textContent,
        badge: !!r.querySelector('.bot-badge'),
        title: (r.querySelector('.bot-badge') || {}).title || null,
    })));

    await A.goto(`${BASE}/a/`);
    const url = await A.createTable('Trombone');
    await A.waitText('Wer spielt mit?');

    step('add two bots; spectators see them but cannot change them');
    await A.click('.tb .tb-addbot', 'Bot hinzufügen');
    await A.waitFor(() => document.querySelectorAll('.tb-players .bot-badge').length === 1);
    await check((await A.text('.tb')).includes('Spiel starten (1 Person + 1 Bot)'), 'start button counts people and bots', [A]);
    await A.click('.tb .tb-addbot', 'Bot hinzufügen');
    await A.waitFor(() => document.querySelectorAll('.tb-players .bot-badge').length === 2);
    await B.goto(url);
    await B.waitFor(() => document.querySelectorAll('.tb-players .bot-badge').length === 2);
    await check(!(await B.exists('.tb-addbot')) && !(await B.exists('.tb-players .tb-remove')), 'a spectator has no buttons to add or remove bots', [B]);
    await B.click('.tb .btn.primary', 'Mitspielen');
    await B.waitText('(du)');
    await A.waitFor(() => document.querySelectorAll('.tb-players .tb-player').length === 4);

    step('badge only for the server’s bots, not for the human called “Bot”');
    for (const p of players) {
        const list = await rows(p);
        const bots = list.filter(r => r.badge), humans = list.filter(r => !r.badge);
        await check(bots.length === 2 && humans.map(r => r.name).sort().join() === 'Anna,Bot', `${p.name} sees the badge at the two bots only (${JSON.stringify(list)})`, [p]);
        await check(bots.every(r => r.title === 'TromboneZero (T0)'), 'badge tooltip names the model', [p]);
        await check(bots.length === 2 && bots[0].name !== bots[1].name && bots.every(r => [...r.name].length === 1 && !/[A-Za-z0-9]/.test(r.name)), `bots are named with different emojis (${bots.map(r => r.name)})`, [p]);
    }
    await A.checkNoHScroll('trombone lobby with bots (mobile)');

    step('remove a bot and add one again (by the other player)');
    await A.click('.tb-players .tb-remove[title="Bot entfernen"]');
    await all(players, p => p.waitFor(() => document.querySelectorAll('.tb-players .bot-badge').length === 1));
    await check(!A.dialogs.some(d => /^confirm/.test(d)), 'removing a bot before the start needs no confirmation', [A]);
    await B.click('.tb .tb-addbot', 'Bot hinzufügen');
    await all(players, p => p.waitFor(() => document.querySelectorAll('.tb-players .bot-badge').length === 2));
    await snap([A, B], 'bot-lobby');

    step('start; play until the round is revealed (bots move by themselves)');
    await A.click('.tb .btn.primary', 'Spiel starten (2 Personen + 2 Bots)');
    await all(players, p => p.waitText('Runde 1'));
    for (const p of players) await check(await badges(p, '.tb-side .tb-players') === 2, `${p.name}: player list of the game shows two badges`, [p]);

    step('propose a new bot during the game; the other human declines');
    await A.click('.tb .btn', '+ Person aufnehmen');
    await A.click('.tb-add .tb-newbot');
    await A.clickFn(() => [...document.querySelectorAll('.tb-add .tb-rankbtn')][0] || null, 'one card');
    await A.click('.tb-add .btn.primary', 'neuen Bot mit 1 vorschlagen');
    await B.waitFor(() => !!document.querySelector('.tb-proposal .bot-badge'));
    await check(await B.page.evaluate(() => current.room.seats.filter(s => s && s.bot).length === 3 && current.room.state.players.length === 4),
        'the proposed bot sits at the table but does not play yet', [B]);
    await B.clickFn(() => [...document.querySelectorAll('.tb-proposal .tb-vote .btn')].find(b => !b.classList.contains('primary')) || null, 'decline');
    await all(players, p => p.waitFor(() => !current.room.state.proposal && current.room.seats.filter(s => s && s.bot).length === 2));
    await check(!A.dialogs.some(d => /^alert/.test(d)), 'declining a new bot removes it again without an error', [A]);

    /** Bis zur Auflösung spielen: Menschen sagen das Kleinstmögliche an und zweifeln an, sobald ein Bot angesagt hat. */
    async function playRound() {
        let sawBotBid = false;
        const deadline = Date.now() + 90000;
        for (;;) {
            if (Date.now() > deadline) throw new Error('round with bots does not end');
            const st = await A.page.evaluate(() => {
                const s = current.room.state, last = s.bids[s.bids.length - 1];
                return { phase: s.phase, n: s.bids.length, lastBot: !!last && !!(s.players.find(p => p.seat === last.seat) || {}).bot };
            });
            if (st.phase !== 'bidding') return sawBotBid;
            const flags = await Promise.all(players.map(p => p.exists('.tb-actions')));
            const who = players.find((p, i) => flags[i]);
            if (!who) { await sleep(150); continue; }   // ein Bot ist dran: Er zieht bei einer der nächsten Abfragen
            const view = await who.page.evaluate(() => {
                const s = current.room.state, last = s.bids[s.bids.length - 1];
                return {
                    n: s.bids.length, lastBot: !!last && !!(s.players.find(p => p.seat === last.seat) || {}).bot,
                    canBid: [...document.querySelectorAll('.tb-composer .tb-cats .tb-chip')].some(b => !b.disabled),
                    curBadge: !!document.querySelector('.tb-curline .bot-badge'),
                    listBadges: document.querySelectorAll('details.tb-history:not(.old):not(.tb-last) ol li b .bot-badge').length,
                    botBids: s.bids.filter(b => (s.players.find(p => p.seat === b.seat) || {}).bot).length,
                };
            });
            if (view.n && view.lastBot) {
                sawBotBid = true;
                await check(view.curBadge, 'current bid of a bot carries the badge', [who]);
            } else if (view.n) {
                await check(!view.curBadge, 'current bid of a human has no badge', [who]);
            }
            await check(view.listBadges === view.botBids, `bid list marks exactly the bots’ bids (${view.listBadges} badges, ${view.botBids} bot bids)`, [who]);
            if (view.n && (view.lastBot || !view.canBid)) {
                await who.click('.tb-trombone');
            } else {
                await who.click('.tb-composer .tb-cats .tb-chip');
                await who.waitFor(() => !!document.querySelector('.tb-composer .tb-chip.on'));
                const rowCount = await who.count('.tb-composer .tb-rankrow');
                for (let row = 0; row < rowCount; row++) {
                    await who.clickFn(r => [...document.querySelectorAll('.tb-composer .tb-rankrow')[r].querySelectorAll('.tb-rankbtn')].find(b => !b.disabled) || null,
                        `rank button in row ${row}`, {}, row);
                    await who.waitFor(r => !!document.querySelectorAll('.tb-composer .tb-rankrow')[r].querySelector('.tb-rankbtn.on'), row);
                }
                await who.click('.tb-composer .btn.primary', 'Ansagen: ');
            }
            await who.waitFor(n => current.room.state.bids.length > n || current.room.state.phase !== 'bidding', view.n);
        }
    }

    /** Auflösung: Abzeichen an den Händen der Bots, in den Sätzen darüber und im Verlauf genau bei den Bots. */
    async function checkReveal() {
        await all(players, p => p.waitFor(() => current.room.state.phase === 'reveal' && !!document.querySelector('.tb-main .tb-reveal')));
        for (const p of players) {
            const v = await p.page.evaluate(() => {
                const s = current.room.state, r = s.reveal;
                const bot = seat => ((s.players.find(q => q.seat === seat) || {}).bot ? 1 : 0);
                const box = document.querySelector('.tb-main .tb-reveal');
                const hist = [...document.querySelectorAll('details.tb-history')].find(d => d.querySelector('summary').textContent === 'Bisherige Runden');
                const h0 = s.history[0];
                return {
                    hands: box.querySelectorAll('.tb-onehand').length, handBadges: box.querySelectorAll('.tb-onehand .bot-badge').length,
                    handBots: Object.keys(r.hands).filter(seat => bot(+seat)).length,
                    textBadges: box.querySelectorAll('.bot-badge').length - box.querySelectorAll('.tb-onehand .bot-badge').length,
                    // „X zweifelt an“, „von Y“, „Z verliert …, W beginnt“
                    textBots: bot(r.challenger) + bot(r.challenged) + bot(r.loser) + bot(r.winner),
                    histBadges: hist ? hist.querySelectorAll('li')[0].querySelectorAll('.bot-badge').length : -1,
                    histBots: bot(h0.challenger) + bot(h0.challenged) + bot(h0.loser),
                };
            });
            await check(v.hands === 4 && v.handBadges === 2 && v.handBots === 2, `${p.name}: revealed hands show the badge at the two bots (${JSON.stringify(v)})`, [p]);
            await check(v.textBadges === v.textBots, `${p.name}: reveal text marks exactly the bots (${v.textBadges} badges, ${v.textBots} expected)`, [p]);
            await check(v.histBadges === v.histBots, `${p.name}: round history marks exactly the bots (${v.histBadges} badges, ${v.histBots} expected)`, [p]);
        }
    }

    let sawBotBid = false;
    for (let round = 1; round <= 3; round++) {
        sawBotBid = (await playRound()) || sawBotBid;
        await checkReveal();
        if (round === 1) {
            await A.checkNoHScroll('trombone reveal with bots (mobile)');
            await snap([A, B], 'bot-reveal');
        }
        await sleep(1500);
        await check(await A.page.evaluate(() => current.room.state.phase === 'reveal'), 'bots never click “Nächste Runde”', [A]);
        await B.click('.tb-main .btn.primary', 'Nächste Runde');
        await all(players, p => p.waitText(`Runde ${round + 1}`, '.tb-info'));
    }
    await check(sawBotBid, 'a bot made a bid within three rounds', players);

    step('remove a bot from the running game; it can be proposed again and does not vote');
    const botName = (await rows(A)).find(r => r.badge).name;
    await A.clickFn(() => {
        const row = [...document.querySelectorAll('.tb-side .tb-player')].find(e => e.querySelector('.bot-badge') && !e.classList.contains('out'));
        return row && row.querySelector('.tb-remove');
    }, 'remove a bot');
    await all(players, p => p.waitFor(() => document.querySelectorAll('.tb-side .tb-player.out .bot-badge').length === 1));
    await check(A.dialogs.some(d => d.startsWith('confirm') && d.includes(`${botName} aus diesem Spiel nehmen`)), 'removing a bot from the game asks for confirmation', [A]);
    // Vorschlagen kann, wer in der Runde mitspielt – unabhängig davon, wer gerade am Zug ist
    await A.click('.tb-side .btn', '+ Person aufnehmen');
    await check(await badges(A, '.tb-add .tb-chips') === 1, 'the removed bot is offered with its badge', [A]);
    await A.click('.tb-add .tb-chip', botName);
    await A.click('.tb-add .tb-rankbtn', '1', { exact: true });
    await A.click('.tb-add .btn.primary', 'vorschlagen');
    await B.waitText('Anna möchte');
    await check(await badges(B, '.tb-proposal') === 1 && (await B.text('.tb-proposal')).includes('Zustimmung: 1 von 2'), 'proposal shows the badge; only the two humans vote', [B]);
    await B.click('.tb-vote .btn.primary', 'Zustimmen');
    await all(players, p => p.waitText('wurde aufgenommen.'));
    await all(players, p => p.waitFor(() => document.querySelectorAll('.tb-side .tb-player.joining .bot-badge').length === 1));

    step('abort: the bots stay at the table for the next game');
    await A.click('.tb-extras .link', 'Spiel abbrechen');
    await all(players, p => p.waitText('Wer spielt mit?'));
    for (const p of players) await check(await badges(p, '.tb-players') === 2, `${p.name}: both bots are still seated`, [p]);

    step('instance without weight file: no bots offered');
    await A.goto(`${BASE}/b/`);
    await A.createTable('Trombone');
    await A.waitText('Wer spielt mit?');
    await check(!(await A.exists('.tb-addbot')), 'no “Bot hinzufügen” without a weight file', [A]);
});

// --- Wer bin ich? ----------------------------------------------------------------

defineSuite('werbinich', async (browser, players) => {
    const A = await Player.create(browser, 'Alma', { mobile: true });
    const B = await Player.create(browser, 'Bruno');
    const C = await Player.create(browser, 'Carla');
    players.push(A, B, C);

    await A.goto(`${BASE}/a/`);
    const url = await A.createTable('Wer bin ich?');
    await check(A.dialogs.some(d => /Wer bin ich mit Alma/.test(d)), 'default table name uses tableName “Wer bin ich”', [A]);
    for (const p of [B, C]) {
        await p.goto(url);
        await p.click('.tb .btn.primary', 'Mitspielen');
        await p.waitText('(du)');
    }
    await A.waitText('Carla');
    await A.click('.tb .btn.primary', 'Spiel starten (3 Personen)');
    await all(players, p => p.waitText('Begriffe aussuchen'));
    await A.checkNoHScroll('werbinich choosing (mobile)');

    const TERMS = { Alma: 'Albert Einstein', Bruno: 'Marie Curie', Carla: 'Ada Lovelace' };
    const targetOf = {};

    step('everyone picks a person');
    for (const [i, p] of players.entries()) {
        // erst wählen, wenn die Wahl der anderen angekommen ist (sonst lehnt der Server womöglich ab)
        await p.waitFor(n => (current.room.state.picks || []).length >= n, i);
        await p.click('.tb-composer .tb-chip');
        await p.waitFor(() => !!document.getElementById('wb-term'));
        targetOf[p.name] = await p.page.evaluate(() => {
            const pick = current.room.state.picks.find(x => x.by === current.room.state.meSeat);
            return current.room.state.players.find(x => x.seat === pick.target).name;
        });
    }
    await check(new Set(Object.values(targetOf)).size === 3 && Object.entries(targetOf).every(([a, b]) => a !== b),
        `everyone has a different target, nobody themselves (${JSON.stringify(targetOf)})`, players);
    // Beispiel im Eingabefeld: eine Person aus der Liste, die bei Aktualisierungen stehen bleibt
    const example = () => players[0].page.evaluate(() => document.getElementById('wb-term').placeholder);
    const ex1 = await example();
    await sleep(2500);   // mehrere Abfragen des Servers
    await check(/^z\. B\. \S/.test(ex1) && ex1 === await example(), `example in the input stays the same (“${ex1}”)`, players.slice(0, 1));

    step('Carla switches to another person and back (“andere Person wählen”)');
    const first = targetOf.Carla;
    await C.click('.tb-composer .link', 'andere Person wählen');
    await C.waitFor(() => !document.getElementById('wb-term'));
    await all([A, B], p => p.waitFor(() => current.room.state.picks.length === 2));
    await C.click('.tb-composer .tb-chip', first);
    await C.waitFor(() => !!document.getElementById('wb-term'));

    step('typing is not interrupted by updates of the others (holdRender)');
    const input = await A.page.$('#wb-term');
    await input.click();
    await A.page.keyboard.type('Albert Ein', { delay: 30 });
    // Bruno speichert inzwischen – das ändert den Stand für alle
    await B.page.type('#wb-term', TERMS.Bruno);
    await B.click('.tb-composer .btn.primary', 'Begriff speichern');
    await B.waitText('Warte auf die anderen');
    await sleep(2500);   // mehrere Abfragen bei Alma
    await A.page.keyboard.type('stein', { delay: 30 });
    const val = await A.page.evaluate(() => ({ v: document.getElementById('wb-term').value, focus: document.activeElement && document.activeElement.id }));
    await check(val.v === 'Albert Einstein' && val.focus === 'wb-term', `input survives updates while typing (value “${val.v}”, focus ${val.focus})`, [A]);
    await A.page.keyboard.press('Enter');
    await A.waitText('Warte auf die anderen');
    await check((await A.text('.tb-main')).includes('Albert Einstein'), 'Alma sees her saved term', [A]);
    await A.waitText('2 von 3 fertig');

    step('Carla writes her term, then changes it');
    await C.page.type('#wb-term', 'Ada Love');
    await C.click('.tb-composer .btn.primary', 'Begriff speichern');
    // letzter Begriff → Raten beginnt sofort; Ändern geht dann über die Karte der Zielperson
    await all(players, p => p.waitText('Noch zu erraten'));
    await C.click('.wb-line .wb-by .link', 'ändern');
    await C.waitFor(() => !!document.getElementById('wb-term'));
    await C.page.$eval('#wb-term', e => e.select());
    await C.page.keyboard.type(TERMS.Carla);
    await C.click('.wb-edit .btn.primary', 'Änderung speichern');
    await all(players.filter(p => targetOf.Carla !== p.name), p => p.waitText(TERMS.Carla));

    step('secrets: nobody sees their own term');
    for (const p of players) {
        const writer = Object.keys(targetOf).find(w => targetOf[w] === p.name);
        const own = TERMS[writer];
        const visible = await p.text('body');
        await check(!visible.includes(own), `${p.name} must not see own term “${own}”`, [p]);
        const raw = await p.page.evaluate(() => JSON.stringify(current.room.state));
        await check(!raw.includes(own), `${p.name}'s browser must not even receive own term “${own}”`, [p]);
        for (const [w, term] of Object.entries(TERMS)) {
            if (w !== writer) await check(visible.includes(term), `${p.name} sees “${term}”`, [p]);
        }
    }
    const orders = await Promise.all(players.map(p => p.page.evaluate(() =>
        [...document.querySelectorAll('.wb-list .wb-name')].map(e => e.textContent.replace(/ \((du|you)\)$/, '')).join(','))));
    await check(new Set(orders).size === 1, `everyone sees the players in the same order (${orders.join(' | ')})`, players);
    await A.checkNoHScroll('werbinich playing (mobile)');
    await snap([A], 'wb-playing');

    step('everyone marks “Erraten!”');
    for (const [i, p] of players.entries()) {
        await p.click('.wb-line.me .btn.primary', 'Erraten!');
        if (i < players.length - 1) {
            await p.waitText('Du hast deinen Begriff erraten!');
            const writer = Object.keys(targetOf).find(w => targetOf[w] === p.name);
            await check((await p.text('.tb-side')).includes(TERMS[writer]), `${p.name} sees own term after guessing`, [p]);
            const other = players[(i + 1) % players.length];
            const ok = await other.waitFor(n => document.getElementById('toast').textContent.includes(n + ' hat erraten'), p.name).then(() => true, () => false);
            await check(ok, `${other.name} gets the notice “${p.name} hat erraten”`, [other]);
        }
    }
    await all(players, p => p.waitText('Alle haben ihren Begriff erraten!'));
    for (const p of players) {
        const txt = await p.text('#app');
        await check(Object.values(TERMS).every(t => txt.includes(t)), `${p.name} sees all terms at the end`, [p]);
    }
    await A.checkNoHScroll('werbinich over (mobile)');
    await C.click('.tb .btn.primary', 'Neues Spiel');
    await all(players, p => p.waitText('Wer spielt mit?'));
});

// --- Übersicht, Links, Löschen (Instanz mit Tischliste) -------------------------------

// --- Grenzfälle: lange Namen/Begriffe auf dem Handy, Umbenennen, Aufstehen, Zuschauen --------

// --- Mathekonferenz ---------------------------------------------------------------------

defineSuite('konferenz', async (browser, players) => {
    const names = ['Ada', 'Bernd', 'Carla', 'Dieter', 'Emmy', 'Felix'];
    for (const [i, n] of names.entries()) players.push(await Player.create(browser, n, { mobile: i < 2 }));
    const [A] = players;

    await A.goto(`${BASE}/a/`);
    const url = await A.createTable('Mathekonferenz');
    await A.waitText('Wer kommt zur Konferenz?');
    for (const p of players.slice(1)) {
        await p.goto(url);
        await p.click('.tb .btn.primary', 'Mitspielen');
        await p.waitText('(du)');
    }
    await A.waitText('Felix');
    step('role setup: suggestion for 6 players, roles can be changed');
    const chips = await A.page.evaluate(() => [...document.querySelectorAll('.kf-roles .tb-chip.on')].map(e => e.textContent));
    await check(chips.length === 5, `5 special roles suggested for 6 players (${chips.join(', ')})`, [A]);
    await A.click('.kf-roles .tb-chip.on', 'Hausmeister');   // abwählen → dafür eine Mathematiker*in
    await A.waitText('Dazu 1 Mathematiker*in');
    await A.click('.kf-roles .tb-chip', 'Physiker');
    await A.waitFor(() => !!document.querySelector('.kf-roles .tb-chip.on') && [...document.querySelectorAll('.kf-roles .tb-chip.on')].some(e => e.textContent.includes('Physiker')));
    await A.checkNoHScroll('konferenz setup (mobile)');
    await A.click('.tb .btn.primary', 'Konferenz eröffnen (6 Personen)');
    await all(players, p => p.waitText('Deine Rolle'));
    await A.checkNoHScroll('konferenz night (mobile)');
    await snap(players.slice(0, 2), 'kf-night1');

    // Was sieht wer? Eigene Rolle, Mitkonstruktivist*innen – sonst keine Rollen Lebender
    for (const p of players) {
        const st = await p.page.evaluate(() => current.room.state);
        const mine = st.me.konstr;
        const seen = st.players.filter(x => x.alive && x.role && x.seat !== st.me.seat && !(mine && x.konstr));
        await check(!seen.length, `${p.name} sees only allowed roles`, [p]);
    }

    step('play the conference through the buttons until someone wins');
    const rowButton = async (p, name, label) => p.clickFn((n, l) => {
        const row = [...document.querySelectorAll('.kf-row')].find(r => r.querySelector('.kf-name').firstChild.textContent === n);
        return row && [...row.querySelectorAll('.kf-act button')].find(b => b.textContent.includes(l) && !b.disabled) || null;
    }, `${label} at ${name}`, {}, name, label);
    let rounds = 0;
    for (let step_ = 0; step_ < 200; step_++) {
        // Jeder Browser fragt etwa 1×/s nach: erst entscheiden, wenn alle denselben Stand haben
        const deadline = Date.now() + TIMEOUT;
        let states;
        for (;;) {
            const vs = await Promise.all(players.map(p => p.page.evaluate(() => ({ v: current.room.version, s: current.room.state }))));
            if (vs.every(x => x.v === vs[0].v)) { states = vs.map(x => x.s); break; }
            if (Date.now() > deadline) throw new Error('browsers do not agree on the game state');
            await sleep(200);
        }
        const st = states[0];
        if (st.phase === 'over') break;
        rounds = st.round;
        const alive = st.players.filter(x => x.alive);
        let acted = false;
        for (const [i, p] of players.entries()) {
            const me = states[i].me;
            if (st.phase === 'night' && me.alive && me.tasks.length) {
                const task = me.tasks[0];
                const others = states[i].players.filter(x => x.alive && x.seat !== me.seat);
                if (task === 'konstr') {
                    const target = others.find(x => !x.konstr);
                    await rowButton(p, target.name, 'vorschlagen');
                    await rowButton(p, target.name, 'auswählen');
                } else if (task === 'betreuer') {
                    await rowButton(p, alive[0].name, 'Koautor');
                    await rowButton(p, alive[1].name, 'Koautor');
                    await p.click('.kf-task .btn.primary', 'verbinden');
                } else if (task === 'ki') {
                    await p.click('.kf-task .btn.primary', 'Fertig für diese Nacht');
                } else if (task === 'numerik') {
                    await rowButton(p, others.find(x => x.seat !== me.protectLast).name, 'nachrechnen');
                } else {
                    const label = { lean: 'formalisieren', reviewer: 'markieren', doktorand: 'Vorbild' }[task];
                    await rowButton(p, others[0].name, label);
                }
                acted = true;
                break;
            }
            if (st.phase === 'day' && me.alive && !(me.seat in st.votes)) {
                // alle stimmen gegen die erste verbliebene Person (die selbst: „Niemanden“)
                const target = alive[0];
                if (target.seat === me.seat) await p.click('.kf-task .btn', 'Niemanden');
                else await rowButton(p, target.name, 'Stimme');
                acted = true;
                break;
            }
            if (st.phase === 'shot' && st.shooter === me.seat) {
                await rowButton(p, alive.find(x => x.seat !== me.seat).name, 'mitreißen');
                acted = true;
                break;
            }
        }
        if (!acted) throw new Error(`nobody can act in phase ${st.phase}`);
        await sleep(300);
        if (st.phase === 'night' && step_ === 0) await snap(players.slice(0, 2), 'kf-task');
    }
    await all(players, p => p.waitFor(() => current.room.state.phase === 'over'));
    const final = await A.page.evaluate(() => ({ text: document.querySelector('.tb-status').textContent, roles: current.room.state.players.filter(p => p.role).length }));
    await check(/gewinn|niemand/i.test(final.text), `game over with a result: “${final.text}” after ${rounds} round(s)`, [A]);
    await check(final.roles === 6, 'all roles revealed at the end', [A]);
    await A.checkNoHScroll('konferenz over (mobile)');
    await snap(players.slice(0, 2), 'kf-over');

    step('new game, then leaving the conference early');
    await A.click('.tb-extras .btn.primary', 'Neues Spiel');
    await all(players, p => p.waitText('Wer kommt zur Konferenz?'));
    await A.click('.tb .btn.primary', 'Konferenz eröffnen (6 Personen)');
    await all(players, p => p.waitText('Deine Rolle'));
    const leaver = players[5];
    leaver.confirmAnswer = true;
    await leaver.click('.tb-extras .link', 'Konferenz verlassen');
    await all(players.slice(0, 5), p => p.waitText('Felix verlässt die Konferenz vorzeitig'));
    const box = await leaver.text('.kf-me');
    await check(box.toLowerCase().includes('du hast die konferenz verlassen'), `whoever leaves sees it in their role box (“${box.slice(0, 80)}”)`, [leaver]);

    step('removing an absent person needs at least half of the others (here 2 of 4)');
    if (await A.page.evaluate(() => current.room.state.phase === 'over')) {
        step('(game already over after leaving – skipped)');
        return;
    }
    const [, B, , , E] = players;
    A.confirmAnswer = true;
    A.promptAnswer = 'Emmy';
    await A.click('.tb-extras .link', 'Abwesende Person herausnehmen');
    await B.waitText('Emmy ist nicht mehr da', '.kf-removal');
    await check((await B.text('.kf-removal')).includes('1 von 2'), 'the proposal is shown to everyone with its count', [B]);
    await check(!(await E.exists('.kf-removal button')), 'the person concerned cannot confirm', [E]);
    await snap([B], 'kf-removal');
    await B.click('.kf-removal button', 'bestätigen');
    await A.waitText('Emmy verlässt die Konferenz vorzeitig');
    await check(!(await A.exists('.kf-removal')), 'after enough confirmations the proposal is gone', [A]);
});

defineSuite('edge', async (browser, players) => {
    const LONG = 'Maximiliankonstantinusxy';   // 24 Zeichen = Höchstlänge, ohne Umbruchmöglichkeit
    const A = await Player.create(browser, LONG, { mobile: true });
    const B = await Player.create(browser, 'Bea', { mobile: true });
    const S = await Player.create(browser, 'Sam', { mobile: true });   // Zuschauer
    players.push(A, B, S);

    step('long unbreakable name, connect4 on mobile');
    await A.goto(`${BASE}/a/`);
    const c4 = await A.createTable('Vier in einer Reihe');
    await B.goto(c4);
    await B.takeSeat(1);
    await A.waitText('Bea');
    await A.checkNoHScroll('connect4 with 24-char name (mobile)');
    await snap([A], 'c4-longname');
    const overflow = await A.page.evaluate(() => [...document.querySelectorAll('.seats .seat')].flatMap(seat => {
        const r = seat.getBoundingClientRect();
        return [...seat.children].filter(c => { const q = c.getBoundingClientRect(); return q.right > r.right + 1 || q.left < r.left - 1; })
            .map(c => `${c.textContent} (${Math.round(c.getBoundingClientRect().right - r.right)}px)`);
    }));
    await check(!overflow.length, `seat names stay inside their seat box on mobile (overflowing: ${overflow.join(', ')})`, [A]);
    await S.goto(c4);
    await S.checkNoHScroll('connect4 spectator (mobile)');

    step('double click on a column places only one disc');
    await A.waitFor(() => !!document.querySelector('.status.my-turn'));
    await A.page.evaluate(() => { const c = document.querySelectorAll('.c4-col')[2]; c.click(); c.click(); });
    await B.waitFor(() => !!document.querySelector('.status.my-turn'));
    await sleep(1200);
    await check(await A.count('.c4-cell.red') === 1 && await A.count('.c4-cell.yellow') === 0, 'double click → exactly one red disc', [A]);

    step('rename while seated');
    B.name = 'Beatrix';
    await B.click('.room-head .whoami button', 'ändern');
    await A.waitText('Beatrix', '.seats');

    step('leave seat, spectator takes it and continues');
    await B.click('.seats .seat.mine .link', 'aufstehen');
    await A.waitText('Warte, bis alle Plätze besetzt sind');
    await S.takeSeat(1);
    await S.waitFor(() => !!document.querySelector('.status.my-turn'));
    await S.click('.c4-col', null, { nth: 4 });
    await A.waitFor(() => !!document.querySelector('.status.my-turn'));

    step('chess and Rithmomachia: spectator on mobile');
    for (const game of ['Schach zu viert', 'Zahlenkampfspiel']) {
        await A.goto(`${BASE}/a/`);
        const u = await A.createTable(game);
        await B.goto(u);
        await B.takeSeat(1);
        await S.goto(u);
        await S.checkNoHScroll(`${game} spectator (mobile)`);
        await A.checkNoHScroll(`${game} with 24-char name (mobile)`);
        await snap([A, S], 'spectator');
    }

    step('Wer bin ich: long unbreakable term on mobile, then remove a player');
    await A.goto(`${BASE}/a/`);
    const wb = await A.createTable('Wer bin ich?');
    for (const p of [B, S]) {
        await p.goto(wb);
        await p.click('.tb .btn.primary', 'Mitspielen');
        await p.waitText('(du)');
    }
    await A.waitText('Sam');
    await A.click('.tb .btn.primary', 'Spiel starten (3 Personen)');
    const term = 'Donaudampfschifffahrtsgesellschaftskapitaensmuetzenabzeichen'.slice(0, 60);
    for (const [i, p] of [A, B, S].entries()) {
        await p.waitFor(n => (current.room.state.picks || []).length >= n, i);
        await p.click('.tb-composer .tb-chip');
        await p.waitFor(() => !!document.getElementById('wb-term'));
        await p.page.type('#wb-term', i === 0 ? term : 'Kurz ' + i);
        await p.page.keyboard.press('Enter');
        await p.waitFor(() => !document.getElementById('wb-term'));
    }
    await all([A, B, S], p => p.waitFor(() => current.room.state.phase === 'playing'));
    for (const p of [A, B, S]) await p.checkNoHScroll(`Wer bin ich with 60-char term (${p.name}, mobile)`);
    await snap([A, B], 'wb-long');
    await A.clickFn(() => {
        const row = [...document.querySelectorAll('.wb-list .wb-line')].find(e => e.querySelector('.wb-name').textContent === 'Sam');
        return row && row.querySelector('.tb-remove');
    }, 'remove Sam');
    await S.waitText('Du spielst beim nächsten Spiel mit.');
    await all([A, B], p => p.waitFor(() => ![...document.querySelectorAll('.wb-list .wb-name')].some(e => e.textContent === 'Sam')));
    // die beiden Übrigen raten weiter bis zum Ende
    for (const p of [A, B]) await p.click('.wb-line.me .btn.primary', 'Erraten!');
    await all([A, B], p => p.waitText('Alle haben ihren Begriff erraten!'));

    step('Trombone: abort game, 8 players are not needed – 2 players start with fixed deck');
    await A.goto(`${BASE}/a/`);
    const tb = await A.createTable('Trombone');
    await B.goto(tb);
    await B.click('.tb .btn.primary', 'Mitspielen');
    await A.waitText('Beatrix');
    await check((await A.text('.tb')).includes('32 Karten + 1 Joker'), '2 players → 32-card deck', [A]);
    await A.click('.tb .btn.primary', 'Spiel starten (2 Personen)');
    await all([A, B], p => p.waitText('Runde 1'));
    await A.checkNoHScroll('trombone with 24-char name (mobile)');
    await snap([A], 'tb-longname');
    await B.click('.tb-extras .link', 'Spiel abbrechen');
    await all([A, B], p => p.waitText('Wer spielt mit?'));
});

defineSuite('lobby-a', async (browser, players) => {
    const L = await Player.create(browser, 'Lena', { mobile: true });
    const M = await Player.create(browser, 'Max');
    const N = await Player.create(browser, 'Nora');
    players.push(L, M, N);

    await L.goto(`${BASE}/a/`);
    await L.waitText('Offene Tische');
    await check(await L.exists('footer a[href="https://example.org/impressum"]'), 'footer link (Impressum) is rendered', [L]);
    await check(await L.exists('.sa-note a[href^="mailto:"]'), 'contact link is rendered', [L]);
    const url = await L.createTable('Vier in einer Reihe');
    const id = await L.roomId();
    const title = 'Vier in einer Reihe mit Lena';
    await L.waitText(title, '.room-head');

    step('the table appears in the list of another person and can be opened from there');
    await M.goto(`${BASE}/a/`);
    await M.waitText(title, '.room-list');
    await check((await M.text('.room-list')).includes('1 Platz frei'), 'list shows “1 Platz frei”', [M]);
    await M.click('.room-list a.room-row', title);
    await M.waitFor(i => location.hash === '#r=' + i, id);
    await M.takeSeat(1);
    await L.waitText('Max', '.seats');

    step('“Link kopieren” and open the link in another browser');
    await L.click('.controls .btn', 'Link kopieren');
    await L.waitText('Link kopiert', '#toast').catch(() => {});
    let copied = await L.page.evaluate(() => navigator.clipboard.readText()).catch(() => null);
    if (!copied) copied = await L.text('#toast');   // Ersatz: Link steht in der Meldung
    await check(copied === url, `copied link equals the table address (${copied})`, [L]);
    await N.goto(copied);
    await N.waitText(title, '.room-head');
    await N.waitText('Max', '.seats');
    await check(!(await N.exists('.seats .btn.small')), 'third person sees no free seat', [N]);
    await check(!(await N.text('.controls')).includes('Aufgeben'), 'spectator has no “Aufgeben”', [N]);

    step('language switch keeps the table');
    const langHref = await L.page.$eval('footer a.sa-lang', a => a.getAttribute('href'));
    await check(langHref.endsWith('#r=' + id), `language link keeps the table (${langHref})`, [L]);

    step('delete the table');
    for (const p of [M, N]) p.allow(/HTTP 404 \/a\/api: /);
    await L.click('.controls .link', 'Tisch löschen');
    await check(L.dialogs.some(d => /endgültig löschen/.test(d)), 'deleting asks for confirmation', [L]);
    await L.waitFor(() => location.hash === '' || location.hash === '#');
    await L.waitText('Offene Tische');
    await L.waitFor(t => !document.querySelector('.room-list') || !document.querySelector('.room-list').innerText.includes(t), title);
    await all([M, N], p => p.waitText('Diesen Tisch gibt es nicht (mehr).'));
    await M.click('#app .btn', 'Zur Übersicht');
    await M.waitText('Offene Tische');
    await sleep(500);
    await check(!(await M.hasText(title, '.room-list')), 'deleted table no longer in the list', [M]);

    step('a non-existing table link');
    L.allow(/HTTP 404 \/a\/api: /);
    await L.goto(`${BASE}/a/#r=abcdef1234567890`);
    await L.waitText('Diesen Tisch gibt es nicht (mehr).');
});

// --- Instanz ohne Tischliste --------------------------------------------------------

defineSuite('lobby-b', async (browser, players) => {
    const P = await Player.create(browser, 'Paula', { mobile: true });
    const Q = await Player.create(browser, 'Quentin');
    players.push(P, Q);

    await P.goto(`${BASE}/b/`);
    await P.waitText('Deine Tische');
    await check(!(await P.hasText('Offene Tische')), 'no public table list in lobby=false mode', [P]);
    await check((await P.text('#app')).includes('Noch keine.'), '“Deine Tische” is empty at first', [P]);
    const offered = await P.page.evaluate(() => [...document.querySelectorAll('.game-card .game-name')].map(e => e.firstChild.textContent.trim()));
    await check(offered.join() === 'Trombone,Zahlenkampfspiel,Wer bin ich?,Schach zu viert,Vier in einer Reihe',
        `default: all finished games in this order, no work in progress (${offered.join(', ')})`, [P]);
    await P.checkNoHScroll('lobby=false overview (mobile)');
    const url = await P.createTable('Trombone');
    const id = await P.roomId();
    await check(/^[a-z0-9]{16}$/.test(id), `table id is long without list (got “${id}”)`, [P]);
    const title = 'Trombone mit Paula';

    step('back to overview: table is listed under “Deine Tische”');
    await P.click('.room-head a', '← Übersicht');
    await P.waitText('Deine Tische');
    await P.waitText(title);
    await P.click('.room-list a.room-main', title);
    await P.waitFor(i => location.hash === '#r=' + i, id);

    step('second person opens the link, joins and gets it in their list');
    await Q.goto(url);
    await Q.click('.tb .btn.primary', 'Mitspielen');
    await P.waitText('Quentin');
    await Q.goto(`${BASE}/b/`);
    await Q.waitText(title);
    step('remove from own list (×)');
    await Q.click('.room-list .room-row button', '×');
    await sleep(300);
    await check(!(await Q.hasText(title)), 'table removed from “Deine Tische”', [Q]);
    await Q.goto(`${BASE}/b/`);
    await check(!(await Q.hasText(title)), 'table stays removed after reload', [Q]);

    step('a table deleted by someone else disappears from “Deine Tische” without opening it');
    await P.goto(`${BASE}/b/`);
    const url2 = await P.createTable('Vier in einer Reihe');
    const title2 = 'Vier in einer Reihe mit Paula';
    await Q.goto(url2);
    await Q.waitFor(() => !!document.querySelector('.room-head'));
    await Q.goto(`${BASE}/b/`);
    await Q.waitText(title2);
    await P.click('.controls .link', 'Tisch löschen');
    await P.waitFor(() => location.hash === '');
    await Q.goto(`${BASE}/b/`);
    const gone = await Q.waitFor(t => ![...document.querySelectorAll('.room-list')].some(e => e.innerText.includes(t)), title2).then(() => true, () => false);
    await check(gone, 'deleted table removed from “Deine Tische” on the overview', [Q]);

    step('the table of instance b is not reachable via instance a');
    P.allow(/HTTP 404 \/a\/api: /);
    await P.goto(`${BASE}/a/#r=${id}`);
    await P.waitText('Diesen Tisch gibt es nicht (mehr).');
});

// --- Englisch -------------------------------------------------------------------------

const GERMAN = /[äöüßÄÖÜ]|\b(und|oder|der|die|das|mit|von|ist|nicht|noch|Tisch|Tische|Spiel|Spiele|Neuer?|Neues|Runde|hinsetzen|aufstehen|frei|Personen|Plätze|Platz|Warte|Karten|Karte|Begriffe?|Mitspielen|Mitmachen|Zustimmen|Ansage|wählen|kopieren|löschen|Aufgeben|Spielregeln|Regeln|Du|bist|dran|Zieh|Weiß|Schwarz|Rot|Gelb|Gerade|Ungerade|Übersicht|Lade|gerade eben|vor)\b/;

async function germanLeaks(p) {
    return p.page.evaluate(src => {
        const re = new RegExp(src);
        // Tischnamen in der Liste sind Eingaben der Leute (evtl. deutsch) – nicht prüfen
        const own = new Set([...document.querySelectorAll('.room-list')].flatMap(e => e.innerText.split('\n').map(s => s.trim())));
        const lines = document.body.innerText.split('\n').map(s => s.trim()).filter(s => s && !own.has(s));
        // Begriff-Feld bei „Wer bin ich?“: Der Name im Beispiel (z. B. Kurt Gödel) ist kein deutscher Text – nur „e.g.“ prüfen
        const attrs = [...document.querySelectorAll('[title],[placeholder],[aria-label]')]
            .flatMap(e => ['title', 'placeholder', 'aria-label'].map(a => e.getAttribute(a)).filter(Boolean)
                .map(v => (e.id === 'wb-term' && v === e.getAttribute('placeholder') ? (v.startsWith('e.g. ') ? 'e.g.' : v) : v)));
        return [...lines, ...attrs].filter(s => re.test(s) && !/^Deutsch$/.test(s) && !/Tobias Schnieders/.test(s));
    }, GERMAN.source);
}

defineSuite('english', async (browser, players) => {
    const E = await Player.create(browser, 'Emma', { lang: 'en', mobile: true });
    const F = await Player.create(browser, 'Finn', { lang: 'en' });
    players.push(E, F);

    const leaks = async (p, where) => {
        const l = await germanLeaks(p);
        await check(!l.length, `${where}: German text in English UI: ${JSON.stringify(l.slice(0, 6))}`, [p]);
    };

    // Selbsttest des Detektors: auf der deutschen Seite muss er anschlagen
    await E.goto(`${BASE}/a/?lang=de`);
    await check((await germanLeaks(E)).length > 3, 'German text detector finds German text on the German page', [E]);
    await E.goto(`${BASE}/a/?lang=en`);
    await E.waitText('New game');
    await check(await E.page.title() === 'Game Night', 'English page title', [E]);
    await leaks(E, 'overview');
    await snap([E], 'en-lobby');
    await E.checkNoHScroll('overview en (mobile)');

    const games = { connect4: 'Four in a Row', handbrain: 'Chess for four', zahlenkampf: 'Rithmomachia', trombone: 'Trombone', werbinich: 'Who am I?',
                    konferenz: 'Maths conference' };
    const urls = {};
    for (const [id, name] of Object.entries(games)) {
        await E.goto(`${BASE}/a/?lang=en`);
        urls[id] = await E.createTable(name);
        await E.page.evaluate(() => { const d = document.querySelector('details.rules'); if (d) d.open = true; });
        await leaks(E, `${name} table`);
        await E.checkNoHScroll(`${name} table en (mobile)`);
    }
    await check(E.dialogs.some(d => /your name/i.test(d)) && E.dialogs.some(d => /Table name/.test(d)), 'English prompts', [E]);

    step('connect4 in English: join, move, resign');
    await F.goto(urls.connect4);
    await F.takeSeat(1);
    await E.goto(urls.connect4);
    await E.waitFor(() => !!document.querySelector('.status.my-turn'));
    await E.click('.c4-col', null, { nth: 3 });
    await F.waitFor(() => !!document.querySelector('.status.my-turn'));
    await leaks(F, 'connect4 running');
    await F.click('.controls .btn', 'Resign');
    await all(players, p => p.waitText('resigns'));
    await leaks(E, 'connect4 over');

    step('maths conference in English: setup and first night with four players');
    const Gw = await Player.create(browser, 'Gwen', { lang: 'en' });
    const Hu = await Player.create(browser, 'Hugo', { lang: 'en', mobile: true });
    for (const p of [F, Gw, Hu]) {
        await p.goto(urls.konferenz);
        await p.click('.tb .btn.primary', 'Join');
        await p.waitText('(you)');
    }
    await E.goto(urls.konferenz);
    await E.waitText('Hugo');
    await leaks(E, 'maths conference setup');
    await E.click('.tb .btn.primary', 'Open the conference (4 players)');
    await all([E, F, Gw, Hu], p => p.waitText('Your role'));
    for (const p of [E, F, Gw, Hu]) await leaks(p, 'maths conference night');
    await Hu.checkNoHScroll('maths conference night en (mobile)');
    await checkErrors([Gw, Hu]);   // nur hier dabei
    await Gw.close();
    await Hu.close();

    step('trombone in English: start and bid');
    await E.goto(urls.trombone);
    await E.click('.tb .tb-addbot', 'Add bot');
    await E.waitFor(() => !!document.querySelector('.tb-players .bot-badge'));
    await check((await E.text('.tb')).includes('Start game (1 player + 1 bot)'), 'English start button counts people and bots', [E]);
    await leaks(E, 'trombone lobby with a bot');
    await E.click('.tb-players .tb-remove[title="Remove bot"]');
    await E.waitFor(() => !document.querySelector('.tb-players .bot-badge'));
    await F.goto(urls.trombone);
    await F.click('.tb .btn.primary', 'Join');
    await E.waitText('Finn');
    await E.click('.tb .btn.primary', 'Start game (2 players)');
    await all(players, p => p.waitText('Round 1'));
    for (const p of players) await leaks(p, 'trombone bidding');
    const who = (await E.exists('.tb-actions')) ? E : F;
    await who.click('.tb-composer .tb-chips .tb-chip');
    await who.click('.tb-composer .tb-rankrow .tb-rankbtn');
    await leaks(who, 'trombone composer');
    await who.click('.tb-composer .btn.primary', 'Bid: ');
    const other = who === E ? F : E;
    await other.click('.tb-trombone');
    await all(players, p => p.waitFor(() => current.room.state.phase === 'reveal'));
    for (const p of players) await leaks(p, 'trombone reveal');

    step('who am I in English: start, pick');
    await E.goto(urls.werbinich);
    await F.goto(urls.werbinich);
    await F.click('.tb .btn.primary', 'Join');
    await E.waitText('Finn');
    await E.click('.tb .btn.primary', 'Start game (2 players)');
    await all(players, p => p.waitText('Choose terms'));
    await E.click('.tb-composer .tb-chip');
    await E.waitFor(() => !!document.getElementById('wb-term'));
    await leaks(E, 'who am I choosing');
    await E.page.type('#wb-term', 'Isaac Newton');
    await E.click('.tb-composer .btn.primary', 'Save term');
    await F.waitFor(() => (current.room.state.picks || []).length === 1);
    await F.click('.tb-composer .tb-chip');
    await F.waitFor(() => !!document.getElementById('wb-term'));
    await F.page.type('#wb-term', 'Grace Hopper');
    await F.click('.tb-composer .btn.primary', 'Save term');
    await all(players, p => p.waitText('Still to guess'));
    for (const p of players) await leaks(p, 'who am I playing');

    step('chess and rithmomachia in English with all seats taken');
    for (const id of ['handbrain', 'zahlenkampf']) {
        await F.goto(urls[id]);
        await F.takeSeat(1);
        await E.goto(urls[id]);
        await E.waitText('Finn');
        await leaks(E, `${id} seated`);
    }

    step('server error messages in English');
    await F.goto(urls.connect4);
    F.allow(/HTTP 4\d\d \/a\/api/);
    await F.page.evaluate(() => api('sit', { room: current.id, seat: 0, name: 'Finn' }).catch(e => window.__err = e.message));
    const msg = await F.page.evaluate(() => window.__err || null);
    await check(!!msg, 'sitting on a taken seat is refused', [F]);
    await check(!msg || !GERMAN.test(msg), `API error message is English (“${msg}”)`, [F]);

    step('language is remembered: link without ?lang= opens in English even with German browser');
    const G = await Player.create(browser, 'Gina', { lang: 'de' });
    players.push(G);
    await G.goto(`${BASE}/a/?lang=en`);
    await G.goto(urls.connect4.replace('?lang=en', ''));
    await G.waitText('Overview');
});

// ---------------------------------------------------------------------------

async function main() {
    const t0 = Date.now();
    fs.mkdirSync(OUT, { recursive: true });
    console.log(`Game Night e2e – server ${BASE}, screenshots in ${OUT}`);
    await startServer();
    const browser = await puppeteer.launch({ executablePath: CHROME, headless: HEADLESS, args: ['--no-sandbox'] });
    try {
        for (const s of suites) {
            if (ONLY.length && !ONLY.includes(s.name)) continue;
            suite = { name: s.name, failures: [] };
            const players = [];
            const st = Date.now();
            console.log(`• ${s.name}`);
            try {
                let timer;
                await Promise.race([
                    s.fn(browser, players),
                    new Promise((_, rej) => { timer = setTimeout(() => rej(new Error('suite timeout')), SUITE_TIMEOUT); }),
                ]).finally(() => clearTimeout(timer));
            } catch (e) {
                fail(`aborted: ${e.message.split('\n')[0]}`, await shootAll(players, 'abort'));
            }
            await sleep(300);
            await checkErrors(players);
            for (const p of players) await p.close();
            results.push({ ...suite, ms: Date.now() - st });
            console.log(`  ${suite.failures.length ? '✗ FAILED' : '✓ ok'} (${((Date.now() - st) / 1000).toFixed(1)} s)`);
        }
    } finally {
        await browser.close().catch(() => {});
        stopServer();
    }
    const failed = results.filter(r => r.failures.length);
    console.log('\n' + results.map(r => `${r.failures.length ? '✗' : '✓'} ${r.name.padEnd(12)} ${(r.ms / 1000).toFixed(1).padStart(6)} s  ${r.failures.length ? r.failures.length + ' failure(s)' : ''}`).join('\n'));
    if (notes.length) console.log('\nNotes:\n' + notes.map(n => '  · ' + n).join('\n'));
    console.log(`\n${failed.length ? 'FAILED' : 'OK'} – ${results.length} suites in ${((Date.now() - t0) / 1000).toFixed(1)} s`);
    process.exitCode = failed.length ? 1 : 0;
}

process.on('SIGINT', () => { stopServer(); process.exit(130); });
main().catch(e => { console.error(e); stopServer(); process.exit(1); });
