<?php

declare(strict_types=1);

namespace LemurAse\FormManagement\UI;

use LemurAse\FormManagement\Domain\FieldDefinition;
use LemurAse\FormManagement\Domain\FieldType;
use LemurAse\FormManagement\Domain\GatewayFormDefinitionInterface;

/**
 * AbstractFormManager — Reusable base for all ASE form managers.
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  RESPONSIBILITIES OF THIS BASE CLASS                                 │
 * │  · Stateless HMAC-based CSRF protection (no session required)        │
 * │  · HTML-escaping helper to prevent XSS                               │
 * │  · Sensitive-field masking sentinel                                  │
 * │  · Generic field renderer (input, checkbox, textarea, select, ...)   │
 * │  · Custom PHP template renderer with variable injection              │
 * │                                                                      │
 * │  WHAT SUBCLASSES ADD                                                 │
 * │  · Domain-specific renderForm(), processSubmission(), etc.           │
 * │  · Their own registry or definition resolution                       │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Usage: extend and call parent::generateCsrfToken(), parent::e(), etc.
 * All concrete subclasses are expected to remain non-instantiable (private
 * constructor pattern), so this base constructor is also private.
 */
abstract class AbstractFormManager
{
    /**
     * Tracks whether the AseFormManager.js block has already been emitted
     * on this request/page so it is never duplicated.
     */
    private static bool $jsInjected = false;

    /** Non-instantiable. */
    private function __construct()
    {
    }

    // =========================================================================
    // CSRF — Stateless HMAC token (works without framework sessions)
    // =========================================================================

    /**
     * Generate a signed CSRF token valid for 1 hour.
     *
     * Token format (base64url-encoded):  userId|action|expires|hmac
     *
     * @param string $userId  Identifies the user; used in HMAC validation
     * @param string $action  Scopes the token (e.g. "gateway:stripe")
     */
    public static function generateCsrfToken(string $userId, string $action): string
    {
        $expires = time() + 3600;
        $payload = implode('|', [$userId, $action, $expires]);
        $hmac = hash_hmac('sha256', $payload, self::csrfSecret());

        return base64_encode($payload . '|' . $hmac);
    }

    /**
     * Validate a CSRF token.
     *
     * Returns false (does NOT throw) — callers decide the error response
     * (redirect, 403, JSON, etc.).
     */
    public static function validateCsrfToken(
        string $token,
        string $userId,
        string $action,
    ): bool {
        $decoded = base64_decode($token, strict: true);
        if ($decoded === false) {
            return false;
        }

        $parts = explode('|', $decoded, 4);
        if (count($parts) !== 4) {
            return false;
        }

        [$tUser, $tAction, $expires, $hmac] = $parts;

        if ((int) $expires < time()) {
            return false; // expired
        }

        if ($tUser !== $userId || $tAction !== $action) {
            return false; // mismatch
        }

        $payload = implode('|', [$tUser, $tAction, $expires]);
        $expected = hash_hmac('sha256', $payload, self::csrfSecret());

        return hash_equals($expected, $hmac); // timing-safe comparison
    }

    // =========================================================================
    // Field rendering — Generic HTML renderer shared by all form managers
    // =========================================================================

    /**
     * Render a custom PHP template, injecting a standard set of variables.
     *
     * Templates receive:
     *   $definition     GatewayFormDefinitionInterface
     *   $fields         FieldDefinition[]
     *   $groups         array[]
     *   $values         array  (possibly masked, keyed by field name)
     *   $csrfToken      string
     *   $formAttributes array
     *   $isEdit         bool
     *   $provider       string
     */
    protected static function renderCustomTemplate(
        string $templatePath,
        GatewayFormDefinitionInterface $definition,
        array $values,
        string $csrfToken,
        array $formAttributes = [],
    ): string {
        if (!is_file($templatePath) || !is_readable($templatePath)) {
            throw new \InvalidArgumentException(
                "Template not found or not readable: {$templatePath}"
            );
        }

        // Variables available inside the template file
        $fields = $definition->fields();
        $groups = $definition->groups();
        $provider = $definition->provider();
        $isEdit = !empty(array_filter($values));

        ob_start();
        include $templatePath;

        return (string) ob_get_clean();
    }

