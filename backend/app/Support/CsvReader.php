<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/** Minimal, forgiving CSV reading for admin imports: BOM, comma or semicolon delimiters, blank lines. */
class CsvReader
{
    /**
     * Rows keyed by lower-cased header name.
     *
     * @return list<array<string, string>>
     */
    public static function rows(string $path, int $maxRows): array
    {
        $raw = self::raw($path, $maxRows + 1);
        $headers = array_map(fn ($h) => mb_strtolower(trim((string) $h)), array_shift($raw) ?? []);

        return array_map(fn ($row) => array_combine($headers, array_map(fn ($i) => trim((string) ($row[$i] ?? '')), array_keys($headers))), $raw);
    }

    /**
     * One column as a flat list. Uses the header called $name when the first row has it, otherwise the first column.
     *
     * @return list<string>
     */
    public static function column(string $path, string $name, int $maxRows): array
    {
        $raw = self::raw($path, $maxRows + 1);
        $index = 0;
        $first = $raw[0] ?? [];
        foreach ($first as $i => $cell) {
            if (mb_strtolower(trim((string) $cell)) === $name) {
                $index = $i;
                array_shift($raw);
                break;
            }
        }

        return array_values(array_filter(array_map(fn ($row) => trim((string) ($row[$index] ?? '')), $raw), fn ($v) => $v !== ''));
    }

    /** @return list<list<string|null>> */
    private static function raw(string $path, int $limit): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be read.']);
        }
        $firstLine = (string) fgets($handle);
        rewind($handle);
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if (count($rows) === 0 && isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
            }
            if ($row === [null] || implode('', array_map('trim', array_map('strval', $row))) === '') {
                continue;
            }
            $rows[] = $row;
            if (count($rows) > $limit) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => 'The file has too many rows (the limit is '.($limit - 1).').']);
            }
        }
        fclose($handle);

        return $rows;
    }
}
