<?php

if (!defined('ABSPATH')) {
    exit;
}

final class BZV_File_Reader {
    public static function read(string $path, string $extension): array {
        $extension = strtolower($extension);
        if ($extension === 'csv') {
            return self::parse_csv($path);
        }
        if ($extension === 'xlsx') {
            return self::parse_xlsx($path);
        }
        throw new RuntimeException('Alleen CSV- en XLSX-bestanden worden ondersteund.');
    }

    private static function parse_csv(string $path): array {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException('CSV kon niet worden geopend.');
        }

        $first = fgets($handle);
        if ($first === false) {
            fclose($handle);
            return [];
        }

        $delimiters = [
            ','  => substr_count($first, ','),
            ';'  => substr_count($first, ';'),
            "\t" => substr_count($first, "\t"),
        ];
        arsort($delimiters);
        $delimiter = (string) array_key_first($delimiters);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = array_map([__CLASS__, 'maybe_utf8'], $row);
        }
        fclose($handle);

        return $rows;
    }

    private static function maybe_utf8($value): string {
        $value = (string) $value;
        if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }
        return (string) preg_replace('/^\xEF\xBB\xBF/', '', $value);
    }

    private static function parse_xlsx(string $path): array {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive is niet beschikbaar; gebruik voorlopig CSV.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('XLSX kon niet worden geopend.');
        }

        try {
            $shared = self::read_shared_strings($zip);
            $sheet_path = self::first_sheet_path($zip);
            $sheet_xml = $zip->getFromName($sheet_path);
            if ($sheet_xml === false) {
                throw new RuntimeException('Werkblad kon niet worden gelezen.');
            }
        } finally {
            $zip->close();
        }

        $xml = simplexml_load_string($sheet_xml);
        if (!$xml) {
            throw new RuntimeException('Werkblad-XML is ongeldig.');
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $out = [];
            foreach ($row->c as $cell) {
                $reference = (string) $cell['r'];
                preg_match('/([A-Z]+)\d+/', $reference, $match);
                $column = self::xlsx_col_to_index($match[1] ?? 'A');
                $type = (string) $cell['t'];

                if ($type === 's') {
                    $value = $shared[(int) $cell->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = self::flatten_text($cell->is);
                } else {
                    $value = (string) $cell->v;
                }
                $out[$column] = $value;
            }

            if (!$out) {
                continue;
            }

            ksort($out);
            $max = max(array_keys($out));
            $filled = [];
            for ($i = 0; $i <= $max; $i++) {
                $filled[] = $out[$i] ?? '';
            }
            $rows[] = $filled;
        }

        return $rows;
    }

    private static function read_shared_strings(ZipArchive $zip): array {
        $shared_xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($shared_xml === false) {
            return [];
        }

        $xml = simplexml_load_string($shared_xml);
        if (!$xml) {
            return [];
        }

        $shared = [];
        foreach ($xml->si as $item) {
            $shared[] = self::flatten_text($item);
        }
        return $shared;
    }

    private static function flatten_text(SimpleXMLElement $element): string {
        $parts = $element->xpath('.//*[local-name()="t"]');
        if (!$parts) {
            return (string) $element;
        }

        $text = '';
        foreach ($parts as $part) {
            $text .= (string) $part;
        }
        return $text;
    }

    private static function first_sheet_path(ZipArchive $zip): string {
        $workbook_xml = $zip->getFromName('xl/workbook.xml');
        $rels_xml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook_xml === false || $rels_xml === false) {
            throw new RuntimeException('Ongeldige XLSX-structuur.');
        }

        $workbook = simplexml_load_string($workbook_xml);
        $rels = simplexml_load_string($rels_xml);
        if (!$workbook || !$rels) {
            throw new RuntimeException('Ongeldige XLSX-structuur.');
        }

        $sheet = $workbook->sheets->sheet[0] ?? null;
        if (!$sheet) {
            throw new RuntimeException('Geen werkblad gevonden.');
        }

        $rid_attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string) $rid_attrs['id'];
        $target = '';

        foreach ($rels->Relationship as $rel) {
            if ((string) $rel['Id'] === $rid) {
                $target = (string) $rel['Target'];
                break;
            }
        }

        if (!$target) {
            throw new RuntimeException('Werkblad-relatie niet gevonden.');
        }

        $target = ltrim(str_replace('../', '', $target), '/');
        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
    }

    private static function xlsx_col_to_index(string $letters): int {
        $number = 0;
        foreach (str_split($letters) as $char) {
            $number = ($number * 26) + (ord($char) - 64);
        }
        return $number - 1;
    }
}
