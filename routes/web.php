<?php

use App\Http\Controllers\MonitoringController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MonitoringController::class, 'dashboard']);
