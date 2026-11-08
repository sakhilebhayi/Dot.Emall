@component('mail::message')
# {{ __('You have been invited to join :team', ['team' => $invitation->team->name]) }}

@if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::registration()))
{{ __('If you do not have an account, you can create one with the button below. Then use the second button to accept the invitation.') }}

@component('mail::button', ['url' => route('register')])
{{ __('Create an account') }}
@endcomponent

{{ __('If you already have an account, accept the invitation with the button below.') }}

@else
{{ __('Accounts come from InfoDot. Sign in with your InfoDot account, then accept the invitation with the button below.') }}
@endif

@component('mail::button', ['url' => $acceptUrl])
{{ __('Accept the invitation') }}
@endcomponent

{{ __('If you did not expect this invitation, you can ignore this email.') }}
@endcomponent
