<?php # /inc/fixed_assets.inc.php v:1.3.1 d:2026-09-15 i:evs
# NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): afskrivnings-
# beregningen udtrukket fra fixed_asset_actions.php til to genbrugelige
# funktioner, så fixed_asset_list.php kan vise "X aktiver, Y kr afskrivning
# skyldig" FØR man trykker den bogførende "Kør afskrivninger"-knap, uden at
# have beregningslogikken kopieret to steder. Samme "udtræk til fælles
# funktion"-mønster som denne sessions øvrige bulk-/forhåndsvisnings-arbejde
# (reconcile_one_bank_line(), send_single_reminder(), mark_expense_paid(),
# get_customer_statement_data()).

// Antal HELE måneder mellem to datoer (afrundet ned) - en påbegyndt, endnu
// ikke fuldført måned afskrives ikke endnu, den tælles med ved NÆSTE kørsel.
// RETTET: et aktiv anskaffet den 29.-31. gled: "+1 month" fra 31/1 gav 3/3 (februar sprang over), så
// last_depreciated_date kunne lande EFTER i dag og afskrivningen fulgte derefter den 3. i stedet for
// månedens slutning - og DateTime::diff() talte 31/1 -> 28/2 som 0 hele måneder. Nu samme regel som
// recurring_next_date(): samme dag i måneden, højst månedens sidste - den sidste forbliver den sidste.
function fa_add_months(string $date, int $n): string {
    $t = strtotime($date);
    $y = (int)date('Y', $t);  $m = (int)date('n', $t) + $n;  $d = (int)date('j', $t);
    $was_last = $d === (int)date('t', $t);
    $y += intdiv($m - 1, 12) - ($m < 1 ? 1 : 0);  $m = (($m - 1) % 12 + 12) % 12 + 1;
    $last = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
    return sprintf('%04d-%02d-%02d', $y, $m, $was_last ? $last : min($d, $last));
}

// Hele måneder fra $from til $to: det største n hvor fa_add_months($from, n) <= $to
function fa_whole_months_between(string $from, string $to): int {
    $from = substr($from, 0, 10);  $to = substr($to, 0, 10);
    if ($to <= $from) return 0;
    $n = ((int)substr($to, 0, 4) - (int)substr($from, 0, 4)) * 12 + ((int)substr($to, 5, 2) - (int)substr($from, 5, 2));
    while ($n > 0 && fa_add_months($from, $n) > $to) $n--;
    return max(0, $n);
}

/**
 * Beregner den skyldige afskrivning for ét aktivt aktiv frem til $today -
 * REN beregning, ingen database-skrivning. Returnerer null hvis intet er
 * skyldigt (allerede fuldt afskrevet, ingen hel måned gået endnu, eller en
 * ugyldig konfiguration), ellers ['amount'=>float, 'months'=>int,
 * 'start_from'=>string, 'new_last_date'=>string].
 */
function fa_calculate_due_depreciation(array $asset, string $today): ?array {
    $depreciable_base = round((float)$asset['acquisition_cost'] - (float)$asset['residual_value'], 2);
    if ($depreciable_base <= 0) return null;

    $monthly_dep = round($depreciable_base / ((int)$asset['useful_life_years'] * 12), 2);
    if ($monthly_dep <= 0) return null;

    $start_from = $asset['last_depreciated_date'] ?: $asset['acquisition_date'];
    $months     = fa_whole_months_between($start_from, $today);
    if ($months <= 0) return null;

    $amount = round($monthly_dep * $months, 2);

    // Aldrig afskriv ud over den afskrivningsbare basis (anskaffelsessum
    // minus scrapværdi) - sidste rate afkortes til reelt at ramme præcis
    // scrapværdien, ikke et par kroner under eller over pga. afrunding.
    $remaining = round($depreciable_base - (float)$asset['accumulated_depreciation'], 2);
    if ($remaining <= 0.01) return null;
    if ($amount > $remaining) $amount = $remaining;
    if ($amount <= 0.01) return null;

    $new_last_date = fa_add_months($start_from, $months);

    return ['amount' => $amount, 'months' => $months, 'start_from' => $start_from, 'new_last_date' => $new_last_date];
}

/**
 * NYT (bruger-anmodet, "kan bogholderen ønske sig mere"): fixed_asset_edit.php
 * viste FØR kun den AKTUELLE bogførte værdi - ingen fremadskuende år-for-år-
 * oversigt over hele levetiden. Bruger PRÆCIS samme månedlige rate-formel
 * (og samme "sidste rate afkortes til at ramme scrapværdien præcist"-
 * princip) som fa_calculate_due_depreciation() ovenfor - de to må ALDRIG
 * kunne regne forskelligt for samme aktiv. Ren fremskrivning, ingen
 * database-skrivning og INGEN afhængighed af hvor meget der reelt ER
 * bogført indtil nu (accumulated_depreciation) - dette er den FULDE,
 * teoretiske plan fra anskaffelse til scrapværdi, til planlægning/
 * revisorgennemgang, ikke et "hvad mangler jeg at bogføre nu"-tal.
 *
 * Returnerer en liste af ['year'=>int, 'months'=>int, 'depreciation'=>float,
 * 'accumulated'=>float, 'book_value'=>float] - én række pr. kalenderår
 * aktivet afskrives i, fra anskaffelsesåret til det år scrapværdien nås.
 */
function fa_build_full_schedule(array $asset): array {
    $cost = (float)$asset['acquisition_cost'];
    $residual = (float)$asset['residual_value'];
    $depreciable_base = round($cost - $residual, 2);
    $schedule = [];
    if ($depreciable_base <= 0) return $schedule;

    $life_months = (int)$asset['useful_life_years'] * 12;
    $monthly_dep = round($depreciable_base / $life_months, 2);
    if ($monthly_dep <= 0) return $schedule;

    $acq = new DateTime($asset['acquisition_date']);
    $accumulated = 0.0;
    $months_left = $life_months;
    $cursor = clone $acq;

    while ($months_left > 0) {
        $year = (int)$cursor->format('Y');
        $months_in_year = min(12 - ((int)$cursor->format('n') - 1), $months_left);
        $year_dep = round($monthly_dep * $months_in_year, 2);

        // Sidste rate (uanset om det er midt i eller ved slutningen af et
        // kalenderår) afkortes til reelt at ramme scrapværdien præcist -
        // samme afrundingsprincip som fa_calculate_due_depreciation().
        $remaining = round($depreciable_base - $accumulated, 2);
        if ($year_dep >= $remaining) { $year_dep = $remaining; $months_left = $months_in_year; }

        $accumulated = round($accumulated + $year_dep, 2);
        $schedule[] = [
            'year' => $year,
            'months' => $months_in_year,
            'depreciation' => $year_dep,
            'accumulated' => $accumulated,
            'book_value' => round($cost - $accumulated, 2),
        ];

        $months_left -= $months_in_year;
        $cursor->modify("+$months_in_year months");
    }

    return $schedule;
}
