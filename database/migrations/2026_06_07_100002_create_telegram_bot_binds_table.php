<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plugin_telegram_bot_binds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bot_id')->index();
            $table->unsignedInteger('uid')->index();
            $table->string('telegram_id', 64)->nullable();
            $table->string('telegram_username', 128)->default('');
            $table->string('telegram_first_name', 128)->default('');
            $table->string('telegram_last_name', 128)->default('');
            $table->string('bind_token', 80)->nullable()->unique();
            $table->timestamp('bound_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique(['bot_id', 'uid']);
            $table->unique(['bot_id', 'telegram_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_telegram_bot_binds');
    }
};
