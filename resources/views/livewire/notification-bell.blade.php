{{-- The notification bell (Livewire). A disclosure: the button toggles a panel of the latest ten notices. Opens with Enter or Space, closes with Esc or a click outside. --}}
@php($badge = $this->unreadCount > 99 ? '99+' : (string) $this->unreadCount)
<div class="ds-dropdown" x-data @click.outside="$wire.open = false" @keydown.escape.window="$wire.open = false" style="display: inline-block;">
    <button wire:click="toggle" type="button" class="ds-icon-btn" aria-label="Notifications{{ $this->unreadCount > 0 ? ', '.$badge.' unread' : '' }}" aria-haspopup="true" aria-expanded="{{ $open ? 'true' : 'false' }}">
        <x-ds.icon name="bell" :size="22" />
        @if ($this->unreadCount > 0)<span class="ds-count" style="position: absolute; inset-block-start: 0.2rem; inset-inline-end: 0.1rem;" aria-hidden="true">{{ $badge }}</span>@endif
    </button>

    @if ($open)
        <div class="ds-dropdown__panel" style="width: min(24rem, calc(100vw - 1.5rem));" role="region" aria-label="Notifications">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: var(--s-3); padding: var(--s-2) var(--s-3);">
                <h2 style="font-size: var(--text-base);">Notifications</h2>
                @if ($this->unreadCount > 0)<button wire:click="markAllAsRead" type="button" class="ds-btn ds-btn--link ds-btn--sm">Mark all read</button>@endif
            </div>
            <hr class="ds-dropdown__sep">
            @if ($this->notifications->isEmpty())
                <p class="ds-dropdown__note">No notifications yet.</p>
            @else
                <ul style="list-style: none; margin: 0; padding: 0; max-height: 22rem; overflow-y: auto;">
                    @foreach ($this->notifications as $notification)
                        <li>
                            <button type="button" class="ds-dropdown__item" style="align-items: flex-start; flex-direction: column; gap: 2px; {{ $notification->read_at ? 'opacity: 0.7;' : '' }}" wire:click="markAsRead('{{ $notification->id }}')">
                                <span style="font-weight: {{ $notification->read_at ? 500 : 700 }};">@unless ($notification->read_at)<span class="ds-visually-hidden">Unread: </span>@endunless{{ $notification->data['title'] ?? 'Notification' }}</span>
                                <span class="ds-muted" style="font-size: var(--text-sm);">{{ $notification->data['message'] ?? '' }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
