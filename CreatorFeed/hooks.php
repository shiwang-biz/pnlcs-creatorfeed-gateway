<?php

/*
 * CreatorFeed: a "Check status" panel on the staff invoice page, without
 * touching the core's views. Loaded on every request, so it bails out fast.
 */

use Illuminate\Support\Facades\Route;
use Modules\Gateways\CreatorFeed\CreatorFeedOrder;

add_hook('AdminAreaFooterOutput', 50, function (array $vars) {
    try {
        if (! request()->routeIs('admin.invoices.show') || ! Route::has('creatorfeed.admin.check-invoice')) {
            return '';
        }

        $param = request()->route('invoice');
        $invoiceId = (int) (is_object($param) ? $param->getKey() : $param);
        if ($invoiceId <= 0) {
            return '';
        }

        $orders = CreatorFeedOrder::where('invoice_id', $invoiceId)->latest('id')->limit(5)->get();
        if ($orders->isEmpty()) {
            return '';
        }

        return view('creatorfeed::admin.invoice-panel', ['invoiceId' => $invoiceId, 'orders' => $orders])->render();
    } catch (\Throwable $e) {
        return ''; // never break the admin page (e.g. migration not run yet)
    }
});
