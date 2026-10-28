<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CmsController extends Controller
{
    private const TYPES = [
        'categories' => [Category::class, 'category', 'Manage categories'],
    ];

    public function index(string $type)
    {
        [$model, $key, $heading] = $this->type($type);
        $records = $model::withCount(['materials' => fn ($query) => $query->withTrashed()])->orderBy('name')->paginate(20);
        return view('bayani', ['page' => $type, 'backend' => ['kind' => 'cms', 'type' => $type, 'records' => $records, 'heading' => $heading]]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        [$model] = $this->type($type);
        $data = $this->validated($request, $model);
        $model::create($data);
        return back()->with('status', 'Record created.');
    }

    public function update(Request $request, string $type, int $record): RedirectResponse
    {
        [$model] = $this->type($type);
        $item = $model::findOrFail($record);
        $item->update($this->validated($request, $model, $item->id));
        return back()->with('status', 'Record updated.');
    }

    public function destroy(string $type, int $record): RedirectResponse
    {
        [$model] = $this->type($type);
        $item = $model::findOrFail($record);
        abort_if($item->materials()->withTrashed()->exists(), 409, 'This value is in use by a material. Deactivate it instead.');
        $item->delete();
        return back()->with('status', 'Record deleted.');
    }

    public function toggle(Request $request, string $type, int $record): RedirectResponse
    {
        [$model] = $this->type($type);
        $item = $model::findOrFail($record);
        $item->update(['status' => $item->status === 'active' ? 'inactive' : 'active']);
        return back()->with('status', 'Record status updated.');
    }

    private function validated(Request $request, string $model, ?int $ignore = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255', Rule::unique((new $model)->getTable(), 'name')->ignore($ignore)],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
        return $request->validate($rules);
    }

    private function type(string $type): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        return self::TYPES[$type];
    }
}
