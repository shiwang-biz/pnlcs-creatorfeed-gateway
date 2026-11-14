<?php

namespace Modules\Gateways\CreatorFeed;

use App\Models\Invoice;
use App\Services\PaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Order creation and status confirmation for CreatorFeed.
 *
 * The rule this class enforces: an invoice is only ever marked paid from an
 * order CreatorFeed's own API reports as "paid" when we ask it - never from a
 * webhook body or a return URL on its own, whatever they say.
 */
class CreatorFeedPayments
{
    public const GATEWAY = 'creatorfeed';

    public function __construct(private ?CreatorFeedApi $api = null)
    {
        $this->api ??= CreatorFeedApi::fromSettings();
    }

    public function api(): CreatorFeedApi
    {
        return $this->api;
    }

    public static function invoiceCurrency(Invoice $invoice): string
    {
        return strtoupper((string) ($invoice->source_currency ?: shop_currency_code()));
    }

    /**
     * Get a payment_url for this invoice: an unexpired pending order for the
     * same amount is reused, otherwise a new order is created.
     *
     * @return array{success: bool, message?: string, payment_url?: string, order?: CreatorFeedOrder}
     */
    public function startPayment(Invoice $invoice, ?float $amount = null): array
    {
        if (! $this->api->isConfigured()) {
            return ['success' => false, 'message' => 'CreatorFeed is not configured.'];
        }

        $currency = self::invoiceCurrency($invoice);
        if ($currency !== 'INR') {
            return ['success' => false, 'message' => "CreatorFeed accepts payments in INR only; this invoice is in {$currency}."];
        }

        $amount = round($amount ?? $invoice->amountDue(), 2);
        if ($amount < 1) {
            return ['success' => false, 'message' => 'CreatorFeed needs an amount of at least ₹1.'];
        }
        if ($amount > 1000000) {
            return ['success' => false, 'message' => 'CreatorFeed accepts at most ₹10,00,000 per payment.'];
        }

        $customer = $this->customer($invoice);
        if (isset($customer['error'])) {
            return ['success' => false, 'message' => $customer['error']];
        }

        // Reuse a live, unexpired link for the same amount instead of piling up orders.
        $reusable = CreatorFeedOrder::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'pending')
            ->where('mode', $this->api->mode())
            ->where('amount', number_format($amount, 2, '.', ''))
            ->whereNotNull('payment_url')
            ->where('expires_at', '>', now()->addMinutes(3))
            ->latest('id')
            ->first();

        if ($reusable) {
            return ['success' => true, 'payment_url' => $reusable->payment_url, 'order' => $reusable];
        }

        $order = CreatorFeedOrder::create([
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'order_id' => $this->newOrderId($invoice),
            'mode' => $this->api->mode(),
            'amount' => $amount,
            'currency' => 'INR',
            'status' => 'creating',
        ]);

        $invoiceNum = (string) ($invoice->invoice_num ?: $invoice->id);
        $payload = [
            'order_id' => $order->order_id,
            'amount' => number_format($amount, 2, '.', ''),
            'customer' => $customer,
            'description' => Str::limit($this->description($invoice), 197),
            'return_url' => route('creatorfeed.return', ['invoice' => $invoice->id]),
            'notes' => [
                'pnlcs_invoice_id' => (string) $invoice->id,
                'pnlcs_invoice_num' => $invoiceNum,
                'pnlcs_client_id' => (string) $invoice->client_id,
                'source' => 'pnlcs',
            ],
        ];

        $res = $this->api->createOrder($payload);

        if (! $res['ok']) {
            $order->update(['status' => 'error', 'last_error' => "[{$res['code']}] {$res['message']}"]);
            CreatorFeedModule::log("Create order {$order->order_id} for invoice #{$invoice->id} failed: [{$res['code']}] {$res['message']}", 'error');

            return ['success' => false, 'message' => 'CreatorFeed could not start the payment: '.($res['message'] ?: 'unknown error')];
        }

        $this->fill($order, $res['data']);
        $order->save();

        CreatorFeedModule::log("Order {$order->order_id} ({$order->cf_id}) created for invoice #{$invoice->id}, ₹{$payload['amount']}, mode {$order->mode}");

        if (! $order->payment_url) {
            return ['success' => false, 'message' => 'CreatorFeed did not return a payment link (order status: '.$order->status.').'];
        }

