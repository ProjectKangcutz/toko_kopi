<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 50)->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('subject');
            $table->enum('status', ['open', 'waiting_customer', 'waiting_admin', 'closed'])->default('open')->index();
            $table->unsignedInteger('unread_by_customer')->default(0);
            $table->unsignedInteger('unread_by_admin')->default(0);
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->enum('sender_type', ['customer', 'admin', 'system']);
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->text('body');
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('conversation_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('conversation_messages')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('conversation_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('unassigned_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'assigned_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_assignments');
        Schema::dropIfExists('conversation_attachments');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
    }
};
