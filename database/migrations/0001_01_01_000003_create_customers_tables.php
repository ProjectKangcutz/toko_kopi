<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 30)->index();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->enum('status', ['active', 'suspended'])->default('active')->index();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->date('birth_date')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('preferred_locale', 10)->default('id');
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamps();
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('label', 60);
            $table->string('recipient_name');
            $table->string('recipient_phone', 30);
            $table->text('address_line');
            $table->string('province_id', 30)->index();
            $table->string('province_name', 100);
            $table->string('city_id', 30)->index();
            $table->string('city_name', 120);
            $table->string('district_id', 30)->nullable()->index();
            $table->string('district_name', 120)->nullable();
            $table->string('subdistrict_id', 30)->nullable()->index();
            $table->string('subdistrict_name', 120)->nullable();
            $table->string('postal_code', 15);
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customer_profiles');
        Schema::dropIfExists('customers');
    }
};
