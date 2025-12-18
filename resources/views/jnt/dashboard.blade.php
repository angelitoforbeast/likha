{{-- resources/views/jnt/dashboard.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>JNT Dashboard</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

    @php
        use Illuminate\Support\Carbon;
        $filters = $currentFilters ?? [];
        $q = request('q', '');
    @endphp

    <style>
        table {
            table-layout: fixed;
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            border: 1px solid #e5e7eb;
            padding: 0.25rem 0.35rem;
            font-size: 0.7rem;
            vertical-align: middle;
        }

        tbody .cell-content {
            display: block;
            width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        thead .cell-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.25rem;
            width: 100%;
        }

        .data-row { cursor: pointer; }
        .data-row:hover { background-color: #f3f4f6; }

        .data-row.expanded .cell-content {
            white-space: normal;
            overflow: visible;
            text-overflow: unset;
        }

        thead th {
            background-color: #f9fafb;
            position: sticky;
            top: 0;
            z-index: 5;
        }

        .table-wrapper {
            max-height: calc(100vh - 300px);
            overflow-y: auto;
        }

        /* column widths tuned to sample */
        .col-submission_time { width: 80px; }
        .col-waybill_number  { width: 95px; }
        .col-receiver        { width: 100px; }
        .col-receiver_cellphone { width: 70px; }
        .col-sender          { width: 130px; }
        .col-item_name       { width: 150px; }

        .col-cod, .col-total_shipping_cost { width: 45px; text-align: right; }

        .col-status     { width: 90px; }
        .col-rts_reason { width: 110px; }

        .col-province, .col-city, .col-barangay { width: 110px; }

        .col-remarks     { width: 150px; }
        .col-status_logs { width: 320px; }

        .col-signingtime, .col-created_at, .col-updated_at { width: 130px; }

        /* default hidden columns */
        .col-remarks,
        .col-province,
        .col-city,
        .col-barangay,
        .col-total_shipping_cost,
        .col-rts_reason,
        .col-created_at,
        .col-updated_at {
            display: none;
        }

        .filter-btn {
            font-size: 0.55rem;
            line-height: 1;
            padding: 0 2px;
            border-radius: 3px;
            border: 1px solid transparent;
            cursor: pointer;
        }
        .filter-btn:hover {
            border-color: #d1d5db;
            background-color: #e5e7eb;
        }
        .filter-btn.filter-btn-active {
            border-color: #2563eb;
            background-color: #dbeafe;
            color: #1d4ed8;
            font-weight: 600;
        }

        #columnFilterMenu.hidden { display: none; }

        /* ✅ prettier status_logs display (like your screenshot) */
        td.col-status_logs .cell-content{
            white-space: normal !important;
            overflow: hidden;
            text-overflow: unset;
        }
        .data-row.expanded td.col-status_logs .cell-content{ overflow: visible; }

        .logs-list{
            list-style: disc;
            padding-left: 1rem;
            margin: 0;
        }
        .logs-list li{
            margin: 0 0 0.2rem 0;
            line-height: 1.2;
        }
        .log-time{ color: #6b7280; }
        .log-status{ font-weight: 700; }
        .log-meta{ color: #6b7280; font-size: 0.65rem; }

        /* clamp logs when row is NOT expanded */
        .data-row:not(.expanded) td.col-status_logs .logs-list{
            max-height: 3.0em;
            overflow: hidden;
        }
    </style>
</head>

<body class="bg-gray-50 text-gray-900">

<div class="w-full py-4 px-2">
    <h1 class="text-xl font-semibold mb-4">JNT Dashboard</h1>

    {{-- MAIN FILTER FORM --}}
    <form id="mainFilterForm" method="GET" action="{{ route('jnt.dashboard') }}" class="mb-4">

        {{-- 🔎 SEARCH BAR (search is server-side; should be limited by date filter in controller) --}}
        <div class="flex flex-wrap gap-2 items-center mb-3">
            <input
                type="text"
                name="q"
                id="q"
                class="border rounded px-3 py-2 text-sm w-full md:w-[720px]"
                placeholder="Search waybill, receiver, sender, item, status, remarks, province, city, barangay..."
                value="{{ old('q', $q) }}"
                autocomplete="off"
            >
            <button
                type="submit"
                class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-4 py-2 rounded"
            >
                Search
            </button>
            <button
                type="button"
                id="clearSearchBtn"
                class="bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm px-4 py-2 rounded"
            >
                Clear
            </button>
        </div>

        <div class="flex flex-wrap gap-3 items-end">
            <div>
                <label for="date_from" class="block text-xs font-medium text-gray-700">
                    Date From (Submission Time)
                </label>
                <input
                    type="text"
                    id="date_from"
                    name="date_from"
                    class="mt-1 border rounded px-2 py-1 text-sm w-40"
                    value="{{ old('date_from', $dateFrom ?? request('date_from')) }}"
                    autocomplete="off"
                >
            </div>

            <div>
                <label for="date_to" class="block text-xs font-medium text-gray-700">
                    Date To (Submission Time)
                </label>
                <input
                    type="text"
                    id="date_to"
                    name="date_to"
                    class="mt-1 border rounded px-2 py-1 text-sm w-40"
                    value="{{ old('date_to', $dateTo ?? request('date_to')) }}"
                    autocomplete="off"
                >
            </div>

            <div class="flex gap-2 mt-4 sm:mt-0">
                <button
                    type="submit"
                    class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-4 py-2 rounded"
                >
                    Apply Filter
                </button>

                <a
                    href="{{ route('jnt.dashboard') }}"
                    class="bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm px-4 py-2 rounded inline-flex items-center"
                >
                    Clear All
                </a>
            </div>
        </div>

        {{-- sort state --}}
        <input type="hidden" name="sort_col" id="sort_col" value="{{ $sortCol }}">
        <input type="hidden" name="sort_dir" id="sort_dir" value="{{ $sortDir }}">

        {{-- existing column filters as hidden inputs --}}
        <div id="filtersContainer">
            @foreach(($currentFilters ?? []) as $col => $vals)
                @if(is_array($vals))
                    @foreach($vals as $v)
                        <input type="hidden" name="filters[{{ $col }}][]" value="{{ $v }}">
                    @endforeach
                @endif
            @endforeach
        </div>

        {{-- column visibility toggles --}}
        <div class="mt-4 mb-3 text-xs text-gray-700 flex flex-wrap gap-4 items-center">
            <span class="font-semibold">Show columns:</span>

            <label class="inline-flex items-center gap-1">
                <input type="checkbox" class="col-toggle" data-col="remarks">
                <span>Remarks</span>
            </label>
            <label class="inline-flex items-center gap-1">
                <input type="checkbox" class="col-toggle" data-col="province">
                <span>Province</span>
            </label>
            <label class="inline-flex items-center gap-1">
                <input type="checkbox" class="col-toggle" data-col="city">
                <span>City</span>
            </label>
            <label class="inline-flex items-center gap-1">
                <input type="checkbox" class="col-toggle" data-col="barangay">
                <span>Barangay</span>
            </label>
            <label class="inline-flex items-center gap-1">
                <input type="checkbox" class="col-toggle" data-col="total_shipping_cost">
                <span>Total Shipping Cost</span>
            </label>
            <label class="inline-flex items-center gap-1">
                <input type="checkbox" class="col-toggle" data-col="rts_reason">
                <span>RTS Reason</span>
            </label>
            <label class="inline-flex items-center gap-1">
                <input type="checkbox" class="col-toggle" data-col="created_at">
                <span>Created At</span>
            </label>
            <label class="inline-flex items-center gap-1">
                <input type="checkbox" class="col-toggle" data-col="updated_at">
                <span>Updated At</span>
            </label>
        </div>

        {{-- filter summary --}}
        <div class="mb-4 text-xs text-gray-600 flex flex-wrap gap-4 items-center">
            <div>
                @if($dateFrom || $dateTo)
                    Filtered by Submission Time:
                    <strong>{{ $dateFrom ?: 'Any' }}</strong>
                    to
                    <strong>{{ $dateTo ?: 'Any' }}</strong>
                @else
                    Showing all records (no date filter applied).
                @endif
            </div>

            <div>
                @if(!empty($q))
                    Search:
                    <strong>{{ $q }}</strong>
                @endif
            </div>
        </div>

        {{-- table --}}
        <div class="bg-white shadow rounded-lg overflow-hidden">
            @if($data->count() === 0)
                <div class="p-4 text-sm text-gray-500">
                    No data found.
                </div>
            @else
                <div class="table-wrapper">
                    <table>
                        <thead>
                        <tr>
                            @php
                                $hasFilter_submission_time   = !empty($filters['submission_time'] ?? []);
                                $hasFilter_receiver_cellphone = !empty($filters['receiver_cellphone'] ?? []);
                                $hasFilter_sender            = !empty($filters['sender'] ?? []);
                                $hasFilter_item_name         = !empty($filters['item_name'] ?? []);
                                $hasFilter_cod               = !empty($filters['cod'] ?? []);
                                $hasFilter_remarks           = !empty($filters['remarks'] ?? []);
                                $hasFilter_province          = !empty($filters['province'] ?? []);
                                $hasFilter_city              = !empty($filters['city'] ?? []);
                                $hasFilter_barangay          = !empty($filters['barangay'] ?? []);
                                $hasFilter_total_shipping    = !empty($filters['total_shipping_cost'] ?? []);
                                $hasFilter_rts_reason        = !empty($filters['rts_reason'] ?? []);
                                $hasFilter_status            = !empty($filters['status'] ?? []);
                                $hasFilter_created_at        = !empty($filters['created_at'] ?? []);
                                $hasFilter_updated_at        = !empty($filters['updated_at'] ?? []);
                            @endphp

                            <th class="col-submission_time" data-col="submission_time">
                                <div class="cell-content">
                                    <span>submission_time</span>
                                    <button type="button"
                                            class="filter-btn {{ $hasFilter_submission_time ? 'filter-btn-active' : '' }}"
                                            data-filter-btn
                                            data-col="submission_time">
                                        {{ $hasFilter_submission_time ? '▼●' : '▼' }}
                                    </button>
                                </div>
                            </th>

                            <th class="col-waybill_number" data-col="waybill_number">
                                <div class="cell-content"><span>waybill_number</span></div>
                            </th>

                            <th class="col-receiver" data-col="receiver">
                                <div class="cell-content"><span>receiver</span></div>
                            </th>

                            <th class="col-receiver_cellphone" data-col="receiver_cellphone">
                                <div class="cell-content">
                                    <span>receiver_cellphone</span>
                                    <button type="button"
                                            class="filter-btn {{ $hasFilter_receiver_cellphone ? 'filter-btn-active' : '' }}"
                                            data-filter-btn
                                            data-col="receiver_cellphone">
                                        {{ $hasFilter_receiver_cellphone ? '▼●' : '▼' }}
                                    </button>
                                </div>
                            </th>

                            <th class="col-sender" data-col="sender">
                                <div class="cell-content">
                                    <span>sender</span>
                                    <button type="button"
                                            class="filter-btn {{ $hasFilter_sender ? 'filter-btn-active' : '' }}"
                                            data-filter-btn
                                            data-col="sender">
                                        {{ $hasFilter_sender ? '▼●' : '▼' }}
                                    </button>
                                </div>
                            </th>

                            <th class="col-item_name" data-col="item_name">
                                <div class="cell-content">
                                    <span>item_name</span>
                                    <button type="button"
                                            class="filter-btn {{ $hasFilter_item_name ? 'filter-btn-active' : '' }}"
                                            data-filter-btn
                                            data-col="item_name">
                                        {{ $hasFilter_item_name ? '▼●' : '▼' }}
                                    </button>
                                </div>
                            </th>

                            <th class="col-cod" data-col="cod">
                                <div class="cell-content">
                                    <span>cod</span>
                                    <button type="button"
                                            class="filter-btn {{ $hasFilter_cod ? 'filter-btn-active' : '' }}"
                                            data-filter-btn
                                            data-col="cod">
                                        {{ $hasFilter_cod ? '▼●' : '▼' }}
                                    </button>
                                </div>
                            </th>

                            <th class="col-remarks" data-col="remarks">
                                <div class="cell-content">
                                    <span>remarks</span>
                                    <button type="button"
                                            class="filter-btn {{ $hasFilter_remarks ? 'filter-btn-active' : '' }}"
                                            data-filter-btn
                                            data-col="remarks">
                                        {{ $hasFilter_remarks ? '▼●' : '▼' }}
                                    </button>
                                </div>
                            </th>

                            <th class="col-province" data-col="province">
                                <div class="cell-content">
                                    <span>province</span>
                                    <button type="button"
                                            class="filter-btn {{ $hasFilter_province ? 'filter-btn-active' : '' }}"
                                            data-filter-btn
                                            data-col="province">
                                        {{ $hasFilter_province ? '▼●' : '▼' }}
                                    </button>
                                </div>
                            </th>

                            <th class="col-city" data-col="city">
                                <div class="cell-content">
                                    <span>city</span>
                                    <button type="button"
                                            class="filter-btn {{ $hasFilter_city ? 'filter-btn-active' : '' }}"
                                            data-filter-btn
                                            data-col="city">
                                        {{ $hasFilter_city ? '▼●' : '▼' }}
                                    </button>
                                </div>
                            </th>

                            <th class="col-barangay" data-col="barangay">
                                <div class="cell-content">
                                    <span>barangay</span>
                                    <button type="button"
                                            class="filter-btn {{ $hasFilter_barangay ? 'filter-btn-active' : '' }}"
                                            data-filter-btn
                                            data-col="barangay">
                                        {{ $hasFilter_barangay ? '▼●' : '▼' }}
                                    </button>
                                </div>
                            </th>

                            <th class="col-total_shipping_cost" data-col="total_shipping_cost">
                                <div class="cell-content">
                                    <span>total_shipping_cost</span>
                                    <button type="button"
                                            class="filter-btn {{ $hasFilter_total_shipping ? 'filter-btn-active' : '' }}"
                                            data-filter-btn
                                            data-col="total_shipping_cost">
                                        {{ $hasFilter_total_shipping ? '▼●' : '▼' }}
                                    </button>
                                </div>
                            </th>

                            <th class="col-rts_reason" data-col="rts_reason">
                                <div class="cell-content">
                                    <span>rts_reason</span>
                                    <button type="button"
                                            class="filter-btn {{ $hasFilter_rts_reason ? 'filter-btn-active' : '' }}"
                                            data-filter-btn
                                            data-col="rts_reason">
                                        {{ $hasFilter_rts_reason ? '▼●' : '▼' }}
                                    </button>
                                </div>
                            </th>

                            <th class="col-status" data-col="status">
                                <div class="cell-content">
                                    <span>status</span>
                                    <button type="button"
                                            class="filter-btn {{ $hasFilter_status ? 'filter-btn-active' : '' }}"
                                            data-filter-btn
                                            data-col="status">
                                        {{ $hasFilter_status ? '▼●' : '▼' }}
                                    </button>
                                </div>
                            </th>

                            <th class="col-signingtime" data-col="signingtime">
                                <div class="cell-content"><span>signingtime</span></div>
                            </th>

                            <th class="col-status_logs" data-col="status_logs">
                                <div class="cell-content"><span>status_logs</span></div>
                            </th>

                            <th class="col-created_at" data-col="created_at">
                                <div class="cell-content">
                                    <span>created_at</span>
                                    <button type="button"
                                            class="filter-btn {{ $hasFilter_created_at ? 'filter-btn-active' : '' }}"
                                            data-filter-btn
                                            data-col="created_at">
                                        {{ $hasFilter_created_at ? '▼●' : '▼' }}
                                    </button>
                                </div>
                            </th>

                            <th class="col-updated_at" data-col="updated_at">
                                <div class="cell-content">
                                    <span>updated_at</span>
                                    <button type="button"
                                            class="filter-btn {{ $hasFilter_updated_at ? 'filter-btn-active' : '' }}"
                                            data-filter-btn
                                            data-col="updated_at">
                                        {{ $hasFilter_updated_at ? '▼●' : '▼' }}
                                    </button>
                                </div>
                            </th>
                        </tr>
                        </thead>

                        <tbody>
                        @foreach($data as $row)
                            @php
                                $submissionDisplay = $row->submission_time
                                    ? Carbon::parse($row->submission_time)->format('Y-m-d')
                                    : '';

                                $signingRaw = $row->signingtime ?? $row->signing_time ?? null;
                                $signingDisplay = $signingRaw
                                    ? Carbon::parse($signingRaw)->format('Y-m-d\TH:i:s')
                                    : '';

                                $createdDisplay = $row->created_at
                                    ? Carbon::parse($row->created_at)->format('Y-m-d\TH:i:s')
                                    : '';

                                $updatedDisplay = $row->updated_at
                                    ? Carbon::parse($row->updated_at)->format('Y-m-d\TH:i:s')
                                    : '';

                                $remarks = $row->remarks;
                                if (is_array($remarks) || is_object($remarks)) {
                                    $remarks = json_encode($remarks, JSON_UNESCAPED_UNICODE);
                                }

                                $rts = $row->rts_reason;
                                if (is_array($rts) || is_object($rts)) {
                                    $rts = json_encode($rts, JSON_UNESCAPED_UNICODE);
                                }

                                // ✅ Pretty status_logs (handles string OR array/object; avoids json_decode error)
                                $rawStatusLogs = $row->status_logs ?? null;

                                $decodedLogs = [];
                                if (is_array($rawStatusLogs)) {
                                    $decodedLogs = $rawStatusLogs;
                                } elseif (is_object($rawStatusLogs)) {
                                    $decodedLogs = json_decode(json_encode($rawStatusLogs), true) ?: [];
                                } elseif (is_string($rawStatusLogs) && trim($rawStatusLogs) !== '') {
                                    $tmp = json_decode($rawStatusLogs, true);
                                    if (is_array($tmp)) $decodedLogs = $tmp;
                                }

                                $logsPlainLines = [];
                                $logsHtmlItems  = [];

                                if (!empty($decodedLogs)) {
                                    foreach ($decodedLogs as $lg) {
                                        $ba   = trim((string)($lg['batch_at'] ?? ''));
                                        $from = trim((string)($lg['from'] ?? ''));
                                        $to   = trim((string)($lg['to'] ?? ''));
                                        $ulid = $lg['upload_log_id'] ?? null;

                                        $fromLabel = $from !== '' ? $from : '(start)';
                                        $toLabel   = $to   !== '' ? $to   : '(blank)';
                                        $timeLabel = $ba !== '' ? $ba : '(no time)';

                                        $plain = $timeLabel . " — {$fromLabel} → {$toLabel}";
                                        if ($ulid !== null && $ulid !== '') {
                                            $plain .= " (upload_log_id: {$ulid})";
                                        }
                                        $logsPlainLines[] = $plain;

                                        $li = '<li>'
                                            . '<span class="log-time">' . e($timeLabel) . '</span>'
                                            . ' — '
                                            . '<span class="log-status">' . e($fromLabel) . '</span>'
                                            . ' → '
                                            . '<span class="log-status">' . e($toLabel) . '</span>';

                                        if ($ulid !== null && $ulid !== '') {
                                            $li .= ' <span class="log-meta">(upload_log_id: ' . e($ulid) . ')</span>';
                                        }

                                        $li .= '</li>';

                                        $logsHtmlItems[] = $li;
                                    }
                                }

                                $logsPlain = implode("\n", $logsPlainLines);

                                if (!empty($logsHtmlItems)) {
                                    $logsHtml = '<ul class="logs-list">' . implode('', $logsHtmlItems) . '</ul>';
                                } else {
                                    $fallback = '';
                                    if (is_string($rawStatusLogs) && trim($rawStatusLogs) !== '') {
                                        $fallback = $rawStatusLogs;
                                    } elseif (is_array($rawStatusLogs) || is_object($rawStatusLogs)) {
                                        $fallback = json_encode($rawStatusLogs, JSON_UNESCAPED_UNICODE);
                                    }

                                    $logsPlain = (string)$fallback;
                                    $logsHtml  = $fallback !== ''
                                        ? '<div class="logs-fallback">' . nl2br(e($fallback)) . '</div>'
                                        : '<span class="text-gray-400">(no logs)</span>';
                                }
                            @endphp

                            <tr class="data-row">
                                <td class="col-submission_time" data-col="submission_time">
                                    <div class="cell-content" title="{{ $submissionDisplay }}">{{ $submissionDisplay }}</div>
                                </td>
                                <td class="col-waybill_number" data-col="waybill_number">
                                    <div class="cell-content" title="{{ $row->waybill_number }}">{{ $row->waybill_number }}</div>
                                </td>
                                <td class="col-receiver" data-col="receiver">
                                    <div class="cell-content" title="{{ $row->receiver }}">{{ $row->receiver }}</div>
                                </td>
                                <td class="col-receiver_cellphone" data-col="receiver_cellphone">
                                    <div class="cell-content" title="{{ $row->receiver_cellphone }}">{{ $row->receiver_cellphone }}</div>
                                </td>
                                <td class="col-sender" data-col="sender">
                                    <div class="cell-content" title="{{ $row->sender }}">{{ $row->sender }}</div>
                                </td>
                                <td class="col-item_name" data-col="item_name">
                                    <div class="cell-content" title="{{ $row->item_name }}">{{ $row->item_name }}</div>
                                </td>
                                <td class="col-cod" data-col="cod">
                                    <div class="cell-content" title="{{ $row->cod }}">{{ $row->cod }}</div>
                                </td>
                                <td class="col-remarks" data-col="remarks">
                                    <div class="cell-content" title="{{ $remarks }}">{{ $remarks }}</div>
                                </td>
                                <td class="col-province" data-col="province">
                                    <div class="cell-content" title="{{ $row->province }}">{{ $row->province }}</div>
                                </td>
                                <td class="col-city" data-col="city">
                                    <div class="cell-content" title="{{ $row->city }}">{{ $row->city }}</div>
                                </td>
                                <td class="col-barangay" data-col="barangay">
                                    <div class="cell-content" title="{{ $row->barangay }}">{{ $row->barangay }}</div>
                                </td>
                                <td class="col-total_shipping_cost" data-col="total_shipping_cost">
                                    <div class="cell-content" title="{{ $row->total_shipping_cost }}">{{ $row->total_shipping_cost }}</div>
                                </td>
                                <td class="col-rts_reason" data-col="rts_reason">
                                    <div class="cell-content" title="{{ $rts }}">{{ $rts }}</div>
                                </td>
                                <td class="col-status" data-col="status">
                                    <div class="cell-content" title="{{ $row->status }}">{{ $row->status }}</div>
                                </td>
                                <td class="col-signingtime" data-col="signingtime">
                                    <div class="cell-content" title="{{ $signingDisplay }}">{{ $signingDisplay }}</div>
                                </td>
                                <td class="col-status_logs" data-col="status_logs">
                                    <div class="cell-content" title="{{ $logsPlain }}">{!! $logsHtml !!}</div>
                                </td>
                                <td class="col-created_at" data-col="created_at">
                                    <div class="cell-content" title="{{ $createdDisplay }}">{{ $createdDisplay }}</div>
                                </td>
                                <td class="col-updated_at" data-col="updated_at">
                                    <div class="cell-content" title="{{ $updatedDisplay }}">{{ $updatedDisplay }}</div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- pagination --}}
                <div class="px-4 py-2 border-t flex justify-between items-center text-xs">
                    <div>
                        Showing <strong>{{ $data->firstItem() }}</strong> to <strong>{{ $data->lastItem() }}</strong>
                        of <strong>{{ $data->total() }}</strong> results
                    </div>
                    <div>
                        {{ $data->links() }}
                    </div>
                </div>
            @endif
        </div>
    </form>
</div>

<script>
    const FILTER_OPTIONS  = @json($filterOptions ?? []);
    const CURRENT_FILTERS = @json($currentFilters ?? []);
</script>

{{-- popup menu --}}
<div id="columnFilterMenu"
     class="hidden fixed z-50 bg-white border border-gray-300 shadow-lg rounded-md p-2 text-xs">
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
    // ✅ reset search when date changes (flatpickr onChange)
    flatpickr("#date_from", {
        dateFormat: "Y-m-d",
        allowInput: true,
        onChange: function () {
            const q = document.getElementById('q');
            if (q) q.value = '';
        }
    });

    flatpickr("#date_to", {
        dateFormat: "Y-m-d",
        allowInput: true,
        onChange: function () {
            const q = document.getElementById('q');
            if (q) q.value = '';
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('mainFilterForm');
        const filtersContainer = document.getElementById('filtersContainer');
        const menu  = document.getElementById('columnFilterMenu');

        // Clear search only (keep date/filters)
        const clearSearchBtn = document.getElementById('clearSearchBtn');
        if (clearSearchBtn) {
            clearSearchBtn.addEventListener('click', function (e) {
                e.preventDefault();
                const q = document.getElementById('q');
                if (q) q.value = '';
                form.submit();
            });
        }

        // ✅ also clear search when user types/changes date field manually (not via picker)
        const qInput = document.getElementById('q');
        const dateFromEl = document.getElementById('date_from');
        const dateToEl   = document.getElementById('date_to');

        function resetSearchOnly() {
            if (qInput) qInput.value = '';
        }

        ['change','input'].forEach(evt => {
            if (dateFromEl) dateFromEl.addEventListener(evt, resetSearchOnly);
            if (dateToEl)   dateToEl.addEventListener(evt, resetSearchOnly);
        });

        // expand rows
        document.querySelectorAll('.data-row').forEach(row => {
            row.addEventListener('click', function () {
                this.classList.toggle('expanded');
            });
        });

        // show/hide columns (force table-cell when visible)
        document.querySelectorAll('.col-toggle').forEach(chk => {
            chk.addEventListener('change', function (e) {
                const colKey  = this.getAttribute('data-col');
                const visible = this.checked;
                document.querySelectorAll('[data-col="' + colKey + '"]').forEach(el => {
                    el.style.display = visible ? 'table-cell' : 'none';
                });
                e.stopPropagation();
            });
        });

        function closeMenu() {
            menu.classList.add('hidden');
            menu.innerHTML = '';
        }

        function getValues(colKey) {
            if (typeof FILTER_OPTIONS !== 'undefined' && FILTER_OPTIONS[colKey]) {
                return FILTER_OPTIONS[colKey].slice();
            }
            return [];
        }

        function openFilterMenu(colKey) {
            const allValues = getValues(colKey);
            const selected = CURRENT_FILTERS[colKey] || [];
            const selectAll = selected.length === 0;

            let html = `
                <div class="mb-2 flex gap-1">
                    <button type="button" class="sort-btn border rounded px-2 py-1" data-col="${colKey}" data-dir="asc">
                        Sort A → Z
                    </button>
                    <button type="button" class="sort-btn border rounded px-2 py-1" data-col="${colKey}" data-dir="desc">
                        Sort Z → A
                    </button>
                </div>
                <div class="border-t pt-2 mt-1">
                    <div class="mb-1 text-[10px] uppercase tracking-wide text-gray-500">
                        Filter by values
                    </div>
                    <input type="text"
                        class="value-search w-full border rounded px-1 py-0.5 mb-2 text-xs"
                        placeholder="Search...">
                    <div class="value-list max-h-48 overflow-auto text-xs">
                        <label class="flex items-center gap-1 mb-1">
                            <input type="checkbox" class="select-all" ${selectAll ? 'checked' : ''}>
                            <span>Select all</span>
                        </label>
            `;

            allValues.forEach(v => {
                const safeV = (v ?? '').toString();
                const isChecked = selectAll || selected.includes(safeV);
                const labelTxt = safeV || '(blank)';
                html += `
                    <label class="flex items-center gap-1 mb-1 value-item">
                        <input type="checkbox" class="value-checkbox" value="${safeV.replace(/"/g, '&quot;')}" ${isChecked ? 'checked' : ''}>
                        <span>${labelTxt}</span>
                    </label>
                `;
            });

            html += `
                    </div>
                    <div class="mt-2 flex justify-end gap-2">
                        <button type="button"
                                class="apply-btn px-2 py-1 bg-blue-600 text-white rounded text-xs"
                                data-col="${colKey}">
                            OK
                        </button>
                        <button type="button"
                                class="clear-btn px-2 py-1 border rounded text-xs"
                                data-col="${colKey}">
                            Clear
                        </button>
                    </div>
                </div>
            `;

            menu.innerHTML = html;
            menu.classList.remove('hidden');

            // center popup
            requestAnimationFrame(() => {
                const rect = menu.getBoundingClientRect();
                let left = (window.innerWidth - rect.width) / 2;
                let top  = (window.innerHeight - rect.height) / 2 + window.scrollY;
                if (left < 10) left = 10;
                if (top  < 10 + window.scrollY) top = 10 + window.scrollY;
                menu.style.left = `${left}px`;
                menu.style.top  = `${top}px`;
            });
        }

        document.querySelectorAll('[data-filter-btn]').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                const colKey = this.getAttribute('data-col');
                if (!menu.classList.contains('hidden')) closeMenu();
                openFilterMenu(colKey);
            });
        });

        menu.addEventListener('click', function (e) {
            e.stopPropagation();
            const target = e.target;

            if (target.classList.contains('sort-btn')) {
                const colKey = target.getAttribute('data-col');
                const dir    = target.getAttribute('data-dir');
                document.getElementById('sort_col').value = colKey;
                document.getElementById('sort_dir').value = dir;
                form.submit();
                return;
            }

            if (target.classList.contains('select-all')) {
                const checked = target.checked;
                menu.querySelectorAll('.value-checkbox').forEach(cb => cb.checked = checked);
                return;
            }

            if (target.classList.contains('apply-btn')) {
                const colKey = target.getAttribute('data-col');
                const selected = [];
                menu.querySelectorAll('.value-checkbox').forEach(cb => {
                    if (cb.checked) selected.push(cb.value);
                });

                const selectAllBox = menu.querySelector('.select-all');
                const selectAll = selectAllBox ? selectAllBox.checked : (selected.length === 0);

                filtersContainer
                    .querySelectorAll('input[name="filters[' + colKey + '][]"]')
                    .forEach(el => el.remove());

                if (!selectAll) {
                    selected.forEach(v => {
                        const input = document.createElement('input');
                        input.type  = 'hidden';
                        input.name  = 'filters[' + colKey + '][]';
                        input.value = v;
                        filtersContainer.appendChild(input);
                    });
                }

                form.submit();
                return;
            }

            if (target.classList.contains('clear-btn')) {
                const colKey = target.getAttribute('data-col');
                filtersContainer
                    .querySelectorAll('input[name="filters[' + colKey + '][]"]')
                    .forEach(el => el.remove());
                form.submit();
                return;
            }
        });

        menu.addEventListener('input', function (e) {
            if (!e.target.classList.contains('value-search')) return;
            const term = e.target.value.toLowerCase();
            menu.querySelectorAll('.value-item').forEach(item => {
                const label = item.textContent.toLowerCase();
                item.style.display = label.includes(term) ? '' : 'none';
            });
        });

        document.addEventListener('click', function () {
            closeMenu();
        });

        menu.addEventListener('mousedown', function (e) {
            e.stopPropagation();
        });
    });
</script>

</body>
</html>
