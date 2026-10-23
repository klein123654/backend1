<?php

use Illuminate\Support\Facades\Route;

Route::get('/run-migration', function () {
    \Artisan::call('migrate', ['--force' => true]);
    return 'Migration run successfully!';
});

Route::get('/', function () {
    return view('welcome');
});
