<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_alert_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ticker', 20);
            $table->text('message');
            $table->enum('status', ['sent', 'failed'])->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index('ticker');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