    /**
     * Render a single FieldDefinition as an HTML string.
     *
     * Field type matrix:
     *  · isSensitive=true (edit) → Sensitive widget: partial mask + show/copy/change actions
     *  · type=PASSWORD (non-sensitive) → Password input + show/hide toggle
     *  · type=CHECKBOX  → Styled checkbox
     *  · type=TEXTAREA  → <textarea>
     *  · type=SELECT    → <select> populated from validation.enum
     *  · everything else → <input type="{type}">
     *
     * @param FieldDefinition $field
     * @param mixed           $value  The actual stored value (NOT the masked sentinel)
     * @param bool            $isEdit Whether this is an edit form
     */
    protected static function renderField(
        FieldDefinition $field,
        mixed $value,
        bool $isEdit,
        ?string $error = null,
    ): string {
        $name = self::e($field->name());
        $id = 'ase_field_' . self::e($field->name());
        $label = self::e($field->label());
        $help = $field->helpText()
            ? '<p class="ase-field__help">' . self::e($field->helpText()) . '</p>'
            : '';
        $errorHtml = $error
            ? '<p class="ase-field__error">' . self::e($error) . '</p>'
            : '';
        $req = $field->isRequired() ? ' <span class="ase-required" aria-hidden="true">*</span>' : '';
        $condAttr = '';

        if ($field->condition() !== null) {
            $condAttr = ' data-condition="' . self::e(json_encode($field->condition())) . '"';
        }

        $wrapperClass = 'ase-field';
        if ($field->condition() !== null) {
            $wrapperClass .= ' ase-field--conditional';
        }
        if ($error) {
            $wrapperClass .= ' ase-field--error';
        }

        // ── CHECKBOX ────────────────────────────────────────────────────────
        if ($field->type() === FieldType::CHECKBOX) {
            $checkedValue = $value ?? $field->default();
            $isChecked = in_array($checkedValue, [true, 1, '1', 'on', 'true'], strict: false);
            $checkedHtml = $isChecked ? ' checked' : '';

            return (<<<HTML
                <div class="{$wrapperClass}" data-field="{$name}"{$condAttr}>
                    <label class="ase-field__checkbox-label" for="{$id}">
                        <input
                            type="checkbox"
                            id="{$id}"
                            name="{$name}"
                            value="on"
                            class="ase-field__checkbox"
                            {$checkedHtml}
                        >
                        {$label}{$req}
                    </label>
                    {$help}
                    {$errorHtml}
                </div>
            HTML);
        }

        // ── TEXTAREA ─────────────────────────────────────────────────────────
        if ($field->type() === FieldType::TEXTAREA) {
            $placeholder = self::e($field->placeholder() ?? '');
            $required = $field->isRequired() ? ' required' : '';
            $textValue = self::e((string) ($value ?? ''));

            return (<<<HTML
                <div class="{$wrapperClass}" data-field="{$name}"{$condAttr}>
                    <label class="ase-field__label" for="{$id}">{$label}{$req}</label>
                    <textarea
                        id="{$id}"
                        name="{$name}"
                        placeholder="{$placeholder}"
                        class="ase-field__input ase-field__textarea"
                        {$required}
                        autocomplete="off"
                    >{$textValue}</textarea>
                    {$help}
                    {$errorHtml}
                </div>
            HTML);
        }

        // ── SELECT ───────────────────────────────────────────────────────────
        if ($field->type() === FieldType::SELECT) {
            $required = $field->isRequired() ? ' required' : '';
            $options = (array) ($field->validation()['enum'] ?? []);
            $opts = '';

            foreach ($options as $opt) {
                $optEsc = self::e((string) $opt);
                $selected = ((string) $value === (string) $opt) ? ' selected' : '';
                $opts .= "<option value=\"{$optEsc}\"{$selected}>{$optEsc}</option>\n";
            }

            return (<<<HTML
                <div class="{$wrapperClass}" data-field="{$name}"{$condAttr}>
                    <label class="ase-field__label" for="{$id}">{$label}{$req}</label>
                    <select id="{$id}" name="{$name}" class="ase-field__select" {$required}>
                        {$opts}
                    </select>
                    {$help}
                    {$errorHtml}
                </div>
            HTML);
        }

        // ── SENSITIVE fields in edit mode (any type): widget with partial mask ─
        if ($field->isSensitive() && $isEdit && (string) $value !== '') {
            return self::renderSensitiveWidget(
                field: $field,
                value: (string) $value,
                wrapperClass: $wrapperClass,
                condAttr: $condAttr,
                label: $label,
                req: $req,
                help: $help,
                errorHtml: $errorHtml,
            );
        }

        // ── PASSWORD (non-sensitive): simple show/hide toggle ────────────────
        if ($field->type() === FieldType::PASSWORD) {
            $placeholder = self::e($field->placeholder() ?? '');
            $required = $field->isRequired() ? ' required' : '';
            $inputValue = self::e((string) ($value ?? ''));

            return (<<<HTML
                <div class="{$wrapperClass}" data-field="{$name}"{$condAttr}>
                    <label class="ase-field__label" for="{$id}">{$label}{$req}</label>
                    <div class="ase-input-group" data-ase-password-wrapper>
                        <div class="ase-input-group__content">
                            <input
                                type="password"
                                id="{$id}"
                                name="{$name}"
                                value="{$inputValue}"
                                placeholder="{$placeholder}"
                                class="ase-field__input ase-input-group__input"
                                {$required}
                                autocomplete="new-password"
                            >
                        </div>
                        <div class="ase-input-group__actions">
                            <button type="button" class="ase-field__icon-btn"
                                    data-ase-password-toggle
                                    title="Mostrar contraseña" aria-label="Mostrar contraseña">
                            </button>
                        </div>
                    </div>
                    {$help}
                    {$errorHtml}
                </div>
            HTML);
        }

        // ── ALL OTHER INPUTS (text, email, url, number, date, phone…) ────────
        $type = self::e($field->type()->value);
        $placeholder = self::e($field->placeholder() ?? '');
        $required = $field->isRequired() ? ' required' : '';
        $maxLen = '';

        if (isset($field->validation()['maxLength'])) {
            $maxLen = ' maxlength="' . (int) $field->validation()['maxLength'] . '"';
        }

        $inputValue = self::e((string) ($value ?? ''));

        return (<<<HTML
            <div class="{$wrapperClass}" data-field="{$name}"{$condAttr}>
                <label class="ase-field__label" for="{$id}">{$label}{$req}</label>
                <input
                    type="{$type}"
                    id="{$id}"
                    name="{$name}"
                    value="{$inputValue}"
                    placeholder="{$placeholder}"
                    class="ase-field__input"
                    {$required}{$maxLen}
                    autocomplete="off"
                >
                {$help}
                {$errorHtml}
            </div>
        HTML);
    }

