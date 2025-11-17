<?php

namespace App\Contracts\Services;

interface S3ServiceInterface
{
    /**
     * Upload string content to S3.
     *
     * @param string $content The content to upload
     * @param string $key The S3 object key
     * @param string $contentType The content MIME type
     * @return string The S3 object key
     */
    public function uploadContent(string $content, string $key, string $contentType): string;

    /**
     * Upload a local file to S3.
     *
     * @param string $localPath The local file path
     * @param string $key The S3 object key
     * @return string The S3 object key
     */
    public function uploadFile(string $localPath, string $key): string;

    /**
     * Check if an object exists in S3.
     *
     * @param string $key The S3 object key
     * @return bool
     */
    public function objectExists(string $key): bool;

    /**
     * Get the bucket name.
     *
     * @return string
     */
    public function getBucket(): string;
}
