<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversation_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id')->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('agent');
            $table->string('role', 25);
            $table->text('content')->nullable();
            $table->text('attachments')->nullable();
            $table->text('tool_calls')->nullable();
            $table->text('tool_results')->nullable();
            $table->text('usage')->nullable();
            $table->text('meta')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'user_id', 'updated_at'], 'ai_conversation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_messages');
    }
};
