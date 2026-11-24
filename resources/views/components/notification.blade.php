@php
    $hasSession = request()->hasSession();
    $successMessage = $hasSession ? session('success') : null;
    $warningMessage = $hasSession ? session('warning') : null;
    $errorMessage = $hasSession ? session('error') : null;
@endphp

@if($successMessage)
    <x-alert type="success" class="mb-3">{{ $successMessage }}</x-alert>
@endif

@if($warningMessage)
    <x-alert type="warning" class="mb-3">{{ $warningMessage }}</x-alert>
@endif

@if($errorMessage)
    <x-alert type="danger" class="mb-3">{{ $errorMessage }}</x-alert>
@endif

@if(isset($errors) && $errors->any())
    <x-alert type="danger" title="Periksa kembali input Anda" class="mb-3">
        <ul class="mb-0 ps-3 small">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-alert>
@endif

<div x-data class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080; max-width: 360px;">
    <template x-for="item in ($store.toast ? $store.toast.items : [])" :key="item.id">
        <div class="toast show align-items-center border-0 shadow mb-2"
             :class="item.type === 'error' ? 'text-bg-danger' : 'text-bg-success'"
             role="alert">
            <div class="d-flex">
                <div class="toast-body" x-text="item.message"></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" @click="$store.toast.dismiss(item.id)"></button>
            </div>
        </div>
    </template>
</div>
