<?php

declare(strict_types=1);

namespace App\BigStore;

use RuntimeException;

class BigStoreException extends RuntimeException
{
    private string $errorCode;
    private mixed $details;

    public function __construct(string $message, int $statusCode = 500, string $errorCode = 'BIGSTORE_ERROR', mixed $details = null)
    {
        parent::__construct($message, $statusCode);
        $this->errorCode = $errorCode;
        $this->details = $details;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getDetails(): mixed
    {
        return $this->details;
    }
}
