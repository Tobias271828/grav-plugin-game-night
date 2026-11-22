# Browser tests (end-to-end)

`run.js` plays every game in real Chromium browsers: each person is a separate browser context
(own localStorage), all actions go through the UI. It starts its own PHP development server with
a fresh temporary data folder and two instances (via `router.php`):

- `/a/` – with table list (`lobby: true`), footer link and contact link
- `/b/` – without table list (`lobby: false`)

Rate limits are switched off for the test instances (all browsers share one IP address).

## What is tested

| Suite | Content |
|---|---|
| `connect4` | 2 players: game until a win, both see the result, “Neue Runde” (other colour starts), resign, “Neu starten” with cancelled confirm, clicks out of turn |
| `handbrain` | 4 players: only legal piece types offered, wrong piece type not selectable, Scholar's mate (capture + checkmate), new round with captures, resign, pawn promotion |
| `zahlenkampf` | 2 players: setting the victory conditions (Bodies 2, Goods off) – both see them, a game with moves chosen by the game logic (capturing moves and flying moves preferred, red/dashed target squares), same log for both, victory of bodies, settings kept for the next round, resign |
| `trombone` | 3 players: deck choice, bids via the composer, “Trombone!” notice, reveal, simultaneous “Nächste Runde”, removing a player, “Person aufnehmen” with vote, rejoining with 2 cards, rounds until somebody loses, “Neues Spiel” |
| `werbinich` | 3 players: picking (incl. “andere Person wählen”), typing is not interrupted by others' updates, changing a term, nobody sees (or receives) their own term, “Erraten!” notices, game over |
| `konferenz` | 6 players (2 on phones): adjusting the role setup, a whole maths conference played through the buttons (proposals/choice of the constructivists, all night roles, open votes, physicist), only allowed roles visible to everyone, all roles revealed at the end, leaving early |
| `edge` | 24-character names and 60-character terms on a phone, double click, renaming while seated, leaving a seat, spectators on a phone, removing a player in Who am I?, aborting Trombone |
| `lobby-a` | table list, joining from the list, “Link kopieren” + opening the link in another browser, language link keeps the table, deleting a table (others see “gibt es nicht”), unknown table |
| `lobby-b` | no table list, “Deine Tische”, removing a table from that list, tables of one instance are not reachable via another |
| `english` | `?lang=en`: overview and all game tables (rules opened) are scanned for German text, plus a maths conference night with four players, games started in English, English server errors, remembered language |

On every page: no JavaScript errors, no `console.error`, no failed API requests (unless expected),
no Content Security Policy violations. At a 375 px wide phone viewport (at least one person per
game) the page must not scroll horizontally.

## Running

Requirements: PHP ≥ 8.0, Node.js ≥ 18, Chromium/Chrome and `puppeteer-core`:

```sh
cd tests/e2e
npm install          # installs puppeteer-core (no browser download)
npm test             # = node run.js
```

Alternatively point `NODE_PATH` at an existing `node_modules` folder that contains `puppeteer-core`.

Takes about 2.5–3 minutes; exit code 0 = all suites passed.

| Variable | Default | |
|---|---|---|
| `CHROME_PATH` | `/usr/bin/chromium` | browser executable |
| `E2E_PORT` | `8030` | port of the PHP server |
| `E2E_ONLY` | all | comma-separated suite names, e.g. `E2E_ONLY=connect4,trombone` |
| `E2E_OUT` | new temp folder | screenshots (on failures; with `E2E_SNAP=1` also at key moments) |
| `E2E_SNAP` | – | `1`: additional screenshots for looking at the layout |
| `E2E_HEADFUL` | – | `1`: show the browser windows |
| `E2E_TIMEOUT` | `12000` | ms to wait for an expected state |
| `E2E_KEEP` | – | `1`: keep the temporary data folder (path is printed on server errors) |
