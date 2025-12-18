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

        a.js-batch-details {
            color: #0b57d0;
            text-decoration: underline;
            cursor: pointer;
        }

        /* Modal */
        #detailsModal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.35);
            z-index: 9999;
        }
        #detailsModal .box {
            background: #fff;
            width: 92%;
            max-width: 1100px;
            margin: 40px auto;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 12px 30px rgba(0,0,0,.25);
        }
        #detailsModal .head {
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        #detailsModal .body {
            padding: 12px;
            max-height: 75vh;
            overflow: auto;
        }
        #detailsClose {
            padding: 6px 10px;
            cursor: pointer;
        }
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

                <td class="text-right">
                    <a href="#"
                       class="js-batch-details"
                       data-date="{{ $date }}"
                       data-batch="{{ $batch['batch_at'] }}"
                       data-metric="delivering">
                        {{ $batch['delivering'] }}
                    </a>
                </td>

                <td class="text-right">
                    <a href="#"
                       class="js-batch-details"
                       data-date="{{ $date }}"
                       data-batch="{{ $batch['batch_at'] }}"
                       data-metric="in_transit">
                        {{ $batch['in_transit'] }}
                    </a>
                </td>

                <td class="text-right">
                    <a href="#"
                       class="js-batch-details"
                       data-date="{{ $date }}"
                       data-batch="{{ $batch['batch_at'] }}"
                       data-metric="delivered"
                       data-range-start="{{ $batch['range_start'] ?? '' }}"
                       data-range-end="{{ $batch['range_end'] ?? '' }}">
                        {{ $batch['delivered'] }}
                    </a>
                </td>

                <td class="text-right">
                    <a href="#"
                       class="js-batch-details"
                       data-date="{{ $date }}"
                       data-batch="{{ $batch['batch_at'] }}"
                       data-metric="for_return">
                        {{ $batch['for_return'] }}
                    </a>
                </td>
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

    <!-- ✅ Modal -->
    <div id="detailsModal">
        <div class="box">
            <div class="head">
                <strong id="detailsTitle">Details</strong>
                <button id="detailsClose" type="button">Close</button>
            </div>
            <div id="detailsBody" class="body">
                Loading...
            </div>
        </div>
    </div>

    <script>
    (function(){
        const modal = document.getElementById('detailsModal');
        const body  = document.getElementById('detailsBody');
        const title = document.getElementById('detailsTitle');
        const close = document.getElementById('detailsClose');

        function openModal(){ modal.style.display = 'block'; }
        function closeModal(){
            modal.style.display = 'none';
            body.innerHTML = '';
        }

        close.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });

        document.addEventListener('click', async (e) => {
            const a = e.target.closest('.js-batch-details');
            if (!a) return;
            e.preventDefault();

            const date    = a.dataset.date;
            const batchAt = a.dataset.batch;
            const metric  = a.dataset.metric;

            const params = new URLSearchParams({
                date: date,
                batch_at: batchAt,
                metric: metric
            });

            if (metric === 'delivered') {
                params.set('range_start', a.dataset.rangeStart || '');
                params.set('range_end', a.dataset.rangeEnd || '');
            }

            title.textContent = `${metric.toUpperCase()} • ${batchAt}`;
            body.innerHTML = 'Loading...';
            openModal();

            try {
                const url = `{{ route('jnt.status-summary.details') }}?` + params.toString();
                const res = await fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const html = await res.text();
                body.innerHTML = html;
            } catch (err) {
                body.innerHTML = `<div style="color:red;">Failed to load details.</div>`;
            }
        });
    })();
    </script>
</body>
</html>
