<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Webhooks\MidtransWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)
    ->name('api.health');

Route::post('/webhooks/midtrans', [MidtransWebhookController::class, 'handle'])
    ->middleware('throttle:midtrans-webhook')
    ->name('webhooks.midtrans');
