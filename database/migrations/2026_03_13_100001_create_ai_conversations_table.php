<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->longText('summary')->nullable();
            $table->string('type', 50)->default('normal');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
