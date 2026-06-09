<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plugin_telegram_bot_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bot_id')->index();
            $table->unsignedBigInteger('telegram_update_id')->nullable()->index();
            $table->string('telegram_id', 64)->nullable()->index();
            $table->text('message_text')->nullable();
            $table->json('payload')->nullable();
            $table->boolean('handled')->default(false);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_telegram_bot_updates');
    }
};
