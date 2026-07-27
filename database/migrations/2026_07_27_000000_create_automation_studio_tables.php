<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('enrollment_policy', 30)->default('every_event');
            $table->string('trigger_type', 100)->nullable();
            $table->json('draft_definition');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'trigger_type', 'status']);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('automation_workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('automation_workflows')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('definition');
            $table->char('checksum', 64);
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['workflow_id', 'version']);
            $table->unique(['workflow_id', 'checksum']);
        });

        Schema::table('automation_workflows', function (Blueprint $table) {
            $table->foreignId('active_version_id')
                ->nullable()
                ->after('revision')
                ->constrained('automation_workflow_versions')
                ->nullOnDelete();
        });

        Schema::create('automation_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 100);
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('funnel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained('contact_submissions')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload')->nullable();
            $table->ulid('causation_run_id')->nullable();
            $table->unsignedTinyInteger('causation_depth')->default(0);
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['user_id', 'event_type']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained('automation_workflows')->cascadeOnDelete();
            $table->foreignId('workflow_version_id')->constrained('automation_workflow_versions')->restrictOnDelete();
            $table->foreignUlid('automation_event_id')->constrained('automation_events')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('funnel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained('contact_submissions')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->string('current_node_id')->nullable();
            $table->json('context');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('next_resume_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['workflow_version_id', 'automation_event_id'], 'automation_run_event_version_unique');
            $table->index(['user_id', 'status']);
            $table->index(['workflow_id', 'created_at']);
            $table->index(['status', 'next_resume_at']);
            $table->index(['workflow_id', 'contact_id', 'status']);
        });

        Schema::create('automation_step_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('automation_run_id')->constrained('automation_runs')->cascadeOnDelete();
            $table->string('node_id');
            $table->string('node_type', 50);
            $table->string('status', 20)->default('queued');
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('input_summary')->nullable();
            $table->json('output_summary')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->timestamps();

            $table->unique(['automation_run_id', 'node_id']);
            $table->index(['status', 'scheduled_for']);
        });

        Schema::create('contact_email_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('unknown');
            $table->string('source', 100)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_email_preferences');
        Schema::dropIfExists('automation_step_runs');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_events');

        Schema::table('automation_workflows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_version_id');
        });

        Schema::dropIfExists('automation_workflow_versions');
        Schema::dropIfExists('automation_workflows');
    }
};
