<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The automatic draft can be made part by part (a minute, five minutes…): the part being made now, and the
        // parts already made, so the editor can say what is covered and suggest the next one.
        Schema::table('transcripts', function (Blueprint $table) {
            $table->unsignedInteger('asr_range_start_ms')->nullable()->after('asr_stage');
            $table->unsignedInteger('asr_range_end_ms')->nullable()->after('asr_range_start_ms');
            $table->json('asr_covered')->nullable()->after('asr_result'); // [[start_ms, end_ms], …] merged, sorted
        });
    }

    public function down(): void
    {
        Schema::table('transcripts', function (Blueprint $table) {
            $table->dropColumn(['asr_range_start_ms', 'asr_range_end_ms', 'asr_covered']);
        });
    }
};
