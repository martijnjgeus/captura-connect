@props([
    'status',
])

@php
    $classes = match ($status) {
        'completed' => 'bg-green-100 text-green-800 ring-green-600/20 dark:bg-green-500/10 dark:text-green-300 dark:ring-green-400/30',
        'completed_with_errors' => 'bg-amber-100 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/30',

        'failed',
        'validation_failed' => 'bg-red-100 text-red-800 ring-red-600/20 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-400/30',

        'started',
        'received',
        'validated',
        'sent',
        'products_read',
        'afas_response_received' => 'bg-blue-100 text-blue-800 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-400/30',

        default => 'bg-gray-100 text-gray-800 ring-gray-600/20 dark:bg-gray-500/10 dark:text-gray-300 dark:ring-gray-400/30',
    };

    $label = str($status)->replace('_', ' ')->title();
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset '.$classes,
]) }}>
    {{ $label }}
</span>
