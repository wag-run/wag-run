{{--
    The Alpine template for one node on the WireFlow canvas, shared by the
    editor and the run view. node.data comes from CanvasMapper; the run view
    adds status and statusLabel. Sticky notes (node.data.isNote) draw as a
    note with no handles. Double-clicking a node dispatches wag-open-node,
    which the editor answers by opening that node's settings.
--}}
<template x-if="node.data.isNote">
    <div class="wag-note" :class="node.data.custom ? 'wag-note-custom' : 'wag-note-' + node.data.color"
        :style="{ opacity: (node.data.opacity ?? 50) / 100, ...(node.data.custom ? { backgroundColor: node.data.custom.fill, color: node.data.custom.ink, '--tw-ring-color': node.data.custom.line } : {}) }" data-test="sticky-note" x-on:dblclick.stop="$dispatch('wag-open-node', { id: node.id })">
        <template x-if="node.data.html">
            <div class="wag-note-text" x-html="node.data.html"></div>
        </template>
        <template x-if="! node.data.html">
            <div class="wag-note-text is-empty">{{ __('workflows.edit.empty_note') }}</div>
        </template>
    </div>
</template>
<template x-if="! node.data.isNote">
<div class="wag-node" x-on:dblclick.stop="$dispatch('wag-open-node', { id: node.id })" :class="{ 'is-trigger': node.data.isTrigger, 'is-unknown': node.data.unknown, ['run-' + node.data.status]: !! node.data.status }">
    <template x-if="! node.data.isTrigger">
        <div x-flow-handle:target.left="'in'"></div>
    </template>
    <div class="wag-node-header">
        <span class="wag-node-icon" x-html="node.data.iconHtml"></span>
        <div class="min-w-0">
            <div class="truncate font-medium" x-text="node.data.name"></div>
            <div class="truncate text-xs text-zinc-500" x-text="node.data.typeLabel + ' · ' + node.id"></div>
        </div>
        <template x-if="node.data.status">
            <span class="wag-run-status ms-auto" x-text="node.data.statusLabel"></span>
        </template>
    </div>
    <div class="py-1">
        <template x-for="port in node.data.ports" :key="port.id">
            <div class="wag-port">
                <span x-text="port.label"></span>
                <div x-flow-handle:source.right="port.id"></div>
            </div>
        </template>
        <template x-if="node.data.hasErrorPort">
            <div class="wag-port wag-port-error">
                <span>{{ __('workflows.editor.on_error') }}</span>
                <div x-flow-handle:source.right="'error'" class="wag-handle-error"></div>
            </div>
        </template>
    </div>
</div>
</template>
