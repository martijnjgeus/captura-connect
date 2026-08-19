<?php

use App\Http\Controllers\Api\IncomingOrderController;
use Illuminate\Support\Facades\Route;

Route::post('/orders/incoming', IncomingOrderController::class);
