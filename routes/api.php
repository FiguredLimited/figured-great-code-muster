<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\BankTransactionController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

// Page 1: Bank Coding
Route::get('/bank-transactions', [BankTransactionController::class, 'index']);
Route::patch('/bank-transactions/{bankTransaction}', [BankTransactionController::class, 'update']);

// Page 2: Inbox
Route::get('/emails', [EmailController::class, 'index']);
Route::post('/emails/{email}/reply', [EmailController::class, 'reply']);

// Page 3: Monthly Report
Route::get('/farms', [ReportController::class, 'farms']);
Route::get('/farms/{farm}/report', [ReportController::class, 'show']);
Route::put('/farms/{farm}/commentary', [ReportController::class, 'saveCommentary']);

// Page 4: Invoice Entry
Route::get('/invoices', [InvoiceController::class, 'index']);
Route::put('/invoices/{invoice}', [InvoiceController::class, 'update']);

// Page 5: Stock Reconciliation
Route::get('/stock', [StockController::class, 'index']);
Route::post('/stock-movements', [StockController::class, 'storeMovement']);
Route::delete('/stock-movements/{stockMovement}', [StockController::class, 'destroyMovement']);
Route::post('/stock/suggest-movements', [StockController::class, 'suggestMovements']);

// The AI proxy. The only way frontend code should ever talk to Claude -
// the API key lives in .env and never leaves the server.
Route::post('/ai', AiController::class);
