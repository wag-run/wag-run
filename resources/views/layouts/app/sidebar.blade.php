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
                $isInstallAdmin = $sidebarUser->isInstallAdmin();
            @endphp

            @if ($switchableWorkspaces->isNotEmpty())
                <flux:dropdown position="bottom" align="start" class="w-full">
                    <flux:button variant="subtle" icon:trailing="chevrons-up-down" class="w-full justify-between! truncate" data-test="workspace-switcher">
                        {{ $activeWorkspace?->name ?? __('Choose a workspace') }}
                    </flux:button>

                    <flux:menu>
                        <flux:menu.heading>{{ __('Workspaces') }}</flux:menu.heading>
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
                        @if ($isInstallAdmin)
                            <flux:menu.separator />
                            <flux:menu.item :href="route('admin.workspaces')" icon="squares-plus" wire:navigate>
                                {{ __('Manage workspaces') }}
                            </flux:menu.item>
                        @endif
                    </flux:menu>
                </flux:dropdown>
            @endif

            <flux:sidebar.nav>
                @if ($activeWorkspace)
                    <flux:sidebar.group :heading="__('Workspace')" class="grid">
                        <flux:sidebar.item icon="home" :href="route('workspaces.show', $activeWorkspace)" :current="request()->routeIs('workspaces.show')" wire:navigate>
                            {{ __('Overview') }}
                        </flux:sidebar.item>
                        @if ($activeRole->can(\App\Enums\WorkspacePermission::ViewWorkflows))
                            <flux:sidebar.item icon="share" :href="route('workspaces.workflows', $activeWorkspace)" :current="request()->routeIs('workspaces.workflows*')" wire:navigate>
                                {{ __('Workflows') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($activeRole->can(\App\Enums\WorkspacePermission::ViewVariables))
                            <flux:sidebar.item icon="variable" :href="route('workspaces.variables', $activeWorkspace)" :current="request()->routeIs('workspaces.variables')" wire:navigate>
                                {{ __('Variables') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($activeRole->can(\App\Enums\WorkspacePermission::ViewSecrets))
                            <flux:sidebar.item icon="lock-closed" :href="route('workspaces.secrets', $activeWorkspace)" :current="request()->routeIs('workspaces.secrets')" wire:navigate>
                                {{ __('Secrets') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($activeRole->can(\App\Enums\WorkspacePermission::ViewCredentials))
                            <flux:sidebar.item icon="key" :href="route('workspaces.credentials', $activeWorkspace)" :current="request()->routeIs('workspaces.credentials')" wire:navigate>
                                {{ __('Authentication') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($activeRole->can(\App\Enums\WorkspacePermission::ViewMembers))
                            <flux:sidebar.item icon="users" :href="route('workspaces.members', $activeWorkspace)" :current="request()->routeIs('workspaces.members')" wire:navigate>
                                {{ __('Members') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($activeRole->can(\App\Enums\WorkspacePermission::ManageSettings))
                            <flux:sidebar.item icon="cog-6-tooth" :href="route('workspaces.settings', $activeWorkspace)" :current="request()->routeIs('workspaces.settings')" wire:navigate>
                                {{ __('Settings') }}
                            </flux:sidebar.item>
                        @endif
                        @if ($activeRole->can(\App\Enums\WorkspacePermission::ViewAuditLog))
                            <flux:sidebar.item icon="clipboard-document-list" :href="route('workspaces.audit', $activeWorkspace)" :current="request()->routeIs('workspaces.audit')" wire:navigate>
                                {{ __('Audit log') }}
                            </flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>
                @endif

                @if ($isInstallAdmin)
                    <flux:sidebar.group :heading="__('Install')" class="grid">
                        <flux:sidebar.item icon="squares-2x2" :href="route('admin.workspaces')" :current="request()->routeIs('admin.workspaces')" wire:navigate>
                            {{ __('Workspaces') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="users" :href="route('admin.users')" :current="request()->routeIs('admin.users')" wire:navigate>
                            {{ __('Users') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="adjustments-horizontal" :href="route('admin.settings')" :current="request()->routeIs('admin.settings')" wire:navigate>
                            {{ __('Sign-in & defaults') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="clipboard-document-list" :href="route('admin.audit')" :current="request()->routeIs('admin.audit')" wire:navigate>
                            {{ __('Audit log') }}
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
                            {{ __('Settings') }}
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
                            {{ __('Log out') }}
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
