<?php

namespace App\ValueObjects;

class ProcessingResult
{
    public function __construct(
        public readonly bool $isComplete,
        public readonly int $processedCount,
        public readonly int $failedCount
    ) {
    }
}
