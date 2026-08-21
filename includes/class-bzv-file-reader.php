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
            ',' => substr_count($first, ','),
            ';' => substr_count($first, ';'),
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

        return self::trim_to_header($rows);
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
                throw new RuntimeException('Werkblad kon niet worden gelezen: ' . $sheet_path . '.');
            }
        } finally {
            $zip->close();
        }

        $xml = self::load_xml($sheet_xml, 'Werkblad-XML is ongeldig.');
        $row_nodes = $xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]');
        if ($row_nodes === false) {
            throw new RuntimeException('Werkblad bevat geen leesbare rijen.');
        }

        $rows = [];
        foreach ($row_nodes as $row) {
            $out = [];
            $cells = $row->xpath('./*[local-name()="c"]');
            if ($cells === false) {
                continue;
            }

            foreach ($cells as $cell) {
                $reference = (string) $cell['r'];
                preg_match('/([A-Z]+)\d+/i', $reference, $match);
                $column = self::xlsx_col_to_index(strtoupper($match[1] ?? 'A'));
                $type = (string) $cell['t'];

                if ($type === 'inlineStr') {
                    $inline = $cell->xpath('./*[local-name()="is"]');
                    $value = $inline ? self::flatten_text($inline[0]) : '';
                } else {
                    $value_nodes = $cell->xpath('./*[local-name()="v"]');
                    $raw_value = $value_nodes ? (string) $value_nodes[0] : '';

                    if ($type === 's') {
                        $value = $shared[(int) $raw_value] ?? '';
                    } elseif ($type === 'b') {
                        $value = $raw_value === '1' ? '1' : '0';
                    } else {
                        $value = $raw_value;
                    }
                }

                $out[$column] = self::maybe_utf8($value);
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

        return self::trim_to_header($rows);
    }

    private static function read_shared_strings(ZipArchive $zip): array {
        $shared_xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($shared_xml === false) {
            return [];
        }

        $xml = self::load_xml($shared_xml, 'Shared strings konden niet worden gelezen.');
        $items = $xml->xpath('//*[local-name()="si"]');
        if ($items === false) {
            return [];
        }

        $shared = [];
        foreach ($items as $item) {
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
            throw new RuntimeException('Ongeldige XLSX-structuur: workbook of relaties ontbreken.');
        }

        $workbook = self::load_xml($workbook_xml, 'Workbook-XML is ongeldig.');
        $rels = self::load_xml($rels_xml, 'Workbook-relaties zijn ongeldig.');

        $sheets = $workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]');
        if (!$sheets) {
            throw new RuntimeException('Geen werkblad gevonden.');
        }

        $sheet = $sheets[0];
        $rid_attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string) ($rid_attrs['id'] ?? '');
        if ($rid === '') {
            $rid_nodes = $sheet->xpath('./@*[local-name()="id"]');
            $rid = $rid_nodes ? (string) $rid_nodes[0] : '';
        }

        if ($rid === '') {
            throw new RuntimeException('Werkblad-ID niet gevonden.');
        }

        $target = '';
        $relationships = $rels->xpath('//*[local-name()="Relationship"]');
        if ($relationships) {
            foreach ($relationships as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = (string) $rel['Target'];
                    break;
                }
            }
        }

        if ($target === '') {
            throw new RuntimeException('Werkblad-relatie niet gevonden.');
        }

        $target = str_replace('\\', '/', $target);
        $target = preg_replace('#^\.\./#', '', $target);
        $target = ltrim((string) $target, '/');

        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
    }

    private static function trim_to_header(array $rows): array {
        foreach ($rows as $index => $row) {
            $normalized = array_map([__CLASS__, 'normalize_header'], $row);

            $has_customer = in_array('klant', $normalized, true)
                || in_array('customer', $normalized, true)
                || in_array('verkooppunt', $normalized, true);
            $has_postal = in_array('postcode', $normalized, true)
                || in_array('postal code', $normalized, true)
                || in_array('postalcode', $normalized, true);
            $has_city = in_array('plaats', $normalized, true)
                || in_array('city', $normalized, true)
                || in_array('woonplaats', $normalized, true);
            $has_address = false;

            foreach ($normalized as $value) {
                if (in_array($value, ['straat huisnr', 'straat huisnummer', 'straat en huisnummer', 'adres', 'address'], true)) {
                    $has_address = true;
                    break;
                }
            }

            if ($has_customer && $has_address && $has_postal && $has_city) {
                return array_slice($rows, $index);
            }
        }

        return $rows;
    }

    private static function normalize_header($value): string {
        $value = function_exists('remove_accents') ? remove_accents((string) $value) : (string) $value;
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[+_\-.\/]+/u', ' ', $value);
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function load_xml(string $xml, string $error_message): SimpleXMLElement {
        $previous = libxml_use_internal_errors(true);
        $element = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($element === false) {
            throw new RuntimeException($error_message);
        }
        return $element;
    }

    private static function xlsx_col_to_index(string $letters): int {
        $number = 0;
        foreach (str_split($letters) as $char) {
            $number = ($number * 26) + (ord($char) - 64);
        }
        return $number - 1;
    }
}
