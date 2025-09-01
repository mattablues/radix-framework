<?php

declare(strict_types=1);

namespace Radix\Http\Exception;

 use RuntimeException;

 class HttpException extends RuntimeException
 {
     protected int $statusCode;

     public function __construct(string $message, int $statusCode)
     {
         $this->statusCode = $statusCode;
         parent::__construct($message);
     }

     public function getStatusCode(): int
     {
         return $this->statusCode;
     }
 }
