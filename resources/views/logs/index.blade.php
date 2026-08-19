@extends('layouts.app', [
    'title' => 'Integration logs',
    'heading' => 'Integration logs',
])

@section('content')
    <div class="space-y-6">
        <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-900 dark:ring-1 dark:ring-gray-800">
            <form method="get" action="{{ route('logs.index') }}" class="grid gap-4 md:grid-cols-4">
                <div>
                    <label for="type">Type</label>

                    <select id="type" name="type" class="mt-1">
                        <option value="">All types</option>

                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected($selectedType === $type)>
                                {{ str($type)->replace('_', ' ')->title() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="source">Source</label>

                    <select id="source" name="source" class="mt-1">
                        <option value="">All sources</option>

                        @foreach ($sources as $source)
                            <option value="{{ $source }}" @selected($selectedSource === $source)>
                                {{ str($source)->replace('_', ' ')->title() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="status">Status</label>

                    <select id="status" name="status" class="mt-1">
                        <option value="">All statuses</option>

                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected($selectedStatus === $status)>
                                {{ str($status)->replace('_', ' ')->title() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-end gap-2">
                    <button
                        type="submit"
                        class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-500"
                    >
                        Filter
                    </button>

                    <a
                        href="{{ route('logs.index') }}"
                        class="rounded-md bg-gray-700 px-4 py-2 text-sm font-medium text-white hover:bg-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:ring-1 dark:ring-gray-700 dark:hover:bg-gray-700"
                    >
                        Reset
                    </a>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-900 dark:ring-1 dark:ring-gray-800">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800">
                <thead class="bg-gray-50 dark:bg-gray-900">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">ID</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Source</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Read</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Updated</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Failed</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">HTTP</th>
                    <th class="px-4 py-3"></th>
                </tr>
                </thead>

                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-800 dark:bg-gray-900">
                @forelse ($logs as $log)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{{ $log->id }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{{ $log->type }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-900 dark:text-gray-100">{{ $log->source }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm">
                            <x-status-badge :status="$log->status" />
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-100">{{ $log->products_read }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-100">{{ $log->products_updated }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-100">{{ $log->updates_failed }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-900 dark:text-gray-100">{{ $log->http_status }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                            <a href="{{ route('logs.show', $log) }}" class="font-medium text-blue-700 underline dark:text-blue-300">
                                View
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                            No logs found.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="dark:text-gray-100">
            {{ $logs->links('pagination.dark') }}
        </div>
    </div>
@endsection