    /**
     * Render the sensitive-field widget:
     *  · A read-only display showing a partial mask of the stored value
     *  · A hidden input (<value>) so JS can copy it to clipboard
     *  · Toggle (show/hide), Copy, and Change buttons
     *  · A collapsible editor for entering a replacement value
     *  · A hidden submit input (empty = keep existing; new value = update)
     *
     * The server NEVER re-sends the full value in a regular input,
     * so the existing credential is preserved unless the user explicitly changes it.
     */
    private static function renderSensitiveWidget(
        FieldDefinition $field,
        string $value,
        string $wrapperClass,
        string $condAttr,
        string $label,
        string $req,
        string $help,
        string $errorHtml,
    ): string {
        $name = self::e($field->name());
        $id = 'ase_field_' . self::e($field->name());
        $partialMask = self::e(self::computePartialMask($value));
        $fullValue = self::e($value);
        $placeholder = self::e($field->placeholder() ?? 'Ingresa el nuevo valor...');
        $required = $field->isRequired() ? ' required' : '';

        return (<<<HTML
            <div class="{$wrapperClass} ase-field--sensitive" data-field="{$name}"{$condAttr}>
                <label class="ase-field__label" for="{$id}">{$label}{$req}</label>

                <div class="ase-input-group" data-ase-sensitive="{$name}">

                    <!-- Hidden: stores the actual value so JS can copy/reveal it -->
                    <input type="hidden" data-ase-sensitive-value value="{$fullValue}">

                    <!-- Hidden submit input: empty = keep existing, filled = update -->
                    <input type="hidden" name="{$name}" value="" data-ase-sensitive-submit>

                    <div class="ase-input-group__content">
                        <!-- Read-only preview: Masked or Plain text -->
                        <div class="ase-sensitive__display" data-ase-sensitive-preview>
                            <span data-ase-sensitive-mask>{$partialMask}</span>
                            <span data-ase-sensitive-plain hidden>{$fullValue}</span>
                        </div>

                        <!-- Editor: Hidden until user clicks Edit (or reveals?) -->
                        <input
                            type="text"
                            id="{$id}"
                            class="ase-field__input ase-input-group__input ase-sensitive__new-value"
                            placeholder="{$placeholder}"
                            autocomplete="new-password"
                            hidden
                            data-ase-sensitive-new-input
                        >
                    </div>

                    <div class="ase-input-group__actions">
                        <button type="button" class="ase-field__icon-btn"
                                data-ase-sensitive-toggle
                                title="Mostrar y editar" aria-label="Mostrar y editar"></button>

                        <button type="button" class="ase-field__icon-btn"
                                data-ase-sensitive-copy
                                title="Copiar" aria-label="Copiar"></button>
                    </div>
                </div>

                {$help}
                {$errorHtml}
            </div>
        HTML);
    }

