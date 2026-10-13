<?php
declare(strict_types=1);

// Staff photos are private: only signed-in people who may see that person get the image.
require dirname(__DIR__) . '/src/bootstrap.php';

use Dwell\Core\Access;
use Dwell\Core\Auth;
use Dwell\Employees\Employees;
use Dwell\Employees\Photos;

try {
    $user = Auth::current();
    $id = (int) ($_GET['id'] ?? 0);
    $row = $id ? Employees::row($id) : null;
    $path = $row ? Photos::path($row['photo']) : null;
    if (!$user || !Access::can($user, 'people.view') || !$row || !Access::canSee($user, $id) || !$path
        || ($row['status'] === 'terminated' && !Access::can($user, 'people.all'))) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
} catch (Throwable $e) {
    http_response_code(500);
}
