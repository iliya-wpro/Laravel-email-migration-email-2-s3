<?php

namespace App\Services;

use Aws\S3\S3Client;
use Exception;

class S3Service
{
    private S3Client $client;
    private string $bucket;

    public function __construct()
    {
        $this->client = new S3Client([
            'version' => 'latest',
            'region' => config('migration.s3.region'),
            'endpoint' => env('S3_ENDPOINT', 'http://minio:9000'),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID', 'minioadmin'),
                'secret' => env('AWS_SECRET_ACCESS_KEY', 'minioadmin'),
            ],
        ]);

        $this->bucket = config('migration.s3.bucket');
        $this->ensureBucketExists();
    }

    /**
     * Ensure the S3 bucket exists, create if not.
     */
    private function ensureBucketExists(): void
    {
        try {
            if (!$this->client->doesBucketExist($this->bucket)) {
                $this->client->createBucket(['Bucket' => $this->bucket]);
                $this->client->waitUntil('BucketExists', ['Bucket' => $this->bucket]);
            }
        } catch (Exception $e) {
            // Log but don't fail - bucket might already exist
        }
    }

    /**
     * Upload string content to S3.
     *
     * @param string $content The content to upload
     * @param string $key The S3 object key
     * @param string $contentType The content MIME type
     * @return string The S3 object key
     */
    public function uploadContent(string $content, string $key, string $contentType): string
    {
        $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $content,
            'ContentType' => $contentType,
            'ServerSideEncryption' => 'AES256',
        ]);

        return $key;
    }

    /**
     * Upload a local file to S3.
     *
     * @param string $localPath The local file path
     * @param string $key The S3 object key
     * @return string The S3 object key
     */
    public function uploadFile(string $localPath, string $key): string
    {
        $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'SourceFile' => $localPath,
            'ServerSideEncryption' => 'AES256',
        ]);

        return $key;
    }

    /**
     * Check if an object exists in S3.
     *
     * @param string $key The S3 object key
     * @return bool
     */
    public function objectExists(string $key): bool
    {
        return $this->client->doesObjectExist($this->bucket, $key);
    }

    /**
     * Get the S3 client instance.
     *
     * @return S3Client
     */
    public function getClient(): S3Client
    {
        return $this->client;
    }

    /**
     * Get the bucket name.
     *
     * @return string
     */
    public function getBucket(): string
    {
        return $this->bucket;
    }
}
