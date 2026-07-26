<?php

namespace App\Http\Controllers;

use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PipelineStageController extends Controller
{
    use AuthorizesRequests;

    public function store(Request $request, Pipeline $pipeline)
    {
        $this->authorize('update', $pipeline);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'probability' => ['required', 'integer', 'between:0,100'],
        ]);

        if ($pipeline->stages()->where('name', $validated['name'])->exists()) {
            throw ValidationException::withMessages(['name' => 'Stage names must be unique within a pipeline.']);
        }

        if ($pipeline->stages()->count() >= 12) {
            throw ValidationException::withMessages(['name' => 'A pipeline can have at most 12 stages.']);
        }

        $pipeline->stages()->create([
            'name' => trim($validated['name']),
            'probability' => $validated['probability'],
            'position' => ((int) $pipeline->stages()->max('position')) + 1,
        ]);

        return back()->with('success', 'Stage added.');
    }

    public function update(Request $request, PipelineStage $stage)
    {
        $this->authorize('update', $stage->pipeline);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'probability' => ['required', 'integer', 'between:0,100'],
        ]);

        $duplicate = $stage->pipeline
            ->stages()
            ->where('name', $validated['name'])
            ->whereKeyNot($stage->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'Stage names must be unique within a pipeline.']);
        }

        $stage->update([
            'name' => trim($validated['name']),
            'probability' => $validated['probability'],
        ]);

        return back()->with('success', 'Stage updated.');
    }

    public function reorder(Request $request, Pipeline $pipeline)
    {
        $this->authorize('update', $pipeline);

        $validated = $request->validate([
            'stage_ids' => ['required', 'array', 'min:1', 'max:12'],
            'stage_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $existingIds = $pipeline->stages()->pluck('id')->sort()->values()->all();
        $requestedIds = collect($validated['stage_ids'])->map(fn ($id) => (int) $id)->sort()->values()->all();

        if ($existingIds !== $requestedIds) {
            throw ValidationException::withMessages(['stage_ids' => 'Stage order must include every stage exactly once.']);
        }

        DB::transaction(function () use ($pipeline, $validated): void {
            foreach ($validated['stage_ids'] as $position => $stageId) {
                $pipeline->stages()->whereKey($stageId)->update(['position' => 1000 + $position]);
            }

            foreach ($validated['stage_ids'] as $position => $stageId) {
                $pipeline->stages()->whereKey($stageId)->update(['position' => $position]);
            }
        });

        return back()->with('success', 'Stages reordered.');
    }

    public function destroy(Request $request, PipelineStage $stage)
    {
        $this->authorize('update', $stage->pipeline);

        if ($stage->pipeline->stages()->count() === 1) {
            throw ValidationException::withMessages(['stage' => 'A pipeline must keep at least one stage.']);
        }

        $validated = $request->validate([
            'move_to_stage_id' => ['nullable', 'integer'],
        ]);
        $hasOpportunities = $stage->opportunities()->exists();
        $destination = isset($validated['move_to_stage_id'])
            ? $stage->pipeline->stages()->whereKeyNot($stage->id)->find($validated['move_to_stage_id'])
            : null;

        if ($hasOpportunities && ! $destination) {
            throw ValidationException::withMessages([
                'move_to_stage_id' => 'Choose another stage for existing opportunities.',
            ]);
        }

        DB::transaction(function () use ($request, $stage, $destination): void {
            if ($destination) {
                $stage->opportunities()->with('stage')->get()->each(function ($opportunity) use ($request, $stage, $destination): void {
                    $opportunity->recordActivity('stage_moved', [
                        'from_stage_id' => $stage->id,
                        'from_stage_name' => $stage->name,
                        'to_stage_id' => $destination->id,
                        'to_stage_name' => $destination->name,
                        'reason' => 'stage_deleted',
                    ], $request->user()->id);
                    $opportunity->update([
                        'pipeline_stage_id' => $destination->id,
                        'stage_changed_at' => now(),
                    ]);
                });
            }

            $pipeline = $stage->pipeline;
            $stage->delete();
            $pipeline->stages()->orderBy('position')->get()->values()->each(function ($remainingStage, int $position): void {
                $remainingStage->update(['position' => 1000 + $position]);
            });
            $pipeline->stages()->orderBy('position')->get()->values()->each(function ($remainingStage, int $position): void {
                $remainingStage->update(['position' => $position]);
            });
        });

        return back()->with('success', 'Stage deleted.');
    }
}
