<?php

namespace Modules\Gateways\CreatorFeed;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Module\ModuleRegistry;
use App\Support\ClientPermissions;
use Illuminate\Http\Request;

class CreatorFeedController extends Controller
{
    private const PAYABLE = ['unpaid', 'overdue', 'partially_paid'];

    private function ensureEnabled(): void
    {
        $usable = array_map('strtolower', app(ModuleRegistry::class)->usableGateways());
        abort_unless(in_array(CreatorFeedPayments::GATEWAY, $usable, true), 404);
    }

    /** Same rule the core's capture endpoints use: the invoice must be the signed-in account's. */
    private function authoriseInvoice(Invoice $invoice): void
    {
        $user = auth()->user();
        $client = $user?->clients()->whereKey($invoice->client_id)->first();
        abort_if(! $client || ! ClientPermissions::allows($user, $client, 'invoices'), 403);
    }

    private function invoicePage(Invoice $invoice, array $query = [])
    {
        return redirect()->to(route('client.invoices.show', $invoice).($query ? '?'.http_build_query($query) : ''));
    }

    // ---------- Client ----------

    /** POST /creatorfeed/pay/{invoice}: create/reuse an order and send the buyer to CreatorFeed. */
    public function pay(Invoice $invoice)
    {
        $this->ensureEnabled();
        $this->authoriseInvoice($invoice);

        if (! in_array(strtolower((string) $invoice->status), self::PAYABLE, true)) {
            return $this->invoicePage($invoice)->with('info', 'This invoice has nothing left to pay.');
        }

        // A payment starts here: an unpaid Add Funds invoice takes today's rate (as the core gateways do).
        if (method_exists($invoice, 'refreshAddFundsRate')) {
            $invoice->refreshAddFundsRate();
        }

        $result = app(CreatorFeedModule::class)->capture($invoice, $invoice->amountDue());

        if (! ($result['success'] ?? false) || empty($result['checkout_url'])) {
            return $this->invoicePage($invoice)->with('error', $result['message'] ?? 'Could not start the CreatorFeed payment.');
        }

        return redirect()->away($result['checkout_url']);
    }

    /**
     * GET /creatorfeed/return/{invoice}?order_id=…&status=…&payment_id=…
     *
     * The query string is never trusted: the order is looked up locally and
     * its status is fetched from the API before anything is recorded.
     */
    public function return(Request $request, Invoice $invoice)
    {
        $this->ensureEnabled();

        $order = CreatorFeedOrder::where('order_id', (string) $request->query('order_id'))
            ->where('invoice_id', $invoice->id)
            ->first();

        if (! $order) {
            return $this->invoicePage($invoice)->with('warning', 'We could not match this CreatorFeed payment to the invoice. If you paid, it will show here once confirmed.');
        }

        $r = app(CreatorFeedPayments::class)->sync($order, 'return');

        if ($r['paid'] && $r['applied']) {
            return $this->invoicePage($invoice, ['payment' => 'success'])->with('success', 'Payment received. Thank you!');
        }
        if (! $r['ok']) {
            return $this->invoicePage($invoice)->with('warning', 'We could not confirm your payment yet. It will update automatically; you can also use "Check payment status".');
        }

        return match ($r['status']) {
            'pending' => $this->invoicePage($invoice)->with('info', 'Your payment is still processing. Use "Check payment status" in a moment.'),
            'failed' => $this->invoicePage($invoice, ['payment' => 'failed'])->with('error', $r['message'].' You can try again.'),
            default => $this->invoicePage($invoice)->with('warning', $r['message']),
        };
    }

