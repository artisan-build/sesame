<?php

use App\Sesame\Http\CreateClientController;
use App\Sesame\Http\RunController;
use App\Sesame\Http\SnsController;
use App\Sesame\Ingest\SendEmailController;
use ArtisanBuild\BuiltForCloud\Http\Middleware\EnsureCurrentOwnerCredential;
use Illuminate\Support\Facades\Route;

/*
 * Simulator surface. No session, cookie or CSRF middleware: these are the
 * same two calls as production (SendEmail in, SNS out), the SNS URLs, and
 * the ground-truth API the CRM reconciles against.
 */
Route::post('/v2/email/outbound-emails', SendEmailController::class);

Route::get('/sns/{account}/cert.pem', [SnsController::class, 'certificate'])->whereUuid('account');
Route::get('/sns/{account}/', [SnsController::class, 'action'])->whereUuid('account');

Route::get('/sesame/v1/runs/{run}/truth', [RunController::class, 'truth'])->whereUuid('run');
Route::get('/sesame/v1/runs/{run}/summary', [RunController::class, 'summary'])->whereUuid('run');
Route::post('/sesame/v1/runs/{run}/missing', [RunController::class, 'missing'])->whereUuid('run');

// An installation owner may issue a staging client's credentials, but neither
// a human login nor a read-only MCP/delegated credential grants that authority.
Route::post('/sesame/v1/clients', CreateClientController::class)
    ->middleware(['throttle:bfc-operator-write', EnsureCurrentOwnerCredential::class]);
