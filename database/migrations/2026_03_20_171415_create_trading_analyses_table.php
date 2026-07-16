<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_analyses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('exchange_symbol_id')->index();
            $table->string('status')->default('pending')->index();
            $table->json('technician_conclusion')->nullable();
            $table->json('macro_conclusion')->nullable();
            $table->json('sentinel_conclusion')->nullable();
            $table->json('investment_debate_history')->nullable();
            $table->json('research_manager_conclusion')->nullable();
            $table->json('broker_conclusion')->nullable();
            $table->json('risk_debate_history')->nullable();
            $table->json('risk_judge_conclusion')->nullable();
            $table->string('trade_decision')->nullable();
            $table->json('trade_config')->nullable();
            $table->string('technician_session_id')->nullable();
            $table->string('macro_session_id')->nullable();
            $table->string('sentinel_session_id')->nullable();
            $table->string('bull_session_id')->nullable();
            $table->string('bear_session_id')->nullable();
            $table->string('research_manager_session_id')->nullable();
            $table->string('broker_session_id')->nullable();
            $table->string('risk_aggressive_session_id')->nullable();
            $table->string('risk_conservative_session_id')->nullable();
            $table->string('risk_neutral_session_id')->nullable();
            $table->string('risk_judge_session_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['exchange_symbol_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_analyses');
    }
};
