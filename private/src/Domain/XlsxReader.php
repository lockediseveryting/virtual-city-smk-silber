<?php
// Lightweight XLSX reader — no external dependencies beyond ext-zip and ext-simplexml.
// Reads .xlsx spreadsheets (ZIP archives of XML) into array rows or CSV text.
declare(strict_types=1);

namespace VC\Domain;

final class XlsxReader
{
    /**
     * Read an XLSX file and return rows as array of arrays of strings.
     *
     * @param string $filePath Absolute path to .xlsx file
     * @param int $sheetIndex 0-based sheet index (default 0)
     * @return array<int, array<int, string>>
     */
    public static function read(string $filePath, int $sheetIndex = 0): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("File tidak ditemukan atau tidak dapat dibaca: {$filePath}");
        }

        $zip = new \ZipArchive();
        $res = $zip->open($filePath);
        if ($res !== true) {
            throw new \RuntimeException("Gagal membuka file XLSX (ZIP error: {$res})");
        }

        try {
            // 1. Read shared strings
            $sharedStrings = [];
            $ssXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($ssXml !== false) {
                $sharedStrings = self::parseSharedStrings($ssXml);
            }

            // 2. Identify target sheet path
            $sheetPath = "xl/worksheets/sheet" . ($sheetIndex + 1) . ".xml";
            $sheetXml = $zip->getFromName($sheetPath);

            // Fallback: search for first available worksheet
            if ($sheetXml === false) {
                for ($i = 1; $i <= 10; $i++) {
                    $tryPath = "xl/worksheets/sheet{$i}.xml";
                    $xml = $zip->getFromName($tryPath);
                    if ($xml !== false) {
                        $sheetXml = $xml;
                        break;
                    }
                }
            }

            if ($sheetXml === false) {
                throw new \RuntimeException("Lembar kerja (worksheet) tidak ditemukan dalam berkas XLSX.");
            }

            // 3. Parse worksheet cells
            return self::parseWorksheet($sheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    /**
     * Convert XLSX file directly to CSV string content.
     */
    public static function toCsv(string $filePath, int $sheetIndex = 0, string $delimiter = ','): string
    {
        $rows = self::read($filePath, $sheetIndex);
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return '';
        }
        foreach ($rows as $row) {
            fputcsv($stream, $row, $delimiter);
        }
        rewind($stream);
        $csv = (string)stream_get_contents($stream);
        fclose($stream);
        return $csv;
    }

    /**
     * Parse shared strings XML into a flat indexed array.
     */
    private static function parseSharedStrings(string $xmlContent): array
    {
        $strings = [];
        $xml = @simplexml_load_string($xmlContent);
        if (!$xml) {
            return [];
        }

        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string)$si->t;
            } elseif (isset($si->r)) {
                $text = '';
                foreach ($si->r as $run) {
                    $text .= (string)($run->t ?? '');
                }
                $strings[] = $text;
            } else {
                $strings[] = '';
            }
        }

        return $strings;
    }

    /**
     * Parse worksheet XML into rows and columns.
     */
    private static function parseWorksheet(string $xmlContent, array $sharedStrings): array
    {
        $rows = [];
        $xml = @simplexml_load_string($xmlContent);
        if (!$xml || !isset($xml->sheetData)) {
            return [];
        }

        foreach ($xml->sheetData->row as $row) {
            $rowData = [];
            $maxCol = 0;

            foreach ($row->c as $c) {
                $cellRef = (string)$c['r'];
                $colIndex = self::columnToIndex($cellRef);
                $type = (string)($c['t'] ?? '');
                $val = '';

                if ($type === 's') {
                    // Shared string index
                    $idx = (int)$c->v;
                    $val = $sharedStrings[$idx] ?? '';
                } elseif ($type === 'inlineStr' && isset($c->is->t)) {
                    $val = (string)$c->is->t;
                } elseif (isset($c->v)) {
                    $val = (string)$c->v;
                }

                $rowData[$colIndex] = trim($val);
                if ($colIndex > $maxCol) {
                    $maxCol = $colIndex;
                }
            }

            // Fill empty cells up to maxCol
            $flatRow = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $flatRow[] = $rowData[$i] ?? '';
            }

            // Ignore completely empty trailing rows
            $hasContent = false;
            foreach ($flatRow as $cell) {
                if ($cell !== '') {
                    $hasContent = true;
                    break;
                }
            }

            if ($hasContent) {
                $rows[] = $flatRow;
            }
        }

        return $rows;
    }

    /**
     * Convert cell coordinate (e.g. "A1", "C4", "AA2") to 0-based column index.
     */
    private static function columnToIndex(string $cellRef): int
    {
        if (preg_match('/^([A-Z]+)/i', $cellRef, $matches)) {
            $letters = strtoupper($matches[1]);
            $len = strlen($letters);
            $col = 0;
            for ($i = 0; $i < $len; $i++) {
                $col = $col * 26 + (ord($letters[$i]) - 64);
            }
            return max(0, $col - 1);
        }
        return 0;
    }
}
