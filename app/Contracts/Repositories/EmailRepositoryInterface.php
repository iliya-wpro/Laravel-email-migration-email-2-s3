<?php

namespace App\Contracts\Repositories;

use App\Models\Email;
use Illuminate\Support\Collection;

interface EmailRepositoryInterface
{
    /**
     * Get the next batch of emails to migrate.
     *
     * @param int $lastId The last processed email ID
     * @param int $limit The batch size
     * @return Collection
     */
    public function getNextBatch(int $lastId, int $limit): Collection;

    /**
     * Count unmigrated emails that haven't exceeded retry limit.
     *
     * @return int
     */
    public function countUnmigratedEmails(): int;

    /**
     * Get emails that have failed migration (exceeded retry limit).
     *
     * @param int $limit
     * @return Collection
     */
    public function getFailedEmails(int $limit = 100): Collection;

    /**
     * Find email by ID.
     *
     * @param int $id
     * @return Email|null
     */
    public function find(int $id): ?Email;
}
