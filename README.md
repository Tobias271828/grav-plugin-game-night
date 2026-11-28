# Game Night

Browser games for game nights with friends – on your own web space, without a database, accounts or
third-party services. German and English user interface.

**Try it:** a public instance (standalone version) runs at **[games.tobimath.de](https://games.tobimath.de)** –
create a table and send its link to the people you want to play with.

| Game | Players | |
|---|---|---|
| **Trombone** | 2–8 | Bluffing card game (poker hands across everyone's cards), optionally with bots |
| **Rithmomachia** | 2 | Medieval battle of numbers (Zahlenkampfspiel): capture by calculating, win by harmony |
| **Who am I?** | 2–12 | Everyone gets a secret term from someone else and has to guess it |
| **Four-player chess** | 4 | Hand and brain: per team, one picks the piece type, the other moves |
| **Four in a Row** | 2 | Classic connection game |

Two flavours from the same code, both need PHP ≥ 8.0 (Apache recommended):

- **Grav plugin** (Grav 1.7 and 2.x): any number of *instances* as pages of a [Grav](https://getgrav.org) site,
  e.g. a private one for friends and a public one.
- **Standalone**: one folder on any PHP web space = one instance, see
  [game-night-standalone](https://github.com/Tobias271828/game-night-standalone/releases).

## Grav plugin

Install with `bin/gpm install game-night` (or the Admin), or unpack `game-night-grav-<version>.zip` from the
[releases](https://github.com/Tobias271828/grav-plugin-game-night/releases) to `user/plugins/game-night/`.

1. Add instances in the Admin (*Plugins → Game Night*) or in `user/config/plugins/game-night.yaml`;
   all options with their defaults are listed in [`game-night.yaml`](game-night.yaml):
   ```yaml
   instances:
     - name: friends                 # data folder: user/data/game-night/friends/
       route: /game-night-x7k2q9     # hard-to-guess route = hidden instance
     - name: public
       route: /games
       lobby: false                  # no table list: tables only via their link
       empty_room_ttl_hours: 1
       creates_per_hour: 10
   ```
2. For each instance, create a page at exactly that route with the template `game-night`, e.g.
   `user/pages/game-night-x7k2q9/game-night.md` with `title` and `visible: false` in its header.
3. Clear the Grav cache.

The template extends your theme's `partials/base.html.twig`. The interface follows the active Grav language
(German or English). With the [Login plugin](https://github.com/getgrav/grav-plugin-login), an instance's API
follows the access rules of its page.

**Behind a reverse proxy** (e.g. Cloudflare), all visitors would share the rate limits. On Grav 2 and on
Grav 1.7.53.11 or newer, enable the header your proxy sets in `system.yaml` → `http_x_forwarded`
(e.g. `cf_connecting_ip: true`). Older Grav 1.7 versions trust `X-Forwarded-For` by default, so the plugin
ignores Grav's setting there: update Grav, or set `proxy_ip_header` in the instance (e.g. `CF-Connecting-IP`).
If `proxy_ip_header` is set, it is used on every Grav version.

## Standalone

1. Unpack `game-night-standalone-<version>.zip` from the
   [standalone releases](https://github.com/Tobias271828/game-night-standalone/releases) to your web space.
2. Copy `config.example.php` to `config.php` and adjust it (all options are explained there). Ideally put
   `data_dir` outside the web root; otherwise `data/.htaccess` protects it.
3. Updates: replace `core/`, `assets/` and `index.php`; `config.php` and your data stay.

The language follows the browser and can be switched in the footer. For public instances, add your legal
notice and privacy policy via `footer_links`. An own icon for the browser tab can be set with `favicon`
(a file next to `index.php` or a full address).

## Trombone bots

Seated players can add bots to a Trombone table (“TromboneZero”, T0 for short; marked with a “Bot” badge) – before the
start, or during a game if all human players agree.
A bot draws its moves from a small neural network that was trained by self-play and is evaluated in plain PHP from
the weight file `core/trombone-net.bin`. Settings per instance: `bots` (default `true`) switches them on or off,
`max_bots` (default `7`) limits the bots per table. Without a matching weight file an instance offers no bots;
everything else keeps working. A game needs at least one human, and a table at which only bots are left is deleted
at once. There is no background process: a bot moves inside the next request of a person who has the table open,
after a short thinking time.

The network is trained locally, without any human games, with the code in `training/` (see `training/README.md`;
not part of the release packages). Replacing the network means replacing the weight file.

## Security

- Table files start with a PHP line that exits immediately, so they cannot be downloaded even without
  `.htaccess`. User input is only ever inserted into the page as text.
- The API only accepts JSON `POST` requests (no use from other websites) of limited size, with per-IP rate
  limits for requests and new tables. Only a daily rotating HMAC of the address is stored.
- Game states sent by browsers are checked for size and structure. Trombone and Who am I? run entirely on the
  server; in the other games players at the table could cheat, but not break other players' pages.
- Trombone bots are seats that only the server can mark as bots – no request can set or remove the flag, and the
  “Bot” badge never depends on a name. They run on your server inside the requests of the people at the table, see
  only their own cards, and no data leaves the server.
- Bots cost computing time (one move: loading the weight file plus one pass through the network), so they have
  limits of their own: `max_bots` per table, `max_bots_per_client` (default 21) per IP address,
  `max_bots_total` (default 70) per instance, and `bot_moves_per_minute` (default 600 per instance, a quarter
  of that per IP address) – when that budget is used up, bots simply move later. A table at which only bots are
  left is deleted at once, and bots stop moving as soon as no seated human is playing any more.
- A bot can never break a request or block a game: whatever goes wrong while it decides (damaged weight file,
  unexpected state) is caught, and it then plays a move that is always legal (challenge, or the lowest bid).
- The weight file `core/trombone-net.bin` contains no secrets, but it is the complete bot. As a Grav plugin it can
  be downloaded by anyone who knows its address; the standalone version blocks `core/` in its `.htaccess`
  (Apache with mod_rewrite).
- There are no accounts: everyone who can open a table can sit down, and everyone seated can free seats and
  delete the table. **With a table list (`lobby: true`) that is every visitor of the page**, so use
  `lobby: false` (tables only reachable via their 64-bit link) unless the page is hidden or restricted.
- Running a public instance (especially in the EU): link a legal notice and privacy policy and offer a way to
  report content.

## Development

`php tests/api-test.php` runs the API tests and `php tests/bot-test.php` the tests of the Trombone bot (both also
on every push; the bot tests need the reference files `tests/ref/`, generated once with
`pip install numpy && python3 training/gen_refs.py`), `tests/e2e/` plays every game in Chromium,
`php build.php` builds both packages into `dist/`, and `tools/server-check.php` checks a web space before
installing. Releases: bump `version` in `blueprints.yaml`, add a `# v<version>` section to `CHANGELOG.md` and push
a tag with the version number; the GitHub Action publishes the Grav package here and the standalone package in
[game-night-standalone](https://github.com/Tobias271828/game-night-standalone) (secret `STANDALONE_TOKEN`).

This project was developed with the help of AI (Claude by Anthropic); the code was reviewed by the author.

## License

MIT, see [LICENSE](LICENSE). Includes [chess.js](https://github.com/jhlywa/chess.js) 0.10.3 (© Jeff Hlywa,
BSD-2-Clause) unmodified in `assets/js/chess.min.js`, with its license at the top of that file.
