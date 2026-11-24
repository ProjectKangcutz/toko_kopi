<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('payment_gateway', 40)->default('midtrans');
            $table->string('midtrans_order_id', 80)->unique();
            $table->string('snap_token')->nullable();
            $table->string('snap_redirect_url')->nullable();
            $table->string('payment_type', 60)->nullable();
            $table->string('payment_channel', 60)->nullable();
            $table->decimal('gross_amount', 15, 2);
            $table->enum('status', [
                'pending', 'settlement', 'capture', 'deny',
                'cancel', 'expire', 'refund', 'partial_refund',
            ])->default('pending')->index();
            $table->string('midtrans_transaction_id', 100)->nullable()->index();
            $table->string('fraud_status', 40)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('transaction_id', 100)->nullable()->index();
            $table->string('event_type', 60);
            $table->string('status', 40);
            $table->decimal('gross_amount', 15, 2);
            $table->string('payment_type', 60)->nullable();
            $table->boolean('signature_verified')->default(false);
            $table->json('raw_payload')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('payment_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 150)->unique();
            $table->string('gateway', 40)->default('midtrans');
            $table->string('midtrans_order_id', 80)->index();
            $table->string('transaction_id', 100)->nullable()->index();
            $table->string('transaction_status', 40);
            $table->string('status_code', 10);
            $table->boolean('signature_valid')->default(false);
            $table->boolean('processed')->default(false)->index();
            $table->text('processing_error')->nullable();
            $table->json('headers')->nullable();
            $table->json('payload');
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('refund_number', 50)->unique();
            $table->decimal('amount', 15, 2);
            $table->text('reason');
            $table->enum('status', ['pending', 'processed', 'failed'])->default('pending')->index();
            $table->string('gateway_refund_key', 100)->nullable();
            $table->json('gateway_response')->nullable();
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_webhook_logs');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payments');
    }
};
