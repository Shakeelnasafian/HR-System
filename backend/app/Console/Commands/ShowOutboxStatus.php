<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Counts and ages only; payloads, dedupe keys and errors stay in the database. */
class ShowOutboxStatus extends Command
{
    protected $signature = 'hr:outbox-status';

    protected $description = 'Show outbox counts by tenant, type and status (no payloads)';

    public function handle(TenantContext $context): void
    {
        $rows = [];
        foreach (DB::table('tenants')->where('status', 'active')->orderBy('id')->pluck('id') as $tenant) {
            $context->runSystem($tenant, function () use ($tenant, &$rows) {
                $oldest = DB::table('outbox_events')->where('tenant_id', $tenant)->where('status', 'pending')
                    ->selectRaw('floor(extract(epoch from clock_timestamp() - min(created_at)))::bigint AS age')->value('age');
                foreach (DB::table('outbox_events')->where('tenant_id', $tenant)->groupBy('type', 'status')->orderBy('type')->orderBy('status')
                    ->selectRaw('type, status, count(*) AS n')->get() as $r) {
                    $rows[] = [$tenant, $r->type, $r->status, $r->n, $r->status === 'pending' ? (int) $oldest : ''];
                }
            });
        }
        $this->table(['tenant', 'type', 'status', 'count', 'oldest pending (s)'], $rows);
        if ($skipped = DB::table('tenants')->where('status', '!=', 'active')->count()) {
            $this->warn("$skipped inactive tenant(s) not shown; their events stay pending until reactivated.");
        }
    }
}
