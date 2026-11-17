<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcripts', function (Blueprint $table) {
            // What the engine is doing right now (loading = downloading/opening the model, transcribing).
            $table->string('asr_stage', 16)->nullable()->after('asr_progress');
            // Last sign of life from the running job: a job that stops reporting is treated as dead.
            $table->timestamp('asr_heartbeat_at')->nullable()->after('asr_stage');
        });
    }

    public function down(): void
    {
        Schema::table('transcripts', function (Blueprint $table) {
            $table->dropColumn(['asr_stage', 'asr_heartbeat_at']);
        });
    }
};
