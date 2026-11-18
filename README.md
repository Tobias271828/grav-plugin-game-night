# Game Night

Browser games for game nights with friends – on your own web space, without a database,
accounts or third-party services. User interface in German and English.

| Game | Players | |
|---|---|---|
| **Trombone** | 2–8 | Bluffing card game (simplified poker hands across everyone's cards) |
| **Who am I?** | 2–12 | Everyone gets a secret term from someone else and has to guess it |
| **Four-player chess** (hand and brain) | 4 | Per team, one person picks the piece type, the other moves |
| **Four in a Row** | 2 | Classic connection game |
| **Zahlenkampf** | 2 | Work in progress – only offered when enabled explicitly (`games`) |

Game Night comes in two flavours built from the same code:

- **Grav plugin** (Grav 1.7 and 2.x) – any number of *instances* as pages of a [Grav](https://getgrav.org)
  site, e.g. a private one for your friends and a public one.
- **Standalone** – one folder on any PHP web space = one instance. Download it from
  [game-night-standalone](https://github.com/Tobias271828/game-night-standalone/releases).

Requirements: PHP ≥ 8.0 and a writable folder. Apache is recommended (the included `.htaccess`
files are an extra protection layer; the data files are protected without them, too).

## Grav plugin

### Installation

- **GPM:** `bin/gpm install game-night` (or via the Admin plugin).
- **Manually:** download `game-night-grav-<version>.zip` from the
  [releases](https://github.com/Tobias271828/grav-plugin-game-night/releases) and unpack it to
  `user/plugins/game-night/`.

### Configuration

1. Define your instances in the Admin (*Plugins → Game Night*) or in `user/config/plugins/game-night.yaml`
   (all options with defaults are listed in [`game-night.yaml`](game-night.yaml)):
   ```yaml
   instances:
     - name: friends                 # data folder: user/data/game-night/friends/
       route: /game-night-x7k2q9     # hard-to-guess route = "hidden" instance
     - name: public
       route: /games
       lobby: false                  # no table list: tables are only reachable via their link
       empty_room_ttl_hours: 1
       requests_per_minute: 600
       creates_per_hour: 10
   ```
   The older form keyed by name (`instances: { friends: { route: … } }`) keeps working; the Admin asks for
   the name once when you save such an instance there.
2. Create a page at exactly that route for each instance, using the template `game-night`, e.g.
   `user/pages/game-night-x7k2q9/game-night.en.md` (and `game-night.de.md` for German):
   ```yaml
   ---
   title: Game Night
   visible: false
   ---
   ```
3. Clear the Grav cache.

The template extends `partials/base.html.twig` of your theme (Quark and most themes have it).
The page language is the active Grav language (e.g. via the LangSwitcher plugin), otherwise the site's
default language; any language other than German shows the English interface.

**Access restrictions:** if the [Login plugin](https://github.com/getgrav/grav-plugin-login) is enabled, the
API of an instance follows the access rules of its page (e.g. `access: { site.login: true }` in the page header):
visitors who may not see the page get no answer from `<route>/api` either.

| Option | Default | |
|---|---|---|
| `name` | – | Name of the data folder (required; `a-z`, `0-9`, `-`, `_`); changing it starts an empty instance |
| `route` | – | Route of the instance's page (required); the API answers at `<route>/api` |
| `lobby` | `true` | Show the list of all tables. `false`: tables only via link (64-bit IDs) |
| `games` | all finished | Offered games: `trombone`, `werbinich`, `handbrain`, `connect4`; `zahlenkampf` (work in progress) only when listed |
| `max_rooms` | `200` | Maximum number of tables at the same time |
| `room_ttl_days` | `7` | Delete tables without activity after this many days |
| `empty_room_ttl_hours` | `0` | Delete tables nobody sits at after this many hours (`0` = off) |
| `requests_per_minute` | `1200` | Per IP address (IPv6: per /64); every open page polls about once per second |
| `creates_per_hour` | `30` | New tables per IP address and hour |
| `rooms_per_client` | `20` | Existing tables per IP address (counted per calendar day) |
| `contact_url` | – | Link for feedback shown on the overview page |

**Behind a reverse proxy** (e.g. Cloudflare) all requests come from the proxy's address, so all visitors would
share the rate limits:
- **Grav 2:** the plugin uses the visitor address as Grav determines it. Enable the header your proxy sets in
  `user/config/system.yaml`, e.g. `http_x_forwarded: { cf_connecting_ip: true }` behind Cloudflare.
- **Grav 1.7** trusts `X-Forwarded-For` by default (`http_x_forwarded.ip: true`), so any visitor could send that
  header to get around the limits. The plugin therefore uses the connection's address there; behind a proxy, set
  `proxy_ip_header` in the instance (e.g. `CF-Connecting-IP`; for `X-Forwarded-For` the last entry is used,
  the one your proxy appends).

## Standalone

1. Download `game-night-standalone-<version>.zip` from the
   [standalone releases](https://github.com/Tobias271828/game-night-standalone/releases) and unpack it to
   your web space (e.g. as the document root of a subdomain).
2. Copy `config.example.php` to `config.php` and adjust it. Ideally set `data_dir` to a folder outside the
   web root; otherwise `data/.htaccess` protects it (Apache only).
3. **Updates:** replace `core/`, `assets/` and `index.php` – `config.php` and your data stay untouched.

Behind a reverse proxy, set `proxy_ip_header` in `config.php` (e.g. `'CF-Connecting-IP'`), see
`config.example.php` – but only if every request really goes through that proxy.

The language follows the browser (English unless it prefers German) and can be switched in the footer (`?lang=de|en`); the choice is
remembered in the browser (localStorage, no cookie). For public instances, add your legal notice and
privacy policy via `footer_links` (can differ per language).

## Security and abuse protection

- Table files start with a PHP line that exits immediately, so they cannot be downloaded even if the
  web server ignores `.htaccess`. User input is sanitized and only ever inserted into the page as text.
- The API only accepts `POST` with `Content-Type: application/json` (other websites cannot use it from
  a visitor's browser) and at most ~220 KB per request.
- Per-IP rate limits for all requests and for new tables. Only a daily rotating HMAC of the address is
  stored, never the address itself.
- Game states sent by browsers are checked for size and structure (no huge numbers, endless move lists
  or unexpected keys), so a manipulated state cannot freeze the other players' browsers.
- Without table list, tables have 64-bit IDs and are only reachable for people who have the link.
- There are no accounts: everyone who can open a table can take a free seat, and everyone seated can free
  other seats (e.g. of someone who left) and delete the table. **With a table list (`lobby: true`) that means
  every visitor of the page** – so use the table list only for instances whose page is restricted (hidden
  route or Login plugin), and `lobby: false` for public ones.
- Security headers (no referrer, no framing; strict Content Security Policy in the standalone version).
- In Four in a Row, chess and Zahlenkampf the game logic runs in the browser and the server only checks
  whose turn it is – players at the table could cheat. A broken game state cannot lock up the other
  players' pages, though. Trombone and Who am I? (secret cards/terms) run entirely on the server.

**Running a public instance** (especially in Germany/EU): link a legal notice (Impressum) and a privacy
policy, offer a way to report content and delete reported tables (file names contain the table ID).

## Development

`tools/server-check.php` checks a web space before installing (PHP version, `.htaccess`, real visitor IP,
file locking); see the instructions at the top of the file and delete it afterwards.
`php tests/api-test.php` runs the API test suite (also run by GitHub Actions on every push).
`tests/e2e/` contains browser tests that play every game in Chromium (see its README).
`php build.php` builds both packages into `dist/`. The standalone version also runs directly from the
repository: `php -S localhost:8000` in the plugin folder, then open `http://localhost:8000/standalone/`.

**Releases:** bump `version` in `blueprints.yaml`, add a `# v<version>` section to `CHANGELOG.md`, commit,
then push a tag with the bare version number (e.g. `git tag 1.0.1 && git push --tags`). The GitHub
Action checks both files, builds the packages and publishes the release: only the Grav package is attached
here (GPM installs the last ZIP of a release); the standalone package goes to the release of the same version
in [game-night-standalone](https://github.com/Tobias271828/game-night-standalone) (needs the repository secret
`STANDALONE_TOKEN`: a fine-grained token with *Contents: read and write* on that repository).

This project was developed with the help of AI (Claude by Anthropic); the code was reviewed by the author.

## License

MIT, see [LICENSE](LICENSE).

Includes **chess.js 0.10.3** (© Jeff Hlywa, BSD-2-Clause, https://github.com/jhlywa/chess.js) in
`assets/js/chess.min.js`, unmodified; the full license text is at the top of that file.
