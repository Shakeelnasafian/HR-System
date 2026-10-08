<?php
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

Artisan::command('hr:grant-runtime', function () {
    DB::unprepared('GRANT USAGE ON SCHEMA public TO hr_app;
      GRANT SELECT, INSERT, UPDATE, DELETE ON users, sessions, password_reset_tokens, cache, cache_locks, jobs, job_batches, failed_jobs TO hr_app;
      GRANT SELECT ON tenants, tenant_memberships TO hr_app;
      GRANT SELECT, INSERT, UPDATE, DELETE ON companies, company_grants, departments, locations, positions, employees, employments TO hr_app;
      GRANT SELECT, INSERT ON audit_events TO hr_app;
      GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO hr_app;');
    $this->info('Runtime grants applied.');
})->purpose('Apply explicit runtime grants using the migration owner connection');

Artisan::command('hr:demo {--email=} {--password-env=}', function () {
    if (! app()->environment('local')) { $this->error('Local environments only.'); return 1; }
    $email=$this->option('email') ?: $this->ask('Demo email (synthetic data only)', 'admin@example.test');
    $password=$this->option('password-env') ? getenv($this->option('password-env')) : $this->secret('Demo password (at least 12 characters)');
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen((string)$password)<12) { $this->error('Invalid email or password length.'); return 1; }
    if(DB::table('users')->where('email',$email)->exists()){$this->error('User already exists. No changes made.');return 1;}
    DB::transaction(function() use ($email,$password){
        $uid=DB::table('users')->insertGetId(['name'=>'Demo Administrator','email'=>$email,'password'=>Hash::make($password)]);
        $tenant=(string)Str::uuid();$membership=(string)Str::uuid();
        DB::table('tenants')->insert(['id'=>$tenant,'name'=>'Demo Group','status'=>'active']);
        DB::table('tenant_memberships')->insert(['id'=>$membership,'tenant_id'=>$tenant,'user_id'=>$uid,'status'=>'active','requires_mfa'=>true]);
        DB::select("select set_config('app.tenant_id',?,true)",[$tenant]);
        foreach(['Demo Company A','Demo Company B'] as $i=>$name){
            $company=(string)Str::uuid();
            DB::table('companies')->insert(['id'=>$company,'tenant_id'=>$tenant,'name'=>$name,'code'=>'DEMO-'.($i+1)]);
            foreach (['company.read','organization.read','organization.write','workforce.read','workforce.write','audit.read'] as $permission) {
                DB::table('company_grants')->insert(['tenant_id'=>$tenant,'membership_id'=>$membership,'company_id'=>$company,'permission'=>$permission]);
            }
        }
    });
    $this->info('Synthetic workspace created. Sign in and enroll MFA.');
})->purpose('Create an explicit synthetic local workspace using the owner connection');
