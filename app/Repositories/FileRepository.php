<?php

namespace App\Repositories;

use App\Contracts\Repositories\FileRepositoryInterface;
use App\Models\File;
use Illuminate\Support\Collection;

class FileRepository implements FileRepositoryInterface
{
    /**
     * Find file by ID.
     *
     * @param int $id
     * @return File|null
     */
    public function find(int $id): ?File
    {
        return File::find($id);
    }

    /**
     * Get unmigrated files.
     *
     * @param int $limit
     * @return Collection
     */
    public function getUnmigratedFiles(int $limit = 100): Collection
    {
        return File::where('is_migrated', false)
            ->limit($limit)
            ->get();
    }

    /**
     * Count unmigrated files.
     *
     * @return int
     */
    public function countUnmigratedFiles(): int
    {
        return File::where('is_migrated', false)->count();
    }
}
