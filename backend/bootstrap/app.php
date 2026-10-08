<?php

use App\Http\Middleware\TenantRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias(['tenant' => TenantRequest::class]);
        $middleware->redirectGuestsTo(fn () => '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (\Illuminate\Database\UniqueConstraintViolationException $e, Request $request) {
            if ($request->is('api/*')) { return response()->json(['message'=>'A record with these identifiers already exists. Reload before retrying.'],409); }
        });
        $exceptions->respond(function ($response) {
            $response->headers->set('Cache-Control', 'no-store, private');
            return $response;
        });
    })->create();