    /** POST /client/creatorfeed/check/{invoice}: the customer's "Check payment status" button. */
    public function clientCheck(Invoice $invoice)
    {
        $this->ensureEnabled();
        $this->authoriseInvoice($invoice);

        $s = app(CreatorFeedPayments::class)->syncInvoice($invoice, 'client', 5);

        if ($s['checked'] === 0) {
            return $this->invoicePage($invoice)->with('info', 'There is no CreatorFeed payment to check for this invoice.');
        }
        if ($s['paid'] > 0) {
            return $this->invoicePage($invoice, ['payment' => 'success'])->with('success', 'Payment confirmed. Thank you!');
        }
        if ($s['errors'] > 0) {
            return $this->invoicePage($invoice)->with('warning', 'We could not reach CreatorFeed just now. Please try again in a minute.');
        }

        return $this->invoicePage($invoice)->with('info', 'No completed payment found yet. If you just paid, wait a minute and check again.');
    }

    // ---------- Admin ----------

    /** GET /admin/creatorfeed/orders */
    public function adminIndex(Request $request)
    {
        $q = CreatorFeedOrder::query()->with('invoice')->latest('id');

        if ($request->filled('invoice')) {
            $q->where('invoice_id', (int) $request->query('invoice'));
        }
        if ($request->filled('status')) {
            $q->where('status', (string) $request->query('status'));
        }
        if ($request->filled('q')) {
            $term = '%'.trim((string) $request->query('q')).'%';
            $q->where(fn ($w) => $w->where('order_id', 'like', $term)->orWhere('cf_id', 'like', $term)->orWhere('utr', 'like', $term));
        }

        return view('creatorfeed::admin.orders', [
            'orders' => $q->paginate(30)->withQueryString(),
            'mode' => CreatorFeedApi::fromSettings()->mode(),
            'enabled' => in_array(CreatorFeedPayments::GATEWAY, array_map('strtolower', app(ModuleRegistry::class)->usableGateways()), true),
        ]);
    }

    /** POST /admin/creatorfeed/orders/{id}/check */
    public function adminCheck(int $id)
    {
        $order = CreatorFeedOrder::findOrFail($id);
        $r = app(CreatorFeedPayments::class)->sync($order, 'admin');

        return back()->with($r['ok'] ? 'success' : 'error', "CreatorFeed {$order->order_id}: {$r['message']}");
    }

    /** POST /admin/creatorfeed/invoices/{invoice}/check */
    public function adminCheckInvoice(Invoice $invoice)
    {
        $s = app(CreatorFeedPayments::class)->syncInvoice($invoice, 'admin', 20);

        if ($s['checked'] === 0) {
            return back()->with('error', "Invoice #{$invoice->id} has no CreatorFeed order that needs checking.");
        }

        return back()->with($s['errors'] ? 'error' : 'success', "Checked {$s['checked']} CreatorFeed order(s): ".implode(' | ', $s['messages']));
    }

    /**
     * POST /admin/creatorfeed/lookup: status of any order id / CFD-TXN id on
     * the account. Read-only unless the order is one of ours.
     */
    public function adminLookup(Request $request)
    {
        $ref = trim((string) $request->input('reference'));
        if ($ref === '' || strlen($ref) > 64) {
            return back()->with('error', 'Enter an order id or CFD-TXN id.');
        }

        $local = CreatorFeedOrder::where('order_id', $ref)->orWhere('cf_id', $ref)->first();
        if ($local) {
            $r = app(CreatorFeedPayments::class)->sync($local, 'admin');

            return back()->with($r['ok'] ? 'success' : 'error', "CreatorFeed {$local->order_id} (invoice #{$local->invoice_id}): {$r['message']}");
        }

        $res = CreatorFeedApi::fromSettings()->getOrder($ref);
        if (! $res['ok']) {
            return back()->with('error', "CreatorFeed lookup of {$ref} failed: [{$res['code']}] {$res['message']}");
        }

        $d = $res['data'];

        return back()->with('success', sprintf(
            'CreatorFeed %s / %s: status %s, ₹%s, mode %s%s. Not linked to any invoice here, so nothing was recorded.',
            $d['order_id'] ?? '-', $d['id'] ?? '-', $d['status'] ?? '-', $d['amount'] ?? '-', $d['mode'] ?? '-',
            ! empty($d['payment']['utr']) ? ', UTR '.$d['payment']['utr'] : ''
        ));
    }
}
