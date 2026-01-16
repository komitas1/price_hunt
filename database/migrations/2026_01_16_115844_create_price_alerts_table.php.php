<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('price_alerts', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tg_user_id');
            $table->string('origin', 3);
            $table->string('destination', 3);
            $table->date('depart_date');
            $table->integer('passengers')->default(1);
            $table->decimal('current_price',8,2 )->nullable();
            $table->decimal('target_price',8,2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('price_alerts');
    }
};
