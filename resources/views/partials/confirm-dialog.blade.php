{{--
    The app's one confirmation dialog, opened from Alpine with
    $confirm({ title, message, confirm, danger }), which resolves to true
    when the person confirms. Replaces the browser's native confirm().
--}}
<flux:modal name="confirm-dialog" class="md:w-md" x-data x-on:close="$store.confirmDialog.settle(false)" data-test="confirm-dialog">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg" x-text="$store.confirmDialog.title || @js(__('common.are_you_sure'))"></flux:heading>
            <flux:text class="mt-2" x-show="$store.confirmDialog.message" x-text="$store.confirmDialog.message"></flux:text>
        </div>
        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" x-on:click="$store.confirmDialog.settle(false); $flux.modal('confirm-dialog').close()" data-test="confirm-dialog-cancel">
                {{ __('common.cancel') }}
            </flux:button>
            <flux:button variant="danger" x-show="$store.confirmDialog.danger" x-on:click="$store.confirmDialog.settle(true); $flux.modal('confirm-dialog').close()" data-test="confirm-dialog-confirm">
                <span x-text="$store.confirmDialog.confirmLabel || @js(__('common.confirm'))"></span>
            </flux:button>
            <flux:button variant="primary" x-show="! $store.confirmDialog.danger" x-on:click="$store.confirmDialog.settle(true); $flux.modal('confirm-dialog').close()" data-test="confirm-dialog-confirm-safe">
                <span x-text="$store.confirmDialog.confirmLabel || @js(__('common.confirm'))"></span>
            </flux:button>
        </div>
    </div>
</flux:modal>
