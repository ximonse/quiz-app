# Förslag: lärarvyerna som ett sammanhängande arbetsflöde

Status: **förslag, inget är byggt.** Skiss med syntetiska data:
[lararvyer-skiss.html](lararvyer-skiss.html) — öppna lokalt i webbläsaren.

Utgångspunkt från 2026-11-25: inloggade elever är förstahandsfallet, oinloggade
spelare andrahandsfallet. Fri utdelning ska finnas kvar. Klasser, elevers
personliga resultat och repetitionen ska lyftas fram.

## 1. Fynd

Allt under "Fakta" är läst i koden, inte antaget.

### Fakta: två visuella världar

| | Mina quiz, statistik, editorer | Klasser och uppgifter, klass, elev, kort |
|---|---|---|
| Stilmall | `assets/vendor/tailwind-3.4.19.css` + `engine/themes.css` | `assets/classroom.css` |
| Bakgrund | temavariabler, gradient via `--bg-from/--bg-to` | fast gradient `#eff6ff → #faf5ff` |
| Moduler | `rounded-xl shadow-lg p-6` | `.panel`, `border-radius:9px` |
| Handlingar | emoji-ikonknappar (📋 📱 ✏️ 📊 🗑️) | textlänkar och `<button>` |
| Navigering | tre knappar i headern | textnav med fyra länkar |

Ingen sida delar header med någon annan. Länkarna skiljer sig åt i urval och
ordning, och ingen vy visar var man är.

### Fakta: arbetsflödet korsar gränsen tre gånger

Lärarens faktiska gång är: skapa innehåll → ge det till en klass → följa upp per
elev → låta repetitionen bära det som sitter löst. I dag:

1. Skapa quiz i `dashboard.php` (Tailwind).
2. Dela ut i `classes.php`, i en `<select>` som listar *alla* quiz, flashcards och
   PDF om vartannat (classroom.css). Det nyss skapade quizet måste letas upp igen.
3. Följa upp i `class.php` / `pupil.php` (classroom.css).
4. Se svåraste frågorna i `stats.php` (Tailwind), utan koppling till klassen.

Quizraden i Mina quiz har sex handlingar — kopiera länk, QR, Granska, Redigera,
Statistik, Radera — men **ingen** "Dela ut till klass". De två första riktar sig
till oinloggade spelare, alltså det som nu är andrahandsfallet.

### Fakta: begrepp som glider

- **Elev** betyder inloggat elevkonto i klassvyerna. Knappen "👥 Elevnamn" i Mina
  quiz leder till `browser-ids.php`, som handlar om oinloggade spelare per
  webbläsare. Två olika sorters människor med nästan samma ord.
- **Uppgift** betyder utdelning i klassvyerna ("Klasser och uppgifter") och
  samtidigt en fråga i ett sorteringsquiz ("6 uppgifter").
- Tre olika sorters publicering heter tre olika saker utan att det sägs:
  *Granska och publicera* (gör spelbart), *Dela ut* (ger till klass), *Kopiera
  elevlänk* (ger till vem som helst).
- Siffran "31 resultat" på quizraden blandar inloggade elever och oinloggade
  spelare i samma tal.

### Fakta: repetitionen är osynlig för läraren

- Den sätts på med en kryssruta i editorn: "Lägg till i elevernas repetition".
- Den fungerar bara om fyra villkor är uppfyllda samtidigt: quizet är av typ
  `fact` eller `glossary`, det är publicerat, det är utdelat till klass eller
  grupp, och eleven är inloggad när hon svarar (`classroomRecord()` i
  `includes/classroom.php`).
- Eleven ser "Lite repetition idag" i `student/index.php`.
- **Läraren ser ingenting.** Inte hur många kort som är förfallna, inte vem som
  har en växande skuld, inte om kryssrutan faktiskt gör något i det här quizet.
- Datan finns: `includes/repetition.php` håller ett kort per elev + quiz + fråga
  med `due`, `stability` och `difficulty`.

### Vad som behålls

Panelerna i Klasser (hopfällbara, lägesminne per webbläsare), hela
elevinloggningen med ordkod och PIN, kortutskrifterna, spelarna, FSRS-motorn,
granska-och-publicera-flödet och den fria utdelningen. Inget av detta behöver
byggas om.

## 2. Förslag: ett skal, tre arbetsytor

En gemensam header på alla lärarsidor, med tre flikar som följer arbetsgången:

- **Innehåll** — biblioteket. Dagens Mina quiz, minus skapandeformuläret.
- **Klasser** — människor och utdelningar. Dagens `classes.php` med sina paneler.
- **Uppföljning** — ny, tunn vy. Per klass eller grupp: vem är efter, vem har
  repetition att göra, vad är svårast just nu, och en utfällbar lista med
  elevens felsvar.

Sidorna under flikarna (klass, elev, statistik, editorer) behåller sitt innehåll
men får samma header, så man alltid ser var man är.

### Begreppstabellen som ska gälla överallt

| Ord | Betyder | Får inte betyda |
|---|---|---|
| Elev | inloggat elevkonto | oinloggad spelare |
| Oinloggad spelare | någon som spelat via länk eller QR, känd per webbläsare | elev |
| Fråga | ett item i ett quiz | utdelning |
| Utdelning | ett quiz, flashcards eller en PDF given till en klass eller grupp | fråga |
| Publicera | göra spelbart | dela ut |
| Dela ut | ge till en klass eller grupp | publicera, dela fritt |
| Dela fritt | länk eller QR till vem som helst | dela ut |

