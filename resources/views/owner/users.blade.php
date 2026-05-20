<x-layout>
  <x-slot name="title">Users</x-slot>
  <x-slot name="heading">👥 Users — Credentials Oversight (CEO only)</x-slot>

  <style>
    .u-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .u-card-header { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border-bottom:1px solid #f1f5f9; flex-wrap:wrap; gap:8px; }
    .u-title { font-size:13px; font-weight:600; color:#0f172a; }
    .u-btn { display:inline-flex; align-items:center; gap:5px; background:#4f46e5; color:#fff; font-weight:600; font-size:12px; padding:6px 11px; border-radius:6px; cursor:pointer; border:0; }
    .u-btn:hover { background:#4338ca; }
    .u-btn-ghost { display:inline-flex; align-items:center; gap:4px; background:transparent; color:#64748b; font-size:12px; padding:5px 10px; border-radius:6px; text-decoration:none; }
    .u-btn-ghost:hover { background:#f1f5f9; color:#0f172a; }

    .u-table { width:100%; border-collapse:separate; border-spacing:0; font-size:12.5px; }
    .u-table thead th {
      position:sticky; top:0; z-index:1; background:#f8fafc; color:#475569;
      font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;
      padding:9px 10px; text-align:left; border-bottom:2px solid #e2e8f0;
    }
    .u-table tbody td { padding:9px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .u-table tbody tr:hover td { background:#f8fafc; }

    .pill { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:999px; font-size:10.5px; font-weight:600; }
    .pill.role-ceo        { background:#fee2e2; color:#991b1b; }
    .pill.role-moic       { background:#dbeafe; color:#1e40af; }
    .pill.role-marketing  { background:#fef3c7; color:#92400e; }
    .pill.role-encoder    { background:#d1fae5; color:#065f46; }
    .pill.role-default    { background:#f1f5f9; color:#475569; }

    .pw-cell { font-family:ui-monospace,monospace; font-size:12px; color:#0f172a; }
    .pw-empty { color:#94a3b8; font-style:italic; }

    .modal-backdrop { position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:50;
                      display:flex; align-items:center; justify-content:center; padding:1rem; }
    .modal-card { background:#fff; border-radius:10px; box-shadow:0 10px 25px rgba(0,0,0,0.2);
                  max-width:440px; width:100%; }
    .modal-section { padding:16px 20px; }
    .modal-section label { display:block; font-size:10.5px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:6px; }
    .modal-section input { width:100%; padding:8px 11px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:13px; font-family:ui-monospace,monospace; }
    .modal-section input:focus { outline:none; border-color:#4f46e5; box-shadow:0 0 0 3px rgba(99,102,241,0.15); }
    .modal-footer { display:flex; justify-content:flex-end; gap:8px; padding:12px 20px; border-top:1px solid #f1f5f9; background:#f8fafc; border-radius:0 0 10px 10px; }
  </style>

  <div x-data="ownerUsersUI()" x-init="init()" class="w-full flex flex-col gap-4 p-2">

    <div class="u-card">
      <div class="u-card-header">
        <div class="u-title">
          👥 Users ({{ count($users) }} total)
          <span style="font-weight:400;color:#94a3b8;margin-left:6px;">— read-only listing · password edit only</span>
        </div>
        <a href="/owner/private" class="u-btn-ghost">← Back to Page Summary</a>
      </div>

      <div style="overflow-x:auto;">
        <table class="u-table">
          <thead>
            <tr>
              <th style="min-width:200px;">Name</th>
              <th style="min-width:160px;">Role</th>
              <th style="min-width:220px;">Email</th>
              <th style="min-width:220px;">Password</th>
              <th style="min-width:160px;">Date Created</th>
            </tr>
          </thead>
          <tbody>
            @forelse($users as $u)
              @php
                $role = trim((string)($u->role ?? ''));
                $roleClass = match(true) {
                    preg_match('/^ceo$/iu', $role) === 1                       => 'role-ceo',
                    preg_match('/oic/iu', $role) === 1                         => 'role-moic',
                    preg_match('/marketing/iu', $role) === 1                   => 'role-marketing',
                    preg_match('/encoder|checker/iu', $role) === 1             => 'role-encoder',
                    default                                                   => 'role-default',
                };
              @endphp
              <tr>
                <td>
                  <div style="font-weight:600;color:#0f172a;">
                    {{ $u->employee_name ?? '—' }}
                  </div>
                  @if(!empty($u->employment_type) || !empty($u->employment_status))
                    <div style="font-size:10.5px;color:#94a3b8;margin-top:2px;">
                      {{ $u->employment_type }}{{ !empty($u->employment_status) ? ' · '.$u->employment_status : '' }}
                    </div>
                  @endif
                </td>
                <td>
                  <span class="pill {{ $roleClass }}">{{ $role !== '' ? $role : '—' }}</span>
                </td>
                <td style="font-family:ui-monospace,monospace;font-size:12px;color:#475569;">
                  {{ $u->email }}
                </td>
                <td>
                  @if(!empty($u->password_plain))
                    <span class="pw-cell" data-pw="{{ $u->password_plain }}" id="pw-{{ $u->id }}">{{ $u->password_plain }}</span>
                  @else
                    <span class="pw-empty" id="pw-{{ $u->id }}">— not set via this page —</span>
                  @endif
                  <button type="button" class="u-btn" style="margin-left:8px;padding:3px 8px;font-size:11px;background:#0ea5e9;"
                          @click="openEdit({{ $u->id }}, @js($u->email), @js($u->employee_name ?? $u->email))">
                    ✎ Edit
                  </button>
                </td>
                <td style="font-family:ui-monospace,monospace;font-size:11.5px;color:#64748b;">
                  {{ $u->created_at ? \Carbon\Carbon::parse($u->created_at)->format('Y-m-d H:i') : '—' }}
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="5" style="text-align:center;padding:30px;color:#94a3b8;">No users found.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <div style="padding:9px 14px;font-size:10.5px;color:#94a3b8;border-top:1px solid #f1f5f9;">
        ⚠ Plaintext passwords stored sa <code>users.password_plain</code> column. Only populated after a CEO-driven password set via this page.
      </div>
    </div>

    {{-- Edit Password Modal --}}
    <template x-if="edit.open">
      <div class="modal-backdrop" @click.self="edit.open = false">
        <div class="modal-card">
          <div class="modal-section" style="border-bottom:1px solid #e2e8f0;">
            <div style="font-size:10.5px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Edit Password</div>
            <div style="font-size:15px;font-weight:700;color:#0f172a;margin-top:4px;" x-text="edit.label"></div>
            <div style="font-size:12px;color:#475569;margin-top:2px;font-family:ui-monospace,monospace;" x-text="edit.email"></div>
          </div>
          <div class="modal-section">
            <label>New Password</label>
            <input type="text" x-model="edit.password" maxlength="255" placeholder="Type the new password (plaintext)" autofocus>
            <div style="font-size:10.5px;color:#94a3b8;margin-top:6px;">
              Saving will overwrite both bcrypt hash + the plaintext column. User can login with this value immediately.
            </div>
          </div>
          <template x-if="edit.error">
            <div class="modal-section" style="background:#fef2f2;color:#991b1b;font-size:12px;" x-text="edit.error"></div>
          </template>
          <div class="modal-footer">
            <button type="button" class="u-btn-ghost" @click="edit.open = false">Cancel</button>
            <button type="button" class="u-btn" :disabled="edit.saving" @click="submitEdit()">
              <span x-text="edit.saving ? 'Saving…' : 'Save Password'"></span>
            </button>
          </div>
        </div>
      </div>
    </template>
  </div>

  <script>
    function ownerUsersUI() {
      return {
        edit: { open:false, saving:false, error:null, id:null, email:'', label:'', password:'' },

        init() {},

        openEdit(id, email, label) {
          this.edit = { open:true, saving:false, error:null, id, email, label, password:'' };
          this.$nextTick(() => {
            const input = document.querySelector('.modal-card input[type="text"]');
            if (input) input.focus();
          });
        },

        async submitEdit() {
          this.edit.saving = true; this.edit.error = null;
          const pw = (this.edit.password || '').trim();
          if (pw.length < 4) {
            this.edit.error = 'Password must be at least 4 characters.';
            this.edit.saving = false; return;
          }
          try {
            const r = await fetch('/owner/users/' + this.edit.id + '/password', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
              },
              body: JSON.stringify({ password: pw }),
            });
            const j = await r.json();
            if (!r.ok || !j.ok) {
              this.edit.error = j.message || (j.errors ? Object.values(j.errors).flat().join('\n') : 'HTTP ' + r.status);
              return;
            }
            // Update the cell in-place
            const cell = document.getElementById('pw-' + this.edit.id);
            if (cell) {
              cell.textContent = j.password;
              cell.classList.remove('pw-empty');
              cell.classList.add('pw-cell');
              cell.setAttribute('data-pw', j.password);
            }
            this.edit.open = false;
          } catch (e) {
            console.error(e);
            this.edit.error = 'Network error: ' + e.message;
          } finally {
            this.edit.saving = false;
          }
        },
      };
    }
  </script>
</x-layout>
