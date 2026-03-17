<?php

declare(strict_types=1);

namespace App\CustomField;

final readonly class CustomFieldRepository
{
    public const SCHEMA_SQL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS custom_field_definitions (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(60) NOT NULL UNIQUE,
            field_type VARCHAR(20) NOT NULL DEFAULT 'text',
            required INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            options TEXT DEFAULT ''
        )
    SQL;

    public const SCHEMA_SQL_SQLITE = <<<'SQL'
        CREATE TABLE IF NOT EXISTS custom_field_definitions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(60) NOT NULL UNIQUE,
            field_type VARCHAR(20) NOT NULL DEFAULT 'text',
            required INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            options TEXT DEFAULT ''
        )
    SQL;

    public function __construct(
        private \PDO $pdo,
    ) {
    }

    /** @return CustomFieldDefinition[] */
    public function findAll(): array
    {
        $this->ensureTable();
        $stmt = $this->pdo->query('SELECT * FROM custom_field_definitions ORDER BY sort_order, id');
        if ($stmt === false) return [];

        $items = [];
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        while (\is_array($row)) {
            $items[] = $this->mapRow($row);
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        return $items;
    }

    public function findById(int $id): ?CustomFieldDefinition
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare('SELECT * FROM custom_field_definitions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return \is_array($row) ? $this->mapRow($row) : null;
    }

    public function save(CustomFieldDefinition $field): int
    {
        $this->ensureTable();

        if ($field->id() > 0) {
            $this->pdo->prepare(
                'UPDATE custom_field_definitions SET name=:name, field_type=:type, required=:req, sort_order=:sort, options=:opts WHERE id=:id'
            )->execute([
                'id' => $field->id(),
                'name' => $field->name(),
                'type' => $field->fieldType(),
                'req' => $field->isRequired() ? 1 : 0,
                'sort' => $field->sortOrder(),
                'opts' => $field->options(),
            ]);
            return $field->id();
        }

        $this->pdo->prepare(
            'INSERT INTO custom_field_definitions (name, field_type, required, sort_order, options) VALUES (:name, :type, :req, :sort, :opts)'
        )->execute([
            'name' => $field->name(),
            'type' => $field->fieldType(),
            'req' => $field->isRequired() ? 1 : 0,
            'sort' => $field->sortOrder(),
            'opts' => $field->options(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM custom_field_definitions WHERE id = :id')->execute(['id' => $id]);
    }

    private function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite' ? self::SCHEMA_SQL_SQLITE : self::SCHEMA_SQL;
        $this->pdo->exec($sql);
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): CustomFieldDefinition
    {
        return new CustomFieldDefinition(
            id: \is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            name: \is_string($row['name'] ?? null) ? $row['name'] : '',
            fieldType: \is_string($row['field_type'] ?? null) ? $row['field_type'] : 'text',
            required: (\is_numeric($row['required'] ?? null) ? (int) $row['required'] : 0) === 1,
            sortOrder: \is_numeric($row['sort_order'] ?? null) ? (int) $row['sort_order'] : 0,
            options: \is_string($row['options'] ?? null) ? $row['options'] : '',
        );
    }
}
