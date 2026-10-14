<?php
declare(strict_types=1);

namespace Dwell\Core;

/** Branded HTML + plain-text email bodies. Inline styles and table layout for broad client support. */
final class EmailTemplate
{
    // Dwell Communities palette
    private const BRAND = '#113263';
    private const ACCENT = '#C26A43';
    private const INK = '#3A3938';
    private const MUTED = '#737170';
    private const SAND = '#F5F4F4';
    private const LINE = '#E8E6E6';
    private const FONT = 'Helvetica,Arial,sans-serif';

    /**
     * @param array{
     *   preheader?: string, banner?: string, eyebrow?: string, heading: string, paragraphs?: string[],
     *   groups?: array<int, array{title: string, items: array<int, array{tag?: string, main: string, sub?: string}>}>,
     *   details?: array<string,string>, notes?: array<int, array{title: string, body: string}>,
     *   buttons?: array<string,string>, footer?: string
     * } $m
     * @return array{0: string, 1: string} [html, text]
     */
    public static function render(array $m): array
    {
        $portal = (string) Settings::get('portal_name');
        $org = (string) Settings::get('org_name');
        $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $nl = static fn ($s) => nl2br($e($s), false);
        $p = 'font-family:' . self::FONT . ';';

        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $e($m['heading']) . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . self::SAND . ';">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $e($m['preheader'] ?? '') . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . self::SAND . ';padding:28px 12px;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;">'
            // Logo image; if the email app blocks images, the alt text shows the name instead.
            . '<tr><td style="padding:0 8px 16px;">'
            . '<img src="' . $e(App::url('assets/img/email-logo.png')) . '" width="180" height="51" alt="' . $e($org) . '"'
            . ' style="display:block;border:0;outline:none;' . $p . 'font-size:16px;letter-spacing:3px;text-transform:uppercase;color:' . self::BRAND . ';font-weight:700;">'
            . '<div style="' . $p . 'font-size:10px;letter-spacing:4px;text-transform:uppercase;color:' . self::BRAND . ';font-weight:700;margin-top:8px;">' . $e(preg_replace('/^dwell\s+/i', '', $portal)) . '</div>'
            . '</td></tr>';

        if (!empty($m['banner'])) {
            $html .= '<tr><td style="padding:0 0 12px;"><div style="background:#FBF0D9;border:1px solid #EBD9AE;border-radius:10px;padding:12px 16px;'
                . $p . 'font-size:13.5px;line-height:1.5;color:#6E4C0B;">' . $nl($m['banner']) . '</div></td></tr>';
        }

        $html .= '<tr><td style="background:#ffffff;border:1px solid ' . self::LINE . ';border-radius:14px;padding:34px 30px;">';
        if (!empty($m['eyebrow'])) {
            $html .= '<div style="' . $p . 'font-size:12px;letter-spacing:2px;text-transform:uppercase;color:' . self::ACCENT . ';font-weight:700;margin-bottom:8px;">' . $e($m['eyebrow']) . '</div>';
        }
        $html .= '<h1 style="margin:0 0 18px;font-family:Georgia,\'Times New Roman\',serif;font-weight:normal;font-size:28px;line-height:1.25;color:' . self::BRAND . ';">' . $e($m['heading']) . '</h1>';

        foreach ($m['paragraphs'] ?? [] as $para) {
            $html .= '<p style="margin:0 0 16px;' . $p . 'font-size:16px;line-height:1.6;color:' . self::INK . ';">' . $nl($para) . '</p>';
        }

        foreach ($m['groups'] ?? [] as $group) {
            $html .= '<div style="margin:22px 0 6px;' . $p . 'font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:' . self::BRAND . ';font-weight:700;">' . $e($group['title']) . '</div>'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid ' . self::LINE . ';">';
            foreach ($group['items'] as $item) {
                $html .= '<tr>'
                    . '<td style="padding:11px 10px 11px 0;border-bottom:1px solid ' . self::LINE . ';width:96px;vertical-align:top;' . $p . 'font-size:12.5px;color:' . self::ACCENT . ';font-weight:700;">' . $e($item['tag'] ?? '') . '</td>'
                    . '<td style="padding:11px 0;border-bottom:1px solid ' . self::LINE . ';vertical-align:top;' . $p . '">'
                    . '<div style="font-size:15.5px;font-weight:600;color:' . self::INK . ';">' . $e($item['main']) . '</div>'
                    . (!empty($item['sub']) ? '<div style="font-size:13.5px;color:' . self::MUTED . ';margin-top:2px;">' . $e($item['sub']) . '</div>' : '')
                    . '</td></tr>';
            }
            $html .= '</table>';
        }

        if (!empty($m['details'])) {
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 22px;border-top:1px solid ' . self::LINE . ';">';
            foreach ($m['details'] as $label => $value) {
                $html .= '<tr><td style="padding:11px 0;border-bottom:1px solid ' . self::LINE . ';' . $p . 'font-size:13px;color:' . self::MUTED . ';width:36%;vertical-align:top;">' . $e($label) . '</td>'
                    . '<td style="padding:11px 0;border-bottom:1px solid ' . self::LINE . ';' . $p . 'font-size:15px;color:' . self::INK . ';font-weight:600;vertical-align:top;">' . $nl($value) . '</td></tr>';
            }
            $html .= '</table>';
        }

        foreach ($m['notes'] ?? [] as $note) {
            $html .= '<div style="margin:18px 0;padding:16px 18px;background:' . self::SAND . ';border-radius:10px;">'
                . '<div style="' . $p . 'font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:' . self::BRAND . ';font-weight:700;margin-bottom:6px;">' . $e($note['title']) . '</div>'
                . '<div style="' . $p . 'font-size:15px;line-height:1.6;color:' . self::INK . ';">' . $nl($note['body']) . '</div></div>';
        }

        if (!empty($m['buttons'])) {
            $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px 0 4px;"><tr>';
            $first = true;
            foreach ($m['buttons'] as $label => $url) {
                $style = $first
                    ? 'background:' . self::BRAND . ';color:#ffffff;border:1px solid ' . self::BRAND . ';'
                    : 'background:#ffffff;color:' . self::BRAND . ';border:1px solid ' . self::BRAND . ';';
                $html .= '<td style="padding:0 10px 10px 0;"><a href="' . $e($url) . '" style="' . $style
                    . 'display:inline-block;padding:12px 20px;border-radius:999px;' . $p . 'font-size:15px;font-weight:600;text-decoration:none;">'
                    . $e($label) . '</a></td>';
                $first = false;
            }
            $html .= '</tr></table>';
        }

        $footer = $m['footer'] ?? self::footer();
        $html .= '</td></tr>'
            . '<tr><td style="padding:16px 8px 0;' . $p . 'font-size:12px;line-height:1.6;color:' . self::MUTED . ';">' . $nl($footer) . '</td></tr>'
            . '</table></td></tr></table></body></html>';

        // Plain-text version
        $text = '';
        if (!empty($m['banner'])) {
            $text .= '*** ' . $m['banner'] . " ***\n\n";
        }
        $text .= strtoupper($portal) . "\n\n" . (!empty($m['eyebrow']) ? $m['eyebrow'] . "\n" : '') . $m['heading'] . "\n\n";
        foreach ($m['paragraphs'] ?? [] as $para) {
            $text .= $para . "\n\n";
        }
        foreach ($m['groups'] ?? [] as $group) {
            $text .= strtoupper($group['title']) . "\n";
            foreach ($group['items'] as $item) {
                $text .= '  - ' . (!empty($item['tag']) ? $item['tag'] . ': ' : '') . $item['main'] . (!empty($item['sub']) ? ' (' . $item['sub'] . ')' : '') . "\n";
            }
            $text .= "\n";
        }
        foreach ($m['details'] ?? [] as $label => $value) {
            $text .= $label . ': ' . $value . "\n";
        }
        if (!empty($m['details'])) {
            $text .= "\n";
        }
        foreach ($m['notes'] ?? [] as $note) {
            $text .= strtoupper($note['title']) . "\n" . $note['body'] . "\n\n";
        }
        foreach ($m['buttons'] ?? [] as $label => $url) {
            $text .= $label . ': ' . $url . "\n";
        }
        $text .= "\n--\n" . $footer . "\n";

        return [$html, $text];
    }

    public static function footer(): string
    {
        return Settings::get('portal_name') . ' · ' . Settings::get('org_name') . "\n"
            . 'This message contains private team information. Please don’t forward it.' . "\n"
            . App::baseUrl() . '/';
    }
}
