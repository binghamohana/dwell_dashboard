<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Dwell\Api\Router;
use Dwell\Core\Auth;
use Dwell\Core\Http;

Http::securityHeaders();
Http::handle(static function (): void {
    $action = (string) ($_GET['action'] ?? '');
    $method = Http::method();
    $routes = Router::routes();
    $public = isset($routes[$action]) && $routes[$action][1] === 'public';
    if ($method === 'POST') {
        // Changes must come from this site's own pages.
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
            throw new Dwell\Core\AppError('Requests from other sites are not accepted.', 403, null, 'origin');
        }
        if (!$public) {
            Auth::checkCsrf(); // every change from a signed-in session carries the CSRF header
        }
    }
    $input = $method === 'GET' ? $_GET : Http::input();
    unset($input['action']);
    Http::json(Router::dispatch($action, $method, $input, Auth::current()));
});
