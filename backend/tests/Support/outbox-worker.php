<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\OutboxProbeHandler;

// Real long-lived queue worker with the test-only outbox handler registered.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['outbox.handlers' => ['test.probe' => OutboxProbeHandler::class], 'outbox.system_context' => true]);
exit(Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $argv[1], '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0]));
