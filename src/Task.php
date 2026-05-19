<?php

declare(strict_types=1);

namespace Basis\SuperSyncClient;

class Task
{
    public function __construct(
        public string $id,
        public string $title,
        public bool $isDone = false,
        public ?string $projectId = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            title: $data['title'] ?? '',
            isDone: $data['isDone'] ?? false,
            projectId: $data['projectId'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'isDone' => $this->isDone,
            'projectId' => $this->projectId,
        ];
    }

    public static function getSection(): string
    {
        return 'task';
    }

    public static function getSectionType(): string
    {
        return 'task';
    }
}
