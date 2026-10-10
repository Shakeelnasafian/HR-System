<?php
namespace App\Providers;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
class AppServiceProvider extends ServiceProvider
{
    public function register(): void { $this->app->scoped(TenantContext::class, fn () => new TenantContext); }
    public function boot(): void
    {
        // Public invitation preview/accept: keyed by IP even for signed-in callers, so a session cannot widen the budget.
        RateLimiter::for('invitations', fn (Request $r) => Limit::perMinute(10)->by('invitations|'.$r->ip()));
    }
}
