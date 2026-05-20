<?php

declare(strict_types=1);

namespace Basis\SuperSyncClient;

/**
 * Project DTO for Super Productivity sync.
 *
 * Supports all fields used by Super Productivity for projects:
 * - Basic: id, title, themeColor
 * - Organization: order, archived, reminderIds
 * - History: createdAt, updatedAt
 */
class Project
{
    /**
     * @param string $id Unique project ID
     * @param string $title Project title
     * @param string|null $themeColor Theme color (hex color code, e.g. '#4fc3f7')
     * @param int $order Sort order (lower = earlier)
     * @param bool $archived Whether the project is archived
     * @param array<string> $reminderIds IDs of reminders associated with this project
     * @param int|null $createdAt Date when project was created (milliseconds)
     * @param int|null $updatedAt Date when project was last updated (milliseconds)
     */
    public function __construct(
        public string $id,
        public string $title = '',
        public ?string $themeColor = null,
        public int $order = 0,
        public bool $archived = false,
        public array $reminderIds = [],
        public ?int $createdAt = null,
        public ?int $updatedAt = null,
    ) {
    }

    /**
     * Create Project from array data (from server).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            title: $data['title'] ?? '',
            themeColor: $data['themeColor'] ?? null,
            order: $data['order'] ?? 0,
            archived: $data['archived'] ?? false,
            reminderIds: $data['reminderIds'] ?? [],
            createdAt: $data['createdAt'] ?? null,
            updatedAt: $data['updatedAt'] ?? null,
        );
    }

    /**
     * Convert Project to array for server.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'themeColor' => $this->themeColor,
            'order' => $this->order,
            'archived' => $this->archived,
            'reminderIds' => $this->reminderIds,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    /**
     * Get the entity type for sync operations.
     */
    public static function getSection(): string
    {
        return 'project';
    }

    /**
     * Get the entity type for sync operations (uppercase).
     */
    public static function getSectionType(): string
    {
        return 'PROJECT';
    }

    /**
     * Check if project is archived.
     */
    public function isArchived(): bool
    {
        return $this->archived;
    }

    /**
     * Archive the project.
     */
    public function archive(): void
    {
        $this->archived = true;
        $this->updatedAt = time() * 1000;
    }

    /**
     * Unarchive the project.
     */
    public function unarchive(): void
    {
        $this->archived = false;
        $this->updatedAt = time() * 1000;
    }

    /**
     * Get human-readable theme color.
     */
    public function getThemeColor(): string
    {
        return $this->themeColor ?? '#4fc3f7';
    }

    /**
     * Set theme color from hex string.
     */
    public function setThemeColor(string $color): void
    {
        // Validate hex color
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $this->themeColor = $color;
        }
    }
}
