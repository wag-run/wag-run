<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            @php
                $sidebarUser = auth()->user();
                $routeWorkspace = request()->route('workspace');
                $activeWorkspace = $routeWorkspace instanceof \App\Models\Workspace ? $routeWorkspace : $sidebarUser->currentWorkspace;
                $activeRole = $activeWorkspace ? $sidebarUser->roleIn($activeWorkspace) : null;
                $activeWorkspace = $activeRole ? $activeWorkspace : null;
                $switchableWorkspaces = $sidebarUser->accessibleWorkspaces();
                $isOrgAdmin = $sidebarUser->isOrgAdmin();
            @endphp

            @if ($switchableWorkspaces->isNotEmpty())
                <flux:dropdown position="bottom" align="start" class="w-full">
                    <flux:button variant="subtle" icon:trailing="chevrons-up-down" class="w-full justify-between! truncate" data-test="workspace-switcher">
                        {{ $activeWorkspace?->name ?? __('navigation.choose_a_workspace') }}
                    </flux:button>

                    <flux:menu>
                        <flux:menu.heading>{{ __('common.workspaces') }}</flux:menu.heading>
                        @foreach ($switchableWorkspaces as $switchable)
                            <flux:menu.item
                                wire:key="switch-{{ $switchable->id }}"
                                :href="route('workspaces.show', $switchable)"
                                :icon="$activeWorkspace?->is($switchable) ? 'check' : null"
                                wire:navigate
                            >
                                {{ $switchable->name }}
                            </flux:menu.item>
                        @endforeach
                        @if ($isOrgAdmin)
                            <flux:menu.separator />
                            <flux:menu.item :href="route('admin.workspaces')" icon="squares-plus" wire:navigate>
                                {{ __('navigation.manage_workspaces') }}
                            </flux:menu.item>
                        @endif
                    </flux:menu>
                </flux:dropdown>
            @endif

            <flux:sidebar.nav>
                @php($unreadNotifications = $sidebarUser->unreadNotifications()->count())
                <flux:sidebar.item icon="bell" :href="route('notifications')" :current="request()->routeIs('notifications')" :badge="$unreadNotifications ?: null" wire:navigate data-test="notifications-link">
                    {{ __('common.notifications') }}
                </flux:sidebar.item>

                @if ($activeWorkspace)
                    <flux:sidebar.group :heading="__('common.workspace')" class="grid">
                        <flux:sidebar.item icon="home" :href="route('workspaces.show', $activeWorkspace)" :current="request()->routeIs('workspaces.show')" wire:navigate>
                            {{ __('navigation.overview') }}
                        </flux:sidebar.item>
                        @if ($activeRole->can(\App\Enums\WorkspacePermission::ViewWorkflows))
                            <flux:sidebar.item icon="share" :href="route('workspaces.workflows', $activeWorkspace)" :current="request()->routeIs('workspaces.workflows*')" wire:navigate>
                                {{ __('common.workflows') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($activeRole->can(\App\Enums\WorkspacePermission::ViewExecutions))
                            <flux:sidebar.item icon="play-circle" :href="route('workspaces.executions', $activeWorkspace)" :current="request()->routeIs('workspaces.executions*')" wire:navigate>
                                {{ __('common.runs') }}
                            </flux:sidebar.item>
                        @endif
                        @php($pendingAssignments = $activeWorkspace->assignments()->where('assignee_id', $sidebarUser->id)->where('status', \App\Enums\AssignmentStatus::Pending)->count())
                        <flux:sidebar.item icon="inbox" :href="route('workspaces.inbox', $activeWorkspace)" :current="request()->routeIs('workspaces.inbox*')" :badge="$pendingAssignments ?: null" wire:navigate>
                            {{ __('common.inbox') }}
                        </flux:sidebar.item>
                        @if ($activeRole->can(\App\Enums\WorkspacePermission::ViewVariables))
                            <flux:sidebar.item icon="variable" :href="route('workspaces.variables', $activeWorkspace)" :current="request()->routeIs('workspaces.variables')" wire:navigate>
                                {{ __('common.variables') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($activeRole->can(\App\Enums\WorkspacePermission::ViewSecrets))
                            <flux:sidebar.item icon="lock-closed" :href="route('workspaces.secrets', $activeWorkspace)" :current="request()->routeIs('workspaces.secrets')" wire:navigate>
                                {{ __('common.secrets') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($activeRole->can(\App\Enums\WorkspacePermission::ViewCredentials))
                            <flux:sidebar.item icon="key" :href="route('workspaces.credentials', $activeWorkspace)" :current="request()->routeIs('workspaces.credentials')" wire:navigate>
                                {{ __('common.authentication') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($activeRole->can(\App\Enums\WorkspacePermission::ViewMembers))
                            <flux:sidebar.item icon="users" :href="route('workspaces.members', $activeWorkspace)" :current="request()->routeIs('workspaces.members')" wire:navigate>
                                {{ __('common.members') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($activeRole->can(\App\Enums\WorkspacePermission::ManageSettings))
                            <flux:sidebar.item icon="cog-6-tooth" :href="route('workspaces.settings', $activeWorkspace)" :current="request()->routeIs('workspaces.settings')" wire:navigate>
                                {{ __('common.settings') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($activeRole->can(\App\Enums\WorkspacePermission::ViewAuditLog))
                            <flux:sidebar.item icon="clipboard-document-list" :href="route('workspaces.audit', $activeWorkspace)" :current="request()->routeIs('workspaces.audit')" wire:navigate>
                                {{ __('common.audit_log') }}
                            </flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>
                @endif

                @if ($isOrgAdmin)
                    <flux:sidebar.group :heading="__('navigation.organization')" class="grid">
                        <flux:sidebar.item icon="squares-2x2" :href="route('admin.workspaces')" :current="request()->routeIs('admin.workspaces')" wire:navigate>
                            {{ __('common.workspaces') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="users" :href="route('admin.users')" :current="request()->routeIs('admin.users')" wire:navigate>
                            {{ __('common.users') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="adjustments-horizontal" :href="route('admin.settings')" :current="request()->routeIs('admin.settings')" wire:navigate>
                            {{ __('common.sign_in_and_defaults') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="finger-print" :href="route('admin.sign-in-providers')" :current="request()->routeIs('admin.sign-in-providers')" wire:navigate>
                            {{ __('common.sign_in_providers') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="envelope" :href="route('admin.email')" :current="request()->routeIs('admin.email')" wire:navigate>
                            {{ __('admin.email.title') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="clipboard-document-list" :href="route('admin.audit')" :current="request()->routeIs('admin.audit')" wire:navigate>
                            {{ __('common.audit_log') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('common.settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('common.log_out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
