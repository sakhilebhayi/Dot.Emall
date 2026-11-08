<?php

use App\Providers\AppServiceProvider;
use App\Providers\DiscoveryServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HealthCheckServiceProvider;
use App\Providers\JetstreamServiceProvider;

return [
    AppServiceProvider::class,
    HealthCheckServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
    DiscoveryServiceProvider::class,
];
