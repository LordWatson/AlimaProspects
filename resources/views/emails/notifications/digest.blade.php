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

@if (count($highlights) > 0)
{{--
    The one part of the digest that names something rather than counting it (S15 task 1).

    **The approach note goes with the headline or the headline does not go.** This email is read
    outside the app, where no screen can warn somebody that a UAE prospect needs consent before a
    cold email (docs/compliance.md §3.11) — so the line travels with the story it is about.

    The headline is the article's own words and the only prose in the payload; everything Alima
    says about it is a translation key, interpolated here under the team's locale.
--}}
## {{ __('Worth a look') }}

@foreach ($highlights as $highlight)
{{-- `<bdi>` round the headline: it is the article's own words, so an English headline inside an
     Arabic paragraph would otherwise drag its punctuation to the wrong end. Two trailing spaces
     are a markdown line break — without them the event line joins the headline into one
     paragraph (brand review). --}}
**[<bdi>{{ $highlight['headline'] }}</bdi>]({{ $signalsUrl }})**  
{{ __($highlight['event_label']) }}@if ($highlight['heat'] !== null) · {{ __('How hot') }} {{ $highlight['heat'] }}/100@endif

@if ($highlight['approach_note'] !== null)
{{-- The guidance carries its own lead-in **in the sentence**, not in the styling: a client that
     strips italics, or a reader skimming, would otherwise see an unlabelled sentence where the
     whole point is that it is a caution. --}}
_{{ __('Before you reach out:') }} {{ __($highlight['approach_note']) }}_
@endif
@endforeach
@endif

<x-mail::button :url="$dashboardUrl">
{{ __('Open Alima') }}
</x-mail::button>

{{ __('Let\'s grow,') }}<br>
{{ __('The Alima team') }}

<x-slot:subcopy>
{{ __('You are getting this because your round-up is switched on.') }} [{{ __('Change it in your settings') }}]({{ $settingsUrl }}).
</x-slot:subcopy>
</x-mail::message>
