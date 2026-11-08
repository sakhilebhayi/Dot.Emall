<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;

/**
 * The /up health route. It replaces the framework's built-in one, whose page loads the Tailwind CDN (a third party the CSP no longer allows).
 * Same contract: the DiagnosingHealth event runs first (App\\Providers\\HealthCheckServiceProvider makes it touch the database), so an unreachable
 * database is a 500, and a healthy app answers 200 with a small page that needs nothing from outside.
 */
class HealthController extends Controller
{
    public function __invoke(): Response
    {
        Event::dispatch(new DiagnosingHealth);

        return response('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Dot.Emall is up</title></head><body><p>Dot.Emall is up.</p></body></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
