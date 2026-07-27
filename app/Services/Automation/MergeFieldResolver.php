<?php

namespace App\Services\Automation;

use App\Services\Automation\Data\WorkflowExecutionContext;

class MergeFieldResolver
{
    public function render(string $template, WorkflowExecutionContext $context, array $additional = []): string
    {
        return (string) preg_replace_callback('/{{\s*([^{}]+?)\s*}}/', function (array $matches) use ($context, $additional): string {
            $field = trim($matches[1]);
            $value = array_key_exists($field, $additional)
                ? $additional[$field]
                : data_get($context->all(), $field);

            if (is_array($value)) {
                return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
            }

            if (is_bool($value)) {
                return $value ? 'Yes' : 'No';
            }

            return (string) ($value ?? '');
        }, $template);
    }
}
