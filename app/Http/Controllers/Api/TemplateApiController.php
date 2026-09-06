<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Funnel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TemplateApiController extends Controller
{
    /**
     * Export a funnel as a template JSON.
     */
    public function export(Request $request, Funnel $funnel): JsonResponse
    {
        abort_if($funnel->user_id !== $request->user()->id, 403);

        $template = [
            'kind' => 'openfunnels-template',
            'schemaVersion' => 1,
            'metadata' => [
                'name' => $funnel->name,
                'description' => $funnel->description ?? '',
                'category' => 'custom',
                'tags' => [],
            ],
            'funnel' => [
                'content' => $funnel->content,
                'settings' => $funnel->settings,
            ],
        ];

        return response()->json($template)->header(
            'Content-Disposition',
            'attachment; filename="'.Str::slug($funnel->name).'.openfunnels.json"'
        );
    }

    /**
     * Import a template and create a new funnel from it.
     *
     * Body: { template: { kind, schemaVersion, metadata, funnel } }
     * Or: { template_json: "..." } (raw JSON string)
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'template' => 'required_without:template_json|array',
            'template.kind' => 'required|string',
            'template.schemaVersion' => 'required|integer',
            'template.funnel' => 'required|array',
            'template.funnel.content' => 'required|array',
            'template.funnel.settings' => 'required|array',
            'template_json' => 'required_without:template|json',
        ]);

        $template = $request->input('template') ?? json_decode($request->input('template_json'), true);

        if (($template['kind'] ?? '') !== 'openfunnels-template') {
            return response()->json(['error' => 'Invalid template kind'], 422);
        }

        if (($template['schemaVersion'] ?? 0) !== 1) {
            return response()->json(['error' => 'Unsupported schema version'], 422);
        }

        $name = $template['metadata']['name'] ?? 'Imported Template';
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 1;

        while (Funnel::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        $funnel = $request->user()->funnels()->create([
            'name' => $name,
            'slug' => $slug,
            'description' => $template['metadata']['description'] ?? null,
            'content' => $template['funnel']['content'],
            'settings' => $template['funnel']['settings'],
        ]);

        return response()->json([
            'id' => $funnel->id,
            'name' => $funnel->name,
            'slug' => $funnel->slug,
            'message' => 'Template imported as new funnel',
        ], 201);
    }
}