Konkret: "👥 Elevnamn" byter namn till "Oinloggade spelare". Rubriken "Klasser och
uppgifter" blir "Klasser". Elevsvar och oinloggade svar räknas som två tal, inte
ett.

### Fem konkreta ändringar, i den ordning jag skulle göra dem

1. **Gemensam header.** Samma markup och CSS på alla lärarsidor, med flikarna och
   aktuell sida markerad. Liten insats, tar bort det mesta av känslan av två
   appar.
2. **"Dela ut" direkt på quizraden.** Välj klass eller grupp i raden, utan att
   lämna biblioteket. Dropdownen i Klasser kan vara kvar för den som står där.
   Detta gör den inloggade vägen till standardvägen.
3. **Skapandet till egen sida.** "Skapa quiz" blir en knapp som leder till
   formuläret, med de fyra quiztyperna som val där. Mina quiz blir ett bibliotek
   man kan överblicka i stället för en sida man scrollar förbi.
4. **Repetitionen synlig.** En chip på quizraden som säger vad som gäller
   (`Repetition` / `Repetition: inte utdelad` / inget för typer som inte stöds),
   och en kolumn "Repetition att göra" per elev i Uppföljning. Ingen ny
   datainsamling behövs.
5. **Fri utdelning till andrahandsplats.** Kopiera länk och QR flyttar in i en
   "⋯"-meny på raden. De försvinner inte, men tar inte längre plats före det som
   är förstahandsfallet.

### Val av stilmall — den avgörande frågan

Två världar kan inte bli en utan att en av dem ger vika. Min rekommendation:
**låt `classroom.css` vara riktningen** och flytta lärarvyerna dit sida för sida.
Skäl: ingen byggkedja, kompakt geometri redan på plats, och det är där
arbetsvyerna finns. Tailwind-filen är vendorad statiskt och kan ligga kvar för
editorerna och elevspelarna tills de flyttas.

Detta är en flerstegsmigrering, inte en omskrivning. Steg 1 (gemensam header) är
det enda som behöver röra båda världarna; de övriga stegen kan göras en vy i
taget, och varje steg är fullt användbart även om nästa aldrig görs.

## 3. Plan: statistik per klass och grupp

Det här var "punkt 3" i den tidigare listan.

**Problemet:** ett sparat resultat i `quizzes.json` innehåller `student_id`, men
inte vilken klass eleven tillhörde när hon spelade. Att filtrera på elevens
*nuvarande* `class_id` ger fel svar för elever som bytt klass eller tagits bort.

**Två vägar:**

- **A, filtrera på utdelningen.** `stats.php?id=X&assignment=Y` räknar bara
  resultat från elever som är eller har varit mottagare av just den utdelningen
  (`classroomAssignmentPupils()` ger listan). Kräver ingen ny lagrad data, och
  rubriken kan säga exakt vad den räknar: "eleverna som fick den här
  utdelningen".
- **B, spara klassen på resultatet.** Lägg `class_id` på resultatet i
  `api/save-result.php` vid sparandet. Ger korrekt historisk filtrering, men
  bara för resultat som sparas från och med då.

**Rekommendation:** gör A nu, och lägg till B:s fält i samma ändring så datan
hinner samlas inför en senare äkta klassfiltrering.

**Genomförande:**

1. `stats.php` tar emot `assignment`-parametern, validerar att utdelningen är
   lärarens, och filtrerar `$results` på mottagarlistans `student_id`.
2. Rubrikraden säger vilken grupp som räknas och hur många av dem som har
   resultat: "6A · 18 av 24 elever har resultat".
3. Resultat utan känt `student_id` räknas separat som "oinloggade spelare" och
   ingår inte i klassens snitt.
4. `save-result.php` sparar `class_id` från elevens konto när eleven är inloggad.
5. Länken från Utdelat och klassvyn pekar på den filtrerade varianten; länken
   från Mina quiz fortsätter visa allt.

**Klart när:** från en utdelning kan jag öppna statistik som bara räknar den
utdelningens elever; snittet och svåraste frågorna ändras när jag byter mellan
filtrerad och ofiltrerad vy; en elev som bytt klass dyker inte upp i fel klass;
gamla resultat fungerar som förut.

## 4. Första hela flödet att bygga, med klart-när-villkor

Om du vill ha ett avgränsat första steg ur det här, skulle jag ta **steg 1 och 2**
(gemensam header + Dela ut på raden) som ett paket, eftersom de tillsammans gör
hela kedjan gångbar utan att röra någon databas:

**Flödet:** Jag skapar ett glosquiz → granskar och publicerar → delar ut det till
6A direkt från raden i Innehåll → öppnar Uppföljning för 6A och ser att alla
24 elever står som "0 av 1" → nästa dag ser jag vilka som är klara, vilka som är
efter, och fäller ut en elevs felsvar.

**Klart när:**

- Samma header, samma länkar och samma ordning på alla lärarsidor, med aktuell
  sida markerad.
- Jag kan dela ut ett publicerat quiz till en klass eller grupp utan att lämna
  Innehåll, och utdelningen syns direkt i Klasser.
- Ett opublicerat quiz kan inte delas ut, och raden säger varför.
- Ingen befintlig vy tappar en funktion, och de hopfällbara panelernas lägesminne
  fungerar som nu.
- Orden i tabellen ovan används konsekvent i alla ändrade vyer.

## 5. Vad som är vad

- **Fakta:** avsnitt 1, läst i koden i denna repo.
- **Förslag:** avsnitt 2–4. Inget av det är beslutat, och inget är byggt.
- **Levererat separat, före detta dokument:** felsvar per elev på elevsidan, och
  länk från utdelning till quizstatistiken.
