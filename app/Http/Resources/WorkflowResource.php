<?php

namespace App\Http\Resources;

use App\Models\Workflow;
use App\Services\ManualRuns;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workflow, with the inputs a run of it takes: the fields of its Manual
 * trigger, if it has one.
 *
 * @mixin Workflow
 */
class WorkflowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'published_version' => $this->publishedVersion?->number,
            'inputs' => array_map(fn (array $field) => [
                'name' => $field['name'],
                'label' => $field['label'] ?? $field['name'],
                'type' => $field['type'] ?? 'text',
                'required' => (bool) ($field['required'] ?? false),
                'options' => ($field['type'] ?? 'text') === 'select' ? (array) ($field['options'] ?? []) : null,
            ], app(ManualRuns::class)->fields($this->resource)),
            'url' => route('workspaces.workflows.edit', [$this->workspace, $this->resource]),
        ];
    }
}
