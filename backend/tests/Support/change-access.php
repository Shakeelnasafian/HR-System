<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
[$script, $tenant, $actor, $company, $membership, $permissions, $barrier] = $argv;
file_put_contents($barrier.'.'.getmypid(), 'ready');
$deadline = microtime(true) + 15;
while (!file_exists($barrier)) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Concurrency barrier timed out.'); }
    usleep(10000);
}
try {
    app(\App\Tenancy\TenantContext::class)->run($tenant, (int)$actor, function () use ($actor, $company, $membership, $permissions) {
        $request = \Illuminate\Http\Request::create('/', 'PUT', ['version'=>1,'reason'=>'Synthetic concurrent access review','permissions'=>json_decode($permissions,true,512,JSON_THROW_ON_ERROR)]);
        $request->setUserResolver(fn()=>\App\Models\User::findOrFail($actor));
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put('mfa_user_id',(int)$actor);
        app(\App\Tenancy\AccessController::class)->replace($request,$company,$membership);
    }, true); // Stands in for an MFA-verified HTTP request.
    echo '200';
} catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
    echo $e->getStatusCode();
}
