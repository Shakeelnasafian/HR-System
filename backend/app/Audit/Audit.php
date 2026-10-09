<?php
namespace App\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Audit
{
    public static function record(string $company, string $action, string $resource, array $changes = [], ?string $reason = null): void
    {
        $context = app(TenantContext::class);
        DB::table('audit_events')->insert([
            'id'=>(string) Str::uuid(), 'tenant_id'=>$context->id(), 'company_id'=>$company,
            'actor_id'=>$context->userId(), 'action'=>$action, 'resource_id'=>$resource,
            'correlation_id'=>(string) Str::uuid(), 'changes'=>json_encode($changes, JSON_THROW_ON_ERROR),
            'reason'=>$reason, 'occurred_at'=>now(),
        ]);
    }
}
