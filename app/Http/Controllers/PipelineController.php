<?php

namespace App\Http\Controllers;

use App\Models\Pipeline;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PipelineController extends Controller
{
    use AuthorizesRequests;

    public function store(Request $request)
    {
        $this->authorize('create', Pipeline::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'stages' => ['required', 'array', 'min:1', 'max:12'],
            'stages.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'stages.*.probability' => ['required', 'integer', 'between:0,100'],
        ]);

        if ($request->user()->pipelines()->where('name', $validated['name'])->exists()) {
            throw ValidationException::withMessages(['name' => 'You already have a pipeline with this name.']);
        }

        $pipeline = DB::transaction(function () use ($request, $validated) {
            $pipeline = $request->user()->pipelines()->create([
                'name' => trim($validated['name']),
                'currency' => strtoupper($validated['currency']),
            ]);

            foreach ($validated['stages'] as $position => $stage) {
                $pipeline->stages()->create([
                    'name' => trim($stage['name']),
                    'position' => $position,
                    'probability' => $stage['probability'],
                ]);
            }

            return $pipeline;
        });

        return redirect()->route('opportunities.index', ['pipeline_id' => $pipeline->id])
            ->with('success', 'Pipeline created.');
    }

    public function update(Request $request, Pipeline $pipeline)
    {
        $this->authorize('update', $pipeline);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
        ]);

        $duplicate = $request->user()
            ->pipelines()
            ->where('name', $validated['name'])
            ->whereKeyNot($pipeline->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'You already have a pipeline with this name.']);
        }

        $currency = strtoupper($validated['currency']);

        if ($currency !== $pipeline->currency && $pipeline->opportunities()->exists()) {
            throw ValidationException::withMessages([
                'currency' => 'Currency cannot change after opportunities have been added.',
            ]);
        }

        $pipeline->update([
            'name' => trim($validated['name']),
            'currency' => $currency,
        ]);

        return back()->with('success', 'Pipeline updated.');
    }

    public function destroy(Pipeline $pipeline)
    {
        $this->authorize('delete', $pipeline);

        if ($pipeline->opportunities()->exists()) {
            throw ValidationException::withMessages([
                'pipeline' => 'Move or remove this pipeline’s opportunities before deleting it.',
            ]);
        }

        $pipeline->delete();

        return redirect()->route('opportunities.index')->with('success', 'Pipeline deleted.');
    }
}
