@props(['headers' => []])

<div class="table-responsive">
    <table {{ $attributes->merge(['class' => 'table align-middle mlm-responsive-table mb-0']) }}>
        @if(count($headers) > 0)
            <thead class="table-light">
                <tr>
                    @foreach($headers as $header)
                        <th scope="col" class="small text-uppercase text-mlm-muted fw-semibold">{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
        @endif
        <tbody>
            {{ $slot }}
        </tbody>
    </table>
</div>
