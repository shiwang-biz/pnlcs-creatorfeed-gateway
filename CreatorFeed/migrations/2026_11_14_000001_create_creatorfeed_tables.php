<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CreatorFeed gateway module: the orders raised for invoices, and the webhook
 * events already handled (CreatorFeed may deliver an event more than once).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('creatorfeed_orders')) {
            Schema::create('creatorfeed_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('invoice_id')->index();
                $table->unsignedBigInteger('client_id')->nullable()->index();
                $table->string('order_id', 64)->unique();          // ours, sent to CreatorFeed
                $table->string('cf_id', 64)->nullable()->index();   // CFD-TXN-…
                $table->string('mode', 8)->nullable();              // test | live
                $table->decimal('amount', 12, 2);
                $table->string('currency', 3)->default('INR');
                $table->string('status', 20)->default('creating')->index(); // creating|pending|paid|failed|expired|error
                $table->text('payment_url')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->string('payment_method', 32)->nullable();
                $table->string('utr', 64)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->decimal('fee', 12, 2)->nullable();
                $table->decimal('gst', 12, 2)->nullable();
                $table->decimal('net', 12, 2)->nullable();
                $table->string('failure_reason')->nullable();
                $table->string('transaction_id', 64)->nullable();   // what was recorded in PNLCS transactions
                $table->timestamp('applied_at')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->string('last_checked_by', 32)->nullable(); // webhook | return | client | admin
                $table->text('last_error')->nullable();
                $table->longText('raw')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('creatorfeed_webhook_events')) {
            Schema::create('creatorfeed_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->string('event_id', 100)->unique();
                $table->string('type', 50);
                $table->string('order_id', 64)->nullable()->index();
                $table->string('result', 50)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('creatorfeed_webhook_events');
        Schema::dropIfExists('creatorfeed_orders');
    }
};
