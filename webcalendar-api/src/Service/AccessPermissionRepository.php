<?php

declare(strict_types=1);

namespace App\Service;

final readonly class AccessPermissionRepository
{
    public function __construct(
        private TenantAwarePdoProvider $pdoProvider,
    ) {}

    /**
     * Load access permissions the current user has been granted by other users.
     *
     * @param list<string> $otherUsers
     *
     * @return array<string, array{can_view: bool, can_edit: bool, see_time_only: bool}>
     */
    public function findGrantsFor(string $viewer, array $otherUsers): array
    {
        if ($otherUsers === []) {
            return [];
        }

        // Query: where other users have granted access to the current user
        $placeholders = [];
        $params = ['viewer' => $viewer];
        foreach ($otherUsers as $i => $login) {
            $key = 'u' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $login;
        }

        $sql = 'SELECT cal_login, cal_can_view, cal_can_edit, cal_see_time_only
                FROM webcal_access_user
                WHERE cal_login IN (' . implode(', ', $placeholders) . ')
                AND cal_other_user = :viewer';

        $stmt = $this->pdoProvider->get()->prepare($sql);
        $stmt->execute($params);

        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (\is_array($row)) {
                /** @var string $owner */
                $owner = $row['cal_login'];
                /** @var int|string $canView */
                $canView = $row['cal_can_view'] ?? 0;
                /** @var int|string $canEdit */
                $canEdit = $row['cal_can_edit'] ?? 0;
                $result[$owner] = [
                    'can_view' => (int) $canView > 0,
                    'can_edit' => (int) $canEdit > 0,
                    'see_time_only' => ($row['cal_see_time_only'] ?? 'N') === 'Y',
                ];
            }
        }

        return $result;
    }
}
