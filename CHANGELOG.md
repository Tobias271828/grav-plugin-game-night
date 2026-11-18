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
