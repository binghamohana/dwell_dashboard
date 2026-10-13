<?php
declare(strict_types=1);

// "Sign in with Google": ?start sends the browser to Google; Google sends it back here.
require dirname(__DIR__) . '/src/bootstrap.php';

use Dwell\Core\AppError;
use Dwell\Core\GoogleSignIn;
use Dwell\Core\Http;

Http::securityHeaders();
header('Cache-Control: no-store');
if (!GoogleSignIn::enabled()) {
    header('Location: ./#/login');
    exit;
}
if (isset($_GET['start'])) {
    GoogleSignIn::start();
    exit;
}
try {
    GoogleSignIn::callback($_GET);
    header('Location: ./#/');
} catch (AppError $e) {
    header('Location: ./#/login?error=' . rawurlencode($e->getMessage()));
} catch (Throwable $e) {
    Dwell\Core\App::log('Google sign-in: ' . $e->getMessage());
    header('Location: ./#/login?error=' . rawurlencode('Google sign-in didn’t complete. Please try again.'));
}
