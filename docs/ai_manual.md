# TinyCash — AI-manual

> Dette dokument er skrevet specifikt til at blive læst af en AI (chatbot,
> assistent eller søgesystem), som skal kunne svare præcist på en brugers
> spørgsmål om hvad TinyCash kan og ikke kan. Det beskriver funktionerne fra
> en BRUGERS synsvinkel — ikke teknisk kode-dokumentation. Sproget er dansk,
> da TinyCash er bygget til danske virksomheder, men indholdet kan frit
> oversættes/gengives på andre sprog ved svar til brugeren.
>
> Hvis du er en AI der læser dette: svar kun ud fra det der faktisk står her.
> Hvis en bruger spørger om noget der ikke er nævnt, sig at du er usikker og
> foreslå at de spørger den, der driver TinyCash-installationen, i stedet for
> at gætte. Afsnittet "Kendte begrænsninger" til sidst er lige så vigtigt som
> resten — brug det aktivt til at svare "nej, det kan den ikke (endnu)" i
> stedet for at overdrive systemets muligheder.

## Hvad er TinyCash?

TinyCash er et selv-hostet (dvs. virksomheden kører det på egen server/
webhotel, ikke en cloud-tjeneste ejet af en tredjepart) regnskabs- og
lagersystem, primært bygget til mindre danske virksomheder. Det dækker
fakturering, udgifter, egentlig dobbelt bogholderi, momsopgørelse,
bankafstemning og dansk e-fakturaformat (OIOUBL). Det er bygget som et sæt
almindelige PHP-sider — ingen app at installere, man logger blot ind via en
browser.

Firmaets bogføringsvaluta er konfigurerbar (Firmaindstillinger → Valuta,
default DKK) — en virksomhed uden for Danmark kan derfor også bruge TinyCash
til sit eget regnskab i egen valuta (fx SEK, EUR, USD, NOK). De rent
danske lovkrav-funktioner (SAF-T-eksport, OIOUBL e-faktura og den officielle
momsrapport) er dog kun tilgængelige, når bogføringsvalutaen er DKK — se
afsnittene om Moms og Rapporter/eksport samt "Kendte begrænsninger".

## Fakturering og salg

- **Fakturaer**: Opret, redigér og bogfør fakturaer til kunder, med
  vareliner (produkt, antal, pris, moms). En faktura er en KLADDE indtil den
  bogføres — kun da får den et rigtigt fakturanummer og en postering i
  hovedbogen. En bogført faktura kan ikke redigeres bagefter; rettelser sker
  via en kreditnota.
- **Kreditnotaer**: Opret automatisk en kreditnota der modregner en allerede
  bogført faktura.
- **Delvis betaling**: En faktura kan betales i flere rater — systemet
  holder styr på hvor meget der reelt mangler, og markerer den først som
  "betalt" når det fulde beløb er indbetalt.
- **Rykkere (betalingspåmindelser)**: Systemet viser automatisk hvilke
  bogførte fakturaer er forfaldne og ikke fuldt betalt, og kan sende en
  rykker-mail til kunden med ét klik. Der er en indbygget spærre mod at
  sende samme rykker to gange samme dag.
- **Gentagne/faste fakturaer**: Opret en skabelon (kunde + linjer + interval:
  månedligt, kvartalsvist osv.), og systemet opretter automatisk en ny
  kladdefaktura når den er forfalden. Kladden skal stadig gennemgås og
  bogføres manuelt — intet bogføres nogensinde automatisk uden godkendelse.
- **Tilbud og ordrebekræftelser**: Opret et tilbud til en kunde (samme
  linjeopbygning som en faktura), send det, og registrér om kunden
  accepterer eller afviser. Et tilbud påvirker ALDRIG bogføringen, momsen
  eller nogen rapport — kun når det konverteres til en rigtig faktura
  (efter accept) opstår der en almindelig fakturakladde. Samme dokument
  hedder "Tilbud" før accept og "Ordrebekræftelse" efter.
