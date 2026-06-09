{{-- pbForm: checklist dynamic rows + free-input attachment uploader (click/drag/Ctrl+V). --}}
<script>
  function pbForm(initialChecklist) {
    return {
      items: (initialChecklist && initialChecklist.length) ? initialChecklist : [{ id: null, label: '' }],
      addItem() { this.items.push({ id: null, label: '' }); },
      removeItem(i) { this.items.splice(i, 1); if (!this.items.length) this.items.push({ id: null, label: '' }); },

      // attachments
      dt: new DataTransfer(), files: [], removed: [],
      add(list) {
        for (const f of (list || [])) {
          if (f && (f.type.startsWith('image/') || f.type === 'application/pdf')) this.dt.items.add(f);
        }
        this.sync();
      },
      sync() { if (this.$refs.fileInput) this.$refs.fileInput.files = this.dt.files; this.files = Array.from(this.dt.files); },
      removeFile(i) {
        const n = new DataTransfer();
        Array.from(this.dt.files).forEach((f, idx) => { if (idx !== i) n.items.add(f); });
        this.dt = n; this.sync();
      },
      paste(e) {
        if (this.$el.offsetParent === null) return;
        const items = e.clipboardData ? e.clipboardData.items : [];
        let got = false;
        for (const it of items) {
          if (it.type && it.type.startsWith('image/')) { const b = it.getAsFile(); if (b) { this.dt.items.add(b); got = true; } }
        }
        if (got) { e.preventDefault(); this.sync(); }
      },
      thumb(f) { return URL.createObjectURL(f); },
      isImg(f) { return f.type.startsWith('image/'); },
      toggleRemove(id) { const i = this.removed.indexOf(id); if (i >= 0) this.removed.splice(i, 1); else this.removed.push(id); },
      isRemoved(id) { return this.removed.includes(id); },
    };
  }
</script>
