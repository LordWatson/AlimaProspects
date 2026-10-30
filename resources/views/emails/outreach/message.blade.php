{{--
    An outreach message, sent on a team's behalf (Phase 11).

    No Alima branding and no design system: this is the team's own message to their own prospect,
    and Alima's chrome on it would misattribute the sender (see OutreachMessageMail). Styling is
    the minimum that makes plain text readable in a mail client — a system font stack, a sensible
    measure, and a quiet footer rule. Arabic sets RTL and a little larger, per the brand's Arabic
    rule, which is the only brand instruction that applies to someone else's words.

    The footer is required: sender identity + opt-out + privacy notice (docs/compliance.md §3.3).
--}}
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $senderName }}</title>
</head>
<body style="margin:0; padding:24px; background:#ffffff;">
<div style="max-width:560px; margin:0 auto; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif; font-size:{{ $isRtl ? '17px' : '15px' }}; line-height:1.6; color:#181723; text-align:{{ $isRtl ? 'right' : 'left' }};">

    @foreach (preg_split('/\R{2,}/', trim($body)) as $paragraph)
        <p style="margin:0 0 16px;">{!! nl2br(e(trim($paragraph))) !!}</p>
    @endforeach

    <hr style="border:none; border-top:1px solid #dddbd4; margin:28px 0 12px;">

    <p style="margin:0 0 6px; font-size:12px; line-height:1.5; color:#6b6977;">
        {{ $senderName }}@if ($senderWebsite) &middot; <a href="{{ \Illuminate\Support\Str::startsWith($senderWebsite, ['http://', 'https://']) ? $senderWebsite : 'https://'.$senderWebsite }}" style="color:#6b6977;">{{ $senderWebsite }}</a>@endif
    </p>

    <p style="margin:0; font-size:12px; line-height:1.5; color:#6b6977;">
        {{--
            The opt-out. Unambiguous, and **one whole sentence with a :link placeholder** rather
            than a link glued to a fragment: a fragment forces English word order and punctuation
            on the translator, and the plain-text part had already drifted to a different key.
        --}}
        {!! __('Would you rather not hear from us? :link and we will not contact you again.', [
            'link' => '<a href="mailto:'.e($optOutAddress).'?subject=unsubscribe" style="color:#6b6977;">'.e(__('Unsubscribe')).'</a>',
        ]) !!}
        @if ($privacyNoticeUrl)
            <a href="{{ $privacyNoticeUrl }}" style="color:#6b6977;">{{ __('Privacy notice') }}</a>.
        @endif
    </p>

    {{--
        Who transmitted it, which the recipient is entitled to know — so it is set at the same
        12px and the same 5.4:1 colour as the rest of the footer. It was 11px #9d9a99 (about
        2.7:1), which failed AA on the one line the law is interested in.
    --}}
    <p style="margin:6px 0 0; font-size:12px; line-height:1.5; color:#6b6977;">
        {{ __('Sent via Alima on behalf of :sender.', ['sender' => $senderName]) }}
    </p>
</div>
</body>
</html>
