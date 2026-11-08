<x-mail::message>
# Confirm your privacy request

We received a request through Alima: **{{ $requestLabel }}**.

Before we act on it, we need to know the request came from you. Nothing has happened yet.

<x-mail::button :url="$verifyUrl">
Confirm my request
</x-mail::button>

Once confirmed, we will act on it and you will hear from us within one month.

This link works for {{ $days }} days. **If you did not make this request, ignore this email** — without
your confirmation nothing will be done, and you can let us know at
{{ config('compliance.privacy_notice.contact_email') }}.

Thank you,<br>
The Alima team
</x-mail::message>
