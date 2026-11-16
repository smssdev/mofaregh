<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audio_file_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            // The editor's document (TipTap/ProseMirror JSON). Null until someone types or a draft is accepted.
            $table->json('content')->nullable();
            // Bumped on every save: a client that saves an older version is told to reload (two people, one file).
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // Automatic first draft (ASR): none -> queued -> running -> done | failed.
            $table->string('asr_status', 16)->default('none');
            $table->unsignedTinyInteger('asr_progress')->default(0);
            $table->json('asr_result')->nullable();   // [{start_ms, end_ms, text}]
            $table->string('asr_model', 64)->nullable();
            $table->text('asr_error')->nullable();
            $table->timestamp('asr_finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcripts');
    }
};
