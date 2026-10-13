<?php
declare(strict_types=1);

namespace Dwell\Employees;

use Dwell\Core\AppError;
use Dwell\Core\Audit;
use Dwell\Core\Db;
use Dwell\Core\Time;

/**
 * Approved staff photos. Uploaded by administrators, re-encoded to a 400×400 JPEG (which also
 * strips location and camera data), stored outside the web folder in storage/photos and served
 * only to signed-in people allowed to see that person (public/photo.php).
 */
final class Photos
{
    private const SIZE = 400;
    private const MAX_BYTES = 12_000_000;

    public static function dir(): string
    {
        return DC_ROOT . '/storage/photos';
    }

    public static function path(?string $file): ?string
    {
        if (!$file || !preg_match('/^[a-f0-9]{32}\.jpg$/', $file)) {
            return null;
        }
        $path = self::dir() . '/' . $file;
        return is_file($path) ? $path : null;
    }

    public static function upload(int $employeeId, array $file): void
    {
        $r = Employees::row($employeeId);
        if (!$r) {
            throw AppError::notFound();
        }
        if (!function_exists('imagecreatetruecolor')) {
            throw new AppError('Photo uploads need the PHP GD extension on the server.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (PHP_SAPI !== 'cli' && !is_uploaded_file($file['tmp_name'] ?? ''))) {
            throw new AppError('The upload didn’t arrive. Please try again.');
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new AppError('That photo is too large (12 MB maximum).');
        }
        $tmp = (string) $file['tmp_name'];
        $info = @getimagesize($tmp);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new AppError('Please upload a JPG, PNG or WebP photo.');
        }
        if ($info[0] * $info[1] > 40_000_000) {
            throw new AppError('That photo has too many pixels. Please use a smaller one.');
        }
        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        };
        if (!$src) {
            throw new AppError('That photo couldn’t be read. Please try another file.');
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmp);
            $rotate = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
            if ($rotate) {
                $rotated = imagerotate($src, $rotate, 0);
                if ($rotated) {
                    imagedestroy($src);
                    $src = $rotated;
                }
            }
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        // Square crop, slightly above center (faces sit in the upper part of portraits)
        $sx = (int) (($w - $side) / 2);
        $sy = (int) max(0, min($h - $side, ($h - $side) * 0.35));
        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, self::SIZE, self::SIZE, $side, $side);
        imagedestroy($src);

        $name = bin2hex(random_bytes(16)) . '.jpg';
        if (!is_dir(self::dir()) || !is_writable(self::dir()) || !imagejpeg($dst, self::dir() . '/' . $name, 86)) {
            imagedestroy($dst);
            throw new AppError('The photo couldn’t be saved: storage/photos must be writable by the web server.');
        }
        imagedestroy($dst);
        $old = $r['photo'];
        Db::update('employees', ['photo' => $name, 'updated_at' => Time::nowDb()], 'id = :id', ['id' => $employeeId]);
        if ($old && ($p = self::path($old))) {
            @unlink($p);
        }
        Audit::log('photo_upload', 'employee', $employeeId, 'Photo updated: ' . Employees::displayName($r));
        Employees::flush();
    }

    public static function remove(int $employeeId): void
    {
        $r = Employees::row($employeeId);
        if (!$r || !$r['photo']) {
            return;
        }
        if ($p = self::path($r['photo'])) {
            @unlink($p);
        }
        Db::update('employees', ['photo' => null, 'updated_at' => Time::nowDb()], 'id = :id', ['id' => $employeeId]);
        Audit::log('photo_remove', 'employee', $employeeId, 'Photo removed: ' . Employees::displayName($r));
        Employees::flush();
    }
}
