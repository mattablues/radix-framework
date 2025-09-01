<?php

declare(strict_types=1);

namespace Radix\Http\Exception;

 class MaintenanceException extends HttpException
 {
     public function __construct(string $message = 'Service unavailable due to maintenance.')
     {
         parent::__construct($message, 503);
     }
 }
