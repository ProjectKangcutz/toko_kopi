<?php

namespace App\Services\Content;

use DOMDocument;
use DOMElement;
use DOMNode;

class HtmlSanitizer
{
    /**
     * @var array<int, string>
     */
    protected const ALLOWED_TAGS = [
        'p', 'br', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'hr',
        'a', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
    ];

    /**
     * @var array<int, string>
     */
    protected const DROP_WITH_CONTENT_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet',
        'svg', 'math', 'form', 'input', 'button', 'textarea', 'select',
        'link', 'meta', 'base', 'noscript',
    ];

    public function sanitize(?string $html): string
    {
        $raw = str_replace("\0", '', trim((string) $html));
        if ($raw === '') {
            return '';
        }

        // Jika konten belum mengandung tag HTML sama sekali, ubah paragraf baris baru secara aman.
        if (! str_contains($raw, '<') && ! str_contains($raw, '>')) {
            $lines = preg_split("/\r\n|\r|\n/", $raw) ?: [];
            $paragraphs = array_filter(array_map(static fn (string $line) => trim($line), $lines), static fn (string $line) => $line !== '');

            if ($paragraphs === []) {
                return '';
            }

            return implode("\n", array_map(
                static fn (string $p): string => '<p>'.e($p).'</p>',
                $paragraphs
            ));
        }

        $previousUseErrors = libxml_use_internal_errors(true);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="utf-8" ?><div id="mlm-sanitize-root">'.$raw.'</div>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        $root = $doc->getElementById('mlm-sanitize-root');
        if (! $root instanceof DOMElement) {
            return e(strip_tags($raw));
        }

        $this->sanitizeChildren($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $doc->saveHTML($child);
        }

        return trim($output);
    }

    public function sanitizeUrl(?string $url, bool $allowMailAndTel = false): ?string
    {
        $cleaned = str_replace("\0", '', trim((string) $url));
        if ($cleaned === '') {
            return null;
        }

        // Tolak protokol berbahaya dan path protocol-relative //evil.com
        if (str_starts_with($cleaned, '//')) {
            return null;
        }

        if (str_starts_with($cleaned, '/')) {
            return $cleaned;
        }

        if ($allowMailAndTel && preg_match('/^(mailto:[^\s@]+@[^\s@]+\.[^\s@]+|tel:\+?[0-9\-\s]{5,25})$/i', $cleaned)) {
            return $cleaned;
        }

        if (filter_var($cleaned, FILTER_VALIDATE_URL)) {
            $scheme = strtolower((string) parse_url($cleaned, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) {
                return $cleaned;
            }
        }

        return null;
    }

    protected function sanitizeChildren(DOMNode $parent): void
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $node) {
            if ($node->nodeType === XML_COMMENT_NODE) {
                $parent->removeChild($node);
                continue;
            }

            if ($node->nodeType === XML_TEXT_NODE) {
                continue;
            }

            if (! $node instanceof DOMElement) {
                $parent->removeChild($node);
                continue;
            }

            $tag = strtolower($node->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT_TAGS, true)) {
                $parent->removeChild($node);
                continue;
            }

            $this->sanitizeChildren($node);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                // Unwrap elemen tidak dikenal namun pertahankan teks/anaknya yang sudah disanitasi.
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }

            $this->sanitizeAttributes($node, $tag);
        }
    }

    protected function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $attrNames = [];
        foreach ($element->attributes as $attr) {
            $attrNames[] = $attr->name;
        }

        if ($tag === 'a') {
            $href = $this->sanitizeUrl($element->getAttribute('href'), allowMailAndTel: true);
            $title = trim($element->getAttribute('title'));
            $target = trim($element->getAttribute('target'));

            foreach ($attrNames as $name) {
                $element->removeAttribute($name);
            }

            if ($href !== null) {
                $element->setAttribute('href', $href);
                if ($target === '_blank') {
                    $element->setAttribute('target', '_blank');
                    $element->setAttribute('rel', 'noopener noreferrer');
                }
            }

            if ($title !== '') {
                $element->setAttribute('title', mb_substr($title, 0, 150));
            }

            return;
        }

        if ($tag === 'img') {
            $src = $this->sanitizeUrl($element->getAttribute('src'), allowMailAndTel: false);
            $alt = trim($element->getAttribute('alt'));
            $title = trim($element->getAttribute('title'));

            foreach ($attrNames as $name) {
                $element->removeAttribute($name);
            }

            if ($src === null) {
                $element->parentNode?->removeChild($element);
                return;
            }

            $element->setAttribute('src', $src);
            $element->setAttribute('alt', mb_substr($alt, 0, 200));
            $element->setAttribute('class', 'img-fluid rounded-3 my-3');
            if ($title !== '') {
                $element->setAttribute('title', mb_substr($title, 0, 150));
            }

            return;
        }

        foreach ($attrNames as $name) {
            $element->removeAttribute($name);
        }
    }
}
