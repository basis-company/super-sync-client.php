<?php

declare(strict_types=1);

namespace Basis\SuperSyncClient;

/**
 * Task DTO for Super Productivity sync.
 *
 * Supports all fields used by Super Productivity:
 * - Basic: id, title, isDone, projectId
 * - Scheduling: dueDate, start, startMode
 * - Time estimation: estimate, estimatedTimeSpent
 * - Tracking: timeSpent, timeSpentNotes
 * - Organization: notes, subtasks, reminders, recurring, tags
 * - Priority: priority
 * - History: doneDate, createdAt, updatedAt
 */
class Task
{
    /**
     * @param string $id Unique task ID
     * @param string $title Task title
     * @param bool $isDone Whether the task is completed
     * @param string|null $projectId Associated project ID
     * @param int|null $dueDate Due date in milliseconds (Unix timestamp * 1000)
     * @param int|null $start Start time in milliseconds (Unix timestamp * 1000)
     * @param int|null $startMode Start mode (0=none, 1=remind, 2=auto)
     * @param int|null $estimate Estimated time in seconds
     * @param int|null $estimatedTimeSpent Estimated time spent in seconds
     * @param int|null $timeSpent Actual time spent in seconds
     * @param string $notes Task notes/description
     * @param array<array<string,mixed>> $subtasks Subtasks
     * @param array<array<string,mixed>> $reminders Reminders
     * @param bool $recurring Whether the task is recurring
     * @param array<string> $tags Task tags
     * @param int $priority Priority (0=none, 1=low, 2=medium, 3=high)
     * @param int|null $doneDate Date when task was done (milliseconds)
     * @param int|null $createdAt Date when task was created (milliseconds)
     * @param int|null $updatedAt Date when task was last updated (milliseconds)
     */
    public function __construct(
        public string $id,
        public string $title,
        public bool $isDone = false,
        public ?string $projectId = null,
        public ?int $dueDate = null,
        public ?int $start = null,
        public ?int $startMode = null,
        public ?int $estimate = null,
        public ?int $estimatedTimeSpent = null,
        public ?int $timeSpent = null,
        public string $notes = '',
        public array $subtasks = [],
        public array $reminders = [],
        public bool $recurring = false,
        public array $tags = [],
        public int $priority = 0,
        public ?int $doneDate = null,
        public ?int $createdAt = null,
        public ?int $updatedAt = null,
    ) {
    }

    /**
     * Create Task from array data (from server).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            title: $data['title'] ?? '',
            isDone: $data['isDone'] ?? false,
            projectId: $data['projectId'] ?? null,
            dueDate: $data['dueDate'] ?? null,
            start: $data['start'] ?? null,
            startMode: $data['startMode'] ?? null,
            estimate: $data['estimate'] ?? null,
            estimatedTimeSpent: $data['estimatedTimeSpent'] ?? null,
            timeSpent: $data['timeSpent'] ?? null,
            notes: $data['notes'] ?? '',
            subtasks: $data['subtasks'] ?? [],
            reminders: $data['reminders'] ?? [],
            recurring: $data['recurring'] ?? false,
            tags: $data['tags'] ?? [],
            priority: $data['priority'] ?? 0,
            doneDate: $data['doneDate'] ?? null,
            createdAt: $data['createdAt'] ?? null,
            updatedAt: $data['updatedAt'] ?? null,
        );
    }

    /**
     * Convert Task to array for server.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'isDone' => $this->isDone,
            'projectId' => $this->projectId,
            'dueDate' => $this->dueDate,
            'start' => $this->start,
            'startMode' => $this->startMode,
            'estimate' => $this->estimate,
            'estimatedTimeSpent' => $this->estimatedTimeSpent,
            'timeSpent' => $this->timeSpent,
            'notes' => $this->notes,
            'subtasks' => $this->subtasks,
            'reminders' => $this->reminders,
            'recurring' => $this->recurring,
            'tags' => $this->tags,
            'priority' => $this->priority,
            'doneDate' => $this->doneDate,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    /**
     * Get the entity type for sync operations.
     */
    public static function getSection(): string
    {
        return 'task';
    }

    /**
     * Get the entity type for sync operations (uppercase).
     */
    public static function getSectionType(): string
    {
        return 'TASK';
    }

    /**
     * Check if task is overdue (dueDate is in the past and not done).
     */
    public function isOverdue(): bool
    {
        if ($this->dueDate === null || $this->isDone) {
            return false;
        }
        return $this->dueDate < time() * 1000;
    }

    /**
     * Check if task is due today.
     */
    public function isDueToday(): bool
    {
        if ($this->dueDate === null) {
            return false;
        }
        $today = strtotime('today');
        $tomorrow = strtotime('tomorrow');
        return $this->dueDate >= $today * 1000 && $this->dueDate < $tomorrow * 1000;
    }

    /**
     * Check if task is due in the future.
     */
    public function isDueInFuture(): bool
    {
        if ($this->dueDate === null) {
            return false;
        }
        return $this->dueDate > time() * 1000;
    }

    /**
     * Get human-readable due date.
     */
    public function getDueDateFormatted(): ?string
    {
        if ($this->dueDate === null) {
            return null;
        }
        return date('Y-m-d H:i:s', $this->dueDate / 1000);
    }

    /**
     * Get human-readable start time.
     */
    public function getStartFormatted(): ?string
    {
        if ($this->start === null) {
            return null;
        }
        return date('Y-m-d H:i:s', $this->start / 1000);
    }

    /**
     * Get estimated time in human-readable format (HH:MM).
     */
    public function getEstimateFormatted(): ?string
    {
        if ($this->estimate === null) {
            return null;
        }
        $hours = intdiv($this->estimate, 3600);
        $minutes = intdiv($this->estimate % 3600, 60);
        return sprintf('%02d:%02d', $hours, $minutes);
    }

    /**
     * Get time spent in human-readable format (HH:MM).
     */
    public function getTimeSpentFormatted(): ?string
    {
        if ($this->timeSpent === null) {
            return null;
        }
        $hours = intdiv($this->timeSpent, 3600);
        $minutes = intdiv($this->timeSpent % 3600, 60);
        return sprintf('%02d:%02d', $hours, $minutes);
    }
}
