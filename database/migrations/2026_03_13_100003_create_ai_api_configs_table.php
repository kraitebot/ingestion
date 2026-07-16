<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_api_configs', function (Blueprint $table): void {
            $table->id();

            if (Schema::hasTable('teams')) {
                $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            } else {
                $table->unsignedBigInteger('team_id')->nullable();
            }

            $table->string('purpose', 50)->default('chat');
            $table->string('provider');
            $table->text('api_key')->nullable();
            $table->text('oauth_access_token')->nullable();
            $table->text('oauth_refresh_token')->nullable();
            $table->timestamp('oauth_expires_at')->nullable();
            $table->string('model')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'provider', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_api_configs');
    }
};
