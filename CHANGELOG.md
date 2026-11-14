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
