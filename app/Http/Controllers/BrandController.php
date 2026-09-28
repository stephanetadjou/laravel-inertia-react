<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BrandController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('brands', 'name')->where(
                    fn ($q) => $q->where('organization_id', $request->user()->organization_id),
                ),
            ],
        ]);

        $request->user()->organization->brands()->create($data);

        return back()->with('success', "Marque « {$data['name']} » créée.");
    }

    public function update(Request $request, Brand $brand): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $brand);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('brands', 'name')->where(
                    fn ($q) => $q->where('organization_id', $request->user()->organization_id),
                )->ignore($brand->id),
            ],
        ]);

        $brand->update($data);

        return back()->with('success', "Marque renommée en « {$brand->name} ».");
    }

    public function destroy(Request $request, Brand $brand): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $brand);

        if ($brand->stores()->exists()) {
            return back()->with('error', "Supprimez ou déplacez d'abord les boutiques de « {$brand->name} ».");
        }

        $brand->delete();

        return back()->with('success', 'Marque supprimée.');
    }

    private function authorizeSameOrganization(Request $request, Brand $brand): void
    {
        if ($brand->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, 'Cette marque n\'appartient pas à votre organisation.');
        }
    }
}
