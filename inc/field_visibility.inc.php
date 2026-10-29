<?php # /inc/field_visibility.inc.php v:1.3.1 d:2026-09-15 i:evs
/* ==========================================================================
   HØJREKLIK-SKJUL FOR ENKELTE FORMULARFELTER (bruger-anmodet)

   Samme princip og tabel-facon som den allerede eksisterende menu_visibility
   (inc/menu.inc.php/menu_hide_action.php) - en administrator kan højreklikke
   et markeret felt og skjule det for L1/L2, uafhængigt pr. niveau, uden
   nogensinde at kunne skjule det for sig selv (level_3 røres aldrig).
   Mangler en række for et givent felt, er det synligt for alle tre niveauer
   som standard.

   Bruges parvis:
     $fv_overrides = get_field_visibility_overrides($conn);   // ét opslag pr. side
     render_hideable_field($fv_overrides, 'customer_edit.credit_limit', $is_admin, $uLev, function() {
         htm_Field(...);
     });

   $item_key-konventionen er "<side>.<feltnavn>" (fx "customer_edit.credit_limit")
   - samme fil kan derfor sagtens have flere skjulbare felter uden kollision
   med andre siders felter af samme navn.
   ========================================================================== */

function get_field_visibility_overrides($conn): array {
    $overrides = [];
    if (!$conn) return $overrides;
    $res = @DB::query($conn, "SELECT item_key, level_1, level_2, level_3 FROM field_visibility");
    if ($res) {
        while ($row = DB::fetch_assoc($res)) {
            $overrides[$row['item_key']] = [
                1 => (int)$row['level_1'] === 1,
                2 => (int)$row['level_2'] === 1,
                3 => (int)$row['level_3'] === 1,
            ];
        }
    }
    return $overrides;
}

function field_visibility_defaults(): array {
    return [1 => true, 2 => true, 3 => true];
}

// Kan den indloggede bruger se feltet (samme regel som render_hideable_field())? Et skjult felt er
// slet ikke med i formularen - gemmekoden må da IKKE skrive en tom værdi over den gemte. RETTET:
// en L1/L2-bruger der gemte en kunde/et produkt, slettede ellers kreditgrænse, fakturasprog,
// fakturalayout, rykker-fravalg og kostpris, når en admin havde skjult felterne for niveauet.
function field_visible_to_user($conn, string $item_key): bool {
    static $overrides = null;
    if ($overrides === null) $overrides = get_field_visibility_overrides($conn);
    $vis = $overrides[$item_key] ?? field_visibility_defaults();
    return !empty($vis[(int)($_SESSION['user_level'] ?? 1)]);
}

/**
 * Renderer et enkelt skjulbart felt via $render_field (en closure der selv
 * kalder htm_Field()/echoer feltets HTML). For en admin vises feltet ALTID,
 * med data-attributter til højreklik-menuen (samme JS/kontekstmenu som
 * inc/menu.inc.php's egen, genbrugt via et fælles data-fieldkey-selektor -
 * se dens egen kommentar). For L1/L2 udelades feltet HELT fra outputtet,
 * hvis det er skjult for netop deres niveau - ikke bare visuelt skjult med
 * CSS, som stadig ville sende feltets værdi med ved en formularindsendelse.
 *
 * RETTET (bruger-rapporteret: "Det var ikke meningen at tilføje en ramme
 * men at den eksisterende blot ændrede udseende, kun hvis der ikke findes
 * en kan den tilføjes"): et almindeligt htm_Field()-kaldt felt har ALLEREDE
 * sin egen <fieldset>-kant (border:1px solid var(--border-fieldset), se
 * inc/php2htm.lib.php) - at pakke DEN ind i endnu en boks med sin egen kant
 * gav en dobbelt/indlejret ramme-effekt, ikke en ren omfarvning af den
 * eksisterende. $has_own_box angiver om $render_field() allerede tegner sin
 * egen fieldset-boks: er den true, omfarves den EKSISTERENDE fieldset til
 * stiplet blå via en efterkommer-CSS-regel (inc/menu.inc.php, ".tc-hideable-
 * field fieldset"); er den false (fx et rent <label>-afkrydsningsfelt uden
 * nogen boks i forvejen, som cust_no_reminders/is_active), tilføjes en helt
 * ny stiplet blå ramme udenom via klassen "tc-no-existing-box".
 */
function render_hideable_field($overrides, string $item_key, bool $is_admin, int $user_level, callable $render_field, bool $has_own_box = false): void {
    $vis = $overrides[$item_key] ?? field_visibility_defaults();

    // RETTET (bruger-anmodet: "kan det ikke ændres så skjul i L1/L2 også
    // virker for admin"): fjernet den tidligere "en admin ser ALTID feltet"-
    // undtagelse - skjul-reglen gælder nu ens for alle niveauer, admin
    // inklusive. I praksis ændrer dette intet for en RIGTIG admin-konto
    // (role_to_level('admin') === 3, inc/db_connect.inc.php, og context-
    // menuen i inc/menu.inc.php har kun afkrydsningsfelter for niveau 1/2 -
    // niveau 3 kan aldrig sættes skjult via UI'en) - brugeren bekræftede at
    // dette er den tilsigtede sikkerhedsmekanisme ("Admin kan vel bare
    // skifte til L3 hvis indstilling skal ændres").
    if (empty($vis[$user_level])) {
        return; // skjult for dette niveau - udelades helt, ikke kun CSS-skjult
    }

    if ($is_admin) {
        // RETTET (bruger-anmodet, runde 28-opfølgning #2): "Tillægsfelt"-
        // badgeteksten fjernet - den stiplede blå kant alene er nok til at
        // markere feltet som et tillægsfelt, uden at overskygge feltets
        // egen, funktionelle label (fx "Kreditgrænse"). data-hint bevarer
        // stadig en forklaring ved hover, for dem der er i tvivl.
        $cls = 'tc-hideable-field' . ($has_own_box ? '' : ' tc-no-existing-box');
        echo '<div class="' . $cls . '" data-fieldkey="' . htmlspecialchars($item_key, ENT_QUOTES) . '"'
            . ' data-hidden-l1="' . (empty($vis[1]) ? '1' : '0') . '"'
            . ' data-hidden-l2="' . (empty($vis[2]) ? '1' : '0') . '"'
            . ' data-hint="' . htmlspecialchars(lang('@This is an extra field - right-click to hide it for specific user levels'), ENT_QUOTES) . '">';
    }
    $render_field();
    if ($is_admin) {
        echo '</div>';
    }
}
?>
