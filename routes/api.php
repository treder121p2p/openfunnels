<?php

use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\FunnelApiController;
use App\Http\Controllers\Api\TemplateApiController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Middleware\AuthenticateApi;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Token-based API for MCP integration and external tools.
| Authentication: Authorization: Bearer <token>
|
| Generate token: POST /api/token (requires session auth)
| Revoke token:   DELETE /api/token (requires session auth)
|
*/

// Public: generate/revoke token (requires web session auth)
Route::middleware('auth')->group(function () {
    Route::post('/token', [ApiTokenController::class, 'store'])->name('api.token.store');
    Route::delete('/token', [ApiTokenController::class, 'destroy'])->name('api.token.destroy');
});

// Protected API routes (bearer token)
Route::middleware(AuthenticateApi::class)->group(function () {
    // Funnels CRUD
    Route::get('/funnels', [FunnelApiController::class, 'index'])->name('api.funnels.index');
    Route::post('/funnels', [FunnelApiController::class, 'store'])->name('api.funnels.store');
    Route::get('/funnels/{funnel}', [FunnelApiController::class, 'show'])->name('api.funnels.show');
    Route::put('/funnels/{funnel}', [FunnelApiController::class, 'update'])->name('api.funnels.update');
    Route::delete('/funnels/{funnel}', [FunnelApiController::class, 'destroy'])->name('api.funnels.destroy');

    // Funnel actions
    Route::post('/funnels/{funnel}/publish', [FunnelApiController::class, 'publish'])->name('api.funnels.publish');
    Route::post('/funnels/{funnel}/unpublish', [FunnelApiController::class, 'unpublish'])->name('api.funnels.unpublish');
    Route::post('/funnels/{funnel}/duplicate', [FunnelApiController::class, 'duplicate'])->name('api.funnels.duplicate');

    // AI generation
    Route::post('/funnels/generate', [FunnelApiController::class, 'generate'])->name('api.funnels.generate');

    // Templates
    Route::get('/funnels/{funnel}/template', [TemplateApiController::class, 'export'])->name('api.templates.export');
    Route::post('/templates/import', [TemplateApiController::class, 'import'])->name('api.templates.import');

    // File uploads
    Route::post('/upload', [UploadController::class, 'store'])->name('api.upload.store');
    Route::post('/upload/batch', [UploadController::class, 'storeMultiple'])->name('api.upload.batch');
    Route::get('/upload', [UploadController::class, 'index'])->name('api.upload.index');
    Route::delete('/upload', [UploadController::class, 'destroy'])->name('api.upload.destroy');
});
