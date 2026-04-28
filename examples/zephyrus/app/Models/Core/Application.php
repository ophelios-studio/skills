<?php

declare(strict_types=1);

namespace App\Models\Core;

use Dotenv\Dotenv;
use Zephyrus\Application\ApplicationBuilder;
use Zephyrus\Application\Configuration;
use Zephyrus\Routing\Router;

/**
 * Minimal Application class.
 *
 * Production projects (see immunity-app) usually extend Leaf\Kernel or
 * provide a richer subclass that:
 *   - registers named middlewares (registerMiddleware)
 *   - injects a custom controller factory (withControllerFactory)
 *   - parses DATABASE_URL on boot (Db::applyDatabaseUrl)
 *
 * This stub is the bare minimum to get a route responding.
 */
final class Application
{
    public function run(): void
    {
        // Load .env if present (does not override existing $_ENV)
        if (file_exists(ROOT_DIR . '/.env')) {
            Dotenv::createImmutable(ROOT_DIR)->safeLoad();
        }

        // Default MODE for single-process local dev
        if (!isset($_ENV['MODE']) && getenv('MODE') === false) {
            $_ENV['MODE'] = 'WEB';
            putenv('MODE=WEB');
        }

        $config = Configuration::fromYamlFile(ROOT_DIR . '/config.yml');

        $router = (new Router())->discoverControllers(
            namespace: 'App\\Controllers',
            directory: ROOT_DIR . '/app/Controllers',
        );

        $app = ApplicationBuilder::create()
            ->withConfiguration($config, basePath: ROOT_DIR)
            ->withRouter($router)
            ->build();

        $app->run();
    }
}
