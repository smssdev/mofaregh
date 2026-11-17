<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\ProofingRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The proofing dictionary. Everyone signed in reads it (the editor checks with it); the people who own the
 * language of the transcripts edit it: admins, project managers and linguistic reviewers.
 *
 * Regular-expression rules run in the browser (JavaScript syntax), so the editor validates them, not PHP.
 */
class ProofingRuleController extends Controller
{
    public static function canEdit(?User $user): bool
    {
        return (bool) $user?->hasRole(Role::Admin, Role::ProjectManager, Role::LinguisticReviewer);
    }

    public function index(Request $request): JsonResponse
    {
        $rules = ProofingRule::query()->with('editor:id,name')->orderBy('type')->orderBy('pattern')->get();

        return response()->json([
            'data' => $rules->map(fn (ProofingRule $r) => $this->present($r))->values(),
            'can_edit' => self::canEdit($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(self::canEdit($request->user()), 403);
        $data = $this->validated($request->all());
        if ($data['type'] === 'builtin') {
            throw ValidationException::withMessages(['type' => 'القواعد المدمجة ثابتة؛ يمكن تعطيلها أو تعديل رسالتها فقط.']);
        }
        $rule = ProofingRule::create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return response()->json(['data' => $this->present($rule->load('editor:id,name'))], 201);
    }

    public function update(Request $request, ProofingRule $proofingRule): JsonResponse
    {
        abort_unless(self::canEdit($request->user()), 403);
        if ($proofingRule->type === 'builtin') {
            // Built-in checks are code: only their switch, message, kind, exceptions and note change.
            $data = $request->validate([
                'enabled' => ['sometimes', 'boolean'],
                'message' => ['sometimes', 'nullable', 'string', 'max:255'],
                'kind' => ['sometimes', Rule::in(ProofingRule::KINDS)],
                'exceptions' => ['sometimes', 'nullable', 'array', 'max:500'],
                'exceptions.*' => ['string', 'max:100'],
                'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            ]);
            if (array_key_exists('message', $data)) {
                $data['message'] = trim((string) $data['message']);
            }
            if (isset($data['exceptions'])) {
                $data['exceptions'] = $this->cleanList($data['exceptions']);
            }
        } else {
            // A partial update (the on/off switch) keeps the other fields.
            $data = $this->validated($request->all() + $this->present($proofingRule), $proofingRule);
            if ($data['type'] === 'builtin') {
                throw ValidationException::withMessages(['type' => 'لا يمكن تحويل قاعدة إلى قاعدة مدمجة.']);
            }
        }
        $proofingRule->update($data + ['updated_by' => $request->user()->id]);

        return response()->json(['data' => $this->present($proofingRule->load('editor:id,name'))]);
    }

    public function destroy(Request $request, ProofingRule $proofingRule): Response
    {
        abort_unless(self::canEdit($request->user()), 403);
        if ($proofingRule->type === 'builtin') {
            throw ValidationException::withMessages(['type' => 'القواعد المدمجة لا تُحذف؛ عطّلها بدلًا من ذلك.']);
        }
        $proofingRule->delete();

        return response()->noContent();
    }

    /** Adds or updates many rules at once (a JSON export of this dictionary, or a list prepared elsewhere). */
    public function import(Request $request): JsonResponse
    {
        abort_unless(self::canEdit($request->user()), 403);
        $request->validate(['rules' => ['required', 'array', 'min:1', 'max:5000']]);

        $created = 0;
        $updated = 0;
        $errors = [];
        DB::transaction(function () use ($request, &$created, &$updated, &$errors) {
            foreach (array_values($request->input('rules')) as $index => $raw) {
                $raw = is_array($raw) ? $raw : [];
                $existing = isset($raw['type'], $raw['pattern']) && is_string($raw['pattern'])
                    ? ProofingRule::where('type', $raw['type'])->where('pattern', $this->squish($raw['pattern']))->first()
                    : null;
                try {
                    if ($existing?->type === 'builtin') {
                        $data = array_intersect_key($raw, array_flip(ProofingRule::BUILTIN_FIELDS));
                        if (isset($data['exceptions']) && is_array($data['exceptions'])) {
                            $data['exceptions'] = $this->cleanList($data['exceptions']);
                        }
                        $existing->update($data + ['updated_by' => $request->user()->id]);
                        $updated++;

                        continue;
                    }
                    $data = $this->validated($raw, $existing);
                    if ($data['type'] === 'builtin') {
                        throw ValidationException::withMessages(['type' => 'قاعدة مدمجة غير معروفة: '.$data['pattern']]);
                    }
                } catch (ValidationException $e) {
                    $errors[] = ['index' => $index + 1, 'pattern' => (string) ($raw['pattern'] ?? ''), 'message' => collect($e->errors())->flatten()->first()];

                    continue;
                }
                if ($existing) {
                    $existing->update($data + ['updated_by' => $request->user()->id]);
                    $updated++;
                } else {
                    ProofingRule::create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
                    $created++;
                }
            }
        });

        return response()->json(['created' => $created, 'updated' => $updated, 'errors' => $errors]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(array $input, ?ProofingRule $rule = null): array
    {
        $data = validator($input, [
            'type' => ['required', Rule::in(ProofingRule::TYPES)],
            'pattern' => ['required', 'string', 'max:500'],
            'replacement' => ['nullable', 'string', 'max:500'],
            'message' => ['nullable', 'string', 'max:255'],
            'kind' => ['nullable', Rule::in(ProofingRule::KINDS)],
            'prefixes' => ['nullable', 'boolean'],
            'exceptions' => ['nullable', 'array', 'max:500'],
            'exceptions.*' => ['string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'enabled' => ['nullable', 'boolean'],
        ], [], [
            'pattern' => 'الخطأ', 'replacement' => 'التصحيح', 'message' => 'الرسالة', 'type' => 'النوع',
        ])->validate();

        $regex = $data['type'] === 'regex';
        $data['pattern'] = $regex ? trim($data['pattern']) : $this->squish($data['pattern']);
        $data['replacement'] = $regex ? (string) ($data['replacement'] ?? '') : $this->squish($data['replacement'] ?? '');
        $data['message'] = $this->squish($data['message'] ?? '');
        $data['kind'] ??= 'spelling';
        $data['prefixes'] = (bool) ($data['prefixes'] ?? ($data['type'] === 'word'));
        $data['enabled'] = (bool) ($data['enabled'] ?? true);
        $data['exceptions'] = $this->cleanList($data['exceptions'] ?? []) ?: null;

        if ($data['pattern'] === '') {
            throw ValidationException::withMessages(['pattern' => 'اكتب الخطأ.']);
        }
        $duplicate = ProofingRule::query()
            ->where('type', $data['type'])->where('pattern', $data['pattern'])
            ->when($rule, fn (Builder $q) => $q->whereKeyNot($rule->id))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['pattern' => 'هذه القاعدة موجودة في القاموس.']);
        }
        if (in_array($data['type'], ['word', 'phrase'], true)) {
            if ($data['replacement'] === '') {
                throw ValidationException::withMessages(['replacement' => 'اكتب التصحيح.']);
            }
            if ($data['replacement'] === $data['pattern']) {
                throw ValidationException::withMessages(['replacement' => 'التصحيح مطابق للخطأ.']);
            }
        }
        if ($data['type'] === 'word' && preg_match('/\s/u', $data['pattern'])) {
            throw ValidationException::withMessages(['pattern' => 'الكلمة لا تحوي مسافات؛ اختر النوع «عبارة».']);
        }

        return $data;
    }

    private function squish(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    /**
     * @param  array<mixed>  $items
     * @return list<string>
     */
    private function cleanList(array $items): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($s) => $this->squish(is_string($s) ? $s : ''), $items))));
    }

    /** @return array<string, mixed> */
    private function present(ProofingRule $r): array
    {
        return [
            'id' => $r->id,
            'type' => $r->type,
            'pattern' => $r->pattern,
            'replacement' => $r->replacement,
            'message' => $r->message,
            'kind' => $r->kind,
            'prefixes' => $r->prefixes,
            'exceptions' => $r->exceptions ?? [],
            'note' => $r->note,
            'enabled' => $r->enabled,
            'updated_by' => $r->editor?->name,
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }
}
