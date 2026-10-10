<?php

use App\Audit\AuditServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [AppServiceProvider::class, FortifyServiceProvider::class, AuditServiceProvider::class];
