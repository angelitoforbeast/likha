<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>JNT Status Summary</title>
    <style>
        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 10px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 4px 8px;
            text-align: left;
        }
        th {
            background: #f5f5f5;
        }
        .text-right { text-align: right; }
        .text-bold { font-weight: bold; }
    </style>
</head>
<body>
    <h2>JNT Status Summary per Upload Batch</h2>

    <form method="GET" action="{{ route('jnt.status-summary') }}">
        <label for="date">Select Date:</label>
        <input type="date" name="date" id="date" value="{{ $date }}" required>
        <button type="submit">Show</button>
    </form>

    <table>
        <thead>
            <tr>
                <th>Date &amp; Time (upload)</th>
                <th>Delivering (new since last)</th>
                <th>In Transit (new since last)</th>
                <th>Delivered (SigningTime = {{ $date }}, new)</th>
                <th>For Return (new, must have been Delivering today)</th>
            </tr>
        </thead>
        <tbody>
        @forelse($batches as $batch)
            <tr>
                <td>{{ $batch['batch_at'] }}</td>
                <td class="text-right">{{ $batch['delivering'] }}</td>
                <td class="text-right">{{ $batch['in_transit'] }}</td>
                <td class="text-right">{{ $batch['delivered'] }}</td>
                <td class="text-right">{{ $batch['for_return'] }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="5">No data for {{ $date }}.</td>
            </tr>
        @endforelse

        @if(!empty($batches))
            <tr>
                <td class="text-bold">TOTAL</td>
                <td class="text-right text-bold">{{ $totals['delivering'] }}</td>
                <td class="text-right text-bold">{{ $totals['in_transit'] }}</td>
                <td class="text-right text-bold">{{ $totals['delivered'] }}</td>
                <td class="text-right text-bold">{{ $totals['for_return'] }}</td>
            </tr>
        @endif
        </tbody>
    </table>

    <p style="margin-top:10px; font-size: 12px;">
        *Counts above are computed from <code>status_logs</code> (per upload batch) and <code>signingtime</code> for Delivered.
    </p>
</body>
</html>
