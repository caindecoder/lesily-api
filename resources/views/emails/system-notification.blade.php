<x-mail::message>
{{ __('notifications.'.$type->value.'.body', ['company' => $companyName]) }}
</x-mail::message>
