<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\SnapexPay\SnapexPay;

// Server-to-server POST from SnapEX Pay: no session, no CSRF token (authenticated by the HMAC signature).
Route::post('/extensions/snapexpay/webhook', [SnapexPay::class, 'webhook'])
    ->withoutMiddleware([
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
    ])
    ->name('extensions.gateways.snapexpay.webhook');
