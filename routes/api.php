<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AiDescriptionController;
use App\Http\Controllers\Api\AiSearchController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\InquiryController;
use App\Http\Controllers\Api\MarketInsightController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\PublisherApplicationController;
use App\Http\Controllers\Api\PublisherController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SavedPropertyController;
use App\Http\Controllers\Api\SavedSearchController;
use App\Http\Controllers\Api\VerificationController;
use App\Http\Controllers\ListingAvailabilityController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:10,1');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:5,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:10,1');
Route::post('/auth/google', GoogleAuthController::class)
    ->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::patch('/user', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/user/password', [AccountController::class, 'changePassword'])->middleware('throttle:5,1');
    Route::post('/user/email', [AccountController::class, 'requestEmailChange'])->middleware('throttle:5,1');
    Route::delete('/user', [AccountController::class, 'destroy'])->middleware('throttle:5,1');
    Route::post('/email/verification-notification', [AccountController::class, 'resendVerification'])->middleware('throttle:3,10');
    Route::get('/my/saved-searches', [SavedSearchController::class, 'index']);
    Route::post('/my/saved-searches', [SavedSearchController::class, 'store'])->middleware('throttle:20,1');
    Route::delete('/my/saved-searches/{savedSearch}', [SavedSearchController::class, 'destroy']);
    Route::get('/my/inquiries', [InquiryController::class, 'mine']);
    Route::get('/my/inquiries/sent', [InquiryController::class, 'sent']);
    Route::get('/inquiries/{inquiry}/messages', [InquiryController::class, 'messages']);
    Route::post('/inquiries/{inquiry}/messages', [InquiryController::class, 'storeMessage']);
    Route::get('/my/saved-properties', [SavedPropertyController::class, 'index']);
    Route::post('/my/saved-properties/{property}', [SavedPropertyController::class, 'store']);
    Route::delete('/my/saved-properties/{property}', [SavedPropertyController::class, 'destroy']);
    Route::patch('/inquiries/{inquiry}', [InquiryController::class, 'update']);
    Route::post('/broker/verification', [VerificationController::class, 'store']);
    Route::get('/broker/verification', [VerificationController::class, 'show']);
    Route::get('/my/publisher-application', [PublisherApplicationController::class, 'show']);
    Route::post('/my/publisher-application', [PublisherApplicationController::class, 'store']);
});

Route::post('/internal/availability-reminders', [ListingAvailabilityController::class, 'sendReminders'])
    ->middleware('throttle:5,1');
Route::get('/barangays', [PropertyController::class, 'barangays']);
Route::get('/publishers/{user}', [PublisherController::class, 'show']);
Route::get('/properties', [PropertyController::class, 'index']);
Route::get('/properties/{property}', [PropertyController::class, 'show']);
Route::post('/ai/search', AiSearchController::class)
    ->middleware('throttle:ai-search');
Route::post('/ai/describe', AiDescriptionController::class)
    ->middleware('throttle:ai-search');
Route::get('/ai/market-insight', MarketInsightController::class)
    ->middleware('throttle:ai-search');
Route::post('/properties/{property}/inquiries', [InquiryController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:10,1']);
Route::post('/properties/{property}/reports', [ReportController::class, 'store'])
    ->middleware('throttle:5,1');
Route::post('/reports', [ReportController::class, 'global'])
    ->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/my/properties', [PropertyController::class, 'mine']);
    Route::post('/my/properties', [PropertyController::class, 'store']);
    Route::patch('/my/properties/{property}', [PropertyController::class, 'update']);
    Route::delete('/my/properties/{property}', [PropertyController::class, 'destroy']);
    Route::patch('/my/properties/{property}/availability', [PropertyController::class, 'updateAvailability']);
});

Route::middleware(['auth:sanctum', 'can:access-admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/properties', [AdminController::class, 'listings']);
    Route::patch('/properties/{property}', [AdminController::class, 'decideListing']);
    Route::patch('/properties/{property}/unpublish', [AdminController::class, 'unpublishListing']);
    Route::delete('/properties/{property}', [AdminController::class, 'deleteListing']);
    Route::get('/users', [AdminController::class, 'users']);
    Route::patch('/users/{user}', [AdminController::class, 'updateUser']);
    Route::get('/verifications', [AdminController::class, 'verifications']);
    Route::patch('/verifications/{verification}', [AdminController::class, 'decideVerification']);
    Route::get('/publisher-applications', [AdminController::class, 'publisherApplications']);
    Route::patch('/publisher-applications/{application}', [AdminController::class, 'decidePublisherApplication']);
    Route::get('/inquiries', [AdminController::class, 'inquiries']);
    Route::get('/reports', [AdminController::class, 'reports']);
    Route::patch('/reports/{report}', [AdminController::class, 'updateReport']);
});
