document.addEventListener('alpine:init', () => {
  Alpine.store('toast', {
    items: [],
    push(message, type = 'success') {
      const id = Date.now() + Math.random();
      this.items.push({ id, message, type });
      setTimeout(() => this.dismiss(id), 4500);
    },
    dismiss(id) {
      this.items = this.items.filter((item) => item.id !== id);
    },
  });

  Alpine.store('confirmModal', {
    open: false,
    title: 'Konfirmasi Tindakan',
    message: 'Apakah Anda yakin ingin melanjutkan tindakan ini?',
    confirmLabel: 'Ya, Lanjutkan',
    confirmClass: 'btn-primary',
    formSelector: null,
    ask({ title, message, confirmLabel, confirmClass, formSelector }) {
      this.title = title || 'Konfirmasi Tindakan';
      this.message = message || 'Apakah Anda yakin ingin melanjutkan?';
      this.confirmLabel = confirmLabel || 'Ya, Lanjutkan';
      this.confirmClass = confirmClass || 'btn-primary';
      this.formSelector = formSelector || null;
      this.open = true;
    },
    confirm() {
      if (this.formSelector) {
        const form = document.querySelector(this.formSelector);
        if (form) form.submit();
      }
      this.open = false;
    },
  });

  Alpine.data('addressRegionForm', (config) => ({
    searchUrl: config.searchUrl || '',
    keyword: '',
    results: [],
    loading: false,
    searched: false,
    searchError: null,
    manualMode: Boolean(config.hasRegionErrors) || (config.initial?.province_id === 'manual'),
    abortController: null,
    province_id: config.initial?.province_id || '',
    province_name: config.initial?.province_name || '',
    city_id: config.initial?.city_id || '',
    city_name: config.initial?.city_name || '',
    district_id: config.initial?.district_id || '',
    district_name: config.initial?.district_name || '',
    subdistrict_id: config.initial?.subdistrict_id || '',
    subdistrict_name: config.initial?.subdistrict_name || '',
    postal_code: config.initial?.postal_code || '',

    init() {
      const form = this.$el.closest('form');
      if (form) {
        form.addEventListener('submit', () => this.prepareSubmit());
      }
    },

    get hasSelectedRegion() {
      return Boolean(this.province_name.trim() || this.city_name.trim() || this.district_name.trim() || this.subdistrict_name.trim());
    },

    get formattedSelectedRegion() {
      const parts = [
        this.subdistrict_name.trim(),
        this.district_name.trim(),
        this.city_name.trim(),
        this.province_name.trim(),
      ].filter((part) => part !== '');

      const base = parts.join(', ');
      const postal = this.postal_code.trim();

      return postal ? `${base} ${postal}`.trim() : base;
    },

    async searchRegions() {
      const q = this.keyword.trim();

      if (q.length < 2) {
        this.results = [];
        this.searched = false;
        this.searchError = 'Masukkan minimal 2 karakter (kelurahan, kecamatan, kota, atau kode pos).';
        return;
      }

      if (this.abortController) {
        this.abortController.abort();
      }
      this.abortController = new AbortController();

      this.loading = true;
      this.searchError = null;
      this.searched = false;

      try {
        const separator = this.searchUrl.includes('?') ? '&' : '?';
        const response = await fetch(`${this.searchUrl}${separator}keyword=${encodeURIComponent(q)}&limit=15`, {
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          signal: this.abortController.signal,
        });

        const payload = await response.json().catch(() => null);

        if (!response.ok || !payload || payload.success !== true) {
          this.results = [];
          this.searched = true;
          this.searchError = payload?.message || 'Layanan pencarian wilayah sedang tidak tersedia. Silakan isi wilayah secara manual.';
          if (!payload || payload.fallback_manual_allowed !== false) {
            this.manualMode = true;
          }
          return;
        }

        this.results = Array.isArray(payload.data) ? payload.data : [];
        this.searched = true;
      } catch (error) {
        if (error && error.name === 'AbortError') {
          return;
        }
        this.results = [];
        this.searched = true;
        this.searchError = 'Gagal terhubung ke layanan pencarian wilayah. Anda tetap dapat mengisi wilayah secara manual.';
        this.manualMode = true;
      } finally {
        this.loading = false;
      }
    },

    selectRegion(item) {
      if (!item || !item.id) {
        return;
      }

      const regionId = String(item.id).trim();
      this.province_id = regionId;
      this.city_id = regionId;
      this.district_id = item.district_name ? regionId : '';
      this.subdistrict_id = regionId;

      this.province_name = (item.province_name || '').trim();
      this.city_name = (item.city_name || '').trim();
      this.district_name = (item.district_name || '').trim();
      this.subdistrict_name = (item.subdistrict_name || '').trim();

      if (item.zip_code) {
        const digits = String(item.zip_code).replace(/\D/g, '').slice(0, 5);
        if (digits) {
          this.postal_code = digits;
        }
      }

      this.results = [];
      this.searched = false;
      this.searchError = null;
      this.manualMode = !this.province_name || !this.city_name;

      if (Alpine.store('toast')) {
        Alpine.store('toast').push('Wilayah pengiriman berhasil dipilih.', 'success');
      }
    },

    toggleManualMode() {
      this.manualMode = !this.manualMode;
    },

    markAsManual() {
      this.province_id = 'manual';
      this.city_id = 'manual';
      this.district_id = '';
      this.subdistrict_id = '';
    },

    prepareSubmit() {
      if (this.manualMode && (this.province_name.trim() !== '' || this.city_name.trim() !== '')) {
        if (!this.province_id.trim()) {
          this.province_id = 'manual';
        }
        if (!this.city_id.trim()) {
          this.city_id = 'manual';
        }
      }
    },
  }));

  Alpine.data('orderPaymentPanel', (config) => ({
    initiateUrl: config.initiateUrl || null,
    statusCheckUrl: config.statusCheckUrl || null,
    refreshUrl: config.refreshUrl || window.location.href,
    loading: false,
    checkingStatus: false,
    refreshing: false,
    feedbackMessage: '',
    feedbackType: 'info',

    get feedbackClass() {
      if (this.feedbackType === 'danger') {
        return 'alert-danger';
      }
      if (this.feedbackType === 'warning') {
        return 'alert-warning';
      }
      if (this.feedbackType === 'success') {
        return 'alert-success';
      }
      return 'alert-info';
    },

    csrfToken() {
      return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    },

    setFeedback(type, message) {
      this.feedbackType = type;
      this.feedbackMessage = message;
    },

    async requestStatusSync() {
      if (!this.statusCheckUrl) {
        return null;
      }

      const response = await fetch(this.statusCheckUrl, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': this.csrfToken(),
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({}),
      });

      const payload = await response.json().catch(() => null);

      return { response, payload };
    },

    async checkPaymentStatus() {
      if (this.loading || this.refreshing || this.checkingStatus || !this.statusCheckUrl) {
        return;
      }

      this.checkingStatus = true;
      this.feedbackMessage = '';

      try {
        const result = await this.requestStatusSync();
        if (!result) {
          return;
        }

        const { response, payload } = result;

        if (!response.ok || !payload || !payload.success) {
          this.setFeedback(
            'danger',
            payload?.message || 'Gagal memeriksa status pembayaran ke server Midtrans.'
          );
          return;
        }

        if (payload.data?.is_paid) {
          this.refreshing = true;
          this.setFeedback('success', payload.message || 'Pembayaran telah dikonfirmasi lunas.');
          window.setTimeout(() => {
            window.location.assign(this.refreshUrl);
          }, 700);
          return;
        }

        if (['failed', 'expired'].includes(payload.data?.payment_status)) {
          this.refreshing = true;
          this.setFeedback('warning', payload.message || 'Status pembayaran telah diperbarui.');
          window.setTimeout(() => {
            window.location.assign(this.refreshUrl);
          }, 900);
          return;
        }

        this.setFeedback(
          'info',
          payload.message || 'Status pembayaran belum berubah menjadi lunas.'
        );
      } catch (error) {
        this.setFeedback(
          'danger',
          'Tidak dapat terhubung ke server untuk memeriksa status pembayaran.'
        );
      } finally {
        this.checkingStatus = false;
      }
    },

    async syncAfterSnapCallback(mode) {
      if (mode === 'success' || mode === 'pending') {
        this.refreshing = true;
        this.setFeedback(
          mode === 'success' ? 'success' : 'info',
          mode === 'success'
            ? 'Memverifikasi konfirmasi pembayaran ke server Midtrans...'
            : 'Memeriksa status transaksi terbaru dari server Midtrans...'
        );
      }

      try {
        const result = await this.requestStatusSync();
        if (result?.response?.ok && result?.payload?.success && result?.payload?.data?.is_paid) {
          this.refreshing = true;
          this.setFeedback('success', 'Pembayaran dikonfirmasi lunas oleh server. Memuat ulang halaman...');
          window.setTimeout(() => {
            window.location.assign(this.refreshUrl);
          }, 600);
          return;
        }
      } catch (error) {
        // Fallback ke reload/feedback standar jika sinkronisasi langsung sementara gagal.
      }

      if (mode === 'success' || mode === 'pending') {
        window.setTimeout(() => {
          window.location.assign(this.refreshUrl);
        }, 1000);
      } else if (mode === 'close') {
        this.setFeedback(
          'warning',
          'Jendela pembayaran ditutup sebelum selesai. Jika Anda sudah membayar, tekan tombol Cek Status Pembayaran.'
        );
      }
    },

    async startPayment() {
      if (this.loading || this.refreshing || this.checkingStatus || !this.initiateUrl) {
        return;
      }

      this.loading = true;
      this.feedbackMessage = '';

      try {
        const response = await fetch(this.initiateUrl, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': this.csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({}),
        });

        const payload = await response.json().catch(() => null);

        if (!response.ok || !payload || !payload.success || !payload.data || !payload.data.snap_token) {
          const message =
            payload && payload.message
              ? payload.message
              : 'Sesi pembayaran belum dapat dimulai saat ini. Silakan coba beberapa saat lagi.';
          this.setFeedback('danger', message);
          return;
        }

        if (!window.snap || typeof window.snap.pay !== 'function') {
          this.setFeedback(
            'warning',
            'Skrip pembayaran Midtrans Snap gagal dimuat di browser. Pastikan koneksi atau pemblokir iklan tidak menghalangi Midtrans lalu muat ulang halaman.'
          );
          return;
        }

        window.snap.pay(payload.data.snap_token, {
          onSuccess: () => {
            this.syncAfterSnapCallback('success');
          },
          onPending: () => {
            this.syncAfterSnapCallback('pending');
          },
          onError: () => {
            this.setFeedback(
              'danger',
              'Terjadi kendala pada jendela pembayaran Midtrans. Anda dapat menekan tombol Bayar Sekarang untuk mencoba lagi.'
            );
          },
          onClose: () => {
            this.syncAfterSnapCallback('close');
          },
        });
      } catch (error) {
        this.setFeedback(
          'danger',
          'Tidak dapat terhubung ke server untuk memulai pembayaran. Silakan periksa koneksi internet Anda.'
        );
      } finally {
        this.loading = false;
      }
    },
  }));

  Alpine.data('settingsTabsPanel', (config) => ({
    activeTab: config.initialTab || 'toko',
    midtransCheckUrl: config.midtransCheckUrl || '',
    rajaongkirCheckUrl: config.rajaongkirCheckUrl || '',
    showMidtransKey: false,
    showRajaOngkirKey: false,
    checks: {
      midtrans: { loading: false, result: null },
      rajaongkir: { loading: false, result: null },
    },

    switchTab(tab) {
      if (!['toko', 'midtrans', 'rajaongkir'].includes(tab)) {
        return;
      }
      this.activeTab = tab;
      try {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url.toString());
      } catch (e) {
        // Abaikan jika browser tidak mendukung History API
      }
    },

    csrfToken() {
      return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    },

    async copyText(text, label) {
      if (!text) return;
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(text);
          if (Alpine.store('toast')) {
            Alpine.store('toast').push(`${label} berhasil disalin ke clipboard.`, 'success');
          }
        }
      } catch (e) {
        if (Alpine.store('toast')) {
          Alpine.store('toast').push(`Salin manual: ${text}`, 'info');
        }
      }
    },

    async checkProvider(provider) {
      const targetUrl = provider === 'midtrans' ? this.midtransCheckUrl : this.rajaongkirCheckUrl;
      if (!targetUrl || !this.checks[provider] || this.checks[provider].loading) {
        return;
      }

      this.checks[provider].loading = true;
      this.checks[provider].result = null;

      try {
        const response = await fetch(targetUrl, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': this.csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({}),
        });

        const payload = await response.json().catch(() => null);

        if (!response.ok || !payload || typeof payload.success !== 'boolean') {
          this.checks[provider].result = {
            success: false,
            status: 'http_error',
            message: payload?.message || `Gagal memeriksa koneksi (${response.status}).`,
            details: payload?.details || {},
          };
          return;
        }

        this.checks[provider].result = payload;
      } catch (error) {
        this.checks[provider].result = {
          success: false,
          status: 'network_error',
          message: 'Tidak dapat terhubung ke server aplikasi untuk menjalankan uji koneksi.',
          details: {},
        };
      } finally {
        this.checks[provider].loading = false;
      }
    },
  }));

  Alpine.data('articleFormHelper', (config) => ({
    title: config.title || '',
    slug: config.slug || '',
    slugEditedManually: Boolean(config.slugEditedManually),

    slugify(value) {
      return String(value || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9\s-]/g, '')
        .trim()
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-');
    },

    onTitleInput() {
      if (!this.slugEditedManually) {
        this.slug = this.slugify(this.title);
      }
    },

    onSlugInput() {
      this.slugEditedManually = this.slug.trim() !== '';
    },

    regenerateSlug() {
      this.slug = this.slugify(this.title);
      this.slugEditedManually = false;
    },
  }));

  Alpine.data('cmsRichEditor', () => ({
    wrapSelection(before, after, placeholder = 'teks') {
      const textarea = this.$refs.textarea;
      if (!textarea) return;

      const start = textarea.selectionStart ?? 0;
      const end = textarea.selectionEnd ?? 0;
      const value = textarea.value || '';
      const selected = value.slice(start, end) || placeholder;
      const next = value.slice(0, start) + before + selected + after + value.slice(end);

      textarea.value = next;
      textarea.focus();
      const cursorStart = start + before.length;
      textarea.setSelectionRange(cursorStart, cursorStart + selected.length);
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
    },

    insertBlock(snippet) {
      const textarea = this.$refs.textarea;
      if (!textarea) return;

      const start = textarea.selectionStart ?? textarea.value.length;
      const end = textarea.selectionEnd ?? textarea.value.length;
      const value = textarea.value || '';
      const prefix = start > 0 && !value.slice(0, start).endsWith('\n') ? '\n' : '';
      const next = value.slice(0, start) + prefix + snippet + '\n' + value.slice(end);

      textarea.value = next;
      textarea.focus();
      const pos = start + prefix.length + snippet.length;
      textarea.setSelectionRange(pos, pos);
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
    },
  }));
});
