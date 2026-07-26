<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('currency', 3)->default('USD');
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('pipelines')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('position');
            $table->unsignedTinyInteger('probability')->default(0);
            $table->timestamps();

            $table->unique(['pipeline_id', 'name']);
            $table->unique(['pipeline_id', 'position']);
        });

        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained('pipelines')->restrictOnDelete();
            $table->foreignId('pipeline_stage_id')->constrained('pipeline_stages')->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('funnel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->unsignedBigInteger('value_cents')->default(0);
            $table->string('status', 20)->default('open');
            $table->string('source', 100)->nullable();
            $table->date('expected_close_date')->nullable();
            $table->timestamp('stage_changed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['pipeline_id', 'pipeline_stage_id', 'status']);
            $table->index(['contact_id', 'status']);
            $table->index(['funnel_id', 'status']);
        });

        Schema::create('opportunity_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 50);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['opportunity_id', 'created_at']);
        });

        Schema::create('funnel_opportunity_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funnel_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->foreignId('pipeline_id')->nullable()->constrained('pipelines')->nullOnDelete();
            $table->foreignId('pipeline_stage_id')->nullable()->constrained('pipeline_stages')->nullOnDelete();
            $table->unsignedBigInteger('default_value_cents')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnel_opportunity_settings');
        Schema::dropIfExists('opportunity_activities');
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('pipeline_stages');
        Schema::dropIfExists('pipelines');
    }
};
