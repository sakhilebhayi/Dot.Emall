@if ($errors->any())
    <x-ds.alert tone="crit" title="Something needs your attention" {{ $attributes }}>
        <ul style="margin: 0; padding-inline-start: 1.1rem;">
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </x-ds.alert>
@endif
