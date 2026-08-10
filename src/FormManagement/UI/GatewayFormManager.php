<?php

declare(strict_types=1);

namespace LemurAse\FormManagement\UI;

use LemurAse\FormManagement\Domain\FieldDefinition;
use LemurAse\FormManagement\Domain\FieldType;
use LemurAse\FormManagement\Domain\GatewayFormDefinitionInterface;
use LemurAse\FormManagement\Infrastructure\GatewayFormRegistry;

/**
 * GatewayFormManager — Gateway configuration form handler.
 *
 * Extends AbstractFormManager, which provides:
 *  · CSRF token generation and validation
 *  · HTML escaping (e())
 *  · Generic field renderer (renderField)
 *  · Custom PHP template renderer
 *
 * This class adds gateway-specific behaviour:
 *  · Resolves providers via GatewayFormRegistry
 *  · Sensitive credential masking (maskCredentials)
 *  · Field-by-field validation with merge of existing credentials
 *  · Inline "Enable this gateway" checkbox
 *  · Custom template registry per provider
 *
 * Typical usage:
 *
 *   $csrf = GatewayFormManager::generateCsrfToken($userId, 'gateway:stripe');
 *   echo GatewayFormManager::renderForm('stripe', $existingCredentials, $csrf);
 *
 *   $result = GatewayFormManager::processSubmission(
 *       provider:  'stripe',
 *       postData:  $_POST,
 *       csrfToken: $_POST['_token'] ?? '',
 *       userId:    $userId,
 *       existing:  $existingCredentials,
 *   );
 *   // $result = ['credentials' => [...], 'is_active' => bool]
 */
final class GatewayFormManager extends AbstractFormManager
{
    /**
     * Sentinel used by maskCredentials() for custom PHP templates.
     * The default HTML renderer no longer relies on this — sensitive fields
     * display a partial mask widget instead of an empty placeholder.
     */
    private const MASKED_SENTINEL = '__MASKED__';

    /** @var array<string, string> provider => absolute path to custom PHP template */
    private static array $templates = [];

