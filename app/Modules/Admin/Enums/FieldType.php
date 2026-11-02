<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

use App\Modules\Admin\Data\ResourceField;

/**
 * The control a {@see ResourceField} renders as, and the cast the generic writer applies to what
 * comes back (Phase 19a).
 *
 * Deliberately small. The registry exists to spare us forty hand-written CRUD screens, not to grow
 * into a form builder: anything needing a control that is not on this list needs a bespoke screen,
 * and saying so here is cheaper than discovering it after the eighth flag has been added.
 */
enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Select = 'select';
    case Date = 'date';

    /**
     * Normalise a submitted value for the database.
     *
     * An empty string becomes null for every type but boolean and text, because an HTML form sends
     * `''` for "nothing" and a nullable integer column wants null rather than a cast of `''` to 0.
     */
    public function cast(mixed $value): mixed
    {
        if ($this === self::Boolean) {
            return (bool) $value;
        }

        if ($value === '' || $value === null) {
            return $this === self::Text ? $value : null;
        }

        return match ($this) {
            self::Integer => (int) $value,
            default => $value,
        };
    }
}
