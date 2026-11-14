<?php
// Game Night – Einstellungen. Nach config.php kopieren und anpassen; fehlende Werte haben Voreinstellungen.
return [
    // Seitentitel (sonst „Spieleabend“ bzw. „Game Night“)
    // 'title' => 'Spieleabend',

    // Ordner für Tische und interne Dateien. Am besten außerhalb des Webroots, z. B. '/home/user/game-night-data'.
    // Voreinstellung: data/ neben index.php (durch data/.htaccess gesperrt – funktioniert nur mit Apache).
    // 'data_dir' => __DIR__ . '/data',

    // Liste aller Tische zeigen? Für öffentliche Instanzen false: Tische sind dann nur über ihren Link erreichbar.
    'lobby' => true,

    'max_rooms'            => 200,    // höchstens so viele Tische gleichzeitig
    'room_ttl_days'        => 7,      // Tische ohne Aktivität nach so vielen Tagen löschen
    'empty_room_ttl_hours' => 0,      // Tische, an denen niemand sitzt, schon nach so vielen Stunden löschen (0 = aus)
    'requests_per_minute'  => 1200,   // pro IP; jede offene Seite fragt etwa 1× pro Sekunde (0 = unbegrenzt)
    'creates_per_hour'     => 30,     // neue Tische pro IP und Stunde (0 = unbegrenzt)
    'rooms_per_client'     => 20,     // gleichzeitig bestehende Tische pro IP (zählt je Kalendertag; 0 = unbegrenzt)

    // Angebotene Spiele (leer = alle): 'trombone', 'werbinich', 'handbrain' (Schach zu viert),
    // 'connect4' (Vier in einer Reihe), 'zahlenkampf'. Bestehende Tische anderer Spiele laufen weiter.
    'games' => [],

    // Link für Hinweise/Fehlermeldungen in der Übersicht (leer = keiner)
    'contact_url' => '',

    // Links in der Fußzeile, z. B. Impressum und Datenschutzerklärung (bei öffentlichen Instanzen Pflicht!).
    // Entweder für beide Sprachen gleich: ['Impressum' => 'https://…'] oder je Sprache wie hier:
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
