<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('PERMIT_ENTRY')) { http_response_code(404); exit; }

/** Deliberately explicit: a route is never interpreted as an arbitrary file path. */
function mobile_routes(): array
{
    return [
        'index.php', 'login.php', 'register.php', 'verify-registration.php', 'setup-admin.php',
        'forgot-password.php', 'verify-reset-otp.php', 'reset-password.php', 'logout.php',
        'dashboard.php', 'apply.php', 'renew.php', 'track.php', 'application.php',
        'payments.php', 'payment.php', 'receipt.php', 'certificate.php', 'verify-permit.php',
        'document.php', 'document-version.php', 'document-history.php', 'payment-proof.php',
        'admin/index.php', 'admin/review.php', 'admin/scan-document.php',
        'admin/analytics.php', 'admin/generate-insights.php', 'admin/payments.php',
        'admin/payment.php', 'admin/payment-action.php', 'admin/fee-settings.php',
        'admin/assignments.php', 'admin/reports.php', 'admin/users.php', 'admin/email-log.php',
        'api/reupload-document.php', 'api/notifications.php', 'api/chatbot.php',
        'api/search-suggestions.php',
    ];
}

/** Convert server template semantics into data, never executable HTML or JavaScript. */
final class MobileScreen
{
    private int $sequence = 0;
    private DOMXPath $xpath;

    public function __construct(private string $route, private string $basePath) {}

