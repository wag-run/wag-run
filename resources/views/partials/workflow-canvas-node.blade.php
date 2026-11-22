{{--
    The Alpine template for one node on the WireFlow canvas, shared by the
    editor and the run view. node.data comes from CanvasMapper; the run view
    adds status and statusLabel.
--}}
<div class="wag-node" :class="{ 'is-trigger': node.data.isTrigger, 'is-unknown': node.data.unknown, ['run-' + node.data.status]: !! node.data.status }">
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
