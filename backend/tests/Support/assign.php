<?php

use App\Tenancy\TenantContext;
use App\Workforce\AssignmentController;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// Standalone restricted-role process used only by the concurrent reporting-line test.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $tenant, $actor, $company, $employment, $manager, $date, $barrier] = $argv;
file_put_contents($barrier.'.'.getmypid(), 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency barrier timed out.');
    }
    usleep(10000);
}
try {
    app(TenantContext::class)->run($tenant, (int) $actor, function () use ($company, $employment, $manager, $date) {
        $request = Request::create('/', 'POST', ['version' => 1, 'reason' => 'Synthetic concurrent reporting change', 'effective_from' => $date, 'manager_employment_id' => $manager]);
        app(AssignmentController::class)->store($request, $company, $employment);
    }, true); // Stands in for an MFA-verified HTTP request.
    echo '201';
} catch (ValidationException $e) {
    echo '422';
} catch (HttpExceptionInterface $e) {
    echo $e->getStatusCode();
}