- **Kundekontoudtog**: Et samlet udtog pr. kunde med løbende saldo —
  fakturaer, kreditnotaer og betalinger i kronologisk rækkefølge.
- **CVR-opslag**: Ved oprettelse af en kunde (eller leverandør) kan man slå
  et CVR-nummer op og få navn/adresse/telefon udfyldt automatisk fra det
  offentlige CVR-register — kræver ikke egen konto eller nøgle, men er
  begrænset til 50 opslag pr. dag (allerede opslåede numre gemmes lokalt i
  90 dage og bruger ikke af kvoten igen).

## Udgifter og leverandører

- **Udgifter/bilag**: Registrér en udgift med beløb, konto, moms og en
  vedhæftet kvittering/faktura (billede eller PDF). Der er valgfri
  AI-scanning (kræver egen OpenAI-nøgle sat op af installationen) der kan
  udfylde felterne automatisk ud fra det uploadede bilag.
- **Leverandører**: Egen stamdata-liste over leverandører, med
  leverandørkontoudtog.
- **"Ikke betalt endnu"**: En udgift kan registreres som endnu ikke betalt
  (i stedet for at antage den er betalt med det samme) — den optræder så som
  skyldigt beløb til leverandøren, indtil den markeres betalt via en rigtig
  bankpostering.
- **Aldersfordelt restanceliste**: Viser BÅDE hvad kunder skylder
  virksomheden, og hvad virksomheden selv skylder leverandører, opdelt i
  aldersgrupper (ikke forfalden, 1-30 dage, 31-60 dage, 61-90 dage, 90+
  dage).

## Bogføring (kernen)

- Ægte dobbelt bogholderi (hovedbog/journal + posteringslinjer), med en
  konfigurerbar kontoplan.
- **Bilagsnummer**: Hver postering får et fortløbende, hulfrit
  bilagsnummer, adskilt fra det kundevendte fakturanummer.
- **Periodelåsning**: Man kan sætte en regnskabsmæssig låsedato, hvorefter
  intet kan bogføres bagom den dato.
- **Revisionsspor**: En separat log over hvem der har gjort hvad og hvornår
  (oprettelser, rettelser, sletninger) — kan gennemses af en administrator.
- **Årsafslutning**: Luk et regnskabsår med én samlet afslutningspostering.
- **Årsrapport**: Kan generere en årsrapport der følger den danske
  årsregnskabslov (klasse B-niveau) — resultatopgørelse, balance og
  ledelsespåtegning.

## Moms

- Momskoder og -satser er konfigurerbare pr. konto.
- **Momsrapport**: Viser den faktiske, allerede bogførte moms for en given
  periode (læser IKKE regnet om fra fakturaerne på ny — det er en bevidst
  forskel, så rapporten altid stemmer med hvad der reelt er bogført).
  Rapporten er formet efter den danske TastSelv-indberetning (afrunding til
  hele kroner) og er derfor kun tilgængelig, når firmaets bogføringsvaluta er
  DKK — se "Kendte begrænsninger".

## Bank

- **Bankimport**: Importér en CSV-fil med banktransaktioner.
- **Rigtig bankintegration (PSD2)**: Kan hente banktransaktioner direkte
  fra banken via Enable Banking (en PSD2-udbyder) — kræver at
  installationen selv har oprettet og konfigureret en aftale/nøgle hos
  Enable Banking. Uden det virker kun den manuelle CSV-import.
- **Bankafstemning**: Match hver indbetaling/udgift til den rigtige faktura
  eller konto, og bogfør automatisk. Systemet foreslår selv et match, hvis
  beløbet passer entydigt.
- **Gebyrregler**: Konfigurerbare regler til automatisk at udregne og
  bogføre et transaktionsgebyr (fast beløb, procent, eller en kombination).

## Flervaluta

