<?php

use App\Http\Controllers\Api\ZohoLeadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Admin one-time connect flow (server-side OAuth)
Route::get('/zoho/connect', [ZohoLeadController::class, 'connectRedirect']);
Route::get('/zoho/callback', [ZohoLeadController::class, 'callbackRedirect']);
