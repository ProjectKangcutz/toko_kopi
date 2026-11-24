<div
    x-data
    x-show="$store.confirmModal && $store.confirmModal.open"
    x-cloak
    :class="{ 'd-block show': $store.confirmModal && $store.confirmModal.open }"
    @click.self="if ($store.confirmModal) $store.confirmModal.open = false"
    @keydown.escape.window="if ($store.confirmModal) $store.confirmModal.open = false"
    class="modal fade"
    style="background: rgba(36, 43, 39, 0.5);"
    role="dialog"
    aria-modal="true"
>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom">
                <h5 class="modal-title fw-bold" x-text="$store.confirmModal?.title || ''"></h5>
                <button type="button" class="btn-close" @click="if ($store.confirmModal) $store.confirmModal.open = false" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0 text-mlm-muted" x-text="$store.confirmModal?.message || ''"></p>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-outline-secondary" style="min-height: 44px;" @click="if ($store.confirmModal) $store.confirmModal.open = false">Batal</button>
                <button type="button" class="btn" :class="$store.confirmModal?.confirmClass || 'btn-primary'" style="min-height: 44px;" @click="$store.confirmModal?.confirm()" x-text="$store.confirmModal?.confirmLabel || 'Konfirmasi'"></button>
            </div>
        </div>
    </div>
</div>
