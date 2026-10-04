<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ReferenceModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Generic CRUD for registry-driven lookup tables (config/reference.php).
 */
class ReferenceDataService
{
    /** @return array{model: class-string<ReferenceModel>, label: string, fields: array<string, array<string, mixed>>} */
    public function definition(string $type): array
    {
        $def = config("reference.{$type}");
        if (! is_array($def)) {
            throw new NotFoundHttpException("Unknown reference type [{$type}].");
        }

        return $def + ['fields' => []];
    }

    /** @return array<string, array{label:string, fields: array<string, mixed>}> */
    public function catalogue(): array
    {
        return collect(config('reference'))->map(fn ($d) => [
            'label' => $d['label'],
            'fields' => collect($d['fields'] ?? [])->map(fn ($f) => collect($f)->except('model')->all())->all(),
        ])->all();
    }

    /** @return Collection<int, ReferenceModel> */
    public function list(string $type, bool $activeOnly = false): Collection
    {
        $model = $this->definition($type)['model'];

        return $model::query()
            ->when($activeOnly, fn ($q) => $q->where('status', 'active'))
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /** @return array<string, list<mixed>> */
    public function rules(string $type, ?ReferenceModel $existing = null): array
    {
        $def = $this->definition($type);
        $table = (new $def['model'])->getTable();
        $req = $existing ? 'sometimes' : 'required';

        $rules = [
            'code' => [$req, 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/', Rule::unique($table, 'code')->ignore($existing?->id)],
            'name' => [$req, 'string', 'max:120'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];

        foreach ($def['fields'] as $key => $f) {
            $base = ! empty($f['required']) ? [$req] : ['sometimes', 'nullable'];
            $rules[$key] = match ($f['type']) {
                'bool' => [...$base, 'boolean'],
                'select' => [...$base, Rule::in($f['options'])],
                'reference' => [...$base, 'integer', Rule::exists((new $f['model'])->getTable(), 'id')],
                default => [...$base, 'string', 'max:255'],
            };
        }

        return $rules;
    }

    /** @param array<string, mixed> $data */
    public function create(string $type, array $data): ReferenceModel
    {
        $model = $this->definition($type)['model'];
        $data['code'] = strtoupper($data['code']);

        return $model::query()->create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(ReferenceModel $item, array $data): ReferenceModel
    {
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }
        $item->fill($data)->save();

        return $item;
    }

    public function find(string $type, int $id): ReferenceModel
    {
        $model = $this->definition($type)['model'];

        return $model::query()->findOrFail($id);
    }

    /** Delete only when unused; otherwise ask the user to deactivate. */
    public function delete(ReferenceModel $item): void
    {
        try {
            DB::transaction(fn () => $item->delete());
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                throw new BusinessRuleException("“{$item->name}” is in use and cannot be deleted. Set it to inactive instead.", 'reference_in_use');
            }
            throw $e;
        }
    }
}
