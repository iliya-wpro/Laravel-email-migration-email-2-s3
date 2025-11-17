<?php

namespace App\Contracts\Repositories;

use App\Models\File;
use Illuminate\Support\Collection;

interface FileRepositoryInterface
{
    /**
     * Find file by ID.
     *
     * @param int $id
     * @return File|null
     */
    public function find(int $id): ?File;

    /**
     * Get unmigrated files.
     *
     * @param int $limit
     * @return Collection
     */
    public function getUnmigratedFiles(int $limit = 100): Collection;

    /**
     * Count unmigrated files.
     *
     * @return int
     */
    public function countUnmigratedFiles(): int;
}
