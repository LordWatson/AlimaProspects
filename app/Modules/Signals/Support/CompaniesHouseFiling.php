<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * One Companies House filing, read into the handful of facts a signal needs (S13b, ADR 0023 §6).
 *
 * **This class is the whole argument for a structured source.** A filing does not need a language
 * model because the registry has already parsed it. The S13b spike found that a filing item carries:
 *
 * ```
 * transaction_id      MzU1MzExNjc0MmFkaXF6a2N4
 * type                AP01
 * date                2026-10-27
 * category            officers
 * subcategory         appointments
 * description         appoint-person-director-company-with-name-date
 * description_values  { officer_name: "Mr Benjamin Jay Waldron", appointment_date: "2026-10-27" }
 * ```
 *
 * `description` is a **machine code**, not prose, and `description_values` is the registry's own
 * extraction of the names and dates out of the form. So "who, what, when" is already answered, and
 * asking an AI to read it would spend money to make a certainty less certain.
 *
 * `transaction_id` is the other gift: a globally unique, stable identity for the filing, which is a
 * far better dedupe key than a URL — it cannot change, cannot be redirected, and is the same string
 * every time the history is polled.
 *
 * What this class deliberately does **not** do is decide which of a team's signal types a filing
 * answers. That mapping is configuration ({@see self::eventKeyFrom()} is given the map), because a
 * signal type is a row a team owns (ADR 0018) and two teams can file the same appointment under
 * different types.
 */
