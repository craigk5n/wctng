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

        return $dt === false ? null : $dt->setTime(0, 0);
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
