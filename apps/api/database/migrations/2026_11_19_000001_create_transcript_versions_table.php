<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshots of a transcript, so any earlier state can be looked at and brought back. One row per editor per
        // ten minutes of work (the row is refreshed while they keep typing), plus one before every restore.
        Schema::create('transcript_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transcript_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version'); // transcripts.version this snapshot holds
            $table->string('reason', 16)->default('edit'); // edit, restore
            $table->string('title')->nullable();
            $table->json('content')->nullable();
            $table->unsignedInteger('words')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['transcript_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcript_versions');
    }
};
