<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Purchase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $organizationId = $request->user()->organization_id;

        $categories = Category::where('organization_id', $organizationId)
            ->orderBy('name')
            ->get();

        $usageCounts = Purchase::query()
            ->join('stores', 'stores.id', '=', 'purchases.store_id')
            ->where('stores.organization_id', $organizationId)
            ->whereNotNull('purchases.product_category')
            ->selectRaw('purchases.product_category as name, count(*) as aggregate')
            ->groupBy('purchases.product_category')
            ->pluck('aggregate', 'name');

        return Inertia::render('Categories/Index', [
            'categories' => $categories->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'purchases_count' => (int) ($usageCounts->get($category->name) ?? 0),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'name')->where(
                    fn ($q) => $q->where('organization_id', $request->user()->organization_id),
                ),
            ],
        ]);

        $request->user()->organization->categories()->create($data);

        return back()->with('success', "Catégorie « {$data['name']} » créée.");
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $category);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('categories', 'name')->where(
                    fn ($q) => $q->where('organization_id', $request->user()->organization_id),
                )->ignore($category->id),
            ],
        ]);

        $category->update($data);

        return back()->with('success', "Catégorie renommée en « {$category->name} ».");
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $category);

        $category->delete();

        return back()->with('success', 'Catégorie supprimée.');
    }

    private function authorizeSameOrganization(Request $request, Category $category): void
    {
        if ($category->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, 'Cette catégorie n\'appartient pas à votre organisation.');
        }
    }
}
