<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;

/**
 * Game Night (Grav-Adapter): Jede Instanz aus der Konfiguration hat eine Route (= Seite mit dem
 * Template „game-night“) und einen eigenen Datenordner user/data/game-night/<instanz>/.
 * Anfragen an <route>/api beantwortet der Kern (core/Api.php), bevor Grav Seiten lädt
 * (schnell und ohne Seitencache).
 */
class GameNightPlugin extends Plugin
{
    /** Instanz der gerade angezeigten Seite (für das Template), sonst null. */
    protected $instance = null;

    /** API-Anfrage, die erst nach der Zugriffsprüfung beantwortet wird: [Name, Einstellungen]. */
    protected $pendingApi = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    /**
     * Alle gültigen Instanzen: Name => Einstellungen (mit 'route'). In der Konfiguration entweder als
     * Liste mit 'name' (so speichert Admin2) oder als Zuordnung Name => Einstellungen (ältere Schreibweise;
     * das klassische Admin behält solche Schlüssel und ergänzt 'name').
     */
    protected function instances(): array
    {
        $instances = (array)$this->config->get('plugins.game-night.instances', []);
        $valid = [];
        foreach ($instances as $key => $settings) {
            if (!is_array($settings)) {
                continue;
            }
            // Name aus dem Feld 'name' (Admin), sonst aus dem Schlüssel (ältere Schreibweise Name => Einstellungen)
            $name = is_string($settings['name'] ?? null) && $settings['name'] !== '' ? $settings['name'] : (is_int($key) ? '' : (string)$key);
            $route = rtrim((string)($settings['route'] ?? ''), '/');
            if (preg_match('/^[a-z0-9_-]{1,40}$/', $name) && $route !== '' && !isset($valid[$name])) {
                $valid[$name] = ['route' => $route] + $settings;
            }
        }
        return $valid;
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            return;
        }

        $path = $this->grav['uri']->path();
        foreach ($this->instances() as $name => $settings) {
            if ($path === $settings['route'] . '/api') {
                if ($this->loginActive()) {
                    // Zugriffsrechte der Seite (Login-Plugin) gelten auch für ihre API: erst prüfen,
                    // wenn die Seiten geladen sind
                    $this->pendingApi = [$name, $settings];
                    $this->enable(['onPagesInitialized' => ['onPagesInitialized', -1000]]);
                    return;
                }
                $this->runApi($name, $settings);   // beendet die Anfrage
            }
            if ($path === $settings['route']) {
                $this->instance = $settings;
            }
        }

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            'onPageInitialized'   => ['onPageInitialized', 0],
        ]);
    }

    /** Ist das Login-Plugin aktiv? Nur dann kann eine Seite Zugriffsrechte haben. */
    protected function loginActive(): bool
    {
        return (bool)$this->config->get('plugins.login.enabled') && isset($this->grav['login']);
    }

    /** API einer Seite mit Zugriffsbeschränkung: nur antworten, wenn die Seite gesehen werden darf. */
    public function onPagesInitialized(): void
    {
        [$name, $settings] = $this->pendingApi;
        $page = $this->grav['pages']->find($settings['route']);
        $user = $this->grav['user'] ?? null;
        if (!$page || !$user || !$this->grav['login']->isUserAuthorizedForPage($user, $page)) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            $de = str_contains((string)file_get_contents('php://input'), '"lang":"de"');   // die Seite schickt ihre Sprache mit
            $msg = $page ? ($de ? 'Bitte zuerst anmelden' : 'Please log in first') : ($de ? 'Seite nicht gefunden' : 'Page not found');
            echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $this->runApi($name, $settings);   // beendet die Anfrage
    }

    protected function runApi(string $name, array $settings): void
    {
        $base = $this->grav['locator']->findResource('user-data://', true) . '/game-night';
        $this->loadCore();
        $config = \GameNight\instance_config($settings, "$base/$name", "$base/.state");
        // Besucheradresse wie Grav sie ermittelt (folgt system.http_x_forwarded, z. B. hinter Cloudflare) –
        // ab Grav 2 und 1.7.53.11: Ältere 1.7-Versionen vertrauen X-Forwarded-For standardmäßig, damit könnte
        // jeder Besucher die Anfragelimits umgehen. Dort gilt REMOTE_ADDR. Die Instanz-Einstellung
        // proxy_ip_header geht immer vor (core/Api.php setzt dann client_ip).
        if (version_compare(GRAV_VERSION, '1.7.53.11', '>=')) {
            $config['client_ip'] ??= $this->grav['uri']->ip();
        }
        \GameNight\run($config);
    }

    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onPageInitialized(): void
    {
        $page = $this->grav['page'];
        if ($page && $page->template() === 'game-night') {
            // Die Seiten-URL (bei privaten Instanzen geheim) soll nie über Links nach außen gelangen
            header('Referrer-Policy: no-referrer');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header("Content-Security-Policy: frame-ancestors 'none'");
        }
    }

    public function onTwigSiteVariables(): void
    {
        if ($this->instance === null) {
            // andere Seite (oder Spieleseite ohne Eintrag in der Konfiguration): Template zeigt einen Hinweis
            $this->grav['twig']->twig_vars['game_night'] = ['configured' => false];
            return;
        }
        $this->loadCore();
        require_once __DIR__ . '/core/Page.php';
        // Einstellungen so auswerten wie die API (unbekannte Spiele ignoriert, leer = alle usw.)
        $config = \GameNight\instance_config($this->instance, '', '');
        $this->grav['twig']->twig_vars['game_night'] = [
            'configured' => true,
            'lobby'      => $config['lobby'],
            'contact'    => $config['contact_url'],
            'games'      => $config['games'],
            'scripts'    => \GameNight\SCRIPTS,
            'version'    => \GameNight\asset_version(__DIR__ . '/assets'),
        ];
    }

    protected function loadCore(): void
    {
        if (!defined('GAME_NIGHT')) {
            define('GAME_NIGHT', 1);
        }
        require_once __DIR__ . '/core/Api.php';
    }
}
