<?php
declare(strict_types=1);

namespace Dwell\Import;

use Dwell\Core\AppError;

/**
 * Reads .xlsx (Office Open XML) and .csv files into plain rows of cell values, using only
 * PHP's zip and XML extensions. Cell values come back as strings, numbers or booleans exactly
 * as stored; dates in .xlsx are serial numbers (see excelDate()).
 *
 * Defensive limits: file size, number of rows and columns, uncompressed XML size; external XML
 * entities are never loaded.
 */
final class SpreadsheetReader
{
    private const MAX_FILE = 15_000_000;
    private const MAX_XML = 60_000_000;
    private const MAX_ROWS = 20_000;
    private const MAX_COLS = 80;

    /**
     * @return array{sheets: array<string, array<int, array<int, mixed>>>, date1904: bool}
     */
    public static function read(string $path, string $originalName): array
    {
        if (!is_file($path) || filesize($path) === 0) {
            throw new AppError('The file is empty.');
        }
        if (filesize($path) > self::MAX_FILE) {
            throw new AppError('That file is too large (15 MB maximum).');
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') {
            return ['sheets' => [pathinfo($originalName, PATHINFO_FILENAME) ?: 'Sheet1' => self::readCsv($path)], 'date1904' => false];
        }
        if ($ext !== 'xlsx') {
            throw new AppError('Please upload an Excel .xlsx file (or a .csv). Older .xls files: open in Excel and “Save As” .xlsx first.');
        }
        return self::readXlsx($path);
    }

    private static function readCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if (!$fh) {
            throw new AppError('The file couldn’t be opened.');
        }
        $first = fgets($fh) ?: '';
        rewind($fh);
        if (str_starts_with($first, "\xEF\xBB\xBF")) {
            fread($fh, 3);
        }
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        $rows = [];
        while (($row = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
            $rows[] = array_map(static fn ($v) => mb_check_encoding((string) $v, 'UTF-8') ? $v : mb_convert_encoding((string) $v, 'UTF-8', 'Windows-1252'), array_slice($row, 0, self::MAX_COLS));
        }
        fclose($fh);
        return $rows;
    }

    private static function readXlsx(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new AppError('Reading .xlsx needs the PHP zip extension. Save the sheet as .csv instead, or install php-zip.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new AppError('That doesn’t look like a valid .xlsx file.');
        }
        try {
            $workbook = self::xml($zip, 'xl/workbook.xml');
            $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');
            if (!$workbook || !$rels) {
                throw new AppError('That doesn’t look like a valid .xlsx file.');
            }
            $date1904 = false;
            $ns = $workbook->getNamespaces(true);
            $main = $workbook->children($ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            if (isset($main->workbookPr)) {
                $attr = (string) ($main->workbookPr->attributes()['date1904'] ?? '');
                $date1904 = $attr === '1' || $attr === 'true';
            }
            $targets = [];
            foreach ($rels->children() as $rel) {
                $a = $rel->attributes();
                $targets[(string) $a['Id']] = (string) $a['Target'];
            }
            $shared = self::sharedStrings($zip);
            $sheets = [];
            $rNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
            foreach ($main->sheets->sheet ?? [] as $sheet) {
                $name = (string) $sheet->attributes()['name'];
                $rid = (string) $sheet->attributes($rNs)['id'];
                $target = $targets[$rid] ?? '';
                if ($target === '') {
                    continue;
                }
                $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
                $sheets[$name] = self::sheetRows($zip, $target, $shared);
            }
            if (!$sheets) {
                throw new AppError('No worksheets were found in that file.');
            }
            return ['sheets' => $sheets, 'date1904' => $date1904];
        } finally {
            $zip->close();
        }
    }

    private static function xml(\ZipArchive $zip, string $name): ?\SimpleXMLElement
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ($stat['size'] > self::MAX_XML) {
            throw new AppError('That file is too large to read.');
        }
        $data = $zip->getFromName($name);
        if ($data === false) {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($data, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $xml === false ? null : $xml;
    }

    private static function sharedStrings(\ZipArchive $zip): array
    {
        $xml = self::xml($zip, 'xl/sharedStrings.xml');
        if (!$xml) {
            return [];
        }
        $out = [];
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $out[] = (string) $si->t;
            } else {
                $text = '';
                foreach ($si->r as $run) {
                    $text .= (string) $run->t;
                }
                $out[] = $text;
            }
        }
        return $out;
    }

