<?php

namespace App\Engine\Editor;

use App\Engine\Definitions\Field;
use App\Engine\Node;
use App\Enums\ValueType;
use App\Models\StoreEntry;
use App\Models\Workspace;

/**
 * What a node's reference fields point at, as extra sibling values that
 * other fields can depend on in their visibleWhen and optionsWhen. A data
 * store entry field named "entry" adds "entry.type" and
 * "entry.collection", so the node can offer only the operations that fit
 * the entry's type and ask for an item key only for a collection.
 */
class FieldFacts
{
    /**
     * The workspace's data store entries by key, as the editor lists them.
     *
     * @return array<string, array{type: ValueType, collection: bool, read_only: bool}>
     */
    public static function storeEntries(Workspace $workspace): array
    {
        return $workspace->storeEntries()->orderBy('key')->get()
            ->mapWithKeys(fn (StoreEntry $entry) => [$entry->key => ['type' => $entry->type, 'collection' => $entry->is_collection, 'read_only' => $entry->read_only]])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $values  the node's config or form values
     * @param  array<string, array{type: ValueType, collection: bool, read_only: bool}>  $storeEntries
     * @return array<string, string>
     */
    public static function for(Node $node, array $values, array $storeEntries): array
    {
        $facts = [];

        foreach ($node->inputs() as $field) {
            $entry = $field->type === Field::STORE_ENTRY ? ($storeEntries[(string) ($values[$field->name] ?? '')] ?? null) : null;

            if ($entry) {
                $facts["{$field->name}.type"] = $entry['type']->value;
                $facts["{$field->name}.collection"] = $entry['collection'] ? 'yes' : 'no';
            }
        }

        return $facts;
    }
}
