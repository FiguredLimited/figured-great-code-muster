<?php

use Illuminate\Support\Facades\Route;

// The whole app is a Vue SPA mounted from this single view.
Route::get('/', function () {
    return view('app');
});
