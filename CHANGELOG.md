# v1.3.0
## 2026-11-28

1. [](#new)
    * Trombone: bots (“TromboneZero”, T0 for short). Seated players can add and remove bots before the start, and add one to a running game if all human players agree (like adding a person); a bot gets a random animal as its name and a “Bot” badge that only the server assigns. Bots draw their moves from a small neural network evaluated in plain PHP on the server (weight file `core/trombone-net.bin`), inside the requests of the people at the table after a short thinking time – no background process, no data leaves the server. A game needs at least one human; bots do not vote on adding players and can be removed from a running game like anyone else
    * New instance settings `bots` (default on) and `max_bots` (default 7 per table). Without a matching weight file an instance offers no bots; everything else keeps working
    * Limits for the computing time of bots: `max_bots_per_client` (default 21 bots per IP address), `max_bots_total` (default 70 per instance) and `bot_moves_per_minute` (default 600 per instance, a quarter of that per IP address; when used up, bots move later). Bots stop moving when no seated human is playing any more, and a failing bot can neither break a request nor block a game (it then challenges or makes the lowest bid)
    * Standalone: optional setting `favicon` for an own icon in the browser tab (a file on the same server or a full address)
2. [](#improved)
    * A table at which only bots are left is deleted at once (spam protection): when the last human leaves, the game is not played on

# v1.2.0
## 2026-11-22

1. [](#new)
    * Rithmomachia (Zahlenkampfspiel) is finished and offered by default, second in the overview: the medieval battle of numbers for two, after Fulke's first variant with the rules of our game night (captures by equality, sum, difference, product, exact quotient or surrounding; flying moves; victory by triumph, bodies or goods), with progress bars for the victory goals and a preview of what a move captures
2. [](#improved)
    * README: link to the public instance on games.tobimath.de to try the games
    * Game states of Rithmomachia sent by browsers are checked more strictly (including the move log and the end of the game)
3. [](#bugfix)
    * “Your tables” (overview without table list) no longer keeps tables that were deleted by someone else or expired; they disappear without having to open them

# v1.1.0
## 2026-11-18

1. [](#new)
    * Instances can be edited in the Admin (Admin and Admin2). In the configuration they are now a list with `name`; the older form keyed by name keeps working
    * Behind a reverse proxy, the rate limits use the visitor's address: on Grav 2 as Grav determines it (`system.http_x_forwarded`), on Grav 1.7 and in the standalone version via the new option `proxy_ip_header`
    * Trombone: the playing order is drawn at random for every game
    * Who am I?: a random famous mathematician as example in the input field
2. [](#improved)
    * Zahlenkampf (work in progress) is no longer offered until its rules are final
    * Trombone: bids without category (“Three kings”), the bids of the round are collapsed (everyone's last bid is in the player list), player list in one row, revealed hands in playing order
    * Opened history sections stay open when the page is redrawn
    * `blueprints.yaml`: compatibility with Grav 1.7 and 2.0, requires PHP 8.0
    * Configuration comments in English; README notes on proxies and on who may free seats and delete tables
    * Releases here only carry the Grav package (GPM installs the last ZIP of a release); the standalone package is published in [game-night-standalone](https://github.com/Tobias271828/game-night-standalone/releases)
3. [](#bugfix)
    * Changing your name during a running Trombone or Who am I? game now also updates it in the game

# v1.0.0
## 11/14/2026

1. [](#new)
    * First public release
    * Games: Trombone, Who am I?, four-player chess (hand and brain), Four in a Row, Zahlenkampf (work in progress)
    * Several independent instances per Grav site, each with its own route, data folder and settings
    * Optional table list per instance; without it, tables are only reachable via their (unguessable) link
    * Standalone version for any PHP web space, built from the same code
    * Abuse protection: per-IP rate limits for requests and new tables, limit of tables per IP, request size limit, automatic cleanup of idle and empty tables, security headers
    * Game states sent by browsers are checked for size and structure
    * With the Login plugin, an instance's API follows the access rules of its page
    * German and English user interface
    * API test suite (`tests/api-test.php`, run by GitHub Actions) and browser test suite (`tests/e2e/`)
