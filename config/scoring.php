<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Scoring & Matching configuration
|--------------------------------------------------------------------------
|
| How a lead's ICP-fit score is produced (Phase 9). The score is hybrid: a
| deterministic pass over the ICP's weighted criteria plus an AI qualitative
| assessment (`scoring.qualitative_fit`), blended by the weights below and
| graded A–D by the thresholds. See docs/modules/scoring-matching.md.
|
*/

return [

    // How the two halves of the hybrid score are blended. Any positive pair works — they are
    // normalised by their sum at use, so 60/40 and 6/4 behave identically. When one half is
    // unavailable (no assessable criteria, or the AI call failed) the other carries the score.
    'blend' => [
        'deterministic' => (float) env('SCORING_BLEND_DETERMINISTIC', 0.6),
        'qualitative' => (float) env('SCORING_BLEND_QUALITATIVE', 0.4),
    ],

    // Minimum blended score (0–100) for each grade, highest first. Anything below the C
    // threshold grades D. A hard ICP exclusion always grades D regardless of these.
    'grades' => [
        'a' => (int) env('SCORING_GRADE_A', 80),
        'b' => (int) env('SCORING_GRADE_B', 60),
        'c' => (int) env('SCORING_GRADE_C', 40),
    ],

    // Weight applied to a positive signal the team left unweighted (weight is optional in the ICP
    // editor). Keeps an unweighted criterion influential without letting it dominate.
    'default_signal_weight' => (int) env('SCORING_DEFAULT_WEIGHT', 50),

    // Manual re-scores one member may ask for per minute (the `scoring` rate limiter). Each one
    // spends AI credit; the team's overall allowance is still enforced by the AI Platform.
    'rescore_rate_limit' => (int) env('SCORING_RESCORE_RATE_LIMIT', 10),

    // Leads per chunk when re-scoring a whole team after its ICP changed. Each chunk dispatches
    // one job per lead, so this only bounds memory, not concurrency.
    'rescore_chunk' => (int) env('SCORING_RESCORE_CHUNK', 200),

    // How many recent scores the ICP calibration preview reads to build its per-criterion picture.
    // The grade mix is exact over every lead; the criterion outcome counts come from each score's
    // stored breakdown (a JSON column), so they are taken from this many most-recent scores to bound
    // the work on a team with very many leads.
    'calibration_sample' => (int) env('SCORING_CALIBRATION_SAMPLE', 500),

    // Country names that mean the same place, so a geography criterion written as "UAE" still
    // matches a lead whose provider stored "AE". Kept to the MVP markets (ADR 0007) plus the
    // neighbours a UK/UAE team is most likely to type; extend as markets are added.
    'country_aliases' => [
        ['gb', 'uk', 'united kingdom', 'great britain', 'britain'],
        ['ae', 'uae', 'united arab emirates', 'emirates'],
        ['ie', 'ireland', 'republic of ireland'],
        ['sa', 'ksa', 'saudi arabia'],
        ['us', 'usa', 'united states', 'united states of america'],
    ],

    // Industry sector taxonomy — the thing a literal string compare cannot be: a map from a
    // *vocabulary* to a *sector*. An ICP written "SaaS" and a lead enriched as "cloud software
    // development" are the same business to a human and share not one word to `str_contains`, so
    // before the taxonomy that was a hard miss that tanked otherwise-strong leads the moment
    // enrichment filled in an industry. `SectorTaxonomy` reads this to place both sides:
    //
    //   - both land on the SAME sector key → a confident **match**;
    //   - both land on DIFFERENT known sectors → a confident **miss** (real filtering is kept —
    //     "logistics" is not "software");
    //   - either side is unmappable → the rules **defer** (CriterionMatch::Weak) to the AI half
    //     rather than guess.
    //
    // Each entry is [canonical key => keywords that imply it]. Keep sectors reasonably coarse: a
    // too-fine split re-introduces the brittleness this exists to remove, and the AI half carries
    // the nuance either way. The key itself is also a keyword. Order matters only for readability;
    // a value that matches two sectors' keywords takes the first, so put the more specific sectors
    // (fintech) above the broader ones (financial services) they would otherwise be swallowed by.
    'sectors' => [
        'software' => ['software', 'saas', 'cloud software', 'web app', 'mobile app', 'developer tools', 'devtools', 'platform as a service', 'paas', 'it services', 'information technology', 'tech company', 'technology company'],
        'fintech' => ['fintech', 'financial technology', 'payments', 'payment processing', 'digital banking', 'insurtech', 'regtech', 'lending platform'],
        'ecommerce' => ['ecommerce', 'e-commerce', 'online retail', 'online store', 'direct to consumer', 'dtc', 'd2c', 'online shop'],
        'retail' => ['retail', 'shop', 'store', 'consumer goods', 'fashion', 'apparel', 'clothing', 'homeware'],
        'food_beverage' => ['food', 'beverage', 'drinks', 'restaurant', 'cafe', 'coffee', 'bakery', 'catering', 'hospitality', 'brewery', 'food and drink'],
        'manufacturing' => ['manufacturing', 'manufacturer', 'factory', 'industrial', 'engineering', 'fabrication', 'production'],
        'construction' => ['construction', 'building', 'builder', 'contractor', 'civil engineering', 'property development', 'architecture'],
        'logistics' => ['logistics', 'shipping', 'freight', 'haulage', 'courier', 'supply chain', 'warehousing', 'fulfilment', 'transport', 'distribution'],
        'real_estate' => ['real estate', 'property', 'estate agent', 'letting', 'lettings', 'property management', 'realtor'],
        'healthcare' => ['healthcare', 'health care', 'medical', 'clinic', 'pharma', 'pharmaceutical', 'biotech', 'life sciences', 'dental', 'care home'],
        'professional_services' => ['consulting', 'consultancy', 'accounting', 'accountant', 'legal', 'law firm', 'solicitor', 'advisory', 'bookkeeping', 'professional services'],
        'marketing' => ['marketing', 'advertising', 'creative agency', 'design agency', 'branding', 'digital agency', 'pr agency', 'media agency', 'seo'],
        'financial_services' => ['financial services', 'finance', 'bank', 'banking', 'insurance', 'investment', 'wealth management', 'asset management', 'brokerage'],
        'education' => ['education', 'edtech', 'school', 'university', 'training', 'e-learning', 'tutoring', 'academy'],
        'energy' => ['energy', 'renewables', 'solar', 'wind', 'oil and gas', 'utilities', 'power', 'cleantech'],
        'hospitality_travel' => ['travel', 'tourism', 'hotel', 'leisure', 'airline', 'booking', 'holiday'],
        'automotive' => ['automotive', 'car dealership', 'vehicle', 'garage', 'motor trade', 'auto repair'],
        'agriculture' => ['agriculture', 'farming', 'agritech', 'horticulture', 'agribusiness'],
        'media_entertainment' => ['media', 'entertainment', 'publishing', 'film', 'music', 'gaming', 'broadcast', 'streaming'],
        'nonprofit' => ['nonprofit', 'non-profit', 'charity', 'ngo', 'social enterprise', 'third sector'],
    ],

    // Seniority ladder — so "Director or above" implies VP and C-level, which a text compare of the
    // criterion against a contact's title never could ("Director or above" does not contain "CEO").
    // `SeniorityLadder` reads this: a criterion names a *floor* (the lowest rank wanted) and a
    // contact title is ranked; a title at or above the floor matches, a ranked title below it is a
    // confident miss, and a title the ladder can't rank defers to the AI half. Higher number =
    // more senior. Each rank lists the title keywords that place a contact on it; `floors` maps the
    // words a criterion uses to name a threshold onto the rank it means.
    'seniority' => [
        'ranks' => [
            // 5 — owns the company or the C-suite.
            5 => ['ceo', 'cfo', 'coo', 'cto', 'cmo', 'cio', 'ciso', 'cpo', 'cro', 'chief', 'founder', 'co-founder', 'cofounder', 'owner', 'proprietor', 'president', 'partner', 'managing director', 'managing partner', 'principal'],
            // 4 — vice-president tier.
            4 => ['vp', 'vice president', 'svp', 'evp', 'senior vice president', 'executive vice president'],
            // 3 — heads a function.
            3 => ['director', 'head of', 'head', 'vice-principal'],
            // 2 — runs a team.
            2 => ['manager', 'lead', 'team lead', 'supervisor', 'foreman'],
            // 1 — individual contributor / entry.
            1 => ['analyst', 'associate', 'coordinator', 'specialist', 'assistant', 'executive', 'officer', 'representative', 'administrator', 'consultant', 'intern', 'junior'],
        ],
        // The words a Seniority criterion uses to name the floor it wants, mapped to the rank.
        'floors' => [
            'c-level' => 5, 'c level' => 5, 'c-suite' => 5, 'c suite' => 5, 'chief' => 5, 'founder' => 5, 'owner' => 5, 'president' => 5, 'managing director' => 5,
            'vp' => 4, 'vice president' => 4,
            'director' => 3, 'head of' => 3, 'head' => 3,
            'manager' => 2, 'management' => 2, 'lead' => 2,
            'senior' => 2,
        ],
    ],

];
