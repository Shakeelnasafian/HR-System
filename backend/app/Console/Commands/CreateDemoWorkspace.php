<?php

namespace App\Console\Commands;

use App\Services\Tenancy\PermissionCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Synthetic local workspace: one tenant, two companies, an MFA administrator holding every permission and an optional
 * read-only colleague. Runs on the owner connection (tenants and memberships are not writable by the runtime role).
 */
class CreateDemoWorkspace extends Command
{
    protected $signature = 'hr:demo {--email=} {--password-env=} {--colleague : Add a synthetic member for permission reviews}';

    protected $description = 'Create an explicit synthetic local workspace using the owner connection';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Local environments only.');

            return self::FAILURE;
        }
        $email = $this->option('email') ?: $this->ask('Demo email (synthetic data only)', 'admin@example.test');
        $password = $this->option('password-env') ? getenv($this->option('password-env')) : $this->secret('Demo password (at least 12 characters)');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen((string) $password) < 12) {
            $this->error('Invalid email or password length.');

            return self::FAILURE;
        }
        if (DB::table('users')->where('email', $email)->exists()) {
            $this->error('User already exists. No changes made.');

            return self::FAILURE;
        }
        $colleague = (bool) $this->option('colleague');
        if ($colleague && DB::table('users')->where('email', 'colleague@example.test')->exists()) {
            $this->error('Synthetic colleague already exists.');

            return self::FAILURE;
        }
        DB::transaction(fn () => $this->create($email, $password, $colleague));
        $this->info('Synthetic workspace created. Sign in and enroll MFA.');

        return self::SUCCESS;
    }

    private function create(string $email, string $password, bool $colleague): void
    {
        $uid = DB::table('users')->insertGetId(['name' => 'Demo Administrator', 'email' => $email, 'password' => Hash::make($password)]);
        $tenant = (string) Str::uuid();
        $membership = (string) Str::uuid();
        DB::table('tenants')->insert(['id' => $tenant, 'name' => 'Demo Group', 'status' => 'active']);
        DB::table('tenant_memberships')->insert(['id' => $membership, 'tenant_id' => $tenant, 'user_id' => $uid, 'status' => 'active', 'requires_mfa' => true]);
        $colleagueMembership = null;
        if ($colleague) {
            $colleagueUser = DB::table('users')->insertGetId(['name' => 'Demo Colleague', 'email' => 'colleague@example.test', 'password' => Hash::make(Str::random(64))]);
            $colleagueMembership = (string) Str::uuid();
            DB::table('tenant_memberships')->insert(['id' => $colleagueMembership, 'tenant_id' => $tenant, 'user_id' => $colleagueUser, 'status' => 'active', 'requires_mfa' => true]);
        }
        DB::select("select set_config('app.tenant_id',?,true)", [$tenant]);
        foreach (['Demo Company A', 'Demo Company B'] as $i => $name) {
            $company = (string) Str::uuid();
            DB::table('companies')->insert(['id' => $company, 'tenant_id' => $tenant, 'name' => $name, 'code' => 'DEMO-'.($i + 1), 'timezone' => 'UTC']);
            if ($colleagueMembership) {
                DB::table('company_grants')->insert(['tenant_id' => $tenant, 'membership_id' => $colleagueMembership, 'company_id' => $company, 'permission' => 'company.read']);
            }
            foreach (array_keys(PermissionCatalog::LABELS) as $permission) {
                DB::table('company_grants')->insert(['tenant_id' => $tenant, 'membership_id' => $membership, 'company_id' => $company, 'permission' => $permission]);
            }
        }
    }
}
