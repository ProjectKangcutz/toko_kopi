@props(['paginator'])

@if($paginator && $paginator->hasPages())
    <div class="mt-3 d-flex justify-content-center justify-content-md-end">
        {{ $paginator->withQueryString()->links() }}
    </div>
@endif
