<?php

namespace ChurchCRM\Plugins\MosGov\Security;

/**
 * CSV / spreadsheet export hardening (Phase 3, finding SEC-04).
 *
 * A governance record whose name begins with '=', '+', '-' or '@' becomes a
 * live formula when the exported CSV is opened in Excel / WPS / Sheets. That
 * is stored-content code execution on the administrator's workstation and can
 * exfiltrate data via WEBSERVICE()/HYPERLINK() style functions.
 *
 * Prefixing with a single quote forces the cell to be text. Tab / CR prefixes
 * are covered too because spreadsheet parsers trim them before evaluating.
 */
final class CsvExportGuard
{
    public static function cell(mixed $value): string
    {
        $text = (string) $value;
        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text) === 1) {
            return "'" . $text;
        }

        return $text;
    }

    /** @param list<string> $columns @return list<string> */
    public static function header(array $columns): array
    {
        return array_map(static fn (string $c): string => preg_replace('/[\r\n]+/', ' ', $c) ?? '', $columns);
    }
}
