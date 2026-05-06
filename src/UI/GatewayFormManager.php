<?php

declare(strict_types=1);

namespace LemurAse\UI;

use LemurAse\Domain\Form\FieldDefinition;
use LemurAse\Domain\Form\FieldType;
use LemurAse\Domain\Form\GatewayFormDefinitionInterface;
use LemurAse\Infrastructure\Form\GatewayFormRegistry;

/**
 * GatewayFormManager — Agnostic gateway configuration form handler.
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  DESIGN PRINCIPLES                                                       │
 * │  · Forms are rendered as server-side HTML — fully functional without JS  │
 * │  · Vanilla JS (optional) adds progressive UX: masking, conditionals      │
 * │  · CSRF uses stateless HMAC tokens — no session required                 │
 * │  · Sensitive credentials shown as masked placeholders, submitted empty    │
 * │    → server keeps existing value when submitted value is blank/masked     │
 * │  · Custom templates accepted as PHP files with injected variables         │
 * └─────────────────────────────────────────────────────────────────────────┘
 *
 * Typical usage:
 *
 *   // 1. Generate CSRF token (store userId in your session)
 *   $csrf = GatewayFormManager::generateCsrfToken($userId, 'gateway:stripe');
 *
 *   // 2. Render the form (no existing config → create; existing → edit)
 *   echo GatewayFormManager::renderForm('stripe', $existingCredentials, $csrf);
 *
 *   // 3. On POST, process submission
 *   $result = GatewayFormManager::processSubmission(
 *       provider:    'stripe',
 *       postData:    $_POST,
 *       csrfToken:   $_POST['_token'] ?? '',
 *       userId:      $userId,
 *       existing:    $existingCredentials,   // pass [] if creating new
 *   );
 *   // $result = ['credentials' => [...], 'is_active' => bool]
 */
final class GatewayFormManager
{
    /** Sentinel used in the rendered HTML for configured-but-hidden values. */
    private const MASKED_SENTINEL = '__MASKED__';

    /** @var array<string, string> provider => absolute path to custom PHP template */
    private static array $templates = [];

    private function __construct() {}

    // =========================================================================
    // CSRF — Stateless HMAC token (works without framework sessions)
    // =========================================================================

    /**
     * Generate a signed CSRF token valid for 1 hour.
     *
     * Token format (base64-encoded):  userId|action|expires|hmac
     */
    public static function generateCsrfToken(string $userId, string $action): string
    {
        $secret  = self::csrfSecret();
        $expires = time() + 3600;
        $payload = implode('|', [$userId, $action, $expires]);
        $hmac    = hash_hmac('sha256', $payload, $secret);

        return base64_encode($payload . '|' . $hmac);
    }

    /**
     * Validate a CSRF token.  Returns false (do NOT throw) so callers can
     * decide the response (redirect, 403, JSON error, etc.).
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

        $payload  = implode('|', [$tUser, $tAction, $expires]);
        $expected = hash_hmac('sha256', $payload, self::csrfSecret());

        return hash_equals($expected, $hmac); // timing-safe comparison
    }

    // =========================================================================
    // Schema — JSON-serializable field definitions
    // =========================================================================

    /**
     * Return the JSON-serializable schema for a given provider.
     * Safe to expose as an API endpoint for a JS-driven UI.
     *
     * @return array{provider:string, label:string, version:string, groups:array[], fields:array[]}
     */
    public static function getFormSchema(string $provider): array
    {
        $def = GatewayFormRegistry::get($provider);

        return [
            'provider' => $def->provider(),
            'label'    => $def->label(),
            'version'  => $def->version(),
            'groups'   => $def->groups(),
            'fields'   => array_map(fn(FieldDefinition $f) => $f->toArray(), $def->fields()),
        ];
    }

    // =========================================================================
    // Rendering — Server-side HTML (no JS required)
    // =========================================================================

