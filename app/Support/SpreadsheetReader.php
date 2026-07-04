<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Đọc file bảng (CSV / XLSX) → headers + rows. Dùng cho màn Import.
 */
class SpreadsheetReader
{
    /**
     * @return array{headers: string[], rows: array<int, array<int, string|null>>}
     */
    public static function read(string $path, string $extension): array
    {
        $rows = strtolower($extension) === 'csv'
            ? self::readCsv($path)
            : self::readSpreadsheet($path);

        if ($rows === []) {
            return ['headers' => [], 'rows' => []];
        }

        $headers = array_map(fn ($h) => trim((string) $h), array_shift($rows));

        // Bỏ dòng trống hoàn toàn
        $rows = array_values(array_filter($rows, fn ($r) => trim(implode('', array_map(strval(...), $r))) !== ''));

        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function readCsv(string $path): array
    {
        $content = file_get_contents($path);
        // Bỏ BOM UTF-8 nếu có (file Excel xuất CSV hay dính)
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $firstLine = strtok($content, "\n");
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $rows = [];
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private static function readSpreadsheet(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();

        return $rows;
    }
}
