<?php

namespace App\Support;

class Changelog
{
    /**
     * Parse CHANGELOG.md thành mảng version.
     * Return: [['version' => 'v0.14.0', 'date' => '2026-08-13', 'items' => ['...','...']], ...]
     */
    public static function all(): array
    {
        $path = base_path('CHANGELOG.md');
        if (! is_file($path)) return [];
        $md = file_get_contents($path);

        $blocks = [];
        preg_match_all('/^##\s+(v[\d.]+)\s*—\s*([\d-]+)\s*$([\s\S]*?)(?=^##\s+v|\z)/mu', $md, $m, PREG_SET_ORDER);
        foreach ($m as $row) {
            $items = [];
            foreach (preg_split('/\r?\n/', trim($row[3])) as $line) {
                if (preg_match('/^-\s+(.+)$/', trim($line), $mm)) $items[] = trim($mm[1]);
            }
            $blocks[] = ['version' => $row[1], 'date' => $row[2], 'items' => $items];
        }
        return $blocks;
    }

    public static function latest(): ?array
    {
        return self::all()[0] ?? null;
    }
}
