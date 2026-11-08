<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Each table is created only if missing: several containers (web, scheduler, worker)
        // may run migrations at the same moment during a deploy.
        if (! Schema::hasTable('support_chat_conversations')) {
            Schema::create('support_chat_conversations', function (Blueprint $table) {
                $table->id();
                $table->uuid()->unique();
                $table->string('reference', 16)->unique();
                $table->char('access_token_hash', 64);
                $table->foreignId('customer_id')->nullable()->index();
                $table->string('customer_name', 120);
                $table->string('customer_email', 120)->nullable();
                $table->string('customer_phone', 30)->nullable();
                $table->string('status', 20)->default('pending');
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'last_message_at']);
            });
        }

        if (! Schema::hasTable('support_chat_messages')) {
            Schema::create('support_chat_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('support_chat_conversations')->cascadeOnDelete();
                $table->string('sender_type', 20);
                // Id of the admin user who answered from an admin panel (no FK: the users table is the host app's).
                $table->unsignedBigInteger('sender_id')->nullable();
                $table->text('message');
                $table->uuid('client_message_id')->nullable();
                $table->string('telegram_chat_id', 32)->nullable();
                $table->unsignedBigInteger('telegram_message_id')->nullable();
                $table->string('telegram_status', 20)->nullable()->index();
                $table->unsignedTinyInteger('telegram_attempts')->default(0);
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['conversation_id', 'client_message_id'], 'sc_messages_client_message_unique');
                $table->unique(['telegram_chat_id', 'telegram_message_id'], 'sc_messages_telegram_message_unique');
            });
        }

        if (! Schema::hasTable('support_chat_settings')) {
            Schema::create('support_chat_settings', function (Blueprint $table) {
                $table->string('key', 64)->primary();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_chat_settings');
        Schema::dropIfExists('support_chat_messages');
        Schema::dropIfExists('support_chat_conversations');
    }
};
