<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProjectDocumentController;

Route::get('/', function () {
    return view('welcome');
});

// Project document viewing (web route - UI screen)
// Accepts token and document_id as query parameters
Route::get('/view-document', [ProjectDocumentController::class, 'viewDocument']);

// Serve PDF files with proper headers for iframe embedding
Route::get('/serve-document', [ProjectDocumentController::class, 'serveDocument']);
