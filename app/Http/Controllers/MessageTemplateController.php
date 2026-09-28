<?php

namespace App\Http\Controllers;

use App\Models\MessageTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MessageTemplateController extends Controller
{
    private const CATEGORIES = ['utility', 'marketing', 'authentication', 'service'];

    public function index(Request $request): Response
    {
        $templates = MessageTemplate::where('organization_id', $request->user()->organization_id)
            ->orderBy('name')
            ->get();

        return Inertia::render('Templates/Index', [
            'templates' => $templates,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $request->user()->organization->messageTemplates()->create($data);

        return back()->with('success', "Template « {$data['name']} » enregistré.");
    }

    public function update(Request $request, MessageTemplate $template): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $template);

        $data = $this->validated($request, $template->id);

        $template->update($data);

        return back()->with('success', "Template « {$template->name} » mis à jour.");
    }

    public function destroy(Request $request, MessageTemplate $template): RedirectResponse
    {
        $this->authorizeSameOrganization($request, $template);

        $template->delete();

        return back()->with('success', 'Template supprimé.');
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $organizationId = $request->user()->organization_id;

        return $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('message_templates', 'name')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId)->where('language', $request->input('language')))
                    ->ignore($ignoreId),
            ],
            'language' => ['required', 'string', 'max:10'],
            'category' => ['required', 'in:'.implode(',', self::CATEGORIES)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }

    private function authorizeSameOrganization(Request $request, MessageTemplate $template): void
    {
        if ($template->organization_id !== $request->user()->organization_id) {
            throw new HttpException(403, 'Ce template n\'appartient pas à votre organisation.');
        }
    }
}
