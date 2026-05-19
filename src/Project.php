<?php

declare(strict_types=1);

namespace Basis\SuperSyncClient;

class Project
{
    public function __construct(
        public string $id,
        public string $title = '',
        public ?string $themeColor = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            title: $data['title'] ?? '',
            themeColor: $data['themeColor'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'themeColor' => $this->themeColor,
        ];
    }

    public static function getSection(): string
    {
        return 'project';
    }

    public static function getSectionType(): string
    {
        return 'project';
    }
}
