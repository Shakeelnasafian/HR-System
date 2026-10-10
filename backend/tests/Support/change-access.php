<?php

use App\Models\User;
use App\Tenancy\AccessController;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $tenant, $actor, $company, $membership, $permissions, $barrier] = $argv;
file_put_contents($barrier.'.'.getmypid(), 'ready');
$deadline = microtime(true) + 15;
while (! file_exists($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency barrier timed out.');
    }
    usleep(10000);
}
try {
    app(TenantContext::class)->run($tenant, (int) $actor, function () use ($actor, $company, $membership, $permissions) {
        $request = Request::create('/', 'PUT', ['version' => 1, 'reason' => 'Synthetic concurrent access review', 'permissions' => json_decode($permissions, true, 512, JSON_THROW_ON_ERROR)]);
        $request->setUserResolver(fn () => User::findOrFail($actor));
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('mfa_user_id', (int) $actor);
        app(AccessController::class)->replace($request, $company, $membership);
    }, true); // Stands in for an MFA-verified HTTP request.
    echo '200';
} catch (HttpExceptionInterface $e) {
    echo $e->getStatusCode();
}
