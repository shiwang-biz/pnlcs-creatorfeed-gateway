<?php

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\Client;
use App\Models\GatewaySettings;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Modules\Gateways\CreatorFeed\CreatorFeedModule;
use Modules\Gateways\CreatorFeed\CreatorFeedOrder;
use Modules\Gateways\CreatorFeed\CreatorFeedPayments;

const CF_SECRET = 'whsec_test_secret';
const CF_BASE = 'https://api-staging.creatorfeed.in/v1';

function cfConfigure(): void
{
    foreach ([
        'active' => '1', 'base_url' => CF_BASE, 'api_key' => 'cf_test_abc123', 'auth_header' => 'bearer',
        'webhook_secret' => CF_SECRET, 'order_prefix' => 'PNL', 'description' => 'Invoice #{invoice_num}', 'timeout' => '5',
    ] as $k => $v) {
        GatewaySettings::updateOrCreate(['gateway' => 'creatorfeed', 'setting' => $k], ['value' => $v]);
    }
}

/** @return array{0: User, 1: Invoice} */
function cfCustomerInvoice(float $total = 499, string $currency = 'INR'): array
{
    $user = User::factory()->create();
    $client = Client::factory()->create(['phone_number' => '98765 11111', 'phone_prefix' => '+91', 'email' => 'rahul@example.com']);
    $user->clients()->attach($client->id);
    $invoice = Invoice::factory()->create([
        'client_id' => $client->id, 'status' => 'Unpaid', 'total' => $total, 'subtotal' => $total, 'source_currency' => $currency,
    ]);

    return [$user, $invoice];
}

function cfApiOrder(CreatorFeedOrder $o, string $status, array $over = []): array
{
    return array_replace_recursive([
        'id' => 'CFD-TXN-TEST'.$o->id, 'object' => 'order', 'order_id' => $o->order_id, 'mode' => 'test',
        'status' => $status, 'amount' => number_format((float) $o->amount, 2, '.', ''), 'currency' => 'INR',
        'notes' => ['pnlcs_invoice_id' => (string) $o->invoice_id],
        'payment_url' => $status === 'paid' ? null : 'https://pay.example/'.$o->order_id,
        'payment' => $status === 'paid' ? ['method' => 'upi', 'utr' => '412345678901', 'paid_at' => '2026-11-14T10:15:00.000Z', 'fee' => '49.90', 'gst' => '8.98', 'net' => '440.12'] : null,
        'expires_at' => now()->addMinutes(30)->toIso8601String(),
    ], $over);
}

function cfSignedWebhook($test, array $event, ?int $t = null, string $secret = CF_SECRET)
{
    $raw = json_encode($event);
    $t ??= time();
    $sig = hash_hmac('sha256', $t.'.'.$raw, $secret);

    return $test->call('POST', '/gateway/creatorfeed/callback', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_CREATORFEED_SIGNATURE' => "t={$t},v1={$sig}",
        'HTTP_CREATORFEED_EVENT' => $event['type'],
        'HTTP_CREATORFEED_EVENT_ID' => $event['id'],
    ], $raw);
}

function cfPendingOrder(Invoice $invoice): CreatorFeedOrder
{
    return CreatorFeedOrder::create([
        'invoice_id' => $invoice->id, 'client_id' => $invoice->client_id, 'order_id' => 'PNL-'.$invoice->id.'-ABCDEFGH',
        'mode' => 'test', 'amount' => $invoice->total, 'status' => 'pending',
    ]);
}

beforeEach(fn () => cfConfigure());

test('the module is discovered with its routes', function () {
    expect(app(\App\Services\Module\ModuleRegistry::class)->getGatewayModule('creatorfeed'))->toBeInstanceOf(CreatorFeedModule::class)
        ->and(app(\App\Services\Module\ModuleRegistry::class)->usableGateways())->toContain('creatorfeed')
        ->and(Route::has('creatorfeed.pay'))->toBeTrue()
        ->and(Route::has('creatorfeed.admin.orders'))->toBeTrue();
});

