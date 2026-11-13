<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\ListingAvailabilityController;
use Illuminate\Support\Facades\Route;

Route::get('/listings/{property}/still-available', [ListingAvailabilityController::class, 'confirm'])
    ->middleware(['signed', 'throttle:20,1'])
    ->name('listings.confirm-availability');

Route::get('/account/verify-email/{user}/{hash}', [AccountController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:20,1'])
    ->name('account.verify-email');

Route::get('/account/confirm-email/{user}', [AccountController::class, 'confirmEmailChange'])
    ->middleware(['signed', 'throttle:20,1'])
    ->name('account.confirm-email-change');
