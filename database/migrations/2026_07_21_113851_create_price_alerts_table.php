<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tracked_stock_id')->constrained()->cascadeOnDelete();
            $table->string('ticker', 20);
            $table->decimal('target_price', 15, 2);
            $table->enum('direction', ['atas', 'bawah']);
            $table->boolean('is_triggered')->default(false);
            $table->timestamp('triggered_at')->nullable();
            $table->timestamps();
            $table->index(['ticker', 'is_triggered']);
            $table->index(['user_id', 'is_triggered']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_alerts');
    }
};
