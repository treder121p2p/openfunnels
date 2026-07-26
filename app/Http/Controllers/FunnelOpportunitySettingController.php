<?php

namespace App\Http\Controllers;

use App\Models\Funnel;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FunnelOpportunitySettingController extends Controller
{
    use AuthorizesRequests;

    public function update(Request $request, Funnel $funnel)
    {
        $this->authorize('update', $funnel);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'pipeline_id' => ['nullable', 'integer'],
            'pipeline_stage_id' => ['nullable', 'integer'],
            'default_value' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        $pipeline = isset($validated['pipeline_id'])
            ? $request->user()->pipelines()->find($validated['pipeline_id'])
            : null;
        $stage = $pipeline && isset($validated['pipeline_stage_id'])
            ? $pipeline->stages()->find($validated['pipeline_stage_id'])
            : null;

        if ($validated['enabled'] && (! $pipeline || ! $stage)) {
            throw ValidationException::withMessages([
                'pipeline_id' => 'Choose an owned pipeline and one of its stages.',
            ]);
        }

        $funnel->opportunitySetting()->updateOrCreate(
            ['funnel_id' => $funnel->id],
            [
                'enabled' => $validated['enabled'],
                'pipeline_id' => $pipeline?->id,
                'pipeline_stage_id' => $stage?->id,
                'default_value_cents' => (int) round((float) ($validated['default_value'] ?? 0) * 100),
            ],
        );

        return back()->with('success', 'CRM automation settings updated.');
    }
}
