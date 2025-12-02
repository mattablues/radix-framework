<?php

declare(strict_types=1);

namespace Radix\Middleware\Middlewares;

use Radix\Http\RedirectResponse;
use Radix\Http\Request;
use Radix\Http\RequestHandlerInterface;
use Radix\Http\Response;
use Radix\Middleware\MiddlewareInterface;
use Radix\Support\GeoLocator;

readonly class Location implements MiddlewareInterface
{
    public function __construct(private GeoLocator $geoLocator) {}

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $appEnv = getenv('APP_ENV') ?: 'production';

        if ($appEnv !== 'development') {
            $location = $this->geoLocator->getLocation(); // Hämta plats för besökaren

            if ($location['country'] !== getenv('LOCATOR_COUNTRY') && $location['city'] !== getenv('LOCATOR_CITY')) {
                $request->session()->setFlashMessage("Endast kommuninvånare i " . getenv('LOCATOR_CITY') . " kommun kan registrera sig för att rösta");

                return new RedirectResponse(route('home.index'));
            }
        }

        return $next->handle($request);
    }
}
