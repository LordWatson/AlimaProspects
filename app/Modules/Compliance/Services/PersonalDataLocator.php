<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Data\SubjectDataReport;
use App\Modules\Compliance\Data\SubjectRecord;
use App\Modules\Compliance\Enums\SubjectDataCategory;
use App\Modules\Compliance\Exceptions\ComplianceException;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Services\LeadQueryBuilder;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Outreach\Services\SuppressionList;
use App\Modules\Signals\Services\SignalsPersonalDataExporter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Finds everything **one team** holds about one person (docs/compliance.md §3.5) — the locate half
 * of the DSAR tooling, and the input to an erasure.
 *
 * Scoped to one team by construction, because a data-subject request is answered **per controller**:
 * each tenant business is the controller for its own prospects (§5), so one team's records are not
 * another's to search, and a platform-wide search would be us disclosing one customer's data to
 * another.
 *
 * **Three kinds of identity, and they are not equally reliable** — which is why the report says
 * which was used:
 *
 * - An **email address** is exact. Normalised and compared, so `John@Acme.com ` finds
 *   `john@acme.com`.
 * - A **phone number** is exact once normalised to digits, but the comparison has to happen in PHP:
 *   the column holds whatever a web page printed (`+44 20 7946 0000`), and no `LIKE` over that
 *   finds `442079460000`. So the team's numbered contacts are read and compared. Bounded by the
 *   result cap, and by the fact that a team's contacts are a small table.
 * - A **name** is a guess. The match is case-insensitive containment and the results are
 *   **candidates for a human to confirm**, never an automatic erasure — "J. Smith" is not reliably
 *   "John Smith", and erasing an unrelated company's director would itself be a data-protection
 *   failure (the same reasoning as {@see SignalsPersonalDataExporter}).
 *
 * What it searches: contact records, company records whose own name matches (a sole trader), drafted
 * and sent messages, logged approaches, news signals via the Signals module's own exporter, and the
 * do-not-contact list. The suppression entry is **reported and never erased** — it holds a hash and
 * a mask rather than an address, and removing it would let the next discovery run reach the person
 * again (see {@see SubjectDataCategory::isErasable()}).
 */
final class PersonalDataLocator
{
    /**
     * The `LIKE ... ESCAPE` character. `!` rather than a backslash, for the reason
     * {@see LeadQueryBuilder} records: SQLite has no default
     * escape character so the clause must be written out, and in MySQL the literal `'\'` is an
     * unterminated string because the backslash escapes the closing quote.
     */
    private const string LIKE_ESCAPE = '!';

