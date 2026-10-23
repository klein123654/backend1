<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AiSearchController;
use App\Http\Controllers\Api\AiDescriptionController;
use App\Http\Controllers\Api\MarketInsightController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InquiryController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\PublisherApplicationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SavedPropertyController;
use App\Http\Controllers\Api\VerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/run-migration', function () {
    \Artisan::call('migrate', ['--force' => true]);
    return 'Migration run successfully!';
});

Route::get('/test-s3', function () {
    try {
        // Create a dummy text file
        $content = "Testing Supabase S3 connection at " . now();

        // Upload it to the 'supabase' disk
        $path = \Storage::disk('supabase')->put('test-folder/test.txt', $content);

        // Get the URL
        $url = \Storage::disk('supabase')->url($path);

        return response()->json([
            'success' => true,
            'path' => $path,
            'url' => $url,
            'message' => 'Check your Supabase bucket!'
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ], 500);
    }
});

Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:10,1');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:5,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::patch('/user', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/my/inquiries', [InquiryController::class, 'mine']);
    Route::get('/my/inquiries/sent', [InquiryController::class, 'sent']);
    Route::get('/my/saved-properties', [SavedPropertyController::class, 'index']);
    Route::post('/my/saved-properties/{property}', [SavedPropertyController::class, 'store']);
    Route::delete('/my/saved-properties/{property}', [SavedPropertyController::class, 'destroy']);
    Route::patch('/inquiries/{inquiry}', [InquiryController::class, 'update']);
    Route::post('/broker/verification', [VerificationController::class, 'store']);
    Route::get('/broker/verification', [VerificationController::class, 'show']);
    Route::get('/my/publisher-application', [PublisherApplicationController::class, 'show']);
    Route::post('/my/publisher-application', [PublisherApplicationController::class, 'store']);
});

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
    Route::get('/admin/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/admin/properties', [AdminController::class, 'listings']);
    Route::patch('/admin/properties/{property}', [AdminController::class, 'decideListing']);
    Route::patch('/admin/properties/{property}/unpublish', [AdminController::class, 'unpublishListing']);
    Route::get('/admin/users', [AdminController::class, 'users']);
    Route::patch('/admin/users/{user}', [AdminController::class, 'updateUser']);
    Route::get('/admin/verifications', [AdminController::class, 'verifications']);
    Route::patch('/admin/verifications/{verification}', [AdminController::class, 'decideVerification']);
    Route::get('/admin/publisher-applications', [AdminController::class, 'publisherApplications']);
    Route::patch('/admin/publisher-applications/{application}', [AdminController::class, 'decidePublisherApplication']);
    Route::get('/admin/inquiries', [AdminController::class, 'inquiries']);
    Route::get('/admin/reports', [AdminController::class, 'reports']);
    Route::patch('/admin/reports/{report}', [AdminController::class, 'updateReport']);
});
