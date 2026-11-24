<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->string('guest_token', 64)->nullable()->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name_snapshot');
            $table->string('customer_email_snapshot');
            $table->string('customer_phone_snapshot', 30);
            $table->boolean('is_guest')->default(false);

            $table->enum('order_status', [
                'pending', 'awaiting_payment', 'paid', 'processing',
                'shipped', 'delivered', 'completed', 'cancelled', 'refunded',
            ])->default('pending')->index();

            $table->enum('payment_status', [
                'unpaid', 'pending', 'paid', 'failed', 'expired', 'refunded', 'partially_refunded',
            ])->default('unpaid')->index();

            $table->enum('shipment_status', [
                'unfulfilled', 'packing', 'shipped', 'in_transit', 'delivered', 'failed', 'returned',
            ])->default('unfulfilled')->index();

            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->string('coupon_code_snapshot', 60)->nullable();
            $table->decimal('shipping_cost', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('payment_fee', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2);
            $table->unsignedInteger('total_weight_grams')->default(0);

            $table->text('customer_notes')->nullable();
            $table->text('admin_internal_notes')->nullable();
            $table->timestamp('payment_due_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('product_name_snapshot');
            $table->string('variant_name_snapshot')->nullable();
            $table->string('sku_snapshot', 80);
            $table->decimal('unit_price_snapshot', 15, 2);
            $table->decimal('cost_price_snapshot', 15, 2)->default(0);
            $table->unsignedInteger('weight_grams_snapshot')->default(100);
            $table->unsignedInteger('quantity');
            $table->decimal('line_total', 15, 2);
            $table->timestamps();

            $table->index('order_id');
        });

        Schema::create('order_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('recipient_name');
            $table->string('recipient_phone', 30);
            $table->text('address_line');
            $table->string('province_id', 30);
            $table->string('province_name', 100);
            $table->string('city_id', 30);
            $table->string('city_name', 120);
            $table->string('district_id', 30)->nullable();
            $table->string('district_name', 120)->nullable();
            $table->string('subdistrict_id', 30)->nullable();
            $table->string('subdistrict_name', 120)->nullable();
            $table->string('postal_code', 15);
            $table->timestamps();
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->enum('status_type', ['order', 'payment', 'shipment']);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->text('notes')->nullable();
            $table->enum('actor_type', ['system', 'customer', 'admin', 'webhook'])->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'status_type']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->enum('type', ['in', 'out', 'adjustment', 'reservation', 'release', 'return']);
            $table->integer('quantity');
            $table->integer('stock_before');
            $table->integer('stock_after');
            $table->string('reference_type', 80)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_number', 50)->unique();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->enum('reason', ['restock', 'damaged', 'lost', 'opname', 'correction']);
            $table->integer('quantity_change');
            $table->text('notes')->nullable();
            $table->foreignId('adjusted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('adjusted_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->enum('status', ['active', 'committed', 'released', 'expired'])->default('active')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_email')->nullable()->index();
            $table->decimal('discount_applied', 15, 2);
            $table->timestamp('used_at')->useCurrent();

            $table->index(['coupon_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_addresses');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
