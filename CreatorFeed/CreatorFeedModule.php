<?php

namespace Modules\Gateways\CreatorFeed;

use App\Contracts\GatewayModuleInterface;
use App\Models\GatewayLog;
use App\Models\GatewaySettings;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

/**
 * CreatorFeed payment gateway for PNLCS.
 *
 * Flow:
 *   1. Customer clicks "Pay" on the invoice -> creatorfeed.pay creates an
 *      order (POST /v1/orders) and redirects to its payment_url.
 *   2. CreatorFeed POSTs payment.success / payment.failed to
 *      /gateway/creatorfeed/callback. The signature is verified here, then the
 *      order is fetched from GET /v1/orders/{order_id}; only an API answer of
 *      "paid" marks the invoice paid.
 *   3. The buyer comes back to creatorfeed.return, which does the same API
 *      check, so the invoice is usually paid before the webhook even arrives.
 *   4. "Check payment status" buttons (client invoice page, admin invoice
 *      page, admin CreatorFeed orders page) run the same check on demand.
 */
class CreatorFeedModule implements GatewayModuleInterface
{
    public const DEFAULT_BASE_URL = 'https://api-staging.creatorfeed.in/v1';

    /** Webhooks older (or newer) than this many seconds are rejected. */
    public const SIGNATURE_TOLERANCE = 300;

    public function getModuleName(): string
    {
        return 'CreatorFeed';
    }

    public function isTokenised(): bool
    {
        return false;
    }

    public function getConfigFields(): array
    {
        $webhookUrl = url('/gateway/creatorfeed/callback');
        $ordersUrl = url('/admin/creatorfeed/orders');

        return [
            ['name' => 'base_url', 'label' => 'API Base URL', 'type' => 'text', 'required' => true, 'default' => self::DEFAULT_BASE_URL,
                'description' => 'From the CreatorFeed API docs, including /v1. Staging: '.self::DEFAULT_BASE_URL],
            ['name' => 'api_key', 'label' => 'Secret API Key', 'type' => 'password', 'required' => true,
                'description' => 'Dashboard → API keys & webhooks. cf_test_… for practice payments, cf_live_… for real money (after KYC).'],
            ['name' => 'auth_header', 'label' => 'Send API key as', 'type' => 'select', 'default' => 'bearer',
                'options' => ['bearer' => 'Authorization: Bearer <key>', 'x-api-key' => 'X-Api-Key: <key>']],
            ['name' => 'webhook_secret', 'label' => 'Webhook Signing Secret', 'type' => 'password', 'required' => true,
                'description' => "whsec_… of the webhook you add in the CreatorFeed dashboard. Webhook URL: {$webhookUrl} (events: payment.success, payment.failed)."],
            ['name' => 'order_prefix', 'label' => 'Order ID Prefix', 'type' => 'text', 'default' => 'PNL',
                'description' => 'Order ids are sent as <prefix>-<invoice id>-<random>. Letters, numbers, - _ . : only.'],
            ['name' => 'description', 'label' => 'Description shown to buyer', 'type' => 'text', 'default' => 'Invoice #{invoice_num}',
                'description' => 'Placeholders: {invoice_num}, {invoice_id}, {company}. Max 200 characters.'],
            ['name' => 'fallback_phone', 'label' => 'Fallback mobile number', 'type' => 'text',
                'description' => 'CreatorFeed requires a 10-digit Indian mobile for every order. Used when the client has none on file; leave blank to ask the client to add one.'],
            ['name' => 'timeout', 'label' => 'API timeout (seconds)', 'type' => 'text', 'default' => '15',
                'description' => "All CreatorFeed orders, with a Check status button for each: {$ordersUrl}"],
        ];
    }

    public static function setting(string $key): ?string
    {
        $value = GatewaySettings::where('gateway', CreatorFeedPayments::GATEWAY)->where('setting', $key)->value('value');

        return $value === null ? null : trim((string) $value);
    }

    /** Admin → Logs → Gateway. Never allowed to break a payment. */
    public static function log(string $message, string $result = 'success'): void
    {
        try {
            GatewayLog::create(['gateway' => CreatorFeedPayments::GATEWAY, 'date' => now(), 'data' => $message, 'result' => $result]);
        } catch (\Throwable $e) {
            Log::warning('CreatorFeed: failed to write gateway log: '.$e->getMessage());
        }
    }

    /**
     * Create (or reuse) an order and hand back the checkout URL.
     */
    public function capture(Invoice $invoice, float $amount, array $params = []): array
    {
        $result = app(CreatorFeedPayments::class)->startPayment($invoice, $amount > 0 ? $amount : null);

        if (! $result['success']) {
            return ['success' => false, 'message' => $result['message'] ?? 'CreatorFeed error'];
        }

        return [
            'success' => true,
            'redirect' => true,
            'checkout_url' => $result['payment_url'],
            'transaction_id' => $result['order']->order_id,
        ];
    }

    /**
     * Refunds are deliberately not wired up in this version: the module only
     * handles orders. Refund from the CreatorFeed dashboard.
     */
    public function refund(string $transactionId, float $amount): array
    {
        return ['success' => false, 'message' => 'Refunds are not enabled in the CreatorFeed module yet. Please refund from the CreatorFeed dashboard.'];
    }

