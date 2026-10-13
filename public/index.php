<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    Dwell\Core\Page::shell();
} catch (Throwable $e) {
    Dwell\Core\Page::setupError($e);
}
