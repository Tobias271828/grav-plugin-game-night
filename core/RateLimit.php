<?php
/*
 * Game Night – Anfragelimits pro Absender, ohne Datenbank (auch für Shared Hosting).
 *
 * Absender = IP-Adresse, bei IPv6 das /64-Netz (sonst könnte man einfach die Adresse wechseln).
 * Gespeichert wird nur ein gekürzter HMAC aus Adresse, Datum und einem geheimen Schlüssel: Die IP
 * selbst landet nirgends, und ab dem nächsten Tag lässt sich der Wert keiner Adresse mehr zuordnen.
 *
 * Je Absender, Instanz und Zähler gibt es eine kleine Datei <state_dir>/ratelimit/<zähler>-<instanz>-<hmac>
 * mit „Fensterbeginn Anzahl“ (festes Zeitfenster). Alte Dateien werden gelegentlich gelöscht.
 */

namespace GameNight;

\defined('GAME_NIGHT') or die();

function state_dir(): string
{
    $dir = cfg()['state_dir'];
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/** Geheimer Schlüssel der Installation (wird beim ersten Aufruf erzeugt). */
function secret(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $file = state_dir() . '/secret.php';
    $raw = @file_get_contents($file);
    if (is_string($raw) && preg_match('/^[0-9a-f]{64}$/', substr($raw, strlen(GUARD)))) {
        return $key = substr($raw, strlen(GUARD));
    }
    // Neu anlegen (oder eine beschädigte Datei ersetzen): erst Hilfsdatei schreiben, dann umbenennen –
    // so liest nie jemand eine halb geschriebene Datei. Gleichzeitige erste Anfragen: die letzte gewinnt,
    // danach gilt für alle derselbe Schlüssel.
    $new = bin2hex(random_bytes(32));
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, GUARD . $new) === strlen(GUARD) + 64 && @rename($tmp, $file)) {
        $raw = @file_get_contents($file);
        return $key = (is_string($raw) && strlen($raw) === strlen(GUARD) + 64) ? substr($raw, strlen(GUARD)) : $new;
    }
    @unlink($tmp);
    // Nicht beschreibbar: fester, aus der Installation abgeleiteter Schlüssel (besser als ein zufälliger je
    // Anfrage, mit dem kein Limit je greifen würde)
    return $key = hash('sha256', __FILE__ . '|' . state_dir() . '|' . php_uname());
}

const NEW_CLIENTS_PER_NETWORK = 256;   // neue /64-Netze je /48-Netz und Stunde (Zählerdateien)
const NEW_CLIENTS_PER_MINUTE   = 600;   // neue Absender insgesamt je Instanz und Minute

/** HMAC eines Adress-Teils mit Datum und geheimem Schlüssel (32 Hex-Zeichen). */
function pseudonym(string $what): string
{
    return substr(hash_hmac('sha256', gmdate('Y-m-d') . '|' . $what, secret()), 0, 32);
}

/** Adresse des Absenders als Binärwert; IPv4 in IPv6-Schreibweise (::ffff:1.2.3.4) als IPv4. */
/** Adresse des Besuchers: aus der Konfiguration (Proxy, Grav), sonst die Gegenstelle der Verbindung. */
function request_ip(): string
{
    return (string)(cfg()['client_ip'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Hinter einem eigenen Proxy (z. B. Cloudflare, nginx): Besucheradresse aus der Kopfzeile, die der Proxy setzt.
 * Bei einer Liste (X-Forwarded-For) zählt der letzte Eintrag – den hängt der eigene Proxy an; frühere Einträge
 * kann der Besucher selbst mitschicken. Ohne gültige Adresse: null (dann gilt REMOTE_ADDR).
 */
function forwarded_ip(string $header): ?string
{
    $value = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', trim($header)))] ?? '';
    $parts = array_map('trim', explode(',', is_string($value) ? $value : ''));
    $ip = (string)end($parts);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

function client_ip(): ?string
{
    $bin = @inet_pton(request_ip());
    if ($bin === false) {
        return null;
    }
    if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
        return substr($bin, 12);
    }
    return $bin;
}

/** Pseudonymer Schlüssel für den Absender dieser Anfrage (IPv4-Adresse bzw. IPv6-/64-Netz). */
function client_key(): string
{
    $bin = client_ip();
    if ($bin === null) {
        return pseudonym(request_ip());
    }
    return pseudonym(strlen($bin) === 16 ? bin2hex(substr($bin, 0, 8)) . '::/64' : inet_ntop($bin));
}

/** Bei IPv6: Schlüssel für das umgebende /48-Netz (typische Zuteilung an einen Anschluss), sonst null. */
function network_key(): ?string
{
    $bin = client_ip();
    return $bin !== null && strlen($bin) === 16 ? pseudonym(bin2hex(substr($bin, 0, 6)) . '::/48') : null;
}

/**
 * Darf für einen bisher unbekannten Absender eine neue Zählerdatei entstehen? Begrenzt, wie viele Dateien
 * jemand mit vielen IPv6-Adressen (ein /48-Netz hat 65 536 /64-Netze) oder ein Massenangriff anlegen kann.
 */
function new_client_allowed(): bool
{
    $network = network_key();
    if ($network !== null && !count_request('net', $network, NEW_CLIENTS_PER_NETWORK, 3600)) {
        return false;
    }
    return count_request('new', 'all', NEW_CLIENTS_PER_MINUTE, 60);
}

/**
 * Zählt eine Anfrage für ($bucket, $client) und sagt, ob sie noch erlaubt ist:
 * höchstens $limit Anfragen je $window Sekunden ($limit = 0: unbegrenzt).
 */
function rate_limit(string $bucket, string $client, int $limit, int $window): bool
{
    if ($limit <= 0) {
        return true;
    }
    $file = ratelimit_dir() . "/$bucket-" . cfg()['id'] . "-$client";
    if (!file_exists($file) && !new_client_allowed()) {
        return false;
    }
    return count_request($bucket, $client, $limit, $window);
}

function ratelimit_dir(): string
{
    $dir = state_dir() . '/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

/** Zähler in einer Datei (festes Zeitfenster) erhöhen; true, solange $limit nicht überschritten ist. */
function count_request(string $bucket, string $client, int $limit, int $window): bool
{
    $dir = ratelimit_dir();
    if (mt_rand(1, 500) === 1) {
        ratelimit_cleanup($dir);
    }
    $fh = @fopen("$dir/$bucket-" . cfg()['id'] . "-$client", 'c+');
    if (!$fh) {
        return true;   // lieber durchlassen als das Spiel blockieren
    }
    flock($fh, LOCK_EX);
    $now = time();
    [$start, $count] = array_map('intval', explode(' ', (string)stream_get_contents($fh)) + [0, 0]);
    if ($now - $start >= $window) {
        $start = $now;
        $count = 0;
    }
    $count++;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, "$start $count");
    flock($fh, LOCK_UN);
    fclose($fh);
    return $count <= $limit;
}

/** Zählerdateien löschen, deren Zeitfenster (höchstens eine Stunde) abgelaufen ist. */
function ratelimit_cleanup(string $dir): void
{
    foreach (@scandir($dir) ?: [] as $name) {
        if ($name[0] !== '.' && @filemtime("$dir/$name") < time() - 3600) {
            @unlink("$dir/$name");
        }
    }
}
