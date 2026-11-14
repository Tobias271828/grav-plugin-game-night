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

    /** Alle gültigen Instanzen: Name => Einstellungen (mit 'route'). */
    protected function instances(): array
    {
        $instances = (array)$this->config->get('plugins.game-night.instances', []);
        $valid = [];
        foreach ($instances as $name => $settings) {
            $route = rtrim((string)($settings['route'] ?? ''), '/');
            if (is_array($settings) && preg_match('/^[a-z0-9_-]{1,40}$/', (string)$name) && $route !== '') {
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
        \GameNight\run(\GameNight\instance_config($settings, "$base/$name", "$base/.state"));
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
