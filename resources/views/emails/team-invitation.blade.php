<x-mail::message>
# You're invited to {{ $teamName }}

@if ($inviterName)
{{ $inviterName }} has invited you to join **{{ $teamName }}** on Alima as **{{ $roleLabel }}**.
@else
You've been invited to join **{{ $teamName }}** on Alima as **{{ $roleLabel }}**.
@endif

Alima helps teams find, qualify and reach the right prospects — together. Accept your invitation to get started.

<x-mail::button :url="$acceptUrl">
Accept invitation
</x-mail::button>

This invitation link expires in a few days. If you weren't expecting it, you can safely ignore this email.

Let's grow,<br>
The Alima team
</x-mail::message>
