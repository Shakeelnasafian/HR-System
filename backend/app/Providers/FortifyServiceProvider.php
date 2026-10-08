<?php
namespace App\Providers;

use App\Actions\ResetUserPassword;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()));
        RateLimiter::for('two-factor', fn (Request $r) => Limit::perMinute(5)->by($r->session()->get('login.id').'|'.$r->ip()));
        Event::listen(ValidTwoFactorAuthenticationCodeProvided::class, function ($event): void {
            request()->session()->put('mfa_user_id', $event->user->getAuthIdentifier());
        });
        ResetPassword::createUrlUsing(fn ($user, string $token) => config('app.url').'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]));
    }
}
