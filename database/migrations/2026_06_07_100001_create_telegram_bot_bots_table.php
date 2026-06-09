<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plugin_telegram_bot_bots', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('token', 200);
            $table->string('username', 64)->nullable();
            $table->string('webhook_path', 100)->unique();
            $table->string('webhook_secret', 128);
            $table->json('allowed_updates')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_webhook_set_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_telegram_bot_bots');
    }
};