    private static function sheetRows(\ZipArchive $zip, string $name, array $shared): array
    {
        $xml = self::xml($zip, $name);
        if (!$xml || !isset($xml->sheetData)) {
            return [];
        }
        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $r = (int) $row->attributes()['r'];
            if ($r < 1 || $r > self::MAX_ROWS) {
                continue;
            }
            $cells = [];
            foreach ($row->c as $c) {
                $a = $c->attributes();
                $col = self::colIndex((string) $a['r']);
                if ($col === null || $col >= self::MAX_COLS) {
                    continue;
                }
                $t = (string) $a['t'];
                $v = isset($c->v) ? (string) $c->v : null;
                $value = match ($t) {
                    's'         => $shared[(int) $v] ?? '',
                    'inlineStr' => (string) ($c->is->t ?? ''),
                    'b'         => $v === '1',
                    'str', 'e'  => (string) $v,
                    default     => $v === null || $v === '' ? null : (is_numeric($v) ? $v + 0 : $v),
                };
                $cells[$col] = $value;
            }
            if ($cells) {
                $max = max(array_keys($cells));
                $line = array_fill(0, $max + 1, null);
                foreach ($cells as $i => $v) {
                    $line[$i] = $v;
                }
                $rows[$r - 1] = $line;
            }
        }
        if (!$rows) {
            return [];
        }
        // Keep row positions (row numbers matter to people reading the preview).
        $out = [];
        for ($i = 0, $n = max(array_keys($rows)); $i <= $n; $i++) {
            $out[] = $rows[$i] ?? [];
        }
        return $out;
    }

    private static function colIndex(string $ref): ?int
    {
        if (!preg_match('/^([A-Z]{1,3})\d+$/', $ref, $m)) {
            return null;
        }
        $n = 0;
        foreach (str_split($m[1]) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }

    /** Excel serial date → "Y-m-d" (null if not a plausible date). */
    public static function excelDate(float $serial, bool $date1904 = false): ?string
    {
        if ($serial < 1 || $serial > 2958465) {
            return null;
        }
        $days = (int) floor($serial);
        if ($date1904) {
            $base = new \DateTimeImmutable('1904-01-01');
        } else {
            // Excel counts a non-existent 1900-02-29 (serial 60).
            $base = new \DateTimeImmutable($days >= 61 ? '1899-12-30' : '1899-12-31');
        }
        return $base->modify("+$days days")->format('Y-m-d');
    }

    /**
     * A date cell or text → "Y-m-d". Accepts Excel serials, 2026-10-13, 10/13/2026, 10/13/26,
     * "Oct 13, 2026" and "13-Oct-2026". Month/day-only text ("Oct 13", "10/13") returns "0000-10-13".
     */
    public static function toDate(mixed $value, bool $date1904 = false): ?string
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return self::excelDate((float) $value, $date1904);
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        if (is_numeric($s)) {
            return self::excelDate((float) $s, $date1904);
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2}|\d{4})$#', $s, $m)) {
            $y = (int) $m[3];
            if ($y < 100) {
                $y += $y > (int) date('y') + 1 ? 1900 : 2000;
            }
            return checkdate((int) $m[1], (int) $m[2], $y) ? sprintf('%04d-%02d-%02d', $y, $m[1], $m[2]) : null;
        }
        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})$#', $s, $m)) {
            return checkdate((int) $m[1], (int) $m[2], 2000) ? sprintf('0000-%02d-%02d', $m[1], $m[2]) : null;
        }
        $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        if (preg_match('/^([a-z]{3})[a-z]*\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?$/i', $s, $m) && isset($months[strtolower($m[1])])) {
            $mo = $months[strtolower($m[1])];
            $y = isset($m[3]) ? (int) $m[3] : 0;
            return checkdate($mo, (int) $m[2], $y ?: 2000) ? sprintf('%04d-%02d-%02d', $y, $mo, $m[2]) : null;
        }
        if (preg_match('/^(\d{1,2})[- ]([a-z]{3})[a-z]*[- ](\d{2,4})$/i', $s, $m) && isset($months[strtolower($m[2])])) {
            $y = (int) $m[3];
            if ($y < 100) {
                $y += $y > (int) date('y') + 1 ? 1900 : 2000;
            }
            $mo = $months[strtolower($m[2])];
            return checkdate($mo, (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $m[1]) : null;
        }
        return null;
    }
}