final readonly class CompaniesHouseFiling
{
    /** Matches `signals.headline`, which is `string(255)`. */
    private const int MAX_HEADLINE = 255;

    private const int MAX_TEXT = 255;

    /**
     * @param  string  $transactionId  The registry's own stable identity for this filing.
     * @param  string  $category  e.g. `officers`, `insolvency`, `accounts`, `persons-with-significant-control`.
     * @param  string|null  $subcategory  e.g. `appointments`, `termination`. Absent on many categories.
     * @param  string  $type  The form code, e.g. `AP01`, `TM01`, `PSC01`.
     * @param  array<string, mixed>  $values  `description_values` — the registry's own extraction.
     */
    private function __construct(
        public string $transactionId,
        public string $category,
        public ?string $subcategory,
        public string $type,
        public string $description,
        public array $values,
        public ?Carbon $filedOn,
    ) {}

    /**
     * Read one `/company/{number}/filing-history` item, or null when it is unusable.
     *
     * Unusable means no `transaction_id` (nothing to dedupe on, so a re-poll would create a second
     * signal for the same filing) or no `category` (nothing to map to a signal type). Both are
     * dropped silently: a registry response is not hostile, it is occasionally incomplete.
     *
     * @param  array<array-key, mixed>  $item
     */
    public static function fromItem(array $item): ?self
    {
        $transactionId = is_string($item['transaction_id'] ?? null) ? trim($item['transaction_id']) : '';
        $category = is_string($item['category'] ?? null) ? trim($item['category']) : '';

        if ($transactionId === '' || $category === '') {
            return null;
        }

        /** @var array<string, mixed> $values */
        $values = is_array($item['description_values'] ?? null) ? $item['description_values'] : [];

        return new self(
            transactionId: Str::limit($transactionId, 128, ''),
            category: Str::limit(Str::lower($category), 64, ''),
            subcategory: self::subcategory($item['subcategory'] ?? null),
            type: is_string($item['type'] ?? null) ? Str::limit(trim($item['type']), 32, '') : '',
            description: is_string($item['description'] ?? null) ? Str::limit(trim($item['description']), self::MAX_TEXT, '') : '',
            values: $values,
            // `action_date` is when the thing happened and `date` is when it was filed; the event's
            // date is the former where the registry gives one, because a member reading "appointed
            // a director" means the appointment and not the paperwork.
            filedOn: self::date($item['action_date'] ?? null) ?? self::date($item['date'] ?? null),
        );
    }

    /**
     * The signal-type key this filing answers, from the run's map, or null when nothing wants it.
     *
     * Looked up most specific first — `officers/appointments` before `officers` — so a team can
     * either treat every officer filing as one type or split appointments from resignations, and
     * the more specific configuration wins without the less specific one having to know.
     *
     * The form code is tried first of all, because it is the least ambiguous thing a filing carries:
     * `AP01` is the appointment of a director and nothing else.
     *
     * @param  array<string, string>  $map  key (lower-case) → signal definition key
     */
    public function eventKeyFrom(array $map): ?string
    {
        foreach ($this->lookupKeys() as $candidate) {
            if (isset($map[$candidate]) && $map[$candidate] !== '') {
                return $map[$candidate];
            }
        }

        return null;
    }

    /**
     * The keys this filing can be matched by, most specific first.
     *
     * @return list<string>
     */
    public function lookupKeys(): array
    {
        $keys = [];

        if ($this->type !== '') {
            $keys[] = Str::lower($this->type);
        }

        if ($this->subcategory !== null) {
            $keys[] = $this->category.'/'.$this->subcategory;
        }

        $keys[] = $this->category;

        return $keys;
    }

    /**
     * The person this filing is about, where it names one.
     *
     * `description_values` uses a different field name per form, which is why this looks at several:
     * an appointment carries `officer_name`, a PSC filing `psc_name`, a charge `charge_creditor_name`.
     * Taken from the registry's extraction rather than parsed out of the description, because the
     * registry has already done it correctly.
     */
    public function personName(): ?string
    {
        foreach (['officer_name', 'psc_name', 'appointment_name', 'name'] as $field) {
            $value = $this->values[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim($value), self::MAX_TEXT, '');
            }
        }

        return null;
    }

    /**
     * A one-line headline, composed from the registry's own facts.
     *
     * Written here rather than translated, because it is the `signals.headline` column — which every
     * other source fills with an article's own words, so it is **not** a translation key and the
     * card renders it verbatim. Keeping it factual and short is the whole job: "Greggs plc —
     * director appointed: Mr Benjamin Jay Waldron".
     */
    public function headline(string $companyName): string
    {
        $label = $this->label();
        $person = $this->personName();

        $headline = trim($companyName).' — '.$label;

        if ($person !== null) {
            $headline .= ': '.$person;
        }

        return Str::limit($headline, self::MAX_HEADLINE, '');
    }

    /**
     * What kind of filing this is, in plain words.
     *
     * A small, deliberate allowlist rather than a prettified `description`: the registry's
     * description codes are stable but numerous, and a code nobody has mapped should read as the
     * category it belongs to rather than as `appoint-person-director-company-with-name-date` turned
     * into title case. A team sees a sentence either way.
     */
    public function label(): string
    {
        return match (true) {
            $this->type === 'AP01' || $this->type === 'AP02' => 'director appointed',
            $this->type === 'AP03' || $this->type === 'AP04' => 'secretary appointed',
            $this->type === 'TM01' => 'director resigned',
            $this->type === 'TM02' => 'secretary resigned',
            $this->type === 'CH01' || $this->type === 'CH02' => 'officer details changed',
            Str::startsWith($this->type, 'PSC') => 'person with significant control changed',
            $this->category === 'insolvency' => 'insolvency filing',
            $this->category === 'gazette' => 'gazette notice',
            $this->category === 'mortgage' => 'charge registered',
            $this->category === 'capital' => 'share capital changed',
            $this->category === 'resolution' => 'resolution filed',
            $this->subcategory === 'appointments' => 'officer appointed',
            $this->subcategory === 'termination' => 'officer resigned',
            $this->category === 'officers' => 'officer change filed',
            $this->category === 'accounts' => 'accounts filed',
            $this->category === 'confirmation-statement' => 'confirmation statement filed',
            default => Str::of($this->category)->replace('-', ' ')->trim()->value().' filing',
        };
    }

    /**
     * The officer's role, where the form says what it was.
     *
     * Derived from the **form code** rather than from the description text: AP01 is a director and
     * AP03 is a secretary, and that is a fact about the form rather than a reading of a sentence.
     */
    public function personRole(): ?string
    {
        return match (true) {
            $this->type === 'AP01' || $this->type === 'AP02' || $this->type === 'TM01' => 'Director',
            $this->type === 'AP03' || $this->type === 'AP04' || $this->type === 'TM02' => 'Company secretary',
            Str::startsWith($this->type, 'PSC') => 'Person with significant control',
            default => null,
        };
    }

    /**
     * The public record page for this filing's company.
     *
     * The **company's** filing-history page rather than the filing's own document: the API's
     * `links.document_metadata` points at a separate document API that needs its own credential and
     * serves a PDF, which is not something to put behind a link in a feed.
     */
    public static function recordUrl(string $companyNumber): string
    {
        $base = rtrim((string) config('lead-sourcing.providers.companies_house.record_base_url'), '/');

        return $base.'/company/'.rawurlencode($companyNumber).'/filing-history';
    }

    private static function subcategory(mixed $value): ?string
    {
        // The API returns a string for most filings and occasionally a list (a filing that belongs
        // to two subcategories). The first is the specific one.
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::limit(Str::lower(trim($value)), 64, '');
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
