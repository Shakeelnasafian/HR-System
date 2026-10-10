<?php
namespace App\Http\Middleware;
use App\Audit\RequestId;
use Closure;
use Illuminate\Http\Request;

/** Assigns the server-generated correlation ID and echoes it as X-Request-ID; inbound X-Request-ID values are ignored. */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): mixed
    {
        $id = app(RequestId::class)->assignForRequest();
        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);
        return $response;
    }
}
