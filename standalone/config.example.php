<?php
// Game Night – settings. Copy to config.php and adjust; missing values use the defaults shown here.
return [
    // Page title (default: "Spieleabend" in German, "Game Night" in English)
    // 'title' => 'Game Night',

    // Icon in the browser tab (favicon): a file on this server, e.g. 'favicon.png' next to index.php (it survives
    // updates there), or a full address like 'https://example.org/logo.svg'. Empty: the browser looks for
    // /favicon.ico at the top of the domain.
    // 'favicon' => 'favicon.png',

    // Folder for tables and internal files. Ideally outside the web root, e.g. '/home/user/game-night-data'.
    // Default: data/ next to index.php (blocked by data/.htaccess – Apache only).
    // 'data_dir' => __DIR__ . '/data',

    // Show the list of all tables? For public instances false: tables are then only reachable via their link.
    'lobby' => true,

    'max_rooms'            => 200,    // at most this many tables at the same time
    'room_ttl_days'        => 7,      // delete tables without activity after this many days
    'empty_room_ttl_hours' => 0,      // delete tables nobody sits at after this many hours (0 = off)
    'requests_per_minute'  => 1200,   // per IP address; every open page asks about once per second (0 = unlimited)
    'creates_per_hour'     => 30,     // new tables per IP address and hour (0 = unlimited)
    'rooms_per_client'     => 20,     // existing tables per IP address (counted per calendar day; 0 = unlimited)

    // Offered games (empty = all): 'trombone', 'zahlenkampf' (Rithmomachia), 'werbinich' (Who am I?),
    // 'handbrain' (four-player chess), 'connect4' (Four in a Row). Existing tables of other games keep working.
    'games' => [],

    // Trombone: offer bots ("TromboneZero")? They are computed on this server (inside the requests of the people
    // at the table) and need the weight file core/trombone-net.bin; without it there are no bots.
    'bots'     => true,
    'max_bots' => 7,                  // at most this many bots per table (0 = none)
    // Bots cost computing time on this server, so they have limits of their own (0 = unlimited):
    'max_bots_per_client'  => 21,     // bots seated at the same time that one IP address has added
    'max_bots_total'       => 70,     // bots seated at the same time in this instance
    'bot_moves_per_minute' => 600,    // bot moves per minute in this instance (a quarter of that per IP address);
                                      // when used up, bots simply move later

    // Behind a reverse proxy (Cloudflare, nginx, …) all requests come from the proxy's address, so all
    // visitors would share the limits. Name the header in which YOUR proxy passes the visitor's address,
    // e.g. 'CF-Connecting-IP' (Cloudflare) or 'X-Forwarded-For' (the last entry is used – the one your proxy
    // appends). Only set this if every request really goes through that proxy: otherwise visitors could
    // send the header themselves and bypass the limits.
    'proxy_ip_header' => '',

    // Link for feedback shown on the overview page (empty = none)
    'contact_url' => '',

    // Links in the footer, e.g. legal notice and privacy policy (required for public instances in many countries).
    // Either the same for both languages: ['Legal notice' => 'https://…'] or per language as here:
    'footer_links' => [
        'de' => [
            // 'Impressum'   => 'https://example.org/de/impressum',
            // 'Datenschutz' => 'https://example.org/de/datenschutz',
        ],
        'en' => [
            // 'Legal notice' => 'https://example.org/en/legal-notice',
            // 'Privacy'      => 'https://example.org/en/privacy',
        ],
    ],
];
