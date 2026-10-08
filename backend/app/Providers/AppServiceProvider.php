<?php
namespace App\Providers;
use App\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;
class AppServiceProvider extends ServiceProvider
{
    public function register(): void { $this->app->scoped(TenantContext::class, fn () => new TenantContext); }
    public function boot(): void {}
}