    /**
     * The comparison appended to each searched column. A constant, so the SQL stays a compile-time
     * literal and nothing from the request is ever concatenated into it.
     */
    private const string LIKE = " LIKE ? ESCAPE '".self::LIKE_ESCAPE."'";

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly SuppressionList $suppressions,
        private readonly SignalsPersonalDataExporter $signals,
    ) {}

    /**
     * @throws ComplianceException when the query is too short to be about one person
     */
    public function locate(Team $team, string $query): SubjectDataReport
    {
        $minimum = max(1, (int) config('compliance.dsar.min_query_length', 3));
        $raw = Str::squish($query);

        if (Str::length($raw) < $minimum) {
            throw ComplianceException::subjectQueryTooShort($minimum);
        }

        [$kind, $normalised] = $this->classify($raw);
        $cap = max(1, (int) config('compliance.dsar.max_results', 200));

        return $this->teamContext->run($team, function () use ($team, $kind, $normalised, $raw, $cap): SubjectDataReport {
            $records = match ($kind) {
                'email' => $this->byEmail($team, $normalised, $cap),
                'phone' => $this->byPhone($team, $normalised, $cap),
                default => $this->byName($team, $raw, $cap),
            };

            $records = array_merge($records, $this->suppressionRecords($team, $kind, $normalised));

            return new SubjectDataReport(
                query: $kind === 'name' ? $raw : $normalised,
                matchedOn: $kind,
                records: array_slice($records, 0, $cap),
                truncated: count($records) > $cap,
                suppressed: $this->isSuppressed($team, $kind, $normalised),
            );
        });
    }

    /**
     * What kind of identity this is, and its canonical form.
     *
     * Normalisation is borrowed from {@see SuppressionType} rather than reimplemented, so a DSAR
     * and the do-not-contact list agree about what "the same address" means — if they didn't, a
     * search could report "not suppressed" for an identity the guard is already blocking.
     *
     * @return array{string, string}
     */
    private function classify(string $query): array
    {
        $email = SuppressionType::Email->normalise($query);

        if ($email !== null) {
            return ['email', $email];
        }

        // Only treated as a number when it is mostly digits. Without that, a name with a stray
        // digit in it would be read as a phone number and find nothing, while silently telling the
        // member it had searched for one.
        if (preg_match('/^[\d\s+()\-.]+$/', $query) === 1) {
            $phone = SuppressionType::Phone->normalise($query);

            if ($phone !== null) {
                return ['phone', $phone];
            }
        }

        return ['name', Str::lower($query)];
    }

    /**
     * Contacts, messages and approaches tied to an exact address.
     *
     * @return list<SubjectRecord>
     */
    private function byEmail(Team $team, string $email, int $cap): array
    {
        $contacts = LeadContact::query()
            ->where('team_id', $team->getKey())
            ->whereRaw('LOWER(email) = ?', [$email])
            ->with('lead:id,company_name')
            ->orderBy('id')
            ->limit($cap)
            ->get();

        $records = [];

        foreach ($contacts as $contact) {
            $records[] = $this->contactRecord($contact, 'email');
        }

        // Messages are matched on `sent_to` as well as through the contact, because `sent_to` is
        // the record of what was actually used at the time and survives the contact being
        // re-derived by a later enrichment pass.
        $messages = OutreachMessage::query()
            ->where('team_id', $team->getKey())
            ->where(function ($query) use ($email, $contacts): void {
                $query->whereRaw('LOWER(sent_to) = ?', [$email]);

                if ($contacts->isNotEmpty()) {
                    $query->orWhereIn('lead_contact_id', array_values($contacts->modelKeys()));
                }
            })
            ->with('lead:id,company_name')
            ->orderBy('id')
            ->limit($cap)
            ->get();

        foreach ($messages as $message) {
            $records[] = $this->messageRecord($message, 'email');
        }

        foreach ($this->attemptsFor($team, array_values($contacts->modelKeys()), $cap) as $attempt) {
            $records[] = $this->attemptRecord($attempt, 'email');
        }

        return $records;
    }

    /**
     * Contacts and approaches tied to a phone number.
     *
     * Compared in PHP: the column holds whatever a page printed, so the normalisation has to happen
     * on both sides. Only rows that have a number at all are read, and the read is capped.
     *
     * @return list<SubjectRecord>
     */
    private function byPhone(Team $team, string $digits, int $cap): array
    {
        $contacts = LeadContact::query()
            ->where('team_id', $team->getKey())
            ->whereNotNull('phone')
            ->with('lead:id,company_name')
            ->orderBy('id')
            ->get()
            ->filter(static fn (LeadContact $contact): bool => SuppressionType::Phone->normalise((string) $contact->phone) === $digits)
            ->take($cap)
            ->values();

        $records = [];

        foreach ($contacts as $contact) {
            $records[] = $this->contactRecord($contact, 'phone');
        }

        foreach ($this->attemptsFor($team, array_values($contacts->modelKeys()), $cap) as $attempt) {
            $records[] = $this->attemptRecord($attempt, 'phone');
        }

        return $records;
    }

    /**
     * Candidates matching a name: contact records, company records named after a person, the
     * messages and approaches that hang off them, and the Signals module's own matches.
     *
     * @return list<SubjectRecord>
     */
    private function byName(Team $team, string $name, int $cap): array
    {
        $pattern = '%'.$this->escapeLike(Str::lower($name)).'%';

        $contacts = LeadContact::query()
            ->where('team_id', $team->getKey())
            ->whereRaw('LOWER(name)'.self::LIKE, [$pattern])
            ->with('lead:id,company_name')
            ->orderBy('id')
            ->limit($cap)
            ->get();

        $records = [];

        foreach ($contacts as $contact) {
            $records[] = $this->contactRecord($contact, 'name');
        }

        // A company named after a person — a sole trader, a consultancy. The company record is the
        // person's personal data in that case, which is exactly the situation a bare
        // contacts-table search misses.
        $leads = Lead::query()
            ->where('team_id', $team->getKey())
            ->whereRaw('LOWER(company_name)'.self::LIKE, [$pattern])
            ->orderBy('id')
            ->limit($cap)
            ->get();

        foreach ($leads as $lead) {
            $records[] = new SubjectRecord(
                category: SubjectDataCategory::Lead,
                id: (int) $lead->getKey(),
                leadId: (int) $lead->getKey(),
                companyName: $lead->company_name,
                detail: (string) ($lead->website ?? $lead->locality ?? ''),
                labels: [$lead->source->label()],
                matchedOn: 'name',
                recordedAt: $lead->created_at?->toIso8601String(),
                erasable: true,
            );
        }

        foreach ($this->attemptsFor($team, array_values($contacts->modelKeys()), $cap) as $attempt) {
            $records[] = $this->attemptRecord($attempt, 'name');
        }

        // Signals answers for itself (§5a). It is asked rather than queried, because Compliance
        // must not learn the shape of another module's personal data — and because Signals is the
        // only place that knows a converted signal is a lead's provenance and must survive.
        foreach ($this->signals->export($team, $name) as $row) {
            $erasable = (bool) ($row['erasable'] ?? false);

            $records[] = new SubjectRecord(
                category: SubjectDataCategory::Signal,
                id: (int) $row['signal_id'],
                leadId: isset($row['converted_to_lead_id']) ? (int) $row['converted_to_lead_id'] : null,
                companyName: isset($row['subject_company_name']) ? (string) $row['subject_company_name'] : null,
                detail: (string) ($row['headline'] ?? ''),
                labels: [],
                matchedOn: 'name',
                recordedAt: isset($row['collected_at']) ? (string) $row['collected_at'] : null,
                erasable: $erasable,
                retainedReason: $erasable
                    ? null
                    : 'This is the provenance of a lead the team is working. Decide what happens to the lead first.',
            );
        }

        return $records;
    }

    /**
     * The person's entry on the do-not-contact list, if there is one. Reported so a subject asking
     * "do you still have me down as do-not-contact?" gets a straight answer; never erasable.
     *
     * Matched by hash through {@see SuppressionList}, and only the masked preview is reported — the
     * table holds no address in the clear and this must not be the place one appears (§3.9).
     *
     * @return list<SubjectRecord>
     */
    private function suppressionRecords(Team $team, string $kind, string $normalised): array
    {
        if ($kind === 'name') {
            // Nothing to match on: the list is keyed on a hash of an address or a number, and a
            // name hashes to nothing that is in it.
            return [];
        }

        $type = $kind === 'email' ? SuppressionType::Email : SuppressionType::Phone;

        $entries = OutreachSuppression::query()
            ->whereIn('scope_key', ['global', 'team:'.$team->getKey()])
            ->where('type', $type->value)
            ->where('value_hash', $this->suppressions->hash($normalised))
            ->orderBy('id')
            ->get();

        $records = [];

        foreach ($entries as $entry) {
            $records[] = new SubjectRecord(
                category: SubjectDataCategory::Suppression,
                id: (int) $entry->getKey(),
                leadId: null,
                companyName: null,
                detail: $entry->value_preview,
                labels: [$entry->reason->label()],
                matchedOn: $kind,
                recordedAt: $entry->suppressed_at->toIso8601String(),
                erasable: false,
                retainedReason: 'Removing this would let us contact them again. It holds a one-way hash, not their address.',
            );
        }

        return $records;
    }

    private function isSuppressed(Team $team, string $kind, string $normalised): bool
    {
        return match ($kind) {
            'email' => $this->suppressions->isEmailSuppressed($normalised, (int) $team->getKey()),
            'phone' => $this->suppressions->isPhoneSuppressed($normalised, (int) $team->getKey()),
            default => false,
        };
    }

    /**
     * Approaches logged against the given contacts.
     *
     * @param  list<int|string>  $contactIds
     * @return Collection<int, ContactAttempt>
     */
    private function attemptsFor(Team $team, array $contactIds, int $cap): Collection
    {
        if ($contactIds === []) {
            return ContactAttempt::query()->whereRaw('1 = 0')->get();
        }

        return ContactAttempt::query()
            ->where('team_id', $team->getKey())
            ->whereIn('lead_contact_id', $contactIds)
            ->with('lead:id,company_name')
            ->orderBy('id')
            ->limit($cap)
            ->get();
    }

    private function contactRecord(LeadContact $contact, string $matchedOn): SubjectRecord
    {
        $detail = match (true) {
            $contact->email !== null => SuppressionType::Email->preview(Str::lower($contact->email)),
            $contact->phone !== null => SuppressionType::Phone->preview((string) SuppressionType::Phone->normalise($contact->phone)),
            default => (string) ($contact->title ?? ''),
        };

        return new SubjectRecord(
            category: SubjectDataCategory::Contact,
            id: (int) $contact->getKey(),
            leadId: (int) $contact->lead_id,
            companyName: $contact->lead?->company_name,
            detail: $detail,
            labels: [],
            matchedOn: $matchedOn,
            recordedAt: $contact->created_at?->toIso8601String(),
            erasable: true,
        );
    }

    private function messageRecord(OutreachMessage $message, string $matchedOn): SubjectRecord
    {
        return new SubjectRecord(
            category: SubjectDataCategory::Message,
            id: (int) $message->getKey(),
            leadId: (int) $message->lead_id,
            companyName: $message->lead?->company_name,
            detail: '',
            labels: [$message->channel->label(), $message->status->label()],
            matchedOn: $matchedOn,
            recordedAt: ($message->sent_at ?? $message->created_at)?->toIso8601String(),
            erasable: true,
        );
    }

    private function attemptRecord(ContactAttempt $attempt, string $matchedOn): SubjectRecord
    {
        return new SubjectRecord(
            category: SubjectDataCategory::Attempt,
            id: (int) $attempt->getKey(),
            leadId: (int) $attempt->lead_id,
            companyName: $attempt->lead?->company_name,
            detail: '',
            labels: [$attempt->channel->label(), $attempt->outcome->label()],
            matchedOn: $matchedOn,
            recordedAt: $attempt->occurred_at->toIso8601String(),
            erasable: true,
        );
    }

    /**
     * Neutralise the `LIKE` wildcards in a value being searched *for*, so a subject whose name
     * contains an underscore doesn't match every single character in its place.
     *
     * The escape character is **`!`, not a backslash**, for the reason `LeadQueryBuilder` records:
     * SQLite has no default escape character so the clause has to be written out, and in MySQL the
     * literal `'\'` is an unterminated string — the backslash escapes the closing quote. A
     * backslash passes every SQLite test and fails every production query.
     */
    private function escapeLike(string $value): string
    {
        $escape = self::LIKE_ESCAPE;

        return str_replace(
            [$escape, '%', '_'],
            [$escape.$escape, $escape.'%', $escape.'_'],
            $value,
        );
    }
}