    /**
     * Compute a display mask that reveals a safe prefix and suffix of a value.
     *
     * Examples:
     *   'sk_test_abc123xyz'   → 'sk_test_••••••xyz'
     *   'whsec_shortvalue'    → 'whsec_•••••lue'
     *   'tiny'                → '••••'
     */
    private static function computePartialMask(string $value): string
    {
        $len = mb_strlen($value);

        if ($len <= 8) {
            return str_repeat('•', $len);
        }

        // Show up to 8 chars prefix (or 30% of length if shorter)
        $prefixLen = min(8, (int) round($len * 0.35));
        // Show last 3 chars as suffix
        $suffixLen = min(4, (int) round($len * 0.15));
        $dotCount = min(10, $len - $prefixLen - $suffixLen);

        return mb_substr($value, 0, $prefixLen)
            . str_repeat('•', $dotCount)
            . mb_substr($value, -$suffixLen);
    }

    /**
     * Return a <script> block containing AseFormManager.js and embedded CSS.
     * Emits the blocks only ONCE per PHP process/request (static flag guard).
     * Subsequent calls return an empty string.
     */
    protected static function injectJsOnce(): string
    {
        if (self::$jsInjected) {
            return '';
        }
        self::$jsInjected = true;

        $jsPath = __DIR__ . '/Assets/AseFormManager.js';
        $js = is_readable($jsPath) ? file_get_contents($jsPath) : '';
        $css = self::getEmbeddedStyles();

        return (<<<HTML
            <style id="ase-form-styles">{$css}</style>
            <script id="ase-form-manager-js">{$js}</script>
        HTML);
    }

