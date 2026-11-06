<?php

use App\CrmHarness\CrmHarnessServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    CrmHarnessServiceProvider::class,
];
