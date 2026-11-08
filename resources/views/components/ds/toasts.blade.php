{{--
  ds.toasts: the polite live region at the bottom of every layout. Session flash messages ('status', 'success', 'error') become toasts;
  scripts add more with window.emallToast(message, 'pos' | 'crit'). Place it once per page (the layouts do).
--}}
<div class="ds-toasts" aria-live="polite" aria-atomic="false">
    @php($flashes = array_filter(['pos' => session('status') ?? session('success'), 'crit' => session('error')]))
    @foreach ($flashes as $tone => $message)
        @if (is_string($message) && $message !== '')
            <div class="ds-toast {{ $tone === 'crit' ? 'ds-toast--crit' : '' }}" role="{{ $tone === 'crit' ? 'alert' : 'status' }}" @if ($tone !== 'crit') data-autodismiss @endif>
                <x-ds.icon :name="$tone === 'crit' ? 'x-octagon' : 'check-circle'" :size="20" />
                <span>{{ $message }}</span>
                <button type="button" class="ds-toast__close" data-toast-close aria-label="Dismiss message"><x-ds.icon name="close" :size="18" /></button>
            </div>
        @endif
    @endforeach
</div>
