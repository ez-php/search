<?php

declare(strict_types=1);

namespace EzPhp\Search\Drivers;

use EzPhp\Search\SearchException;
use EzPhp\Search\SearchHit;

/**
 * Class DocumentFilter
 *
 * The attribute filter and sort rules shared by the drivers that evaluate them
 * in PHP (NullDriver, DatabaseDriver): `field = value` / `field != value`
 * conditions joined by AND (values bare or quoted; anything else throws), and
 * `field:asc|desc` sort descriptors (numeric-aware).
 *
 * @internal
 *
 * @package EzPhp\Search\Drivers
 */
final class DocumentFilter
{
    /**
     * Parse `field = value [AND field != value ...]` into conditions.
     *
     * @param string $filter
     *
     * @return list<array{field: string, negate: bool, value: string}>
     * @throws SearchException On any other syntax.
     */
    public static function parse(string $filter): array
    {
        if (trim($filter) === '') {
            return [];
        }

        $conditions = [];

        foreach (preg_split('/\s+AND\s+/i', trim($filter)) ?: [] as $part) {
            if (preg_match('/^([A-Za-z0-9_.]+)\s*(!=|=)\s*(?:"([^"]*)"|\'([^\']*)\'|(\S+))$/', trim($part), $m) !== 1) {
                throw new SearchException(
                    "Only `field = value` / `field != value` filters joined by AND are supported here, got: {$filter}"
                );
            }

            $conditions[] = [
                'field' => $m[1],
                'negate' => $m[2] === '!=',
                'value' => ($m[3] ?? '') . ($m[4] ?? '') . ($m[5] ?? ''),
            ];
        }

        return $conditions;
    }

    /**
     * @param array<string, mixed>                                    $document
     * @param list<array{field: string, negate: bool, value: string}> $conditions
     *
     * @return bool
     */
    public static function passes(array $document, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            $equal = self::stringValue($document[$condition['field']] ?? null) === $condition['value'];

            if ($equal === $condition['negate']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Stable sort by `field:asc|desc` descriptors (numeric-aware comparison).
     *
     * @param list<SearchHit> $hits
     * @param list<string>    $sort
     *
     * @return list<SearchHit>
     */
    public static function sort(array $hits, array $sort): array
    {
        if ($sort === []) {
            return $hits;
        }

        usort($hits, static function (SearchHit $a, SearchHit $b) use ($sort): int {
            foreach ($sort as $descriptor) {
                [$field, $direction] = array_pad(explode(':', $descriptor, 2), 2, 'asc');
                $left = $a->document[$field] ?? null;
                $right = $b->document[$field] ?? null;
                $cmp = is_numeric($left) && is_numeric($right)
                    ? $left <=> $right
                    : strcmp(self::stringValue($left), self::stringValue($right));

                if ($cmp !== 0) {
                    return strtolower($direction) === 'desc' ? -$cmp : $cmp;
                }
            }

            return 0;
        });

        return $hits;
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    private static function stringValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
