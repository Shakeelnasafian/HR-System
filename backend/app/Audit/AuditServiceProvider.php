<?php
namespace App\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Events as Fortify;

final class AuditServiceProvider extends ServiceProvider
{
    private const EVENTS = [
        Login::class=>'login.succeeded', Logout::class=>'logout', PasswordReset::class=>'password.reset',
        Fortify\PasswordUpdatedViaController::class=>'password.updated',
        Fortify\TwoFactorAuthenticationEnabled::class=>'mfa.enabled', Fortify\TwoFactorAuthenticationConfirmed::class=>'mfa.confirmed',
        Fortify\TwoFactorAuthenticationDisabled::class=>'mfa.disabled', Fortify\RecoveryCodesGenerated::class=>'mfa.recovery_codes_generated',
        Fortify\RecoveryCodeReplaced::class=>'mfa.recovery_code_used', Fortify\TwoFactorAuthenticationChallenged::class=>'mfa.challenged',
        Fortify\ValidTwoFactorAuthenticationCodeProvided::class=>'mfa.challenge_passed', Fortify\TwoFactorAuthenticationFailed::class=>'mfa.challenge_failed',
    ];

    public function register(): void { $this->app->scoped(RequestId::class, fn () => new RequestId); }
    public function boot(): void
    {
        Event::listen(CommandStarting::class, fn () => $this->app->make(RequestId::class)->assignForCommand());
        foreach (self::EVENTS as $class => $name) {
            Event::listen($class, fn ($e) => SecurityEvents::record($name, $e->user?->getAuthIdentifier()));
        }
        // Only a keyed hash of the attempted identifier is kept; credentials never leave this listener.
        Event::listen(Failed::class, fn (Failed $e) => SecurityEvents::record('login.failed', $e->user?->getAuthIdentifier(), (string) ($e->credentials['email'] ?? '')));
        Event::listen(Lockout::class, fn (Lockout $e) => SecurityEvents::record('login.locked_out', null, (string) $e->request->input('email', '')));
    }
}
