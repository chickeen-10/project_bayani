<?php

namespace App\Http\Controllers;

use App\Models\Material;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FavoriteController extends Controller
{
    public function index(Request $request): View
    {
        $materials = $request->user()->favorites()->where('status', 'Approved')
            ->with(['category:id,name', 'contributor:id,name', 'curriculum:id,name', 'materialType:id,name', 'subjects:id,name', 'gradeLevels:id,name'])
            ->orderByPivot('created_at', 'desc')->paginate(9)->withQueryString();
        $materials->getCollection()->each(fn (Material $material) => $material->setAttribute('is_favorited', true));

        return view('bayani', ['page' => 'favorites', 'backend' => ['kind' => 'favorites', 'materials' => $materials]]);
    }

    public function store(Request $request, Material $material): RedirectResponse
    {
        abort_unless($material->status === 'Approved', 404);
        $request->user()->favorites()->syncWithoutDetaching([$material->id]);

        return back()->with('status', 'Resource saved to your favorites.');
    }

    public function destroy(Request $request, Material $material): RedirectResponse
    {
        $request->user()->favorites()->detach($material->id);

        return back()->with('status', 'Resource removed from your favorites.');
    }
}
