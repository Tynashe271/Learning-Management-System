<?php

use Illuminate\Support\Facades\Route;

// There is no website here, only the API under /api. The default Laravel welcome page is gone: it advertised the framework.
Route::get('/', fn () => response()->json(['name' => config('app.name'), 'status' => 'ok', 'api' => url('/api')]));
