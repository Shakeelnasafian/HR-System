<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class OutsideProbe implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $path) {}

    public function handle(): void
    {
        file_put_contents($this->path, json_encode(['count' => DB::table('companies')->count(), 'pid' => getmypid()])."\n", FILE_APPEND);
    }
}
