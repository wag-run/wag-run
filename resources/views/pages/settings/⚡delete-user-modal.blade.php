<?php

use App\Livewire\Actions\Logout;
use App\Models\Organization;
use App\Services\UserDirectory;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout, UserDirectory $directory): void
    {
        abort_unless(Organization::allowsAccountDeletion(), 403);

        $directory->ensureNotLastAdmin(Auth::user(), __('settings.delete_user_modal.you_are_the_last_organization'));

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form method="POST" wire:submit="deleteUser" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('settings.delete_user_modal.are_you_sure_you_want') }}</flux:heading>

            <flux:subheading>
                {{ __('settings.delete_user_modal.once_your_account_is_deleted') }}
            </flux:subheading>
        </div>

        <flux:error name="user" />

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('common.cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="danger" type="submit" data-test="confirm-delete-user-button">
                {{ __('common.delete_account') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
