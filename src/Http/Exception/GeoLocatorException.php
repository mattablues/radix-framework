<?php

declare(strict_types=1);

namespace Radix\Http\Exception;

/**
 * Class GeoLocatorException
 * @package Radix\Http\Exception
 */
class GeoLocatorException extends HttpException
{
    public function __construct(string $message = 'Fel vid hämtning av geolokalisering.', int $statusCode = 500)
    {
        parent::__construct($message, $statusCode);
    }
}