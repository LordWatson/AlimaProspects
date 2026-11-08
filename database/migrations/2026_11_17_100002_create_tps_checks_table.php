<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared TPS/CTPS screening cache (PL5).
 *
 * **Platform reference data, not tenant data** — no `team_id`, no `BelongsToTeam`, and the absence
 * is the statement (ADR 0025's "platform reference dataset" shape). Whether a number is on the
 * Telephone Preference Service is a fact about the number, identical for every team that holds it,
 * and the register charges per lookup: keying this per tenant would make two customers pay twice
 * for the same answer and let one of them hold a stale "clear" while the other knows better.
 *
 * It stores a **hash, never a number**. The row says "this identity is registered"; it is not a
 * second copy of a phone book, and nothing in the product needs to read a number back out of it.
 *
 * `checked_at` is the only freshness signal — a verdict goes stale after
 * `compliance.tps.recheck_days` (28 by default, which is the register's own refresh cadence) and is
 * re-screened rather than aged out, because an expired row with no replacement would read as
 * "never checked" the moment the provider is down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tps_checks', function (Blueprint $table): void {
            $table->id();

            // SHA-256 of the normalised digits, via `Core\Support\ContactIdentity` — the same
            // normaliser `outreach_suppressions` and `lead_contacts` hash through, so one number
            // written four ways is one row here too.
            $table->char('phone_hash', 64);

            $table->string('register', 8);   // TpsRegister value: tps | ctps
            $table->string('result', 16);    // TpsResult value: clear | registered

            // The provider key that answered, so an operator can tell a licensed lookup from a
            // seeded fixture. Not a secret: it is our supplier, not a credential.
            $table->string('provider', 32);

            $table->timestamp('checked_at');
            $table->timestamps();

            // One current verdict per (number, register). A re-screen updates the row rather than
            // appending: this is a cache, and the history of a register's answers is not something
            // the product has a question about.
            $table->unique(['phone_hash', 'register']);

            // The sweep that drops verdicts nobody has asked about in a long time.
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tps_checks');
    }
};