    public function getPaymentForm(Invoice $invoice): string
    {
        if (CreatorFeedPayments::invoiceCurrency($invoice) !== 'INR') {
            return '<div class="alert alert-warning">CreatorFeed accepts payments in INR only.</div>';
        }

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $csrf = $e(csrf_token());
        $payUrl = $e(route('creatorfeed.pay', ['invoice' => $invoice->id]));
        $checkUrl = $e(route('creatorfeed.check', ['invoice' => $invoice->id]));
        $amount = $e('₹'.number_format($invoice->amountDue(), 2));

        $testBadge = str_starts_with((string) self::setting('api_key'), 'cf_test_')
            ? '<div class="alert alert-warning" style="margin-bottom:10px">Test mode: a practice checkout opens and no real money moves.</div>'
            : '';

        $hasOrders = CreatorFeedOrder::where('invoice_id', $invoice->id)->where('created_at', '>', now()->subDays(7))->exists();
        $checkForm = $hasOrders ? <<<HTML
<form method="POST" action="{$checkUrl}" style="margin-top:10px">
    <input type="hidden" name="_token" value="{$csrf}">
    <button type="submit" class="btn btn-outline" style="width:100%">Already paid? Check payment status</button>
</form>
HTML : '';

        return <<<HTML
<div class="gw-form-box">
    {$testBadge}
    <p class="text-muted text-sm mb-16">Pay securely with UPI, cards or netbanking via CreatorFeed. You will return here after paying.</p>
    <form method="POST" action="{$payUrl}">
        <input type="hidden" name="_token" value="{$csrf}">
        <button type="submit" class="btn btn-primary" style="width:100%">Pay {$amount} with CreatorFeed</button>
    </form>
    {$checkForm}
</div>
HTML;
    }

    /**
     * Webhook, delivered by the core to /gateway/creatorfeed/callback with
     * _raw_payload and _headers added.
     *
     * Answers: 400 bad signature (CreatorFeed will retry, then the delivery
     * log shows it), 503 when CreatorFeed's API could not be asked (so it
     * retries later), 200 otherwise.
     */
    public function processWebhook(array $data): array
    {
        $headers = (array) ($data['_headers'] ?? []);
        $raw = (string) ($data['_raw_payload'] ?? '');

        if (! self::verifySignature($raw, (string) ($headers['creatorfeed-signature'] ?? ''), (string) self::setting('webhook_secret'))) {
            self::log('Webhook rejected: invalid or expired signature', 'error');

            return ['success' => false, 'response' => 'invalid signature', 'http_status' => 400];
        }

        $event = json_decode($raw, true);
        if (! is_array($event)) {
            return ['success' => false, 'response' => 'invalid body', 'http_status' => 400];
        }

        $eventId = (string) ($headers['creatorfeed-event-id'] ?? $event['id'] ?? '');
        $type = (string) ($headers['creatorfeed-event'] ?? $event['type'] ?? '');
        $orderId = (string) ($event['data']['order_id'] ?? '');

        if ($eventId !== '' && CreatorFeedWebhookEvent::where('event_id', $eventId)->exists()) {
            return ['success' => false, 'response' => 'OK (duplicate)', 'http_status' => 200];
        }

        if (! in_array($type, ['payment.success', 'payment.failed'], true)) {
            $this->remember($eventId, $type, $orderId, 'ignored');

            return ['success' => false, 'response' => 'OK (ignored)', 'http_status' => 200];
        }

        $order = $orderId !== '' ? CreatorFeedOrder::where('order_id', $orderId)->first() : null;
        if (! $order) {
            // A payment page or another integration on the same account - not ours.
            self::log("Webhook {$type} for unknown order '{$orderId}' ignored");
            $this->remember($eventId, $type, $orderId, 'unknown_order');

            return ['success' => false, 'response' => 'OK (unknown order)', 'http_status' => 200];
        }

        // 100% confirmation: whatever the webhook says, ask the API.
        $result = app(CreatorFeedPayments::class)->sync($order, 'webhook');

        if (! $result['ok'] && ($result['retryable'] ?? false)) {
            return ['success' => false, 'response' => 'status check failed, retry', 'http_status' => 503];
        }

        $this->remember($eventId, $type, $orderId, $result['paid'] ? 'paid' : $result['status']);
        self::log("Webhook {$type} for {$orderId}: API status {$result['status']} - {$result['message']}", $result['ok'] ? 'success' : 'error');

        if ($result['paid'] && $result['applied']) {
            // Already applied by sync(); returning it lets the core log it too
            // (PaymentService ignores the repeat transaction id).
            return [
                'success' => true,
                'invoice_id' => $order->invoice_id,
                'transaction_id' => $result['transaction_id'],
                'amount' => $result['amount'],
                'response' => 'OK',
                'http_status' => 200,
            ];
        }

        return ['success' => false, 'response' => 'OK', 'http_status' => 200];
    }

    /**
     * CreatorFeed-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">
     */
    public static function verifySignature(string $rawBody, string $header, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || $header === '' || $rawBody === '') {
            return false;
        }

        $t = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $t = $v;
            } elseif ($k === 'v1' && $v !== '') {
                $signatures[] = strtolower($v);
            }
        }

        if ($t === null || ! ctype_digit($t) || $signatures === []) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $t) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $t.'.'.$rawBody, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    private function remember(string $eventId, string $type, string $orderId, string $result): void
    {
        if ($eventId === '') {
            return;
        }

        try {
            CreatorFeedWebhookEvent::firstOrCreate(['event_id' => $eventId], ['type' => $type, 'order_id' => $orderId ?: null, 'result' => $result]);
        } catch (\Throwable $e) {
            Log::warning('CreatorFeed: could not store webhook event '.$eventId.': '.$e->getMessage());
        }
    }
}
