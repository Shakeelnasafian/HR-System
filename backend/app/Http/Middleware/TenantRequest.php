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
        $user = $request->user();
        $enrolled = $user->two_factor_confirmed_at && $user->two_factor_secret;
        $verified = $enrolled && (int) $request->session()->get('mfa_user_id') === (int) $user->id;
        // The membership flag gates the whole tenant; privileged permissions additionally require $verified at use (CompanyAccess).
        return app(TenantContext::class)->run($id, (int) $user->id, function ($membership) use ($request, $next, $enrolled, $verified) {
            if ($membership->requires_mfa) {
                abort_unless($enrolled, 403, 'MFA enrollment required.');
                abort_unless($verified, 403, 'MFA login required.');
            }
            $response = $next($request);
            if ($response->getStatusCode() >= 400) { throw new \Illuminate\Http\Exceptions\HttpResponseException($response); }
            $response->headers->set('Cache-Control', 'no-store, private');
            return $response;
        }, $verified);
    }
}
