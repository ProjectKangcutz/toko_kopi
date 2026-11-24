@props([
    'id' => 'cmsContent',
    'name' => 'content',
    'label' => 'Konten',
    'value' => '',
    'rows' => 12,
    'required' => false,
    'placeholder' => 'Tulis konten di sini...',
    'help' => 'Mendukung teks biasa maupun HTML aman (p, h2, h3, strong, em, ul, ol, li, blockquote, a). Script dan atribut berbahaya akan disanitasi otomatis.',
])

<div x-data="cmsRichEditor()">
    <label for="{{ $id }}" class="form-label small fw-semibold">
        {{ $label }} @if($required)<span class="text-danger">*</span>@endif
    </label>

    <div class="mlm-editor-toolbar d-flex flex-wrap gap-1" role="toolbar" aria-label="Alat format teks">
        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" @click="wrapSelection('<h2>', '</h2>', 'Subjudul')" title="Subjudul H2">
            H2
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" @click="wrapSelection('<h3>', '</h3>', 'Subjudul Kecil')" title="Subjudul H3">
            H3
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" @click="wrapSelection('<p>', '</p>', 'Paragraf')" title="Paragraf">
            P
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" @click="wrapSelection('<strong>', '</strong>', 'teks tebal')" title="Tebal">
            <i class="bi bi-type-bold" aria-hidden="true"></i>
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" @click="wrapSelection('<em>', '</em>', 'teks miring')" title="Miring">
            <i class="bi bi-type-italic" aria-hidden="true"></i>
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" @click="insertBlock('<ul>\n  <li>Poin pertama</li>\n  <li>Poin kedua</li>\n</ul>')" title="Daftar Poin">
            <i class="bi bi-list-ul" aria-hidden="true"></i>
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" @click="insertBlock('<ol>\n  <li>Langkah pertama</li>\n  <li>Langkah kedua</li>\n</ol>')" title="Daftar Bernomor">
            <i class="bi bi-list-ol" aria-hidden="true"></i>
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" @click="wrapSelection('<blockquote>', '</blockquote>', 'Kutipan penting')" title="Kutipan">
            <i class="bi bi-quote" aria-hidden="true"></i>
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2" @click="wrapSelection('<a href=&quot;https://&quot;>', '</a>', 'Teks Tautan')" title="Tautan">
            <i class="bi bi-link-45deg" aria-hidden="true"></i>
        </button>
    </div>

    <textarea
        x-ref="textarea"
        id="{{ $id }}"
        name="{{ $name }}"
        rows="{{ $rows }}"
        class="form-control mlm-editor-textarea @error($name) is-invalid @enderror"
        placeholder="{{ $placeholder }}"
        @if($required) required @endif
    >{{ $value }}</textarea>

    @error($name)
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
    @if($help)
        <div class="form-text small">{{ $help }}</div>
    @endif
</div>
