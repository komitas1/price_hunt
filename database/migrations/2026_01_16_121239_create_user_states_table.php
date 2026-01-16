<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_states', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tg_user_id')->unique();
            $table->string('step')->default('start'); // start, waiting_action, from, to, date, passengers
            $table->json('data')->nullable();          // собранные данные пользователя
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_states');
    }
};
