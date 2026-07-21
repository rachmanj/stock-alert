<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracked_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ticker', 20);
            $table->string('name')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'ticker']);
            $table->index('ticker');
            $table->index(['active', 'ticker']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_stocks');
    }
};