    /**
     * Render the gateway configuration form as an HTML string.
     *
     * @param string $provider           Gateway slug ('stripe', 'paypal', …)
     * @param array  $existingCredentials Pass [] when creating, existing array when editing.
     *                                   Sensitive fields will be masked automatically.
     * @param string $csrfToken          Token from generateCsrfToken()
     * @param array  $formAttributes     Extra HTML attributes for <form>
     *                                   e.g. ['action' => '/admin/gateways', 'class' => 'my-form']
     *
     * @return string  Complete HTML fragment (no <html>/<body> wrapper)
     */
    public static function renderForm(
        string $provider,
        array  $existingCredentials = [],
        string $csrfToken           = '',
        array  $formAttributes      = [],
    ): string {
        $def      = GatewayFormRegistry::get($provider);
        $isEdit   = !empty($existingCredentials);
        $masked   = $isEdit ? self::maskCredentials($provider, $existingCredentials) : [];
        $template = self::$templates[$provider] ?? null;

        if ($template !== null) {
            return self::renderCustomTemplate($template, $def, $masked, $csrfToken, $formAttributes);
        }

        return self::renderDefaultForm($def, $masked, $csrfToken, $formAttributes, $isEdit);
    }

    /**
     * Render a form using a custom PHP template.
     *
     * The template file receives these variables:
     *   $definition    GatewayFormDefinitionInterface
     *   $fields        FieldDefinition[]
     *   $groups        array[]
     *   $values        array  (masked credentials, keyed by field name)
     *   $csrfToken     string
     *   $formAttributes array
     *   $isEdit        bool
     *   $provider      string
     */
    public static function renderCustomTemplate(
        string                         $templatePath,
        GatewayFormDefinitionInterface $definition,
        array                          $values,
        string                         $csrfToken,
        array                          $formAttributes = [],
    ): string {
        if (!is_file($templatePath) || !is_readable($templatePath)) {
            throw new \InvalidArgumentException("Template not found or not readable: {$templatePath}");
        }

        // Extract variables into template scope
        $fields         = $definition->fields();
        $groups         = $definition->groups();
        $provider       = $definition->provider();
        $isEdit         = !empty(array_filter($values));

        ob_start();
        include $templatePath;
        return (string) ob_get_clean();
    }

    // =========================================================================
    // Submission — Validate, sanitize, merge with existing
    // =========================================================================

    /**
     * Process a form submission.
     *
     * Validates CSRF, validates each field per its FieldDefinition rules,
     * merges with existing credentials (preserves sensitive values when empty).
     *
     * Returns the clean credential array ready to be persisted via AseManager.
     * Does NOT persist — the caller decides what to do with the result.
     *
     * @param string $provider   Gateway slug
     * @param array  $postData   Raw $_POST data
     * @param string $csrfToken  Token from the hidden form field
     * @param string $userId     User ID for CSRF validation
     * @param array  $existing   Existing credentials (decrypted). Pass [] for new gateways.
     *
     * @return array{credentials: array, is_active: bool}
     *
     * @throws \LemurAse\UI\GatewayFormException  On CSRF failure or field validation errors
     */
    public static function processSubmission(
        string $provider,
        array  $postData,
        string $csrfToken,
        string $userId,
        array  $existing = [],
    ): array {
        // 1. CSRF
        $action = "gateway:{$provider}";
        if (!self::validateCsrfToken($csrfToken, $userId, $action)) {
            throw new GatewayFormException('Invalid or expired security token. Please reload the page.');
        }

        $def    = GatewayFormRegistry::get($provider);
        $errors = [];
        $clean  = [];

        // 2. Field-by-field validation
        foreach ($def->fields() as $field) {
            $name  = $field->name();
            $value = $postData[$name] ?? null;

            // Normalize checkbox: 'on'/'1'/true => true, absent => false
            if ($field->type() === FieldType::CHECKBOX) {
                $clean[$name] = in_array($value, ['on', '1', 'true', 1, true], strict: false);
                continue;
            }

            // Sensitive field: keep existing when submitted value is empty or masked sentinel
            if ($field->isSensitive()) {
                $submitted = trim((string) $value);
                if ($submitted === '' || $submitted === self::MASKED_SENTINEL) {
                    $clean[$name] = $existing[$name] ?? '';
                    // If still empty and required, mark error
                    if ($field->isRequired() && empty($clean[$name])) {
                        $errors[$name] = "{$field->label()} is required.";
                    }
                    continue;
                }
                $value = $submitted;
            }

            $value = trim((string) $value);

            // Required check
            if ($field->isRequired() && $value === '') {
                $errors[$name] = "{$field->label()} is required.";
                continue;
            }

            // Skip further validation on empty optional fields
            if ($value === '') {
                $clean[$name] = '';
                continue;
            }

            // Pattern validation (server-side; anchored automatically)
            $rules = $field->validation();
            if (!empty($rules['pattern'])) {
                $regex = '/' . str_replace('/', '\/', $rules['pattern']) . '/';
                if (preg_match($regex, $value) !== 1) {
                    $errors[$name] = "{$field->label()} has an invalid format.";
                    continue;
                }
            }

            // Length validation
            $len = mb_strlen($value);
            if (isset($rules['minLength']) && $len < (int) $rules['minLength']) {
                $errors[$name] = "{$field->label()} must be at least {$rules['minLength']} characters.";
                continue;
            }
            if (isset($rules['maxLength']) && $len > (int) $rules['maxLength']) {
                $errors[$name] = "{$field->label()} must not exceed {$rules['maxLength']} characters.";
                continue;
            }

            // Enum validation (SELECT)
            if (!empty($rules['enum']) && !in_array($value, (array) $rules['enum'], strict: true)) {
                $errors[$name] = "{$field->label()} contains an invalid selection.";
                continue;
            }

            $clean[$name] = $value;
        }

        if (!empty($errors)) {
            throw new GatewayFormException(
                'Validation failed: ' . implode(' ', $errors),
                $errors,
            );
        }

        return [
            'credentials' => $clean,
            'is_active'   => (bool) ($postData['is_active'] ?? true),
        ];
    }