test('signature verification', function () {
    $raw = '{"id":"evt_1"}';
    $t = 1730110500;
    $good = hash_hmac('sha256', "{$t}.{$raw}", 's');

    expect(CreatorFeedModule::verifySignature($raw, "t={$t},v1={$good}", 's', $t + 10))->toBeTrue()
        ->and(CreatorFeedModule::verifySignature($raw, "t={$t},v1={$good}", 'other', $t))->toBeFalse()
        ->and(CreatorFeedModule::verifySignature($raw, "t={$t},v1={$good}", 's', $t + 301))->toBeFalse()
        ->and(CreatorFeedModule::verifySignature($raw.' ', "t={$t},v1={$good}", 's', $t))->toBeFalse()
        ->and(CreatorFeedModule::verifySignature($raw, "v1={$good}", 's', $t))->toBeFalse()
        ->and(CreatorFeedModule::verifySignature($raw, '', 's', $t))->toBeFalse();
});

test('Indian mobile numbers are normalised', function () {
    expect(CreatorFeedPayments::normalisePhone('+91 98765 11111'))->toBe('9876511111')
        ->and(CreatorFeedPayments::normalisePhone('09876511111'))->toBe('9876511111')
        ->and(CreatorFeedPayments::normalisePhone('9876511111', '+91'))->toBe('9876511111')
        ->and(CreatorFeedPayments::normalisePhone('9876511111', '+44'))->toBeNull()
        ->and(CreatorFeedPayments::normalisePhone('12345'))->toBeNull();
});

test('pay creates an order with the right payload and redirects to CreatorFeed; a second click reuses it', function () {
    [$user, $invoice] = cfCustomerInvoice();
    Http::fake([CF_BASE.'/orders' => fn ($req) => Http::response([
        'id' => 'CFD-TXN-NEW1', 'order_id' => $req['order_id'], 'mode' => 'test', 'status' => 'pending', 'amount' => $req['amount'],
        'currency' => 'INR', 'payment_url' => 'https://checkout.creatorfeed.in/p/xyz', 'expires_at' => now()->addMinutes(30)->toIso8601String(),
    ], 201)]);

    $this->actingAs($user)->post(route('creatorfeed.pay', $invoice))->assertRedirect('https://checkout.creatorfeed.in/p/xyz');
    $this->actingAs($user)->post(route('creatorfeed.pay', $invoice))->assertRedirect('https://checkout.creatorfeed.in/p/xyz');

    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer cf_test_abc123')
        && $r['amount'] === '499.00' && $r['customer']['phone'] === '9876511111' && $r['customer']['email'] === 'rahul@example.com'
        && $r['notes']['pnlcs_invoice_id'] === (string) $invoice->id
        && str_starts_with($r['order_id'], "PNL-{$invoice->id}-")
        && str_contains($r['return_url'], "/creatorfeed/return/{$invoice->id}"));

    expect(CreatorFeedOrder::where('invoice_id', $invoice->id)->sole()->status)->toBe('pending');
});

test('a stranger cannot start a payment on someone else\'s invoice', function () {
    [, $invoice] = cfCustomerInvoice();
    $stranger = User::factory()->create();
    Http::fake();

    $this->actingAs($stranger)->post(route('creatorfeed.pay', $invoice))->assertForbidden();
    Http::assertNothingSent();
});

test('a non-INR invoice is refused', function () {
    [$user, $invoice] = cfCustomerInvoice(100, 'USD');
    Http::fake();

    expect(app(CreatorFeedModule::class)->getPaymentForm($invoice))->toContain('INR only');
    $this->actingAs($user)->post(route('creatorfeed.pay', $invoice))->assertRedirect()->assertSessionHas('error');
    Http::assertNothingSent();
});

