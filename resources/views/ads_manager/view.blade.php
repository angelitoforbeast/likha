<x-layout>
  <x-slot name="heading">Ads Manager Records (Editable)</x-slot>

  @if(session('success'))
    <div class="text-green-600 font-semibold mb-4">{{ session('success') }}</div>
  @endif

  <style>
    #editableTable thead th {
      position: sticky;
      top: 0;
      background-color: #f9fafb;
      z-index: 10;
    }

    #editableTable {
      max-height: 600px;
      overflow-y: auto;
      display: block;
    }

    .search-input {
      border: 1px solid #ccc;
      padding: 6px 10px;
      margin-bottom: 10px;
      width: 100%;
      max-width: 300px;
    }
  </style>

  <input type="text" id="searchInput" class="search-input" placeholder="🔍 Search by page name...">

  <table class="w-full text-sm border-collapse border" id="editableTable">
    <thead>
      <tr class="bg-gray-100">
        <th class="border px-2 py-1">Date</th>
        <th class="border px-2 py-1">Page</th>
        <th class="border px-2 py-1">Amount Spent</th>
        <th class="border px-2 py-1">CPM</th>
        <th class="border px-2 py-1">CPI</th>
        <th class="border px-2 py-1 text-center" colspan="2">Actions</th>
      </tr>
    </thead>
    <tbody>
      @foreach($ads as $ad)
      <tr data-id="{{ $ad->id }}">
        <td class="border px-2 py-1" data-field="reporting_starts">{{ $ad->reporting_starts }}</td>
        <td class="border px-2 py-1" data-field="page">{{ $ad->page }}</td>
        <td class="border px-2 py-1" data-field="amount_spent">{{ $ad->amount_spent }}</td>
        <td class="border px-2 py-1" data-field="cpm">{{ $ad->cpm }}</td>
        <td class="border px-2 py-1" data-field="cpi">{{ $ad->cpi }}</td>
        <td class="border px-2 py-1 text-center">
          <button class="edit-btn text-blue-600 hover:text-blue-800">✏️</button>
        </td>
        <td class="border px-2 py-1 text-center">
          <button class="delete-btn text-red-600 hover:text-red-800">🗑️</button>
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <div class="mt-4">
    {{ $ads->links() }}
  </div>

  <script>
    // Search filter
    document.getElementById('searchInput').addEventListener('input', function () {
      const filter = this.value.toLowerCase();
      document.querySelectorAll('#editableTable tbody tr').forEach(row => {
        const page = row.querySelector('td[data-field="page"]').innerText.toLowerCase();
        row.style.display = page.includes(filter) ? '' : 'none';
      });
    });

    // Edit button
    document.querySelectorAll('.edit-btn').forEach(button => {
      button.addEventListener('click', function () {
        const row = this.closest('tr');
        const cells = row.querySelectorAll('td[data-field]');
        cells.forEach(cell => {
          cell.setAttribute('contenteditable', 'true');
          cell.classList.add('bg-yellow-100');
        });

        cells.forEach(cell => {
          const onBlur = function () {
            const id = row.dataset.id;
            const field = this.dataset.field;
            const value = this.innerText.trim();

            fetch('/ads_manager/update_field', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
              },
              body: JSON.stringify({ id, field, value })
            })
            .then(res => res.json())
            .then(data => {
              if (data.success) {
                this.style.backgroundColor = '#dcfce7';
              } else {
                this.style.backgroundColor = '#fee2e2';
                alert('❌ Error saving!');
              }
              setTimeout(() => this.style.backgroundColor = '', 500);
            })
            .catch(() => {
              this.style.backgroundColor = '#fee2e2';
              alert('❌ Network error.');
            });

            this.removeAttribute('contenteditable');
            this.classList.remove('bg-yellow-100');
            this.removeEventListener('blur', onBlur);
          };
          cell.addEventListener('blur', onBlur);
        });
      });
    });

    // Delete button
    document.querySelectorAll('.delete-btn').forEach(button => {
      button.addEventListener('click', function () {
        const row = this.closest('tr');
        const id = row.dataset.id;

        if (confirm('Are you sure you want to delete this row?')) {
          fetch('/ads_manager/delete_row', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': '{{ csrf_token() }}',
            },
            body: JSON.stringify({ id })
          })
          .then(res => res.json())
          .then(data => {
            if (data.success) {
              row.remove();
            } else {
              alert('❌ Failed to delete row.');
            }
          })
          .catch(() => alert('❌ Network error.'));
        }
      });
    });
  </script>
</x-layout>
