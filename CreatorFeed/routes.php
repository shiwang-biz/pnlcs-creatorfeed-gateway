<?php

use Illuminate\Support\Facades\Route;
use Modules\Gateways\CreatorFeed\CreatorFeedController;

/*
 * Route names live under "creatorfeed." (not "gateway.creatorfeed.") on
 * purpose: the core's generic /gateway/creatorfeed/callback keeps answering
 * the webhook, and this file only adds pages.
 */

// Customer starts a payment / checks status (signed-in client area).
Route::middleware(['web', 'banned.ip', 'client.permission', 'auth', '2fa'])->group(function () {
    Route::post('creatorfeed/pay/{invoice}', [CreatorFeedController::class, 'pay'])
        ->middleware('throttle:10,1')->name('creatorfeed.pay');
    Route::post('client/creatorfeed/check/{invoice}', [CreatorFeedController::class, 'clientCheck'])
        ->middleware('throttle:10,1')->name('creatorfeed.check');
});

// Buyer coming back from CreatorFeed. Public: the session may have lapsed
// during checkout, and nothing here trusts the URL - the API is asked.
Route::middleware(['web', 'throttle:30,1'])
    ->get('creatorfeed/return/{invoice}', [CreatorFeedController::class, 'return'])
    ->name('creatorfeed.return');

// Staff.
Route::middleware(['web', 'admin.auth', 'admin.2fa', 'admin.permission:view_invoices'])
    ->prefix('admin/creatorfeed')->name('creatorfeed.admin.')
    ->group(function () {
        Route::get('orders', [CreatorFeedController::class, 'adminIndex'])->name('orders');
        Route::post('orders/{id}/check', [CreatorFeedController::class, 'adminCheck'])->whereNumber('id')->middleware('throttle:60,1')->name('check');
        Route::post('invoices/{invoice}/check', [CreatorFeedController::class, 'adminCheckInvoice'])->middleware('throttle:30,1')->name('check-invoice');
        Route::post('lookup', [CreatorFeedController::class, 'adminLookup'])->middleware('throttle:30,1')->name('lookup');
    });
