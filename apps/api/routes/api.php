<?php

use App\Http\Controllers\AudioFileController;
use App\Http\Controllers\AudioUploadController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProofingRuleController;
use App\Http\Controllers\TranscriptController;
use App\Http\Controllers\TranscriptVersionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::put('/auth/password', [AuthController::class, 'updatePassword']);

    Route::apiResource('users', UserController::class)->except('show');

    Route::post('/proofing-rules/import', [ProofingRuleController::class, 'import']);
    Route::apiResource('proofing-rules', ProofingRuleController::class)->except('show');

    Route::apiResource('projects', ProjectController::class);
    Route::put('/projects/{project}/members', [ProjectController::class, 'syncMembers']);

    Route::get('/projects/{project}/audio-files', [AudioFileController::class, 'index']);
    Route::post('/projects/{project}/audio-files', [AudioUploadController::class, 'store']);
    Route::get('/audio-files/{audioFile}', [AudioFileController::class, 'show']);
    Route::delete('/audio-files/{audioFile}', [AudioFileController::class, 'destroy']);
    Route::get('/audio-files/{audioFile}/stream', [AudioFileController::class, 'stream']);
    Route::post('/audio-files/{audioFile}/retry', [AudioFileController::class, 'retry']);

    Route::get('/audio-files/{audioFile}/transcript', [TranscriptController::class, 'show']);
    Route::put('/audio-files/{audioFile}/transcript', [TranscriptController::class, 'update']);
    Route::post('/audio-files/{audioFile}/transcript/transcribe', [TranscriptController::class, 'transcribe']);
    Route::post('/audio-files/{audioFile}/transcript/transcribe/cancel', [TranscriptController::class, 'cancel']);
    Route::delete('/audio-files/{audioFile}/transcript/draft', [TranscriptController::class, 'destroyDraft']);
    Route::get('/audio-files/{audioFile}/transcript/export', [TranscriptController::class, 'export']);
    Route::get('/audio-files/{audioFile}/transcript/versions', [TranscriptVersionController::class, 'index']);
    Route::get('/audio-files/{audioFile}/transcript/versions/{version}', [TranscriptVersionController::class, 'show']);
    Route::post('/audio-files/{audioFile}/transcript/versions/{version}/restore', [TranscriptVersionController::class, 'restore']);

    Route::get('/audio-files/{audioFile}/upload', [AudioUploadController::class, 'offset']);
    Route::patch('/audio-files/{audioFile}/upload', [AudioUploadController::class, 'append'])->withoutMiddleware('throttle:api');
});
