<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middlewareAliases = [
        // ... other middleware
        'role' => \App\Http\Middleware\CheckRole::class,
    ];
}