<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content');
            $table->string('template', 50)->default('default');
            $table->boolean('is_published')->default(true)->index();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('robots_meta', 60)->default('index,follow');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();
            $table->longText('content');
            $table->string('featured_image_path')->nullable();
            $table->string('featured_image_alt')->nullable();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['draft', 'published'])->default('draft')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->string('canonical_url')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->enum('placement', ['hero', 'promo_middle', 'category_top'])->default('hero')->index();
            $table->string('image_desktop_path');
            $table->string('image_mobile_path')->nullable();
            $table->string('alt_text')->nullable();
            $table->string('cta_label', 60)->nullable();
            $table->string('cta_url')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->enum('location', ['header', 'footer_col_1', 'footer_col_2', 'footer_policy'])->index();
            $table->foreignId('parent_id')->nullable()->constrained('menus')->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('url');
            $table->string('target', 20)->default('_self');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('seo_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('source_path')->unique();
            $table->string('target_path');
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->unsignedInteger('hit_count')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('category', 80)->default('Umum')->index();
            $table->string('question');
            $table->text('answer');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->string('customer_name');
            $table->string('customer_role_or_city')->nullable();
            $table->unsignedTinyInteger('rating')->default(5);
            $table->text('quote');
            $table->string('avatar_path')->nullable();
            $table->boolean('is_featured')->default(true)->index();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->boolean('is_approved')->default(true)->index();
            $table->timestamps();

            $table->index(['product_id', 'is_approved']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->enum('group', [
                'general', 'store', 'appearance', 'checkout',
                'notification', 'security', 'seo', 'rajaongkir', 'midtrans',
            ])->index();
            $table->string('key', 100);
            $table->longText('value')->nullable();
            $table->enum('type', ['string', 'boolean', 'integer', 'decimal', 'json', 'encrypted', 'file'])->default('string');
            $table->boolean('is_public')->default(false)->index();
            $table->timestamps();

            $table->unique(['group', 'key']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('recipient_type', ['user', 'customer']);
            $table->unsignedBigInteger('recipient_id');
            $table->string('type', 80);
            $table->string('title');
            $table->text('message');
            $table->string('action_url')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['recipient_type', 'recipient_id', 'read_at'], 'notifications_recipient_read_idx');
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('actor_type', ['user', 'customer', 'system', 'webhook']);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 100)->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description');
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['actor_type', 'actor_id']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('testimonials');
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('seo_redirects');
        Schema::dropIfExists('menus');
        Schema::dropIfExists('banners');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('pages');
    }
};
