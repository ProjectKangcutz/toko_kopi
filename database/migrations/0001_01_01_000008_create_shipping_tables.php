<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_couriers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('shipping_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_id')->constrained('shipping_couriers')->cascadeOnDelete();
            $table->string('service_code', 40);
            $table->string('service_name', 120);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['courier_id', 'service_code']);
        });

        Schema::create('shipping_rate_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40)->default('rajaongkir');
            $table->string('origin_id', 30);
            $table->string('destination_id', 30);
            $table->unsignedInteger('weight_grams');
            $table->string('courier_codes');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('is_success')->default(false)->index();
            $table->text('error_message')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['is_success', 'created_at']);
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('courier_code', 30);
            $table->string('courier_name', 100);
            $table->string('service_code', 40);
            $table->string('service_name', 120);
            $table->string('etd', 40)->nullable();
            $table->decimal('shipping_cost', 15, 2);
            $table->unsignedInteger('weight_grams');
            $table->string('tracking_number', 100)->nullable()->index();
            $table->enum('status', [
                'unfulfilled', 'packing', 'shipped', 'in_transit', 'delivered', 'failed', 'returned',
            ])->default('unfulfilled')->index();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('shipment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('tracking_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('shipment_id');
        });

        Schema::create('order_tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->timestamp('event_time');
            $table->string('location')->nullable();
            $table->text('description');
            $table->enum('source', ['admin_manual', 'courier_api'])->default('admin_manual');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['shipment_id', 'event_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_tracking_events');
        Schema::dropIfExists('shipment_histories');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('shipping_rate_logs');
        Schema::dropIfExists('shipping_services');
        Schema::dropIfExists('shipping_couriers');
    }
};