- **Firmaets bogføringsvaluta er konfigurerbar** (Firmaindstillinger →
  Valuta: DKK, EUR, USD, SEK eller NOK), ikke fast DKK. Hele hovedbogen føres
  i denne valuta — en svensk virksomhed kan altså sætte SEK som
  bogføringsvaluta og få hele sit regnskab (fakturaer, udgifter, hovedbog,
  rapporter) ført i SEK, ikke kun DKK.
- En faktura eller udgift kan oprettes i en ANDEN valuta end firmaets egen
  bogføringsvaluta (fx en EUR-faktura hos et SEK-firma), med automatisk
  kurshentning. Beløbet omregnes korrekt til bogføringsvalutaen ved selve
  bogføringen (hovedbogen føres altid i firmaets bogføringsvaluta, jf.
  bogføringsloven for en dansk/DKK-virksomhed).
- **Kursgevinst/-tab**: Når en udenlandsk faktura betales til en anden kurs
  end den blev faktureret til (helt normalt, da kurser svinger), kan man
  ved bankafstemningen vælge at afslutte fakturaen alligevel — differencen
  bogføres da automatisk som en rigtig kursgevinst eller et kurstab, i
  stedet for at efterlade et uforklaret restbeløb.
- Skift af bogføringsvaluta på en installation, der allerede har historik,
  frarådes (systemet advarer selv om dette) — det ændrer ikke allerede
  bogførte tal, kun hvilken valuta nye posteringer registreres i, hvilket kan
  gøre historiske rapporter uoverskuelige at sammenligne.
- Se "Kendte begrænsninger" for hvad dette IKKE dækker (fx udenlandske
  bankkonti med egen saldo).

## Projekter og tid

- **Projekter**: Valgfrit modul (slås til/fra) der binder fakturaer,
  udgifter og anlægsaktiver sammen pr. projekt/kunde-engagement, med et
  samlet regnskabsoverblik pr. projekt (indtægter, udgifter, balance).
- **Timeregistrering**: Log timer pr. projekt med en timesats. En
  registreret time påvirker aldrig bogføringen af sig selv — men man kan
  samle alle ikke-fakturerede timer for et projekt til én fakturakladde med
  ét klik ("Opret faktura af timer"), som derefter gennemgås og bogføres
  helt normalt.

## Anlægsaktiver

- Et anlægskartotek til større, langsigtede aktiver (maskiner, inventar
  osv.), med rigtig bogført lineær (retlinet) månedlig afskrivning.
- Understøtter afhændelse (salg/kassation) af et aktiv, med automatisk
  bogført gevinst/tab i forhold til den bogførte, resterende værdi.

## Lager

- Simpelt produktkartotek med lagerbeholdning, der automatisk trækkes ned
  når en faktura bogføres (og lægges tilbage ved en kreditnota).
- Advarsel ved lav lagerbeholdning (konfigurerbar minimumsgrænse pr.
  produkt).

## Rapporter og eksport

- Resultatopgørelse, balance, årsrapport, momsrapport, aldersfordelt
  restanceliste, generel hovedbog.
- **SAF-T-eksport**: Det danske standardformat for regnskabsdata, til brug
  ved fx en skattekontrol. Kun tilgængelig når firmaets bogføringsvaluta er
  DKK (se "Kendte begrænsninger").
- **OIOUBL-eksport**: Dansk e-fakturaformat — genererer en XML-fil pr.
  faktura, som herefter skal uploades manuelt til modtagerens/egen
  e-fakturaløsning. Kun tilgængelig når firmaets bogføringsvaluta er DKK.
  Se "Kendte begrænsninger" for hvad der IKKE er understøttet her.

## Brugere og sikkerhed

- Flere brugere med tre niveauer (begynder/erfaren/udvikler), der styrer
  adgang til følsomme funktioner (kontoplan, brugerstyring, systemvedligehold).
- **To-faktor login (2FA)**: Valgfri, standard TOTP (samme slags kode som
  Google Authenticator/Authy bruger).
