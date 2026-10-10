<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\TenantRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->statefulApi();
        $middleware->alias(['tenant' => TenantRequest::class]);
        // Route model binding queries tenant data, so the tenant transaction and RLS context must exist first.
        $middleware->prependToPriorityList(SubstituteBindings::class, TenantRequest::class);
        $middleware->redirectGuestsTo(fn () => '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'A record with these identifiers already exists. Reload before retrying.'], 409);
            }
        });
        $exceptions->respond(function ($response) {
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        });
    })->create();
