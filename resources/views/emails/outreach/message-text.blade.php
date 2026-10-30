{{-- The plain-text part. Many cold-email recipients (and most spam filters) read this one. --}}
{{ trim($body) }}

--
{{ $senderName }}@if ($senderWebsite) · {{ $senderWebsite }}@endif

{{ __('Would you rather not hear from us?') }} {{ __('Reply with "unsubscribe", or email :address, and we will not contact you again.', ['address' => $optOutAddress]) }}
@if ($privacyNoticeUrl)
{{ __('Privacy notice') }}: {{ $privacyNoticeUrl }}
@endif
{{ __('Sent via Alima on behalf of :sender.', ['sender' => $senderName]) }}
