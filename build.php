<?php
/*
 * Baut die beiden Release-Pakete nach dist/:
 *   game-night-grav-<version>.zip         Grav-Plugin (Ordner game-night/ → user/plugins/)
 *   game-night-standalone-<version>.zip   eigenständige Version (Ordner game-night/ → Webspace)
 *
 * Aufruf: php build.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$root = __DIR__;
preg_match('/^version:\s*([\w.\-]+)/m', (string)file_get_contents("$root/blueprints.yaml"), $m);
$version = $m[1] ?? 'dev';
@mkdir("$root/dist");

/** Alle Dateien unter $dir (relativ), ohne versteckte Dateien außer .htaccess. */
function files(string $root, string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $rel = substr($file->getPathname(), strlen($root) + 1);
        if ($file->isFile() && ($file->getFilename()[0] !== '.' || $file->getFilename() === '.htaccess')) {
            $out[] = $rel;
        }
    }
    sort($out);
    return $out;
}

/** $map: Pfad im Archiv => Pfad im Repository */
function make_zip(string $target, array $map): void
{
    @unlink($target);
    $zip = new ZipArchive();
    if ($zip->open($target, ZipArchive::CREATE) !== true) {
        fwrite(STDERR, "Kann $target nicht anlegen\n");
        exit(1);
    }
    foreach ($map as $inZip => $source) {
        $zip->addFile($source, "game-night/$inZip");
    }
    if (!$zip->close()) {
        fwrite(STDERR, "Kann $target nicht schreiben\n");
        exit(1);
    }
    echo basename($target), ': ', count($map), " Dateien\n";
}

$common = [];
foreach (array_merge(files($root, 'core'), files($root, 'assets')) as $f) {
    $common[$f] = "$root/$f";
}
foreach (['README.md', 'LICENSE', 'CHANGELOG.md'] as $f) {
    if (is_file("$root/$f")) {
        $common[$f] = "$root/$f";
    }
}

// Grav-Plugin
$grav = $common;
foreach (array_merge(['game-night.php', 'game-night.yaml', 'blueprints.yaml'], files($root, 'templates')) as $f) {
    $grav[$f] = "$root/$f";
}
unset($grav['assets/standalone.css']);
make_zip("$root/dist/game-night-grav-$version.zip", $grav);

// Standalone: index.php & Co. liegen im Paket neben core/ und assets/; config.php und Daten nie mitnehmen
$standalone = $common;
foreach (['index.php', 'config.example.php', '.htaccess', 'data/.htaccess'] as $f) {
    $standalone[$f] = "$root/standalone/$f";
}
make_zip("$root/dist/game-night-standalone-$version.zip", $standalone);