    /**
     * Premium CSS for ASE forms.
     * Focused on making sensitive fields look like sleek, modern inputs
     * with integrated actions.
     */
    private static function getEmbeddedStyles(): string
    {
        return <<<CSS
            :root {
                --ase-primary: #000000;
                --ase-border: #e2e8f0;
                --ase-bg-input: #ffffff;
                --ase-text: #1e293b;
                --ase-text-muted: #64748b;
                --ase-bg-selected: rgba(19, 19, 27, 0.1);
                --ase-radius: 0px;
                --ase-transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            }

            .ase-field { margin-bottom: 1.25rem; font-family: inherit; }
            .ase-field__label { display: block; font-weight: 600; font-size: 0.85rem; color: var(--ase-text); margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.025em; }
            .ase-required { color: #ef4444; margin-left: 2px; }

            .ase-field__input, .ase-field__select, .ase-field__textarea {
                width: 100%; border: 1px solid var(--ase-border); border-radius: var(--ase-radius);
                padding: 0.625rem 0.75rem; font-size: 0.95rem; color: var(--ase-text);
                background: var(--ase-bg-input); transition: var(--ase-transition);
                box-sizing: border-box; outline: none;
            }
            .ase-field__input:focus { border-color: var(--ase-primary); box-shadow: 0 0 0 3px var(--ase-bg-selected); }

            /* Premium Sensitive & Password Widget */
            .ase-input-group {
                position: relative; display: flex; align-items: center;
                background: var(--ase-bg-input); border: 1px solid var(--ase-border);
                border-radius: var(--ase-radius); transition: var(--ase-transition);
                min-height: 42px; overflow: hidden;
            }
            .ase-input-group:focus-within { border-color: var(--ase-primary); box-shadow: 0 0 0 3px var(--ase-bg-selected); }

            .ase-input-group__content { flex: 1; padding: 0 0.75rem; display: flex; align-items: center; min-width: 0; }
            .ase-input-group__actions { display: flex; align-items: center; padding-right: 0.25rem; flex-shrink: 0; }

            .ase-input-group__input {
                border: none !important; padding: 0.625rem 0 !important; background: transparent !important;
                box-shadow: none !important; width: 100%;
            }

            .ase-sensitive__display {
                font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
                font-size: 0.9rem; color: var(--ase-text); white-space: nowrap;
                overflow: hidden; text-overflow: ellipsis; cursor: default;
            }

            .ase-field__icon-btn {
                background: transparent; border: none; padding: 0.5rem; color: var(--ase-text-muted);
                cursor: pointer; border-radius: 6px; transition: var(--ase-transition);
                display: flex; align-items: center; justify-content: center; line-height: 1;
            }
            .ase-field__icon-btn:hover { background: #f1f5f9; color: var(--ase-primary); }
            .ase-field__icon-btn svg { width: 18px; height: 18px; }

            .ase-sensitive__change-btn {
                font-size: 0.75rem; font-weight: 600; color: var(--ase-primary);
                background: transparent; border: none; padding: 0 0.5rem; cursor: pointer;
                transition: var(--ase-transition);
            }
            .ase-sensitive__change-btn:hover { text-decoration: underline; }

            .ase-sensitive__cancel-btn {
                font-size: 0.75rem; color: #ef4444; background: transparent; border: none;
                cursor: pointer; padding: 0.5rem;
            }

            .ase-field__help { margin-top: 0.375rem; font-size: 0.8rem; color: var(--ase-text-muted); }
            .ase-field__error { margin-top: 0.375rem; font-size: 0.8rem; color: #ef4444; font-weight: 500; }

            .ase-field--error .ase-field__input,
            .ase-field--error .ase-field__textarea,
            .ase-field--error .ase-field__select,
            .ase-field--error .ase-input-group {
                border-color: #ef4444 !important;
                background-color: #fef2f2 !important;
            }
            .ase-field--error .ase-field__label { color: #ef4444; }

            /* Utilities */
            [hidden] { display: none !important; }
        CSS;
    }

    /**
     * Build the extra HTML attribute string from a key-value map.
     * Known structural attributes (action, method, class, id) are skipped.
     *
     * @param  array    $attrs        Full attribute map
     * @param  string[] $skip         Keys to exclude
     * @return string   Space-prefixed attribute string, e.g. ' data-foo="bar"'
     */
    protected static function buildExtraAttributes(array $attrs, array $skip = []): string
    {
        $extra = '';
        foreach ($attrs as $k => $v) {
            if (!in_array($k, $skip, strict: true)) {
                $extra .= ' ' . self::e($k) . '="' . self::e((string) $v) . '"';
            }
        }
        return $extra;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * HTML-escape a string value to prevent XSS.
     * Always use this before rendering user-supplied or dynamic content.
     */
    protected static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Resolve the CSRF secret from environment variables.
     * Priority: GATEWAY_CSRF_SECRET → GATEWAY_SERVICE_SECRET
     *
     * @throws \RuntimeException if no secret is configured
     */
    private static function csrfSecret(): string
    {
        $secret = (string) getenv('GATEWAY_CSRF_SECRET');
        if ($secret === '') {
            $secret = (string) getenv('GATEWAY_SERVICE_SECRET');
        }
        if ($secret === '') {
            throw new \RuntimeException(
                'GATEWAY_CSRF_SECRET (or GATEWAY_SERVICE_SECRET) environment variable is not set.'
            );
        }

        return $secret;
    }
}
