<?php

namespace Tests\Support;

use App\Jobs\Middleware\UseTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class TenantProbe implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $tenantId, public int $actorId, public string $path, public bool $fail) {}

    public function middleware(): array
    {
        return [new UseTenantContext];
    }

    public function handle(): void
    {
        file_put_contents($this->path, json_encode(['count' => DB::table('companies')->count(), 'pid' => getmypid()])."\n", FILE_APPEND);
        if ($this->fail) {
            throw new \RuntimeException('Intentional isolation test failure');
        }
    }
}
