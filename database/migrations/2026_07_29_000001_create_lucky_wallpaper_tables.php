<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        Schema::create('wallpapers', function (Blueprint $table) {
            $table->id();
            $table->date('target_date')->unique();
            $table->string('title')->nullable();
            $table->string('art_style')->nullable();
            $table->text('conclusion')->nullable();
            $table->longText('overview')->nullable();
            $table->longText('composition')->nullable();
            $table->longText('color_wu_xing')->nullable();
            $table->longText('symbolism')->nullable();
            $table->unsignedBigInteger('prize_vnd')->nullable();
            $table->string('source', 32)->default('generated');
            $table->string('notion_page_id')->nullable()->unique();
            $table->unsignedBigInteger('chosen_proposal_id')->nullable();
            $table->string('image_disk')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_mime', 100)->nullable();
            $table->unsignedBigInteger('image_bytes')->nullable();
            $table->string('image_sha256', 64)->nullable();
            $table->string('state', 32)->default('draft')->index();
            $table->json('warnings')->nullable();
            $table->timestamp('result_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('composition_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallpaper_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('status', 24)->default('proposed')->index();
            $table->string('title');
            $table->string('art_style');
            $table->text('conclusion');
            $table->longText('overview');
            $table->longText('composition');
            $table->longText('color_wu_xing');
            $table->longText('symbolism');
            $table->json('calendar_context')->nullable();
            $table->string('analysis_hash', 64)->nullable();
            $table->string('input_hash', 64);
            $table->timestamps();

            $table->unique(['wallpaper_id', 'sequence']);
        });

        Schema::create('analysis_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('data_hash', 64)->index();
            $table->string('prompt_version', 32);
            $table->string('model');
            $table->longText('summary');
            $table->json('statistics')->nullable();
            $table->string('status', 24)->default('succeeded')->index();
            $table->timestamps();

            $table->unique(['data_hash', 'prompt_version']);
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('wallpaper_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40)->index();
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('skipped_existing')->default(0);
            $table->unsignedInteger('skipped_invalid')->default(0);
            $table->unsignedInteger('skipped_empty_body')->default(0);
            $table->boolean('retryable')->default(false);
            $table->json('warnings')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('checkpoint_at')->nullable();
            $table->timestamps();
        });

        Schema::create('api_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 40)->index();
            $table->string('model');
            $table->string('prompt_version', 32);
            $table->string('input_hash', 64);
            $table->string('openai_request_id')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('status', 24)->default('queued')->index();
            $table->string('error_code')->nullable();
            $table->boolean('retryable')->default(false);
            $table->nullableMorphs('subject');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_runs');
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('analysis_snapshots');
        Schema::dropIfExists('composition_proposals');
        Schema::dropIfExists('wallpapers');
        Schema::dropIfExists('app_settings');
    }
};