    public function parse(string $html): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->xpath = new DOMXPath($dom);
        $title = $this->text($this->xpath->query('//title')->item(0)?->textContent ?? 'PERMIT');
        $navigation = [];
        foreach ($this->xpath->query('//aside[contains(concat(" ",normalize-space(@class)," ")," sidebar ")]//nav/a') as $link) {
            $copy = $link->cloneNode(true);
            foreach (iterator_to_array($copy->childNodes) as $child) {
                if ($child instanceof DOMElement && $child->tagName === 'span') $copy->removeChild($child);
            }
            $navigation[] = ['label' => $this->text($copy->textContent), 'href' => $this->href($link->getAttribute('href'))];
        }
        $root = $this->xpath->query('//section[contains(concat(" ",normalize-space(@class)," ")," page ")]')->item(0)
            ?? $this->xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," auth-card ")]')->item(0)
            ?? $this->xpath->query('//main')->item(0)
            ?? $this->xpath->query('//body')->item(0);
        $messages = [];
        foreach ($this->xpath->query('//*[@id="toastRegion"]/*') as $message) $messages[] = $this->text($message->textContent);
        return ['title' => preg_replace('/\s*\|\s*PERMIT$/i', '', $title), 'navigation' => $navigation,
            'messages' => $messages, 'nodes' => $root ? $this->children($root) : []];
    }

    private function text(string $value): string
    {
        $value = str_replace('Your browser will ask permission.', 'Your device will ask permission.', $value);
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    public function href(string $href): string
    {
        if ($href === '') return $this->route;
        if (preg_match('~^(?:https?://|mailto:|tel:)~i', $href)) return $href;
        if (str_starts_with($href, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $href)) return '';
        $parts = parse_url($href);
        if ($parts === false) return '';
        $path = $parts['path'] ?? '';
        $currentPath = explode('?', $this->route, 2)[0];
        if ($path === '') $path = $currentPath;
        elseif (str_starts_with($path, '/')) {
            $prefix = rtrim($this->basePath, '/') . '/';
            if (!str_starts_with(rawurldecode($path), rawurldecode($prefix))) return '';
            $path = substr(rawurldecode($path), strlen(rawurldecode($prefix)));
        } else {
            $directory = dirname($currentPath);
            $path = ($directory === '.' ? '' : $directory . '/') . $path;
        }
        if (!in_array($path, mobile_routes(), true)) return '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        if (!isset($parts['path']) && !isset($parts['query'])) $query = str_contains($this->route, '?') ? '?' . explode('?', $this->route, 2)[1] : '';
        return $path . $query;
    }

    private function children(DOMNode $parent): array
    {
        $out = [];
        foreach ($parent->childNodes as $child) {
            $node = $this->node($child);
            if ($node !== null) $out[] = $node;
        }
        return $out;
    }

    private function node(DOMNode $element): ?array
    {
        $key = 'n' . ++$this->sequence;
        if ($element instanceof DOMText) {
            $value = $this->text($element->textContent);
            return $value === '' ? null : ['key' => $key, 'kind' => 'text', 'text' => $value];
        }
        if (!$element instanceof DOMElement) return null;
        $tag = strtolower($element->tagName);
        $class = $element->getAttribute('class');
        $id = $element->getAttribute('id');
        if (in_array($tag, ['script', 'style', 'head', 'svg', 'canvas', 'noscript', 'template', 'iframe'], true)) return null;
        if (in_array($id, ['reviewSummary', 'notificationDropdown', 'searchSuggestions'], true)) return null;
        if (preg_match('/(?:^|\s)(?:stepper|upload-icon|document-icon|stat-icon)(?:\s|$)/', $class)) return null;
        if ($element->hasAttribute('data-permit-verification')) return ['key' => $key, 'kind' => 'qr', 'text' => $element->getAttribute('data-permit-verification')];
        if ($tag === 'img') return null; // Brand artwork is bundled in the native application.
        if ($tag === 'form') return ['key' => $key, 'kind' => 'form', 'href' => $this->href($element->getAttribute('action')),
            'method' => strtolower($element->getAttribute('method') ?: 'get'), 'children' => $this->children($element)];
        if ($tag === 'label') {
            $controls = [];
            foreach ($this->xpath->query('.//input | .//select | .//textarea | .//small', $element) as $control) {
                $parsed = $this->node($control);
                if ($parsed) $controls[] = $parsed;
            }
            return $controls ? ['key' => $key, 'kind' => 'group', 'children' => $controls] : null;
        }
        if (in_array($tag, ['input', 'select', 'textarea'], true)) {
            $name = $element->getAttribute('name');
            if ($name === '') return null;
            $type = $tag === 'input' ? strtolower($element->getAttribute('type') ?: 'text') : $tag;
            if ($type === 'submit') return ['key' => $key, 'kind' => 'button', 'action' => 'submit', 'name' => $name,
                'value' => $element->getAttribute('value'), 'text' => $element->getAttribute('value') ?: 'Submit'];
            $label = $this->xpath->query('ancestor::label[1]', $element)->item(0);
            if (!$label && $id !== '') {
                foreach ($this->xpath->query('//label[@for]') as $candidate) {
                    if ($candidate->getAttribute('for') === $id) { $label = $candidate; break; }
                }
            }
            $labelText = $element->getAttribute('aria-label');
            if ($label) {
                $copy = $label->cloneNode(true);
                foreach (['input', 'select', 'textarea', 'small'] as $removeTag) {
                    foreach (iterator_to_array($copy->getElementsByTagName($removeTag)) as $remove) $remove->parentNode->removeChild($remove);
                }
                $labelText = $this->text($copy->textContent);
                if ($type === 'file' && $copy->getElementsByTagName('strong')->length > 0) {
                    $labelText = $this->text($copy->getElementsByTagName('strong')->item(0)->textContent);
                }
            }
            $value = $type === 'textarea' ? $element->textContent : $element->getAttribute('value');
            if (in_array($type, ['checkbox', 'radio'], true) && !$element->hasAttribute('value')) $value = 'on';
            $node = ['key' => $key, 'kind' => 'field', 'type' => $type, 'name' => $name, 'label' => $labelText ?: str_replace('_', ' ', $name),
                'value' => $type === 'password' ? '' : $value, 'required' => $element->hasAttribute('required'),
                'disabled' => $element->hasAttribute('disabled'), 'readonly' => $element->hasAttribute('readonly'),
                'checked' => $element->hasAttribute('checked'), 'placeholder' => $element->getAttribute('placeholder'),
                'maxLength' => (int) $element->getAttribute('maxlength') ?: null, 'minLength' => (int) $element->getAttribute('minlength') ?: null,
                'inputMode' => $element->getAttribute('inputmode'), 'accept' => $element->getAttribute('accept')];
            if ($type === 'select') {
                $options = [];
                $selected = null;
                foreach ($element->getElementsByTagName('option') as $option) {
                    $optionValue = $option->hasAttribute('value') ? $option->getAttribute('value') : $this->text($option->textContent);
                    $options[] = ['label' => $this->text($option->textContent), 'value' => $optionValue, 'disabled' => $option->hasAttribute('disabled')];
                    if ($option->hasAttribute('selected')) $selected = $optionValue;
                }
                $node['options'] = $options;
                $node['value'] = $selected ?? ($options[0]['value'] ?? '');
            }
            return $node;
        }
        if ($tag === 'button') {
            if ($element->hasAttribute('data-next-step') || $element->hasAttribute('data-prev-step')) return null;
            $action = str_contains($element->getAttribute('onclick'), 'window.print') ? 'print'
                : ($element->hasAttribute('data-geolocate') ? 'location' : (($element->getAttribute('type') ?: 'submit') === 'submit' ? 'submit' : ''));
            if ($action === '') return null;
            return ['key' => $key, 'kind' => 'button', 'action' => $action, 'text' => $this->text($element->textContent),
                'name' => $element->getAttribute('name'), 'value' => $element->getAttribute('value'),
                'skipValidation' => $element->hasAttribute('formnovalidate'), 'disabled' => $element->hasAttribute('disabled'),
                'tone' => str_contains($class, 'danger') ? 'danger' : (str_contains($class, 'secondary') ? 'secondary' : 'primary')];
        }
        if ($tag === 'a') {
            $href = $this->href($element->getAttribute('href'));
            if ($href === '') return null;
            return ['key' => $key, 'kind' => 'link', 'href' => $href, 'text' => $this->text($element->textContent),
                'tone' => str_contains($class, 'button') ? 'button' : 'link'];
        }
        if ($tag === 'table') {
            $headers = [];
            foreach ($this->xpath->query('.//thead//th', $element) as $heading) $headers[] = $this->text($heading->textContent);
            $rows = [];
            foreach ($this->xpath->query('.//tbody/tr | ./tr | .//tfoot/tr', $element) as $row) {
                $cells = [];
                foreach ($row->childNodes as $cell) {
                    if ($cell instanceof DOMElement && in_array($cell->tagName, ['td', 'th'], true)) $cells[] = $this->children($cell);
                }
                if ($cells) $rows[] = $cells;
            }
            return ['key' => $key, 'kind' => 'table', 'headers' => $headers, 'rows' => $rows];
        }
        if (str_contains($class, 'forecast-column')) return ['key' => $key, 'kind' => 'text', 'text' => $element->getAttribute('title')];
        $children = $this->children($element);
        if (!$children) return null;
        $tone = preg_match('/(?:^|\s)(?:panel|stat-card|certificate-card|ai-document-row)(?:\s|$)/', $class) ? 'card' : 'plain';
        if (str_contains($class, 'form-alert') || str_contains($class, 'toast') || str_contains($class, 'release-guard')) $tone = 'notice';
        if (str_contains($class, 'error')) $tone = 'error';
        if (preg_match('/(?:^|\s)status(?:\s|$)/', $class)) $tone = 'badge';
        if ($tag === 'details') return ['key' => $key, 'kind' => 'details', 'children' => $children];
        // Inline prose stays together. Any interactive descendants remain native controls.
        $interactive = $this->xpath->query('.//input | .//select | .//textarea | .//a | .//button', $element)->length > 0;
        if (!$interactive && in_array($tag, ['h1','h2','h3','h4','h5','p','small','strong','b','span','dt','dd','legend','li','summary','address','code','time','em'], true)) {
            return ['key' => $key, 'kind' => 'text', 'text' => $this->text($element->textContent),
                'tone' => $tone !== 'plain' ? $tone : (str_starts_with($tag, 'h') ? 'heading' : (in_array($tag, ['strong','b','legend'], true) ? 'bold' : (in_array($tag, ['small','dt'], true) ? 'muted' : 'plain')))];
        }
        return ['key' => $key, 'kind' => 'group', 'tone' => $tone, 'children' => $children];
    }
}
