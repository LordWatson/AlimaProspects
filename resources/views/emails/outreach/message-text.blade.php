{{-- The plain-text part. Many cold-email recipients (and most spam filters) read this one. --}}
{{ trim($body) }}

--
{{ $senderName }}@if ($senderWebsite) · {{ $senderWebsite }}@endif

@if ($unsubscribeUrl)
{{ __('Would you rather not hear from us? Open :link, or reply with "unsubscribe", and we will not contact you again.', ['link' => $unsubscribeUrl]) }}
@else
{{ __('Would you rather not hear from us? Reply with "unsubscribe", or email :address, and we will not contact you again.', ['address' => $optOutAddress]) }}
@endif
@if ($privacyNoticeUrl)
{{ __('Privacy notice') }}: {{ $privacyNoticeUrl }}
@endif
{{ __('Sent via Alima on behalf of :sender.', ['sender' => $senderName]) }}