    // =========================================================================
    // Credential masking
    // =========================================================================

    /**
     * Return credentials with sensitive fields replaced by the masked sentinel.
     * Non-sensitive fields are returned as-is.
     *
     * The sentinel value is what gets placed in the hidden input for edit forms —
     * the server discards it and keeps the stored value.
     */
    public static function maskCredentials(string $provider, array $credentials): array
    {
        $def    = GatewayFormRegistry::get($provider);
        $masked = $credentials;

        foreach ($def->fields() as $field) {
            $name = $field->name();
            if ($field->isSensitive() && isset($masked[$name]) && $masked[$name] !== '') {
                $masked[$name] = self::MASKED_SENTINEL;
            }
        }

        return $masked;
    }

    // =========================================================================
    // Custom templates
    // =========================================================================

    /**
     * Register a custom PHP template for a specific provider.
     *
     * The template receives the variables documented in renderCustomTemplate().
     */
    public static function registerTemplate(string $provider, string $absolutePath): void
    {
        if (!is_file($absolutePath)) {
            throw new \InvalidArgumentException("Template file does not exist: {$absolutePath}");
        }

        self::$templates[$provider] = $absolutePath;
    }

    // =========================================================================
    // Default HTML renderer (internal)
    // =========================================================================

    private static function renderDefaultForm(
        GatewayFormDefinitionInterface $def,
        array                          $values,
        string                         $csrfToken,
        array                          $attrs,
        bool                           $isEdit,
    ): string {
        $provider = self::e($def->provider());
        $action   = self::e($attrs['action'] ?? '');
        $method   = self::e($attrs['method'] ?? 'POST');
        $class    = self::e($attrs['class']  ?? 'ase-gateway-form');
        $id       = self::e($attrs['id']     ?? "ase-form-{$provider}");

        // Build extra attributes string (excluding known ones)
        $skip      = ['action', 'method', 'class', 'id'];
        $extraAttr = '';
        foreach ($attrs as $k => $v) {
            if (!in_array($k, $skip, strict: true)) {
                $extraAttr .= ' ' . self::e($k) . '="' . self::e((string) $v) . '"';
            }
        }

        // Group fields
        $groups       = $def->groups();
        $groupedFields = [];
        $ungrouped    = [];

        foreach ($def->fields() as $field) {
            $grp = $field->group();
            if ($grp !== null) {
                $groupedFields[$grp][] = $field;
            } else {
                $ungrouped[] = $field;
            }
        }

        // Sort groups by order
        usort($groups, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        ob_start();
        ?>
<form
    id="<?= $id ?>"
    method="<?= $method ?>"
    action="<?= $action ?>"
    class="<?= $class ?>"
    data-ase-gateway-form="<?= $provider ?>"
    <?= $extraAttr ?>
    novalidate
>
    <input type="hidden" name="_token" value="<?= self::e($csrfToken) ?>">
    <input type="hidden" name="_provider" value="<?= $provider ?>">

<?php foreach ($groups as $group): ?>
<?php $gFields = $groupedFields[$group['id']] ?? []; ?>
<?php if (!empty($gFields)): ?>
    <fieldset class="ase-form-group" data-group="<?= self::e($group['id']) ?>">
        <legend class="ase-form-group__title"><?= self::e($group['title']) ?></legend>
        <?php foreach ($gFields as $field): ?>
            <?= self::renderField($field, $values[$field->name()] ?? null, $isEdit) ?>
        <?php endforeach; ?>
    </fieldset>
<?php endif; ?>
<?php endforeach; ?>

<?php if (!empty($ungrouped)): ?>
    <div class="ase-form-fields">
        <?php foreach ($ungrouped as $field): ?>
            <?= self::renderField($field, $values[$field->name()] ?? null, $isEdit) ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

    <div class="ase-form-actions">
        <button type="submit" class="ase-btn ase-btn--primary">
            <?= $isEdit ? 'Update Gateway' : 'Save Gateway' ?>
        </button>
    </div>
</form>
        <?php
        return (string) ob_get_clean();
    }

    private static function renderField(
        FieldDefinition $field,
        mixed           $value,
        bool            $isEdit,
    ): string {
        $name  = self::e($field->name());
        $id    = 'ase_field_' . self::e($field->name());
        $label = self::e($field->label());
        $help  = $field->helpText() ? '<p class="ase-field__help">' . self::e($field->helpText()) . '</p>' : '';
        $req   = $field->isRequired() ? ' <span class="ase-required" aria-hidden="true">*</span>' : '';

        // Condition data attribute for JS progressive enhancement
        $condAttr = '';
        if ($field->condition() !== null) {
            $condAttr = ' data-condition="' . self::e(json_encode($field->condition())) . '"';
        }

        $wrapperClass = 'ase-field';
        if ($field->condition() !== null) {
            $wrapperClass .= ' ase-field--conditional';
        }

        ob_start();

        if ($field->type() === FieldType::CHECKBOX) {
            $checked = $value ?? $field->default();
            $isChecked = in_array($checked, [true, 1, '1', 'on', 'true'], strict: false);
            echo <<<HTML
    <div class="{$wrapperClass}" data-field="{$name}"{$condAttr}>
        <label class="ase-field__checkbox-label" for="{$id}">
            <input
                type="checkbox"
                id="{$id}"
                name="{$name}"
                value="on"
                class="ase-field__checkbox"
                {$condAttr}
                {$condAttr}
            >
            {$label}{$req}
        </label>
        {$help}
    </div>

HTML;
            // Fix the checked attribute properly
            $checkedHtml = $isChecked ? ' checked' : '';
            $output = ob_get_clean();
            return str_replace(
                'class="ase-field__checkbox"' . $condAttr . "\n                " . $condAttr,
                'class="ase-field__checkbox"' . $checkedHtml,
                $output,
            );
        }

        // For all other input types
        $type        = self::e($field->type()->value);
        $placeholder = self::e($field->placeholder() ?? '');
        $required    = $field->isRequired() ? ' required' : '';
        $maxLen      = '';
        if (isset($field->validation()['maxLength'])) {
            $maxLen = ' maxlength="' . (int) $field->validation()['maxLength'] . '"';
        }

        // Sensitive field in edit mode: value = sentinel (hidden), placeholder shows "configured"
        if ($field->isSensitive() && $isEdit && $value === self::MASKED_SENTINEL) {
            $displayPlaceholder = '••• configured — leave blank to keep current value •••';
            $inputValue         = '';  // empty → server keeps existing on submit
            $inputClass         = 'ase-field__input ase-field__input--configured';
        } else {
            $displayPlaceholder = $placeholder;
            $inputValue         = self::e((string) ($value ?? ''));
            $inputClass         = 'ase-field__input';
        }

        echo <<<HTML
    <div class="{$wrapperClass}" data-field="{$name}"{$condAttr}>
        <label class="ase-field__label" for="{$id}">{$label}{$req}</label>
        <input
            type="{$type}"
            id="{$id}"
            name="{$name}"
            value="{$inputValue}"
            placeholder="{$displayPlaceholder}"
            class="{$inputClass}"
            {$required}{$maxLen}
            autocomplete="off"
        >
        {$help}
    </div>

HTML;

        return (string) ob_get_clean();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** HTML-escape a value to prevent XSS. */
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function csrfSecret(): string
    {
        $secret = (string) getenv('GATEWAY_CSRF_SECRET');
        if ($secret === '') {
            // Fallback to ASE_SECRET_KEY if GATEWAY_CSRF_SECRET not set
            $secret = (string) getenv('ASE_SECRET_KEY');
        }
        if ($secret === '') {
            throw new \RuntimeException('GATEWAY_CSRF_SECRET (or ASE_SECRET_KEY) is not set in .env.');
        }
        return $secret;
    }
}
