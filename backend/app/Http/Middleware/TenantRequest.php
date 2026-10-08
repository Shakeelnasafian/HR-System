<?php
namespace App\Http\Middleware;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

final class TenantRequest
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless(! $request->exists('tenant_id'), 422, 'tenant_id is not writable.');
        $id = $request->header('X-Tenant-ID');
        abort_unless(is_string($id) && $id !== '', 400, 'Tenant context required.');
        return app(TenantContext::class)->run($id, (int) $request->user()->id, function ($membership) use ($request, $next) {
            if ($membership->requires_mfa) {
                abort_unless($request->user()->two_factor_confirmed_at && $request->user()->two_factor_secret, 403, 'MFA enrollment required.');
                abort_unless((int) $request->session()->get('mfa_user_id') === (int) $request->user()->id, 403, 'MFA login required.');
            }
            $response = $next($request);
            if ($response->getStatusCode() >= 400) { throw new \Illuminate\Http\Exceptions\HttpResponseException($response); }
            $response->headers->set('Cache-Control', 'no-store, private');
            return $response;
        });
    }
}
