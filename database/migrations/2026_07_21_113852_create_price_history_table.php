<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_history', function (Blueprint $table) {
            $table->id();
            $table->string('ticker', 20);
            $table->decimal('price', 15, 2);
            $table->decimal('change', 15, 2)->nullable();
            $table->decimal('change_percent', 8, 4)->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['ticker', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_history');
    }
};
