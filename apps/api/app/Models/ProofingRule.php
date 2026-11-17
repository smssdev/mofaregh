<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of the proofing dictionary (see the migration for the types). The editor compiles them all.
 *
 * @property list<string>|null $exceptions
 */
#[Fillable(['type', 'pattern', 'replacement', 'message', 'kind', 'prefixes', 'exceptions', 'note', 'enabled', 'created_by', 'updated_by'])]
class ProofingRule extends Model
{
    public const TYPES = ['word', 'phrase', 'regex', 'formula', 'accept', 'builtin'];

    public const KINDS = ['spelling', 'grammar', 'punctuation'];

    /** The checks written in code; their rows only switch them on or off and set their message. */
    public const BUILTINS = ['separated_waw', 'repeated_word', 'latin_punctuation', 'space_before_punctuation', 'tanween'];

    /** What a built-in row may change. */
    public const BUILTIN_FIELDS = ['enabled', 'message', 'kind', 'exceptions', 'note'];

    protected $attributes = ['replacement' => '', 'message' => '', 'kind' => 'spelling', 'prefixes' => true, 'enabled' => true];

    protected function casts(): array
    {
        return ['prefixes' => 'boolean', 'enabled' => 'boolean', 'exceptions' => 'array'];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
