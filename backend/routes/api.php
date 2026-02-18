<?php

use App\Http\Controllers\Api\ZohoLeadController;
use Illuminate\Support\Facades\Route;

Route::get('/zoho/status', [ZohoLeadController::class, 'status']);
Route::get('/zoho/oauth/url', [ZohoLeadController::class, 'authUrl']);
Route::post('/zoho/oauth/exchange', [ZohoLeadController::class, 'exchangeCode']);
Route::post('/zoho/token', [ZohoLeadController::class, 'upsertToken']);
Route::post('/zoho/deal-account', [ZohoLeadController::class, 'store']);
