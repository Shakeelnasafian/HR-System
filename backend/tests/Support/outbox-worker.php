<?php
// Real long-lived queue worker with the test-only outbox handler registered.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['outbox.handlers' => ['test.probe' => \Tests\Support\OutboxProbeHandler::class]]);
exit(\Illuminate\Support\Facades\Artisan::call('queue:work', ['connection'=>'redis', '--queue'=>$argv[1], '--stop-when-empty'=>true, '--tries'=>1, '--sleep'=>0]));
