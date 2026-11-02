<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Data\SettingDefinition;
use App\Modules\Admin\Services\PlatformSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as ValidatorFacade;

/**
 * Validates a settings change against the declaration in `config/admin.php` (Phase 19b).
 *
 * Rules built from the schema, like `ResourceWriteRequest` builds its from a descriptor: the type
 * that says a key is a boolean is the same type that validates what came back, so the two cannot
 * disagree. A key the config does not declare is simply absent from the rules and therefore from
 * `validated()`, so it cannot be written — which is the same whitelist property the resource
 * registry has, for the same reason.
 */
final class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The container only. Every setting is validated in {@see self::withValidator()} instead,
        // and the reason is worth writing down because the obvious version silently does nothing:
        // **a setting name contains dots**, so a rule keyed `settings.billing.stripe_enabled` is
        // read by the validator as `settings → billing → stripe_enabled`, which is nesting that
        // does not exist in the payload. `data_get()` returns null, `sometimes` therefore skips the
        // rule, and the rules for every key in a file full of kill switches were no-ops.
        return ['settings' => ['sometimes', 'array']];
    }

    /**
     * Validate each declared setting under a dot-free alias, then report the errors under the names
     * the screen uses.
     *
     * The aliasing is the whole trick: `billing.stripe_enabled` becomes `billing__stripe_enabled`
     * for the validator's benefit and goes back to `settings.billing.stripe_enabled` in the error
     * bag, which is the key `form.errors` is indexed by on the Vue side.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $submitted = $this->input('settings');

            if (! is_array($submitted)) {
                return;
            }

            $payload = [];
            $rules = [];
            $names = [];

            foreach ($this->definitions() as $definition) {
                if (! array_key_exists($definition->key, $submitted)) {
                    continue;
                }

                $alias = self::alias($definition->key);
                $payload[$alias] = $submitted[$definition->key];
                $rules[$alias] = $definition->type->rules();
                $names[$alias] = $definition->label;

                // A closed list validates each **item**, not just the shape: `signals` and
                // `signal` are indistinguishable to a `nullable` rule, and the second is a kill
                // switch that kills nothing — discovered during the incident it was reached for.
                //
                // Checked against the **cast** value, because the type accepts a textarea string
                // as well as an array and the items only exist once it has been split.
                if ($definition->allowed !== null) {
                    $unknown = array_values(array_diff(
                        (array) $definition->type->cast($submitted[$definition->key]),
                        $definition->allowed,
                    ));

                    if ($unknown !== []) {
                        $validator->errors()->add(
                            'settings.'.$definition->key,
                            'Not something this can switch off: '.implode(', ', $unknown)
                                .'. Allowed: '.implode(', ', $definition->allowed).'.',
                        );
                    }
                }
            }

            if ($rules === []) {
                return;
            }

            $inner = ValidatorFacade::make($payload, $rules, [], $names);

            foreach ($inner->errors()->messages() as $alias => $messages) {
                // Back to the dotted name, so the message lands on the field the operator was
                // editing — `settings.billing.stripe_enabled` is the key `form.errors` is indexed
                // by on the Vue side.
                $key = 'settings.'.self::unalias($alias);

                foreach ($messages as $message) {
                    $validator->errors()->add($key, $message);
                }
            }
        });
    }

    private static function alias(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    private static function unalias(string $alias): string
    {
        return str_replace('__', '.', $alias);
    }

    /**
     * The changes to apply: declared keys only, each cast by its declared type.
     *
     * Booleans are read from the raw input rather than from `validated()`, because an unchecked
     * checkbox is **absent** rather than false — and a settings screen where switching something
     * off silently did nothing would be the worst possible bug in a file full of kill switches.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = [];
        $submitted = $this->input('settings', []);

        foreach ($this->definitions() as $definition) {
            if (! is_array($submitted) || ! array_key_exists($definition->key, $submitted)) {
                continue;
            }

            $changes[$definition->key] = $definition->type->cast($submitted[$definition->key]);
        }

        return $changes;
    }

    /**
     * @return array<string, SettingDefinition>
     */
    private function definitions(): array
    {
        return app(PlatformSettings::class)->definitions();
    }
}