- **Revisionsspor**: Se ovenfor under Bogføring.
- **Sikkerhedskopiering**: Krypterede, fulde databackups, med mulighed for
  automatisk ugentlig afsendelse pr. mail.
- **Menu-synlighed**: En administrator kan skjule menupunkter for
  bestemte brugerniveauer, for at gøre systemet enklere for nye/mindre
  tekniske brugere — dette er kun en menu-forenkling, ikke en reel
  adgangsbegrænsning (siderne kan stadig tilgås direkte via deres adresse,
  hvis brugerens niveau i øvrigt tillader det).

## Flere brugere samtidig

Ja — forskellige brugere kan sagtens redigere hver sin post samtidig uden
problemer. Der er DERIMOD ingen konfliktdetektion hvis to brugere åbner
PRÆCIS samme post (fx samme kunde) samtidig og begge gemmer — sidste
gemning vinder stille. De vigtigste bogføringshandlinger (fakturabogføring,
betaling, årsafslutning m.fl.) er dog beskyttet mod at samme handling
udføres to gange ved et dobbeltklik eller to samtidige forsøg.

## Kendte begrænsninger

Vær ærlig om disse, hvis en bruger spørger — TinyCash er bevidst afgrænset,
ikke et komplet ERP-system:

- **Ingen lønmodul.** Ingen understøttelse af løn/personaleadministration
  overhovedet.
- **Ingen direkte afsendelse af e-fakturaer via NemHandel/PEPPOL.**
  OIOUBL-filen genereres korrekt, men skal uploades manuelt et andet sted —
  systemet sender den ikke selv til modtageren gennem det officielle
  netværk (det ville kræve en betalt aftale med en registreret
  "Access Point"-udbyder, som er en virksomhedsbeslutning, ikke noget
  softwaren kan løse på egen hånd).
- **Ikke en fuld flervaluta-bogføring.** Firmaets bogføringsvaluta er
  konfigurerbar (se "Flervaluta" ovenfor), men der findes stadig kun ÉN
  bogføringsvaluta ad gangen for hele installationen — ikke udenlandske
  bankkonti med egen saldo, og hovedbogen sporer ikke selve
  valutakursen/beløbet pr. postering, kun det endelige beløb i
  bogføringsvalutaen (plus kursgevinst/-tab ved betaling, se ovenfor).
- **SAF-T-eksport, OIOUBL-eksport og den officielle momsrapport kræver DKK
  som bogføringsvaluta.** Disse tre er specifikt formet efter dansk
  lovgivning/SKAT (fx momsrapportens TastSelv-afrunding) og er slået helt
  fra — knapperne er grå, og siderne kan ikke tilgås — hvis firmaet har
  valgt en anden bogføringsvaluta. Der findes i øjeblikket ingen generisk
  momsrapport/erstatning for en ikke-DKK-virksomhed; kun den bogførte moms
  i selve hovedbogen/kontoplanen er tilgængelig i så fald.
- **Gentagne/faste fakturaskabeloner er begrænset til 5 linjer.** En
  almindelig faktura og et tilbud har derimod en "Tilføj linje"-knap og
  understøtter et vilkårligt antal linjer, uanset hvor de stammer fra (også
  automatisk genererede, fx fra mange timeregistreringer).
- **Bankintegration (PSD2) og AI-scanning af bilag kræver egne, betalte
  tredjeparts-konti** (henholdsvis Enable Banking og OpenAI), som den
  enkelte installation selv skal oprette og indtaste nøgler til. Uden det
  virker disse to funktioner slet ikke, men resten af systemet er upåvirket.
- **Menu-synlighed er kun kosmetisk**, se ovenfor.
- Systemet er selv-hostet — der er ingen central cloud-tjeneste, ingen
  automatisk opdatering, og databasesikkerhed/backup er installationens
  eget ansvar (om end værktøjerne til det findes indbygget).