test('a signed payment.success webhook is confirmed via the API and pays the invoice exactly once', function () {
    [, $invoice] = cfCustomerInvoice();
    $order = cfPendingOrder($invoice);
    Http::fake([CF_BASE.'/orders/*' => Http::response(cfApiOrder($order, 'paid'))]);

    cfSignedWebhook($this, ['id' => 'evt_1', 'type' => 'payment.success', 'data' => ['order_id' => $order->order_id]])->assertOk();
    cfSignedWebhook($this, ['id' => 'evt_1', 'type' => 'payment.success', 'data' => ['order_id' => $order->order_id]])->assertOk(); // redelivery
    cfSignedWebhook($this, ['id' => 'evt_2', 'type' => 'payment.success', 'data' => ['order_id' => $order->order_id]])->assertOk(); // another event

    Http::assertSent(fn ($r) => $r->method() === 'GET' && str_ends_with($r->url(), '/orders/'.$order->order_id));
    $order->refresh();
    expect(strtolower($invoice->fresh()->status))->toBe('paid')
        ->and(Transaction::where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and(Transaction::where('invoice_id', $invoice->id)->value('transaction_id'))->toBe('CFD-TXN-TEST'.$order->id)
        ->and($order->status)->toBe('paid')->and($order->utr)->toBe('412345678901')->and($order->applied_at)->not->toBeNull();
});

test('a webhook claiming success is not trusted when the API says the order is still pending', function () {
    [, $invoice] = cfCustomerInvoice();
    $order = cfPendingOrder($invoice);
    Http::fake([CF_BASE.'/orders/*' => Http::response(cfApiOrder($order, 'pending'))]);

    cfSignedWebhook($this, ['id' => 'evt_3', 'type' => 'payment.success', 'data' => ['order_id' => $order->order_id, 'status' => 'paid']])->assertOk();

    expect(strtolower($invoice->fresh()->status))->toBe('unpaid')->and(Transaction::where('invoice_id', $invoice->id)->count())->toBe(0);
});

test('bad, stale or missing signatures are rejected without calling the API', function () {
    [, $invoice] = cfCustomerInvoice();
    $order = cfPendingOrder($invoice);
    Http::fake();
    $event = ['id' => 'evt_4', 'type' => 'payment.success', 'data' => ['order_id' => $order->order_id]];

    cfSignedWebhook($this, $event, secret: 'whsec_wrong')->assertStatus(400);
    cfSignedWebhook($this, $event, t: time() - 600)->assertStatus(400);
    $this->postJson('/gateway/creatorfeed/callback', $event)->assertStatus(400);

    Http::assertNothingSent();
    expect(strtolower($invoice->fresh()->status))->toBe('unpaid');
});

test('the API answer must be for this invoice', function () {
    [, $invoice] = cfCustomerInvoice();
    $order = cfPendingOrder($invoice);
    Http::fake([CF_BASE.'/orders/*' => Http::response(cfApiOrder($order, 'paid', ['notes' => ['pnlcs_invoice_id' => '999999']]))]);

    cfSignedWebhook($this, ['id' => 'evt_5', 'type' => 'payment.success', 'data' => ['order_id' => $order->order_id]])->assertOk();

    expect(strtolower($invoice->fresh()->status))->toBe('unpaid');
});

test('when the API cannot be reached the webhook asks CreatorFeed to retry, and the retry pays', function () {
    [, $invoice] = cfCustomerInvoice();
    $order = cfPendingOrder($invoice);
    Http::fakeSequence(CF_BASE.'/orders/*')
        ->push(['code' => 'oops'], 500)->push(['code' => 'oops'], 500)->push(['code' => 'oops'], 500)
        ->push(cfApiOrder($order, 'paid'));

    $event = ['id' => 'evt_6', 'type' => 'payment.success', 'data' => ['order_id' => $order->order_id]];
    cfSignedWebhook($this, $event)->assertStatus(503);
    expect(strtolower($invoice->fresh()->status))->toBe('unpaid');

    cfSignedWebhook($this, $event)->assertOk();
    expect(strtolower($invoice->fresh()->status))->toBe('paid');
});

test('webhooks for unknown orders and other event types are acknowledged and ignored', function () {
    Http::fake();
    cfSignedWebhook($this, ['id' => 'evt_7', 'type' => 'payment.success', 'data' => ['order_id' => 'someone-elses']])->assertOk();
    cfSignedWebhook($this, ['id' => 'evt_8', 'type' => 'payout.completed', 'data' => ['payout_id' => 'po_1']])->assertOk();
    Http::assertNothingSent();
});

test('the return URL does not trust its query string', function () {
    [$user, $invoice] = cfCustomerInvoice();
    $order = cfPendingOrder($invoice);
    Http::fake([CF_BASE.'/orders/*' => Http::response(cfApiOrder($order, 'pending'))]);

    $this->get(route('creatorfeed.return', $invoice).'?order_id='.$order->order_id.'&status=paid&payment_id=CFD-TXN-FAKE')
        ->assertRedirect()->assertSessionHas('info');
    expect(strtolower($invoice->fresh()->status))->toBe('unpaid');
});

test('the return URL pays the invoice when the API confirms', function () {
    [, $invoice] = cfCustomerInvoice();
    $order = cfPendingOrder($invoice);
    Http::fake([CF_BASE.'/orders/*' => Http::response(cfApiOrder($order, 'paid'))]);

    $this->get(route('creatorfeed.return', $invoice).'?order_id='.$order->order_id.'&status=paid')->assertRedirect()->assertSessionHas('success');
    expect(strtolower($invoice->fresh()->status))->toBe('paid');
});

test('the customer "check payment status" button confirms via the API', function () {
    [$user, $invoice] = cfCustomerInvoice();
    $order = cfPendingOrder($invoice);
    Http::fake([CF_BASE.'/orders/*' => Http::response(cfApiOrder($order, 'paid'))]);

    expect(app(CreatorFeedModule::class)->getPaymentForm($invoice))->toContain('Check payment status');
    $this->actingAs($user)->post(route('creatorfeed.check', $invoice))->assertRedirect()->assertSessionHas('success');
    expect(strtolower($invoice->fresh()->status))->toBe('paid');
});

test('staff can list orders and check status from the orders page and the invoice page', function () {
    [, $invoice] = cfCustomerInvoice();
    $order = cfPendingOrder($invoice);
    $admin = Admin::factory()->create(['role_id' => AdminRole::factory()->fullAdmin()->create()->id]);
    // hooks.php is require_once'd at boot; the suite makes a new app per test, so register it again here.
    include base_path('modules/Gateways/CreatorFeed/hooks.php');
    Http::fakeSequence(CF_BASE.'/orders/*')->push(cfApiOrder($order, 'pending'))->push(cfApiOrder($order, 'paid'));

    $this->actingAs($admin, 'admin')->get(route('creatorfeed.admin.orders'))->assertOk()->assertSee($order->order_id)->assertSee('Check status');
    $this->actingAs($admin, 'admin')->get(route('admin.invoices.show', $invoice))->assertOk()->assertSee('Check status via API');

    $this->actingAs($admin, 'admin')->post(route('creatorfeed.admin.check', $order->id))->assertRedirect()->assertSessionHas('success');
    expect(strtolower($invoice->fresh()->status))->toBe('unpaid');

    $this->actingAs($admin, 'admin')->post(route('creatorfeed.admin.check-invoice', $invoice))->assertRedirect()->assertSessionHas('success');
    expect(strtolower($invoice->fresh()->status))->toBe('paid');
});

test('staff pages are closed to customers', function () {
    [$user] = cfCustomerInvoice();
    $this->actingAs($user)->get(route('creatorfeed.admin.orders'))->assertRedirect();
});

test('the client invoice page shows the CreatorFeed pay button', function () {
    [$user, $invoice] = cfCustomerInvoice();
    $invoice->update(['payment_method' => 'creatorfeed']);

    $this->actingAs($user)->get(route('client.invoices.show', $invoice))->assertOk()
        ->assertSee('with CreatorFeed')->assertSee(route('creatorfeed.pay', $invoice), false)->assertSee('Test mode');
});
