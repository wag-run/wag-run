<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        @include('partials.sidebar-width')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar
            sticky
            collapsible="true"
            class="wag-noise border-e border-zinc-200 bg-zinc-50 lg:not-data-flux-sidebar-collapsed-desktop:w-[var(--wag-sidebar-width,16rem)] dark:border-zinc-700 dark:bg-zinc-900"
            data-test="sidebar"
        >
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
                    <flux:button
                        variant="subtle"
                        class="w-full justify-between! truncate in-data-flux-sidebar-collapsed-desktop:w-10 in-data-flux-sidebar-collapsed-desktop:justify-center! in-data-flux-sidebar-collapsed-desktop:px-0!"
                        :aria-label="$activeWorkspace?->name ?? __('navigation.choose_a_workspace')"
                        data-test="workspace-switcher"
                    >
                        <span class="truncate in-data-flux-sidebar-collapsed-desktop:hidden">{{ $activeWorkspace?->name ?? __('navigation.choose_a_workspace') }}</span>
                        @if ($activeWorkspace)
                            <span class="hidden size-6 items-center justify-center rounded-md bg-zinc-200 text-xs font-semibold text-zinc-800 in-data-flux-sidebar-collapsed-desktop:flex dark:bg-zinc-700 dark:text-white" aria-hidden="true" data-test="workspace-initials">{{ $activeWorkspace->initials() }}</span>
                        @endif
                        <flux:icon.chevrons-up-down variant="micro" @class(['shrink-0', 'in-data-flux-sidebar-collapsed-desktop:hidden' => $activeWorkspace]) />
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

            @php($unreadNotifications = $sidebarUser->unreadNotifications()->count())

            <flux:sidebar.nav>
                @if ($activeWorkspace)
                    <div class="px-3 py-2 text-sm leading-none font-medium text-zinc-400 in-data-flux-sidebar-collapsed-desktop:hidden">{{ __('common.workspace') }}</div>
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
                    @if ($activeRole->can(\App\Enums\WorkspacePermission::ViewDataStore))
                        <flux:sidebar.item icon="circle-stack" :href="route('workspaces.data-store', $activeWorkspace)" :current="request()->routeIs('workspaces.data-store')" wire:navigate>
                            {{ __('common.data_store') }}
                        </flux:sidebar.item>
                    @endif
                    @if ($activeRole->can(\App\Enums\WorkspacePermission::ViewSecrets))
                        <flux:sidebar.item icon="lock-closed" :href="route('workspaces.secrets', $activeWorkspace)" :current="request()->routeIs('workspaces.secrets')" wire:navigate>
                            {{ __('common.secrets') }}
                        </flux:sidebar.item>
                    @endif
                    @if ($activeRole->can(\App\Enums\WorkspacePermission::ViewCredentials))
                        <flux:sidebar.item icon="key" :href="route('workspaces.credentials', $activeWorkspace)" :current="request()->routeIs('workspaces.credentials')" wire:navigate>
                            {{ __('common.credentials') }}
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
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" :unread="$unreadNotifications" />
        </flux:sidebar>

        <div
            x-data="wagSidebarResize"
            role="separator"
            aria-orientation="vertical"
            tabindex="0"
            aria-label="{{ __('navigation.resize_sidebar') }}"
            :aria-valuenow="width"
            :aria-valuemin="minWidth"
            :aria-valuemax="maxWidth"
            x-on:pointerdown="start"
            x-on:pointermove="move"
            x-on:pointerup="end"
            x-on:pointercancel="end"
            x-on:dblclick="toggle"
            x-on:keydown="key"
            class="wag-sidebar-handle group/resize fixed inset-y-0 z-10 hidden w-3 cursor-col-resize touch-none focus:outline-hidden lg:block"
            :data-dragging="dragging"
            data-test="sidebar-resize-handle"
        >
            <div class="absolute inset-y-0 start-1/2 w-0.5 -translate-x-1/2 transition-colors group-hover/resize:bg-zinc-400 group-focus-visible/resize:bg-zinc-400 group-data-[dragging=true]/resize:bg-zinc-400 dark:group-hover/resize:bg-zinc-500 dark:group-focus-visible/resize:bg-zinc-500 dark:group-data-[dragging=true]/resize:bg-zinc-500"></div>
            <flux:tooltip :content="__('navigation.double_click_to_toggle_sidebar')" position="right" class="absolute start-1/2 top-1/2 -translate-1/2" x-bind:disabled="dragging">
                <div class="h-10 w-2 rounded-full border border-zinc-400 bg-white opacity-0 transition-opacity group-hover/resize:opacity-100 group-focus-visible/resize:opacity-100 group-data-[dragging=true]/resize:opacity-100 dark:border-zinc-500 dark:bg-zinc-800"></div>
            </flux:tooltip>
        </div>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                    :avatar:badge="$unreadNotifications > 0"
                    avatar:badge:color="red"
                    avatar:badge:circle
                    avatar:badge:position="top right"
                    :aria-label="$unreadNotifications ? trans_choice('navigation.account_menu_unread', $unreadNotifications, ['count' => $unreadNotifications]) : null"
                    data-test="mobile-user-menu-button"
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
                        @include('partials.inbox-menu-item', ['unread' => $unreadNotifications])
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('navigation.preferences') }}
                        </flux:menu.item>
                        @if ($isOrgAdmin)
                            <flux:menu.item :href="route('admin.workspaces')" icon="building-office" wire:navigate data-test="org-settings-menu-item">
                                {{ __('navigation.org_settings') }}
                            </flux:menu.item>
                        @endif
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

        @include('partials.confirm-dialog')

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
