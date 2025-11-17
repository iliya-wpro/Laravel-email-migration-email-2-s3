<?php

namespace App\Repositories;

use App\Contracts\Repositories\EmailRepositoryInterface;
use App\Models\Email;
use Illuminate\Support\Collection;

class EmailRepository implements EmailRepositoryInterface
{
    /**
     * Get the next batch of emails to migrate.
     *
     * @param int $lastId The last processed email ID
     * @param int $limit The batch size
     * @return Collection
     */
    public function getNextBatch(int $lastId, int $limit): Collection
    {
        return Email::where('id', '>', $lastId)
            ->where('is_migrated', false)
            ->where('migration_attempts', '<', config('migration.max_attempts'))
            ->orderBy('id')
            ->limit($limit)
            ->lockForUpdate()
            ->get();
    }

    /**
     * Count unmigrated emails that haven't exceeded retry limit.
     *
     * @return int
     */
    public function countUnmigratedEmails(): int
    {
        return Email::where('is_migrated', false)
            ->where('migration_attempts', '<', config('migration.max_attempts'))
            ->count();
    }

    /**
     * Get emails that have failed migration (exceeded retry limit).
     *
     * @param int $limit
     * @return Collection
     */
    public function getFailedEmails(int $limit = 100): Collection
    {
        return Email::where('is_migrated', false)
            ->where('migration_attempts', '>=', config('migration.max_attempts'))
            ->limit($limit)
            ->get();
    }

    /**
     * Find email by ID.
     *
     * @param int $id
     * @return Email|null
     */
    public function find(int $id): ?Email
    {
        return Email::find($id);
    }
}
