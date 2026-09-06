<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Funnel;
use App\Services\FunnelPublicUrlResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FunnelApiController extends Controller
{
    public function __construct(
        private readonly FunnelPublicUrlResolver $publicUrlResolver,
    ) {}

    /**
     * List all funnels for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $funnels = $request->user()
            ->funnels()
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(fn (Funnel $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'slug' => $f->slug,
                'description' => $f->description,
                'status' => $f->status,
                'is_published' => $f->is_published,
                'views' => $f->views,
                'conversions' => $f->conversions,
                'public_url' => $this->publicUrlResolver->resolve($f),
                'updated_at' => $f->updated_at->toISOString(),
            ]);

        return response()->json(['funnels' => $funnels]);
    }

    /**
     * Create a new funnel.
     *
     * Body: { name, description?, content?, settings? }
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'content' => 'nullable|array',
            'settings' => 'nullable|array',
        ]);

        $baseSlug = Str::slug($validated['name']);
        $slug = $baseSlug;
        $counter = 1;

        while (Funnel::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        $funnel = $request->user()->funnels()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'content' => $validated['content'] ?? ['sections' => []],
            'settings' => $validated['settings'] ?? [
                'backgroundColor' => '#ffffff',
                'maxWidth' => '1200px',
            ],
        ]);

        return response()->json([
            'id' => $funnel->id,
            'name' => $funnel->name,
            'slug' => $funnel->slug,
            'status' => $funnel->status,
        ], 201);
    }

    /**
     * Get a single funnel with full content.
     */
    public function show(Request $request, Funnel $funnel): JsonResponse
    {
        $this->authorizeFunnel($request, $funnel);

        return response()->json([
            'id' => $funnel->id,
            'name' => $funnel->name,
            'slug' => $funnel->slug,
            'description' => $funnel->description,
            'content' => $funnel->content,
            'settings' => $funnel->settings,
            'status' => $funnel->status,
            'is_published' => $funnel->is_published,
            'revision' => $funnel->revision,
            'views' => $funnel->views,
            'conversions' => $funnel->conversions,
            'public_url' => $this->publicUrlResolver->resolve($funnel),
            'updated_at' => $funnel->updated_at->toISOString(),
        ]);
    }

    /**
     * Update a funnel (name, description, content, settings).
     */
    public function update(Request $request, Funnel $funnel): JsonResponse
    {
        $this->authorizeFunnel($request, $funnel);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'content' => 'nullable|array',
            'settings' => 'nullable|array',
        ]);

        $data = array_filter($validated, fn ($v) => $v !== null);

        if (isset($data['name']) && $data['name'] !== $funnel->name) {
            $baseSlug = Str::slug($data['name']);
            $slug = $baseSlug;
            $counter = 1;
            while (Funnel::query()->where('slug', $slug)->where('id', '!=', $funnel->id)->exists()) {
                $slug = $baseSlug.'-'.$counter;
                $counter++;
            }
            $data['slug'] = $slug;
        }

        $funnel->update($data);

        return response()->json([
            'id' => $funnel->id,
            'name' => $funnel->name,
            'slug' => $funnel->slug,
            'status' => $funnel->status,
            'updated_at' => $funnel->updated_at->toISOString(),
        ]);
    }

    /**
     * Delete a funnel.
     */
    public function destroy(Request $request, Funnel $funnel): JsonResponse
    {
        $this->authorizeFunnel($request, $funnel);
        $funnel->delete();

        return response()->json(['message' => 'Funnel deleted']);
    }

    /**
     * Publish a funnel.
     */
    public function publish(Request $request, Funnel $funnel): JsonResponse
    {
        $this->authorizeFunnel($request, $funnel);
        $funnel->publish();

        return response()->json([
            'message' => 'Funnel published',
            'public_url' => $this->publicUrlResolver->resolve($funnel),
        ]);
    }

    /**
     * Unpublish a funnel.
     */
    public function unpublish(Request $request, Funnel $funnel): JsonResponse
    {
        $this->authorizeFunnel($request, $funnel);
        $funnel->unpublish();

        return response()->json(['message' => 'Funnel unpublished']);
    }

    /**
     * Duplicate a funnel.
     */
    public function duplicate(Request $request, Funnel $funnel): JsonResponse
    {
        $this->authorizeFunnel($request, $funnel);

        $newFunnel = $request->user()->funnels()->create([
            'name' => $funnel->name.' (copy)',
            'slug' => $funnel->slug.'-copy-'.Str::random(5),
            'description' => $funnel->description,
            'content' => $funnel->content,
            'settings' => $funnel->settings,
        ]);

        return response()->json([
            'id' => $newFunnel->id,
            'name' => $newFunnel->name,
            'slug' => $newFunnel->slug,
        ], 201);
    }

    /**
     * Generate a funnel using AI.
     *
     * Body: { goal, business, audience, offer, tone }
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'goal' => 'required|string|max:100',
            'business' => 'required|string|max:500',
            'audience' => 'required|string|max:500',
            'offer' => 'required|string|max:1000',
            'tone' => 'required|string|max:100',
        ]);

        $driver = config('funnel-ai.driver', 'disabled');

        if ($driver === 'disabled') {
            return response()->json([
                'error' => 'AI generation is disabled. Set FUNNEL_AI_DRIVER in .env',
            ], 503);
        }

        try {
            $generator = app(\App\Contracts\FunnelGenerator::class);
            $validator = app(\App\Services\Ai\FunnelGenerationValidator::class);

            $result = $generator->generate($validated);
            $validated_result = $validator->validate($result);

            return response()->json(['funnel' => $validated_result]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Check that the funnel belongs to the authenticated user.
     */
    private function authorizeFunnel(Request $request, Funnel $funnel): void
    {
        abort_if($funnel->user_id !== $request->user()->id, 403, 'Unauthorized');
    }
}
