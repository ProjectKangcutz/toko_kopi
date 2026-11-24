@extends('layouts.storefront')

@php
    $storeName = $storeSettings['store_name'] ?? 'Pasar Lokal Nusantara';
    $pageTitle = 'Pertanyaan Umum (FAQ) — '.$storeName;
    $metaDescription = 'Jawaban atas pertanyaan yang sering diajukan mengenai pemesanan, pembayaran Midtrans, ongkos kirim, dan pengiriman di '.$storeName.'.';

    $faqEntities = [];
    foreach ($groupedFaqs as $faqs) {
        foreach ($faqs as $faq) {
            $faqEntities[] = [
                '@type' => 'Question',
                'name' => $faq->question,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => trim(strip_tags($sanitizedAnswers[$faq->id] ?? $faq->answer)),
                ],
            ];
        }
    }

    $faqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $faqEntities,
    ];
@endphp

@section('title', $pageTitle)
@section('meta_description', $metaDescription)
@section('canonical', route('storefront.faq'))
@section('og_title', $pageTitle)
@section('og_description', $metaDescription)

@if(count($faqEntities) > 0)
    @push('structured_data')
        <script type="application/ld+json">{!! json_encode($faqSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    @endpush
@endif

@section('content')
<div class="container py-4 py-md-5">
    <x-breadcrumb :items="[
        ['label' => 'Beranda', 'url' => route('storefront.home')],
        ['label' => 'FAQ'],
    ]" />

    <div class="row justify-content-center">
        <div class="col-12 col-lg-9">
            <div class="mb-4">
                <h1 class="h2 fw-bold text-mlm-primary mb-2">Pertanyaan yang Sering Diajukan (FAQ)</h1>
                <p class="text-mlm-muted mb-0">Temukan panduan cepat seputar pemesanan, pembayaran, pengiriman, dan layanan pelanggan {{ $storeName }}.</p>
            </div>

            @if($groupedFaqs->isEmpty())
                <div class="mlm-card p-4 p-md-5">
                    <x-empty-state
                        icon="bi-question-circle"
                        title="Belum Ada FAQ Tersedia"
                        message="Informasi pertanyaan umum akan segera tersedia. Silakan hubungi tim kami jika Anda membutuhkan bantuan."
                        action-label="Hubungi Kami"
                        :action-url="route('storefront.contact')"
                    />
                </div>
            @else
                <div class="d-flex flex-column gap-4">
                    @foreach($groupedFaqs as $category => $faqs)
                        <section class="mlm-card p-3 p-md-4">
                            <h2 class="h5 fw-bold text-mlm-primary mb-3">
                                <i class="bi bi-folder2-open me-2" aria-hidden="true"></i>{{ $category }}
                            </h2>

                            <div class="accordion accordion-flush" id="faqAccordion-{{ \Illuminate\Support\Str::slug($category) }}-{{ $loop->index }}">
                                @foreach($faqs as $faq)
                                    @php
                                        $parentId = 'faqAccordion-'.\Illuminate\Support\Str::slug($category).'-'.$loop->parent->index;
                                        $collapseId = 'faqCollapse-'.$faq->id;
                                        $isFirst = $loop->parent->first && $loop->first;
                                    @endphp
                                    <div class="accordion-item border rounded-3 mb-2 overflow-hidden">
                                        <h3 class="accordion-header">
                                            <button
                                                class="accordion-button {{ $isFirst ? '' : 'collapsed' }} fw-semibold"
                                                type="button"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#{{ $collapseId }}"
                                                aria-expanded="{{ $isFirst ? 'true' : 'false' }}"
                                                aria-controls="{{ $collapseId }}"
                                            >
                                                {{ $faq->question }}
                                            </button>
                                        </h3>
                                        <div
                                            id="{{ $collapseId }}"
                                            class="accordion-collapse collapse {{ $isFirst ? 'show' : '' }}"
                                            data-bs-parent="#{{ $parentId }}"
                                        >
                                            <div class="accordion-body mlm-prose small text-mlm-muted">
                                                {!! $sanitizedAnswers[$faq->id] ?? e($faq->answer) !!}
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>
            @endif

            <div class="mlm-card p-4 bg-mlm-secondary border-0 mt-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h2 class="h6 fw-bold text-mlm-primary mb-1">Masih Membutuhkan Bantuan?</h2>
                    <p class="small text-mlm-muted mb-0">Tim layanan pelanggan kami siap membantu Anda pada jam operasional toko.</p>
                </div>
                <a href="{{ route('storefront.contact') }}" class="btn btn-primary flex-shrink-0">
                    Hubungi Admin
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
