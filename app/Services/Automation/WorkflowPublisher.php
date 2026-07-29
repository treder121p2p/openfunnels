<?php

namespace App\Services\Automation;

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkflowPublisher
{
    public function __construct(private WorkflowDefinitionValidator $validator) {}

    public function publish(AutomationWorkflow $workflow): AutomationWorkflowVersion
    {
        $definition = $workflow->draft_definition;
        $revision = $workflow->revision;
        $this->validator->assertValid($definition, $workflow->user, true);

        $normalized = $this->normalize($definition);
        $enrollmentPolicy = $workflow->enrollment_policy;
        $checksum = hash('sha256', json_encode([
            'definition' => $normalized,
            'enrollment_policy' => $enrollmentPolicy,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($workflow, $revision, $normalized, $enrollmentPolicy, $checksum) {
            $workflow = AutomationWorkflow::query()->lockForUpdate()->findOrFail($workflow->id);
            if ($workflow->revision !== $revision) {
                throw ValidationException::withMessages([
                    'definition' => 'The automation draft changed while it was being published. Validate and publish the latest revision again.',
                ]);
            }

            $existing = $workflow->versions()->where('checksum', $checksum)->first();

            if ($existing) {
                $workflow->update([
                    'active_version_id' => $existing->id,
                    'trigger_type' => data_get($existing->definition, 'trigger.type'),
                    'status' => 'active',
                ]);

                return $existing;
            }

            $version = $workflow->versions()->create([
                'version' => ((int) $workflow->versions()->max('version')) + 1,
                'enrollment_policy' => $enrollmentPolicy,
                'definition' => $normalized,
                'checksum' => $checksum,
                'published_at' => now(),
            ]);

            $workflow->update([
                'active_version_id' => $version->id,
                'trigger_type' => data_get($normalized, 'trigger.type'),
                'status' => 'active',
            ]);

            return $version;
        });
    }

    private function normalize(array $definition): array
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (array_is_list($value)) {
                return array_map($normalize, $value);
            }

            ksort($value);

            return array_map($normalize, $value);
        };

        return $normalize($definition);
    }
}
