<x-mail::message>
{{ __(':company has invited you to join their team on Lesily.', ['company' => $companyName]) }}

<x-mail::button :url="$acceptUrl">
{{ __('Accept invitation') }}
</x-mail::button>

{{ __('This invitation expires on :date.', ['date' => $expiresAt->toFormattedDateString()]) }}

{{ __('If you were not expecting this invitation, you can safely ignore this email.') }}

{{ config('app.name') }}
</x-mail::message>
