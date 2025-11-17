<?php

namespace App\Console\Commands;

use Aws\S3\S3Client;
use Illuminate\Console\Command;

class CreateS3BucketCommand extends Command
{
    protected $signature = 'storage:create-bucket';
    protected $description = 'Create S3/MinIO bucket if it does not exist';

    public function handle(): int
    {
        $bucket = config('filesystems.disks.s3.bucket');
        $endpoint = config('filesystems.disks.s3.endpoint');

        $this->info("Checking if bucket '{$bucket}' exists...");

        try {
            $client = new S3Client([
                'version' => 'latest',
                'region' => config('filesystems.disks.s3.region'),
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => true,
                'credentials' => [
                    'key' => config('filesystems.disks.s3.key'),
                    'secret' => config('filesystems.disks.s3.secret'),
                ],
            ]);

            if (!$client->doesBucketExist($bucket)) {
                $this->info("Creating bucket '{$bucket}'...");
                $client->createBucket([
                    'Bucket' => $bucket,
                ]);
                $this->info("Bucket '{$bucket}' created successfully!");
            } else {
                $this->info("Bucket '{$bucket}' already exists.");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to create bucket: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
