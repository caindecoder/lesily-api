<x-mail::message>
{{ __('notifications.'.$type->value.'.body', [...$details, 'company' => $companyName]) }}
</x-mail::message>
