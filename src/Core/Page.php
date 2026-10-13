<?php
declare(strict_types=1);

namespace Dwell\Core;

/** The single HTML page; everything else is drawn by the JavaScript app from the API. */
final class Page
{
    public static function asset(string $path): string
    {
        $file = DC_ROOT . '/public/' . $path;
        return $path . '?v=' . (is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : '1');
    }

    public static function shell(): void
    {
        Http::securityHeaders();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        $portal = (string) Settings::get('portal_name');
        $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $boot = [
            'portal_name' => $portal,
            'org_name'    => Settings::get('org_name'),
            'mark'        => self::asset('assets/img/mark.svg'),
        ];
        $scripts = ['assets/vendor/jquery-3.7.1.min.js', 'assets/vendor/d3-7.9.0.min.js', 'assets/js/common.js', 'assets/js/app.js',
            'assets/js/dashboard.js', 'assets/js/people.js', 'assets/js/orgchart.js', 'assets/js/celebrations.js', 'assets/js/admin.js', 'assets/js/import.js'];
        ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($portal) ?></title>
<meta name="theme-color" content="#F4F2EE">
<link rel="icon" href="<?= $e(self::asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preload" href="assets/fonts/inter-latin-var.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= $e(self::asset('assets/css/app.css')) ?>">
</head>
<body>
<div id="app"><div class="boot-loading"><span class="spinner"></span></div></div>
<noscript><p style="padding:24px">Please turn on JavaScript to use <?= $e($portal) ?>.</p></noscript>
<script type="application/json" id="boot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php foreach ($scripts as $s): ?>
<script src="<?= $e(self::asset($s)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
    }

    public static function setupError(\Throwable $e): void
    {
        App::log('Page error: ' . $e->getMessage());
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Temporarily unavailable</title>'
            . '<body style="font-family:system-ui,sans-serif;background:#F4F2EE;color:#1A2127;display:grid;place-items:center;min-height:100vh;margin:0">'
            . '<div style="max-width:440px;padding:24px;text-align:center"><h1 style="font-weight:500">Dwell Central is temporarily unavailable</h1>'
            . '<p>Please try again in a few minutes. If this keeps happening, contact the system administrator.</p>'
            . (App::config('debug') ? '<pre style="text-align:left;white-space:pre-wrap;font-size:12px">' . htmlspecialchars($e->getMessage()) . '</pre>' : '')
            . '</div></body>';
    }
}
