<?php

declare(strict_types=1);

namespace App\CustomField;

final readonly class CustomFieldDefinition
{
    public function __construct(
        private int $id,
        private string $name,
        private string $fieldType,
        private bool $required,
        private int $sortOrder,
        private string $options,
    ) {}

    public function id(): int
    {
        return $this->id;
    }
    public function name(): string
    {
        return $this->name;
    }
    public function fieldType(): string
    {
        return $this->fieldType;
    }
    public function isRequired(): bool
    {
        return $this->required;
    }
    public function sortOrder(): int
    {
        return $this->sortOrder;
    }
    public function options(): string
    {
        return $this->options;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'field_type' => $this->fieldType,
            'required' => $this->required,
            'sort_order' => $this->sortOrder,
            'options' => $this->options !== '' ? json_decode($this->options, true) : [],
        ];
    }
}
