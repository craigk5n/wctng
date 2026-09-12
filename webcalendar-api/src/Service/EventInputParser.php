<?php

declare(strict_types=1);

namespace App\Service;

final class EventInputParser
{
    public static function parseDateParam(string $dateStr): ?\DateTimeImmutable
    {
        if (\strlen($dateStr) !== 8 || !ctype_digit($dateStr)) {
            return null;
        }

        $formatted = sprintf(
            '%s-%s-%s',
            substr($dateStr, 0, 4),
            substr($dateStr, 4, 2),
            substr($dateStr, 6, 2),
        );

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $formatted);

        // The digit check above lets through eight digits that are not a date:
        // createFromFormat rolls those forward rather than refusing them, so
        // 20260231 came back as the 3rd of March and 20261345 as the 14th of
        // February the year after. Every route that reads a date this way then
        // answered for a day nobody asked about.
        if ($dt === false || $dt->format('Y-m-d') !== $formatted) {
            return null;
        }

        return $dt->setTime(0, 0);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<int>
     */
    public static function parseCategoryIds(array $data): array
    {
        if (!isset($data['categories']) || !\is_array($data['categories'])) {
            return [];
        }

        $result = [];
        /** @var list<int|string> $catList */
        $catList = $data['categories'];
        foreach ($catList as $v) {
            if (is_numeric($v)) {
                $id = (int) $v;
                if ($id > 0) {
                    $result[] = $id;
                }
            }
        }

        return $result;
    }
}
