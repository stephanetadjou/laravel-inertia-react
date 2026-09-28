<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->organization;

        $brands = Brand::where('organization_id', $organization->id)
            ->withCount('stores')
            ->with('stores:id,brand_id,name')
            ->orderBy('name')
            ->get();

        return Inertia::render('Organisation/Index', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'brands' => $brands->map(fn (Brand $brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
                'stores_count' => $brand->stores_count,
                'stores' => $brand->stores->map(fn ($store) => [
                    'id' => $store->id,
                    'name' => $store->name,
                ]),
            ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->organization->update($data);

        return back()->with('success', 'Organisation mise à jour.');
    }
}
