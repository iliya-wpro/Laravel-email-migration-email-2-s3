<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'Email Migration to S3 System',
        'status' => 'running'
    ]);
});
