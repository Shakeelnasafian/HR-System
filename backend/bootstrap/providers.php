<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuditServiceProvider;
use App\Providers\FortifyServiceProvider;

return [AppServiceProvider::class, FortifyServiceProvider::class, AuditServiceProvider::class];