        return ['success' => true, 'payment_url' => $order->payment_url, 'order' => $order];
    }

    /**
     * Ask CreatorFeed for the order's current state, store it, and - when it
     * is paid - record the payment on the invoice (once; PaymentService
     * ignores a repeat of the same transaction id).
     *
     * @return array{ok: bool, status: string, paid: bool, applied: bool, message: string, retryable?: bool, transaction_id?: string, amount?: float}
     */
    public function sync(CreatorFeedOrder $order, string $by = 'admin'): array
    {
        $res = $this->api->getOrder($order->order_id);
        $order->last_checked_at = now();
        $order->last_checked_by = $by;

        if (! $res['ok']) {
            $order->last_error = "[{$res['code']}] {$res['message']}";
            $order->save();
            CreatorFeedModule::log("Status check of {$order->order_id} failed: [{$res['code']}] {$res['message']}", 'error');

            return [
                'ok' => false, 'status' => $order->status, 'paid' => false, 'applied' => false,
                'retryable' => $res['status'] === 0 || $res['status'] === 429 || $res['status'] >= 500,
                'message' => 'Could not reach CreatorFeed: '.($res['message'] ?: 'unknown error'),
            ];
        }

        $data = $res['data'];

        // The answer has to be about this order and this invoice.
        $notesInvoice = (string) ($data['notes']['pnlcs_invoice_id'] ?? '');
        if (($data['order_id'] ?? null) !== $order->order_id || $notesInvoice !== (string) $order->invoice_id) {
            $order->last_error = 'CreatorFeed returned an order that does not match this invoice.';
            $order->save();
            CreatorFeedModule::log("Order {$order->order_id}: API answer does not match (order_id ".($data['order_id'] ?? '-').", invoice note {$notesInvoice})", 'error');

            return ['ok' => false, 'status' => $order->status, 'paid' => false, 'applied' => false, 'message' => $order->last_error];
        }

        $this->fill($order, $data);
        $order->last_error = null;
        $order->save();

        if ($order->status !== 'paid') {
            return [
                'ok' => true, 'status' => $order->status, 'paid' => false, 'applied' => false,
                'message' => match ($order->status) {
                    'pending' => 'Payment not received yet.',
                    'failed' => 'The last payment attempt failed'.($order->failure_reason ? " ({$order->failure_reason})" : '').'.',
                    'expired' => 'This payment link expired without a payment.',
                    default => "CreatorFeed status: {$order->status}.",
                },
            ];
        }

        if (strtoupper((string) ($data['currency'] ?? 'INR')) !== 'INR') {
            $order->update(['last_error' => 'Paid in unexpected currency '.($data['currency'] ?? '?')]);

            return ['ok' => false, 'status' => 'paid', 'paid' => true, 'applied' => false, 'message' => 'Paid in an unexpected currency; not applied, check manually.'];
        }

        $transactionId = (string) ($data['id'] ?? $order->cf_id);
        $amount = round((float) ($data['amount'] ?? $order->amount), 2);

        if (abs($amount - (float) $order->amount) > 0.009) {
            CreatorFeedModule::log("Order {$order->order_id}: paid amount ₹{$amount} differs from requested ₹{$order->amount}; recording the paid amount", 'error');
        }

        $invoice = Invoice::find($order->invoice_id);
        if (! $invoice) {
            $order->update(['last_error' => 'Invoice no longer exists.']);

            return ['ok' => false, 'status' => 'paid', 'paid' => true, 'applied' => false, 'message' => 'Paid, but the invoice no longer exists.'];
        }

        $applied = app(PaymentService::class)->applyPayment($invoice, self::GATEWAY, $transactionId, $amount);

        if ($order->applied_at === null) {
            $order->update(['applied_at' => now(), 'transaction_id' => $transactionId]);
            CreatorFeedModule::log("Payment {$transactionId} (₹{$amount}, UTR {$order->utr}) applied to invoice #{$invoice->id} via {$by} check, mode {$order->mode}");
        }

        return [
            'ok' => true, 'status' => 'paid', 'paid' => true, 'applied' => true,
            'transaction_id' => $transactionId, 'amount' => $amount,
            'message' => ($applied['duplicate'] ?? false) ? 'Payment confirmed (already recorded).' : 'Payment confirmed and recorded.',
        ];
    }

    /**
     * Check every order of an invoice that a check can still change.
     *
     * @return array{checked: int, paid: int, errors: int, messages: array<int,string>}
     */
    public function syncInvoice(Invoice $invoice, string $by, int $limit = 10): array
    {
        $orders = CreatorFeedOrder::query()
            ->where('invoice_id', $invoice->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->filter(fn (CreatorFeedOrder $o) => $o->isOpen() || ($o->status === 'expired' && $o->updated_at > now()->subDay()))
            ->take($limit);

        $out = ['checked' => 0, 'paid' => 0, 'errors' => 0, 'messages' => []];
        foreach ($orders as $order) {
            $r = $this->sync($order, $by);
            $out['checked']++;
            $out['paid'] += $r['paid'] && $r['applied'] ? 1 : 0;
            $out['errors'] += $r['ok'] ? 0 : 1;
            $out['messages'][] = "{$order->order_id}: {$r['message']}";
        }

        return $out;
    }

    /** Copy the API's order object onto our row. */
    private function fill(CreatorFeedOrder $order, array $d): void
    {
        $order->cf_id = $d['id'] ?? $order->cf_id;
        $order->mode = $d['mode'] ?? $order->mode;
        $order->status = in_array($d['status'] ?? null, ['pending', 'paid', 'failed', 'expired'], true) ? $d['status'] : $order->status;
        $order->payment_url = $d['payment_url'] ?? null;
        $order->failure_reason = isset($d['failure_reason']) ? Str::limit((string) $d['failure_reason'], 250) : null;
        $order->expires_at = ! empty($d['expires_at']) ? Carbon::parse($d['expires_at']) : $order->expires_at;

        $p = is_array($d['payment'] ?? null) ? $d['payment'] : [];
        if ($p) {
            $order->payment_method = $p['method'] ?? $order->payment_method;
            $order->utr = $p['utr'] ?? $order->utr;
            $order->paid_at = ! empty($p['paid_at']) ? Carbon::parse($p['paid_at']) : $order->paid_at;
            $order->fee = $p['fee'] ?? $order->fee;
            $order->gst = $p['gst'] ?? $order->gst;
            $order->net = $p['net'] ?? $order->net;
        }

        $order->raw = $d;
    }

    private function newOrderId(Invoice $invoice): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) (CreatorFeedModule::setting('order_prefix') ?: 'PNL'));
        $prefix = substr($prefix ?: 'PNL', 0, 20);

        return substr("{$prefix}-{$invoice->id}-".strtoupper(Str::random(8)), 0, 64);
    }

    private function description(Invoice $invoice): string
    {
        $template = (string) (CreatorFeedModule::setting('description') ?: 'Invoice #{invoice_num}');

        return strtr($template, [
            '{invoice_num}' => (string) ($invoice->invoice_num ?: $invoice->id),
            '{invoice_id}' => (string) $invoice->id,
            '{company}' => (string) \App\Models\Setting::get('CompanyName', ''),
        ]);
    }

    /** @return array{name:string,email:string,phone:string}|array{error:string} */
    private function customer(Invoice $invoice): array
    {
        $client = $invoice->client;

        $name = trim((string) $invoice->buyer('first_name').' '.(string) $invoice->buyer('last_name'));
        $name = $name !== '' ? $name : trim((string) $invoice->buyer('company_name'));
        $name = $name !== '' ? $name : 'Customer '.$invoice->client_id;

        $email = (string) ($invoice->buyer('email') ?: $client?->email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'An email address is needed on your account to pay with CreatorFeed.'];
        }

        $phone = self::normalisePhone((string) ($client?->phone_number ?? ''), (string) ($client?->phone_prefix ?? ''))
            ?? self::normalisePhone((string) CreatorFeedModule::setting('fallback_phone'), '');

        if ($phone === null) {
            return ['error' => 'A 10-digit Indian mobile number is needed on your profile to pay with CreatorFeed. Please update it and try again.'];
        }

        return ['name' => Str::limit($name, 100, ''), 'email' => $email, 'phone' => $phone];
    }

    /** A 10-digit Indian mobile (starting 6-9), or null. Accepts +91 / 91 / 0 prefixes and spacing. */
    public static function normalisePhone(string $number, string $prefix = ''): ?string
    {
        $prefixDigits = preg_replace('/\D/', '', $prefix);
        if ($prefixDigits !== '' && $prefixDigits !== '91') {
            return null; // not an Indian number
        }

        $d = preg_replace('/\D/', '', $number);
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            $d = substr($d, 2);
        } elseif (strlen($d) === 11 && str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }

        return preg_match('/^[6-9]\d{9}$/', $d) ? $d : null;
    }
}
