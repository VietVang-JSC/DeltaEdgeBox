<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/edge-manager', [App\Http\Controllers\Api\EdgeManagerController::class, 'dashboard']);
Route::post('/edge-manager/action', [App\Http\Controllers\Api\EdgeManagerController::class, 'action']);
