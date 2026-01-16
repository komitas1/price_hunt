<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tg_user_id')->unique();
            $table->string('username')->nullable();
            $table->enum('plan', ['free', 'premium'])->default('free');
            $table->timestamp('premium_until')->nullable(); // дата окончания премиума
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
