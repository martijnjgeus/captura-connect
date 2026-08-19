<?php

namespace App\Http\Controllers;

use App\Models\IntegrationLog;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class IntegrationLogController extends Controller
{
    public function index(Request $request): Factory|View|\Illuminate\View\View
    {
        $selectedType   = $request->string('type')->toString();
        $selectedSource = $request->string('source')->toString();
        $selectedStatus = $request->string('status')->toString();

        $logs = IntegrationLog::query()
            ->when($selectedType !== '', function ($query) use ($selectedType) {
                $query->where('type', $selectedType);
            })
            ->when($selectedSource !== '', function ($query) use ($selectedSource) {
                $query->where('source', $selectedSource);
            })
            ->when($selectedStatus !== '', function ($query) use ($selectedStatus) {
                $query->where('status', $selectedStatus);
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $types = IntegrationLog::query()
            ->select('type')
            ->whereNotNull('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type');

        $sources = IntegrationLog::query()
            ->select('source')
            ->whereNotNull('source')
            ->distinct()
            ->orderBy('source')
            ->pluck('source');

        $statuses = IntegrationLog::query()
            ->select('status')
            ->whereNotNull('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');

        return view('logs.index', [
            'logs'           => $logs,
            'types'          => $types,
            'sources'        => $sources,
            'statuses'       => $statuses,
            'selectedType'   => $selectedType,
            'selectedSource' => $selectedSource,
            'selectedStatus' => $selectedStatus,
        ]);
    }

    public function show(IntegrationLog $integrationLog)
    {
        return view('logs.show', [
            'log' => $integrationLog,
        ]);
    }
}
