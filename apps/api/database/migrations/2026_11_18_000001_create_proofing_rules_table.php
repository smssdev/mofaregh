<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The proofing dictionary the editor checks against: common mistakes and their corrections, phrases,
        // advanced (regular expression) rules, accepted words, and switches for the built-in checks.
        Schema::create('proofing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16); // word, phrase, regex, formula (a vowelled formula), accept, builtin
            $table->string('pattern', 500);
            $table->string('replacement', 500)->default('');
            $table->string('message', 255)->default('');
            $table->string('kind', 16)->default('spelling'); // spelling, grammar, punctuation
            $table->boolean('prefixes')->default(true); // word rules: also with و/ف (and ب/ك/ل before ال)
            $table->json('exceptions')->nullable(); // builtin repeated_word: words a speaker may say twice
            $table->string('note', 500)->nullable();
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['type', 'pattern']);
        });

        // The dictionary that shipped with the editor, so every rule is visible and editable from day one.
        $now = now();
        $rows = json_decode((string) file_get_contents(database_path('data/proofing-rules.json')), true);
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('proofing_rules')->insert(array_map(fn (array $r): array => [
                'type' => $r['type'],
                'pattern' => $r['pattern'],
                'replacement' => $r['replacement'] ?? '',
                'message' => $r['message'] ?? '',
                'kind' => $r['kind'] ?? 'spelling',
                'prefixes' => (bool) ($r['prefixes'] ?? false),
                'exceptions' => isset($r['exceptions']) ? json_encode($r['exceptions'], JSON_UNESCAPED_UNICODE) : null,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('proofing_rules');
    }
};
