{{--
    The daily / weekly round-up (Phase 12).

    Alima-branded, through the shared `alima` markdown theme (config/mail.php) — this one is Alima
    talking, unlike an outreach message, which carries no Alima chrome at all.

    Every line is a translation key plus params, interpolated here rather than stored as prose: the
    app is EN/AR (ADR 0007) and the digest goes out in the team's language.
--}}
<x-mail::message>
# {{ $weekly ? __('Your week on Alima') : __('Your day on Alima') }}

{{ __('Hello :name,', ['name' => $name]) }}

{{ $weekly ? __('Here is what happened this week.') : __('Here is what happened today.') }}

<x-mail::panel>
@foreach ($lines as $line)
- {{ __($line['key'], $line['params']) }}
@endforeach
</x-mail::panel>

<x-mail::button :url="$dashboardUrl">
{{ __('Open Alima') }}
</x-mail::button>

{{ __('Let\'s grow,') }}<br>
{{ __('The Alima team') }}

<x-slot:subcopy>
{{ __('You are getting this because your round-up is switched on.') }} [{{ __('Change it in your settings') }}]({{ $settingsUrl }}).
</x-slot:subcopy>
</x-mail::message>
