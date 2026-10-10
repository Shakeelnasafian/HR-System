<?php
// Standalone restricted-role process used only by the concurrent reporting-line test.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$script, $tenant, $actor, $company, $employment, $manager, $date, $barrier] = $argv;
file_put_contents($barrier.'.'.getmypid(), 'ready');
$deadline = microtime(true) + 15;
while (!file_exists($barrier)) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Concurrency barrier timed out.'); }
    usleep(10000);
}
try {
    app(\App\Tenancy\TenantContext::class)->run($tenant, (int)$actor, function () use ($company, $employment, $manager, $date) {
        $request = \Illuminate\Http\Request::create('/', 'POST', ['version'=>1, 'reason'=>'Synthetic concurrent reporting change', 'effective_from'=>$date, 'manager_employment_id'=>$manager]);
        app(\App\Workforce\AssignmentController::class)->store($request, $company, $employment);
    }, true); // Stands in for an MFA-verified HTTP request.
    echo '201';
} catch (\Illuminate\Validation\ValidationException $e) {
    echo '422';
} catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
    echo $e->getStatusCode();
}
