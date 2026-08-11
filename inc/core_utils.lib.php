<?php # /inc/core_utils.lib.php v:1.2.0 d:2026-08-11 i:evs 
/* ==========================================================================
   KERNE-UTILITY-FUNKTIONER (IKKE HTML-BYGGENDE)

   Udskilt fra php2htm.lib.php, som nu udelukkende indeholder HTML-byggende
   htm_*-rutiner. Denne fil rummer lang() og andre hjælpefunktioner, der
   returnerer rene strenge/data - ikke markup.

   Inkluderes automatisk af php2htm.lib.php, som stadig er masterfilen.
   Intet andet sted i projektet skal require'e denne fil direkte.
   ========================================================================== */

# -------------------------------------------------------------------------
# SPROGFUNKTION (Sikret mod dobbeltindlæsning, men dynamisk ved sprogskift)
# -------------------------------------------------------------------------
if (!function_exists('lang')) {
    function lang($key, $echo=false) {
        $current_lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'da';
        static $tr = null;
        static $loaded_lang = null; // Holder øje med, hvilket sprog der ligger i cachen
        // Hvis vi ikke har indlæst noget endnu, ELLER hvis sproget har ændret sig undervejs:
        if ($tr === null || $loaded_lang !== $current_lang) {
            $tr = null; // Nulstil det gamle sprog-array
            $loaded_lang = $current_lang; // Opdater det tracked sprog
            $f = dirname(__DIR__) . '/json-data/languages.json';
            if (file_exists($f)) {
                $d = json_decode(file_get_contents($f), true);
                if ($d && isset($d['language'])) {
                    foreach ($d['language'] as $l) {
                        if ($l['code'] === $current_lang) {
                            $tr = $l['translation'];
                            break;
                        }
                    }
                }
            }
        }
        if ($tr === null || !isset($tr[$key]) || $tr[$key] === "") {
            return ltrim($key, '@');
        }
        if ($echo) echo $tr[$key]; else return $tr[$key];
    }
}

# -------------------------------------------------------------------------
# TEKST- OG STI-HJÆLPEFUNKTIONER (returnerer rene strenge, ikke HTML)
# -------------------------------------------------------------------------

function clean_address_text($text) { // Renser adresse-tekst for linjeskift og overflødigt whitespace
    if (empty($text)) return '';
    $text = preg_replace('/\R+/', ' ', $text);       // Fjerner alle linjeskift (\r, \n) og erstatter med et mellemrum
    return trim(preg_replace('/\s+/', ' ', $text));  // Fjerner dobbelte mellemrum der kan opstå efter udskiftning
}

function get_tc_doc($filename, $type = 'DOC') {
    if (empty($filename)) return false;
    $base_dir = 'uploads/'; $clean_name = basename($filename); $path = $base_dir . $clean_name;
    if (file_exists(__DIR__ . '/../' . $path) || file_exists($path)) { return $path; }
    return false;
}

// OMDØBT fra htm_DocPath(): returnerer kun en filsti (string), ikke HTML -
// hørte derfor reelt aldrig hjemme i den htm_*-navngivne gruppe af
// markup-byggende funktioner. Kaldes internt fra htm_GetDocIcon() i
// php2htm.lib.php.
function resolve_doc_path($filename) {
    if (!$filename) return false;
    $clean_name = basename($filename); $paths = array('uploads/', 'expenses/', 'bilag/');
    foreach ($paths as $p) { if (file_exists($p . $clean_name)) { return $p . $clean_name; } }
    return false;
}