    private function __construct()
    {
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
            'label' => $def->label(),
            'version' => $def->version(),
            'groups' => $def->groups(),
            'fields' => array_map(fn(FieldDefinition $f) => $f->toArray(), $def->fields()),
        ];
    }

    // =========================================================================
    // Rendering — Server-side HTML
    // =========================================================================

    /**
     * Render the gateway configuration form as an HTML string.
     *
     * @param string $provider    Gateway slug ('stripe', 'paypal', …)
     * @param array  $values      Existing credentials OR old input from a failed submission.
     * @param array  $errors      Validation errors mapped by field name.
     * @param string $csrfToken   Token from generateCsrfToken()
     * @param array  $attrs       Extra HTML attributes for <form>
     * @param bool   $isEdit      Whether we are editing an existing record (enables masking).
     *
     * @return string Complete HTML fragment.
     */
    public static function renderForm(
        string $provider,
        array $values = [],
        array $errors = [],
        string $csrfToken = '',
        array $attrs = [],
        bool $isEdit = false,
    ): string {
        $def = GatewayFormRegistry::get($provider);

        $template = self::$templates[$provider] ?? null;
        if ($template !== null) {
            // Custom templates still receive the masked version for backward compat
            $masked = $isEdit ? self::maskCredentials($provider, $values) : $values;
            return self::renderCustomTemplate($template, $def, $masked, $csrfToken, $attrs);
        }

        // Default renderer receives actual values — renderField() handles masking UX
        return self::renderDefaultForm($def, $values, $errors, $csrfToken, $attrs, $isEdit);
    }

    // =========================================================================
    // Submission — Validate, sanitize, merge with existing
    // =========================================================================

    /**
     * Process a form submission.
     *
     * 1. Validates CSRF token.
     * 2. Validates each field per its FieldDefinition rules.
     * 3. Merges with existing credentials (preserves sensitive values when empty).
     * 4. Resolves the `is_active` flag from POST data.
     *
     * Does NOT persist — the caller (or a domain event listener) decides what to do.
     *
     * @param string $provider  Gateway slug
     * @param array  $postData  Raw $_POST data
     * @param string $csrfToken Token from the hidden form field
     * @param string $userId    User ID for CSRF validation
     * @param array  $existing  Existing decrypted credentials. Pass [] for new gateways.
     *
     * @return array{credentials: array, is_active: bool}
     *
     * @throws GatewayFormException On CSRF failure or field validation errors
     */
    public static function processSubmission(
        string $provider,
        array $postData,
        string $csrfToken,
        string $userId,
        array $existing = [],
    ): array {
        // 1. CSRF
        $action = "gateway:{$provider}";
        if (!self::validateCsrfToken($csrfToken, $userId, $action)) {
            throw new GatewayFormException(
                'Invalid or expired security token. Please reload the page.'
            );
        }

        $def = GatewayFormRegistry::get($provider);
        $errors = [];
        $clean = [];

        // 2. Field-by-field validation
        foreach ($def->fields() as $field) {
            $name = $field->name();
            $value = $postData[$name] ?? null;

            // Normalize checkbox: 'on'/'1'/true → true, absent → false
            if ($field->type() === FieldType::CHECKBOX) {
                $clean[$name] = in_array($value, ['on', '1', 'true', 1, true], strict: false);
                continue;
            }

            // Sensitive field: empty submit input = keep existing (new UX: no sentinel needed)
            if ($field->isSensitive()) {
                $submitted = trim((string) $value);
                if ($submitted === '') {
                    $clean[$name] = $existing[$name] ?? '';
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

            $rules = $field->validation();

            // Pattern validation
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

        // 3. Resolve is_active — absent checkbox = unchecked = false
        $isActive = $existing['is_active'] ?? true;
        if (isset($postData['is_active'])) {
            $isActive = in_array($postData['is_active'], ['on', '1', 'true', 1, true], strict: false);
        } elseif (isset($postData['_token'])) {
            $isActive = false;
        }

        // 4. Strict activation rule: Cannot activate gateway if not configured
        if ($isActive) {
            $isConfigured = true;
            foreach ($def->fields() as $field) {
                if ($field->isRequired()) {
                    $val = $clean[$field->name()] ?? '';
                    if ($val === '') {
                        $isConfigured = false;
                        break;
                    }
                }
            }
            if (!$isConfigured) {
                throw new GatewayFormException(
                    "No puedes activar la pasarela {$provider} sin haber guardado sus credenciales obligatorias previamente.",
                    ['is_active' => 'Debes configurar las credenciales obligatorias antes de activar la pasarela.']
                );
            }
        }

        return [
            'credentials' => $clean,
            'is_active' => $isActive,
        ];
    }

    // =========================================================================
    // Credential masking
    // =========================================================================

    /**
     * Return credentials with sensitive fields replaced by the masked sentinel.
     * Non-sensitive fields are returned as-is.
     */
    public static function maskCredentials(string $provider, array $credentials): array
    {
        $def = GatewayFormRegistry::get($provider);
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
    // Custom template registry
    // =========================================================================

    /**
     * Register a custom PHP template for a specific provider.
     *
     * The template receives the variables documented in AbstractFormManager::renderCustomTemplate().
     */
    public static function registerTemplate(string $provider, string $absolutePath): void
    {
        if (!is_file($absolutePath)) {
            throw new \InvalidArgumentException(
                "Template file does not exist: {$absolutePath}"
            );
        }

        self::$templates[$provider] = $absolutePath;
    }

    // =========================================================================
    // Default HTML renderer (internal)
    // =========================================================================

    private static function renderDefaultForm(
        GatewayFormDefinitionInterface $def,
        array $values,
        array $errors,
        string $csrfToken,
        array $attrs,
        bool $isEdit,
    ): string {
        $provider = self::e($def->provider());
        $action = self::e($attrs['action'] ?? '');
        $method = self::e($attrs['method'] ?? 'POST');
        $class = self::e($attrs['class'] ?? 'ase-gateway-form');
        $id = self::e($attrs['id'] ?? "ase-form-{$provider}");

        $extraAttr = self::buildExtraAttributes($attrs, ['action', 'method', 'class', 'id']);

        // Bucket fields into groups vs. ungrouped
        $groups = $def->groups();
        $groupedFields = [];
        $ungrouped = [];

        foreach ($def->fields() as $field) {
            $grp = $field->group();
            if ($grp !== null) {
                $groupedFields[$grp][] = $field;
            } else {
                $ungrouped[] = $field;
            }
        }

        $enable = isset($values['is_active']) ? (bool) $values['is_active'] : false;

        usort($groups, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        ob_start();
        ?>
        <form id="<?= $id ?>" method="<?= $method ?>" action="<?= $action ?>" class="<?= $class ?>"
            data-ase-gateway-form="<?= $provider ?>" <?= $extraAttr ?> novalidate>
            <input type="hidden" name="_token" value="<?= self::e($csrfToken) ?>">
            <input type="hidden" name="_provider" value="<?= $provider ?>">

            <?php foreach ($groups as $group): ?>
                <?php $gFields = $groupedFields[$group['id']] ?? []; ?>
                <?php if (!empty($gFields)): ?>
                    <fieldset class="ase-form-group" data-group="<?= self::e($group['id']) ?>">
                        <legend class="ase-form-group__title"><?= self::e($group['title']) ?></legend>
                        <?php foreach ($gFields as $field): ?>
                            <?= self::renderField(
                                $field,
                                $values[$field->name()] ?? null,
                                $isEdit,
                                $errors[$field->name()] ?? null
                            ) ?>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if (!empty($ungrouped)): ?>
                <div class="ase-form-fields">
                    <?php foreach ($ungrouped as $field): ?>
                        <?= self::renderField(
                            $field,
                            $values[$field->name()] ?? null,
                            $isEdit,
                            $errors[$field->name()] ?? null
                        ) ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="ase-form-fields ase-form-fields--system">
                <div class="ase-field ase-field--system">
                    <label class="ase-field__checkbox-label">
                        <input type="checkbox" name="is_active" class="ase-field__checkbox" <?= $enable ? 'checked' : '' ?>>
                        Enable this gateway
                    </label>
                </div>
            </div>

            <div class="ase-form-actions">
                <button type="submit" class="ase-btn ase-btn--primary">
                    <?= $isEdit ? 'Update Gateway' : 'Save Gateway' ?>
                </button>
            </div>
        </form>
        <?= self::injectJsOnce() ?>
        <?php
        return (string) ob_get_clean();
    }
}
