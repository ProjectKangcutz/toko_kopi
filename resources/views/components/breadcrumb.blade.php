@props(['items' => []])

@if(count($items) > 0)
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0 small">
        @foreach($items as $item)
            @if(!empty($item['url']) && !$loop->last)
                <li class="breadcrumb-item"><a href="{{ $item['url'] }}" class="text-decoration-none text-mlm-primary">{{ $item['label'] }}</a></li>
            @else
                <li class="breadcrumb-item active text-mlm-muted" aria-current="page">{{ $item['label'] }}</li>
            @endif
        @endforeach
    </ol>
</nav>
@endif
