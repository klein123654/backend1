<?php

use App\Http\Controllers\ListingAvailabilityController;
use Illuminate\Support\Facades\Route;

Route::get('/listings/{property}/still-available', [ListingAvailabilityController::class, 'confirm'])
    ->middleware(['signed', 'throttle:20,1'])
    ->name('listings.confirm-availability');
