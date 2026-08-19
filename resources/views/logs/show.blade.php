@extends('layouts.app', [
    'title' => 'Log '.$log->id,
    'heading' => 'Log '.$log->id,
])

@section('content')
    @php
        $prettyJson = static function ($value): string {
            $json = json_encode(
                $value,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_PARTIAL_OUTPUT_ON_ERROR
            );

            if ($json !== false) {
                return $json;
            }

            return print_r($value, true);
        };
    @endphp
    <div class="space-y-6">
        <div>
            <a
                href="{{ route('logs.index') }}"
                class="inline-flex items-center text-sm font-medium text-blue-700 underline hover:text-blue-900 dark:text-blue-300 dark:hover:text-blue-200"
            >
                ← Back to logs
            </a>
        </div>

        <div class="rounded-lg bg-white p-6 shadow dark:bg-gray-900 dark:ring-1 dark:ring-gray-800">
            <div class="grid gap-5 md:grid-cols-3">
                @foreach ([
                    'ID' => $log->id,
                    'Date' => $log->created_at?->format('Y-m-d H:i:s'),
                    'Type' => $log->type,
                    'Source' => $log->source,
                    'HTTP status' => $log->http_status ?: '-',
                    'Products read' => $log->products_read,
                    'Products updated' => $log->products_updated,
                    'Failed updates' => $log->updates_failed,
                ] as $label => $value)
                    <div class="rounded-md bg-gray-50 p-4 dark:bg-gray-950 dark:ring-1 dark:ring-gray-800">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ $label }}
                        </dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ $value }}
                        </dd>
                    </div>
                @endforeach

                <div class="rounded-md bg-gray-50 p-4 dark:bg-gray-950 dark:ring-1 dark:ring-gray-800">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Status
                    </dt>
                    <dd class="mt-2">
                        <x-status-badge :status="$log->status" />
                    </dd>
                </div>
            </div>

            @if ($log->error_message)
                <div class="mt-5 rounded-md bg-red-50 p-4 text-sm text-red-800 ring-1 ring-red-600/20 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-400/30">
                    <div class="font-semibold">Error message</div>
                    <div class="mt-1">{{ $log->error_message }}</div>
                </div>
            @endif
        </div>

        @foreach ([
            'Received body' => $log->received_body,
            'Validation result' => $log->validation_result,
            'Sent body' => $log->sent_body,
            'Result' => $log->result_body,
        ] as $title => $body)
            @php
                $hasBody = ! is_null($body);
                $url = is_array($body) ? data_get($body, 'url') : null;
            @endphp

            <details
                @if ($hasBody) open @endif
            class="group overflow-hidden rounded-lg bg-white shadow dark:bg-gray-900 dark:ring-1 dark:ring-gray-800"
            >
                <summary class="flex cursor-pointer select-none items-center justify-between border-b border-gray-200 px-6 py-4 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/60">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                            {{ $title }}
                        </h2>

                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $hasBody ? 'Content available' : 'No content' }}
                        </p>
                    </div>

                    <span class="ml-4 text-sm text-gray-500 transition group-open:rotate-180 dark:text-gray-400">
                        ▼
                    </span>
                </summary>

                <div class="space-y-4 p-6">
                    @if ($url)
                        <div class="rounded-md bg-gray-50 p-4 dark:bg-gray-950 dark:ring-1 dark:ring-gray-800">
                            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                URL
                            </div>

                            <div class="mt-2 break-all font-mono text-sm text-gray-900 dark:text-gray-100">
                                {{ $url }}
                            </div>
                        </div>
                    @endif

                    @if ($title === 'Result' && is_array($body))
                        <div class="grid gap-4 md:grid-cols-4">
                            @foreach ([
                                'Dry run' => data_get($body, 'dry_run') ? 'Yes' : 'No',
                                'Lines built' => data_get($body, 'lines_built', 0),
                                'AFAS attempted' => data_get($body, 'afas_attempted', 0),
                                'AFAS failed' => data_get($body, 'afas_failed', 0),
                            ] as $label => $value)
                                <div class="rounded-md bg-gray-50 p-4 dark:bg-gray-950 dark:ring-1 dark:ring-gray-800">
                                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ $label }}
                                    </div>
                                    <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                        {{ $value }}
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @if (! empty($body['afas_failed_chunks']))
                            <div class="overflow-hidden rounded-md border border-red-200 dark:border-red-900">
                                <div class="bg-red-50 px-4 py-3 text-sm font-semibold text-red-800 dark:bg-red-500/10 dark:text-red-300">
                                    AFAS failed chunks
                                </div>

                                <div class="divide-y divide-red-100 dark:divide-red-900">
                                    @foreach ($body['afas_failed_chunks'] as $chunk)
                                        <details class="p-4" open>
                                            <summary class="cursor-pointer text-sm font-semibold text-gray-900 dark:text-gray-100">
                                                Chunk {{ $chunk['chunk_index'] ?? '-' }}
                                                —
                                                Status {{ $chunk['status'] ?? '-' }}
                                                —
                                                {{ $chunk['lines_count'] ?? 0 }} lines
                                            </summary>

                                            <div class="mt-4 space-y-4">
                                                @if (! empty($chunk['url']))
                                                    <div>
                                                        <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">URL</div>
                                                        <div class="mt-1 break-all font-mono text-sm text-gray-900 dark:text-gray-100">
                                                            {{ $chunk['url'] }}
                                                        </div>
                                                    </div>
                                                @endif

                                                <div>
                                                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">AFAS response</div>
                                                    <pre class="mt-1 max-h-96 overflow-auto rounded-md bg-gray-950 p-4 text-xs text-gray-100"><code>{{ $prettyJson($chunk['response_body'] ?? $chunk['response_raw'] ?? null) }}</code></pre>
                                                </div>

                                                <div>
                                                    <div class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Sample request lines</div>
                                                    <pre class="mt-1 max-h-96 overflow-auto rounded-md bg-gray-950 p-4 text-xs text-gray-100"><code>{{ $prettyJson($chunk['sample_lines'] ?? []) }}</code></pre>
                                                </div>
                                            </div>
                                        </details>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif
                    <div class="overflow-hidden rounded-md bg-gray-950 ring-1 ring-gray-800">
                        <pre class="max-h-[600px] overflow-auto p-6 text-sm leading-6 text-gray-100"><code>{{ $prettyJson($body) }}</code></pre>
                    </div>
                </div>
            </details>
        @endforeach
    </div>
@endsection
