<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Minimal single-sheet .xlsx writer (no third-party dependency; needs ext-zip).
 *
 * Text goes in as inline strings, so a value such as "=HYPERLINK(...)" is stored as text and can never be
 * evaluated as a formula. Columns marked numeric are written as numbers when the value is a plain decimal.
 */
final class XlsxWriter
{
    /**
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  list<array<string, string|null>>  $rows
     * @return string the file contents
     */
    public static function build(string $sheetTitle, array $columns, array $rows): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required for Excel export.');
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';

        $sheet .= '<row r="1">';
        foreach ($columns as $i => $column) {
            $sheet .= self::text(self::ref($i, 1), $column['label'], 1);
        }
        $sheet .= '</row>';

        foreach ($rows as $r => $row) {
            $line = $r + 2;
            $sheet .= "<row r=\"{$line}\">";
            foreach ($columns as $i => $column) {
                $value = $row[$column['key']] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $value = (string) $value;
                $sheet .= (($column['align'] ?? 'left') === 'right' && preg_match('/^-?\d+(\.\d+)?$/', $value))
                    ? '<c r="'.self::ref($i, $line).'"><v>'.$value.'</v></c>'
                    : self::text(self::ref($i, $line), $value, 0);
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $title = self::xml(mb_substr(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $sheetTitle) ?: 'Report', 0, 31));
        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$title.'" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the spreadsheet file.');
        }
        foreach ($parts as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private static function text(string $ref, string $value, int $style): string
    {
        return '<c r="'.$ref.'" t="inlineStr"'.($style ? ' s="'.$style.'"' : '').'><is><t xml:space="preserve">'.self::xml($value).'</t></is></c>';
    }

    /** Column index (0-based) + row → "A1", "AB12". */
    private static function ref(int $col, int $row): string
    {
        $letters = '';
        for ($n = $col + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters.$row;
    }

    /** Escapes for XML and drops characters that are illegal in XML 1.0. */
    private static function xml(string $value): string
    {
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
