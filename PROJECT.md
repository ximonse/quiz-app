# Quiz App - Projektdokumentation

Aktuell drift och testning: [utvecklingsguiden](docs/development.md). Aktuell AI- och publiceringsmodell: [AI-guiden](docs/ai-connection.md).

## Syfte
En webbbaserad quiz-applikation för lärare och elever. Lärare skapar quiz (kunskapsquiz eller glosquiz) och elever genomför dem. Systemet samlar statistik för att hjälpa lärare identifiera svåra frågor och följa elevernas framsteg.

## Användarroller

### Lärare
- Loggar in med användarnamn och lösenord via `index.php` (konton skapas i `super-admin.php`)
- Skapar och hanterar quiz via `admin.php`
- Ser statistik för sina quiz via `stats.php`
- Hanterar klasser, grupper, elevkonton och uppdrag via `admin/classes.php`

### Elever
Två sätt att öva:

**Med elevkonto (inloggad)**
- Loggar in via `student/` med sin ordkod (tre ord) eller QR-kod, plus en fyrsiffrig PIN
- PIN visas för läraren i 15 minuter efter att den skapats; bara hashen sparas
- Återställning ger ny PIN och QR men behåller ordkoden, och loggar ut aktiva sessioner
- Sessioner gäller i åtta timmar; misslyckade inloggningar begränsas per elev och per IP
- Ser sina uppdrag och status (inte tidigare poäng eller svarshistorik)
- Övning på ett tilldelat uppdrag registreras i lärarens klassöversikt

**Utan konto (publik länk/QR)**
- Skriver in sitt namn
- Genomför aktuella quiz via `play/`; äldre länkar använder `q/index.php`
- Resultat sparas som tidigare och kopplas inte automatiskt till elevkonton

### Klasser, grupper och uppdrag
- En elev tillhör en klass och kan vara med i flera grupper
- Läraren tilldelar publicerade quiz, flashcards, kortlekar eller PDF:er till klass eller grupp
- Nya medlemmar får befintliga uppdrag för sin klass/grupp
- Borttagen elev inaktiveras; historiken finns kvar för läraren
- Detaljer: `docs/classroom.md`

## Quiz-typer

### Bildvalsquiz (image_choice)
- 2–6 bildalternativ per fråga, ett eller flera rätta svar
- Format och redigeringsflöde finns i [AI-guiden](docs/ai-connection.md)

### Kunskapsquiz (fact)
- Fråga med 4 svarsalternativ (1 rätt, 3 fel)
- Format (CSV): `Fråga;Rätt svar;Fel 1;Fel 2;Fel 3;...`
- Exempel: `Vad är huvudstaden i Sverige?;Stockholm;Göteborg;Malmö;Uppsala`

### Glosquiz (glossary)
- Mening med markerat ord som ska översättas
- Format (CSV): `Mening;Ord;Rätt översättning;Fel övers 1;Fel övers 2;Fel övers 3;Fel ord 1;Fel ord 2;Fel ord 3`
- Minst 4 kolumner krävs, kolumn 7+ används för omvända felsvar
- Exempel: `El gato duerme;gato;katt;hund;fågel;häst;perro;casa;libro`
- Stöd för stavningslägen: Easy mode (förslag), Puritan mode (eget svar), Elevval
- Språk: språkneutralt (t.ex. svenska, spanska, engelska, ukrainska)
- TTS (text-to-speech) med röstval för iPad
- Omvänd glosträning stöds: översättning → glosord, med egna omvända felsvar

### Sorteringsquiz (sort)
- Varje fråga har en huvudfråga, 1–5 valfält (rubrik, infotext och/eller bild) och 2–12 objekt (text och/eller bild)
- Eleven drar objekten till rätt fält; objekt med `field: null` (distraktorer) ska ligga kvar utanför
- Rättas per objekt. Första gången en fråga visas blinkar fel objekt rött och glider sedan till rätt plats (grönt); vid repetition ligger de kvar där eleven lade dem
- Repetition: frågan kommer tillbaka med bara de objekt som behöver övas (`required_correct` gäller per objekt)
- Objekt kan aldrig överlappa: de ligger alltid i radbrytande behållare
- Uppläsning: eleven håller fingret stilla 1 s på en text; eleven kan stänga av ljudet i headern
- Flashcards: ett kort per objekt som hör till ett fält, med ledtråden (`flashcard_prompt`) på framsidan
- Skapas i `admin/sort-edit.php`, via MCP, eller med prompt/JSON-mall (`assets/templates/sorteringsquiz-mall.json`)
- Filer: `engine/sort-engine.js` (regler), `engine/drag-board.js` (dra och släpp, delas med kommande ordningsquiz), `engine/sort-player.js`, `play/sort-quiz.php`, `ai/sort-validation.php`
- Statistik visar vilka objekt som oftast hamnar fel och var

### Ordningsquiz (order)
- Varje fråga har en huvudfråga, en vågrät (vänster → höger) eller lodrät (uppifrån och ner) linje med valfri text i början, mitten och slutet, och 2–8 objekt som skrivs i rätt ordning
- Eleven drar objekten till linjen; bara ordningen räknas, inte avstånden
- Hela ordningen rättas, men bara objekt som bryter ordningen markeras röda (de utanför den längsta korrekt ordnade följden)
- Första gången frågan visas glider objekten till rätt ordning; vid repetition ligger de kvar. Fel ger att hela frågan kommer tillbaka
- Inga flashcards. Skapas i `admin/order-edit.php`, via MCP eller med prompt/JSON-mall (`assets/templates/ordningsquiz-mall.json`)
- Filer: `engine/order-engine.js`, `engine/order-player.js`, `play/order-quiz.php`, `ai/order-validation.php`. Redigerarna för sortering och ordning delar `includes/drag-quiz-editor.php` och `engine/editor-kit.js`

## Huvudfunktioner

### Admin-gränssnitt (admin.php)
**Quiz-typväljare**
- Tydlig knappväljare vid toppen: Kunskapsquiz 📝 eller Glosquiz 📚
- Alla formulär uppdateras dynamiskt baserat på valt quiz-typ

**Tagging-system**
- Ämne (Subject): t.ex. Matematik, Svenska, Engelska
- Årskurs (Grade): t.ex. År 1, År 2, Högstadiet
- Anpassade taggar: fritext, kommaseparerade

**Filtreringssystem**
- Filtrera på quiz-typ (Kunskap/Glosor)
- Filtrera på ämne
- Filtrera på årskurs
- Fritextsökning på titel

**Import-metoder**
1. **Manuell inmatning**: Lägg till frågor en i taget
2. **CSV-inmatning**: Klistra in CSV-formaterad text
3. **Batch-import**: Ladda upp CSV-fil med flera quiz samtidigt
   - Struktur: Titel → Frågor → Tom rad → Nästa titel → Frågor...
   - Separata knappar för Kunskapsquiz och Glosquiz
4. **AI-hjälp**: Generera quiz med AI (olika prompter för kunskap vs glosor)

### Quiz-gränssnitt (`play/`, äldre format i `q/`)
**För Kunskapsquiz**
- Visa fråga
- 4 svarsalternativ i slumpmässig ordning
- Feedback vid fel/rätt svar
- Räknare för fel

**För Glosquiz**
- Visa mening med markerat ord
- Röst-uppläsning (TTS)
  - Automatisk uppläsning
  - Håll stilla 1 s på en text för uppläsning (gäller alla elevspelare, `engine/hold-speak.js`)
  - Röstval för iPad/iPhone (Paulina som standard för spanska)
  - Hjälptext för nedladdning av bättre röster
- Stavningslägen:
  - Easy mode: 4 svarsalternativ
  - Puritan mode: Skriv in själv
  - Elevval: Eleven väljer läge innan quiz

**Gemensamt**
- Timer
- Elevnamn registreras
- Statistik sparas automatiskt

### Statistik (stats.php)
**Flik 1: Översikt**
- Totalt försök
- Antal klarade
- Genomförandeprocent
- Genomsnittstid
- Genomsnittligt antal fel
- Stapeldiagram: Resultatfördelning (visar hur många som fick 0 fel, 1 fel, 2 fel etc.)
- Svåraste frågorna (top 5)
- Quiz-länk för delning

**Flik 2: Per elev**
- Lista över alla elever som gjort quizet
- För varje elev:
  - Antal försök
  - Antal genomförda
  - Bästa resultat (minst fel)
  - Genomsnittlig tid
  - Genomsnittliga fel
- Expanderbar detaljvy: Visa alla försök för eleven

**Flik 3: Alla försök**
- Tabell med alla försök
- Filtrering:
  - Per elev (dropdown)
  - Status (Alla/Genomförda/Ej genomförda)
- Sortering:
  - Datum (nyast först/äldst först)
  - Fel (minst först/flest först)
  - Tid (snabbast/långsammast)

## Filstruktur

### Huvudfiler
- `index.php` - Lärar-inloggning
- `admin.php` - Quiz-hantering för lärare
- `stats.php` - Statistikvy
- `q/index.php` - Quiz-genomförande för elever
- `admin/classes.php` - Klasser, grupper, elevkonton och uppdrag
- `student/` - Elevinloggning och elevens uppdragsvy
- `includes/classroom.php` - Inloggning, sessioner och aktivitet för elevkonton
- `config.php` - Konfiguration, sessionshantering, hjälpfunktioner

### API-endpoints
- `api/save-stats.php` - Spara quiz-resultat
- `api/batch-import.php` - Batch-import av quiz
  - Action: `batch_import_fact` - Importera kunskapsquiz
  - Action: `batch_import_gloss` - Importera glosquiz

### Datafiler (JSON)
- `data/teachers.json` - Lärarkonton
- `data/quizzes.json` - Alla quiz
- `data/stats.json` - Statistik per quiz
- `data/classroom.json` - Klasser, grupper, elevkonton, uppdrag och övningsaktivitet
- `data/repetition.json` - Spaced-repetition-kort (FSRS) per elev och fråga, se nedan
- `data/materials/` - Uppladdade PDF:er (ska skyddas med `.htaccess`)

## Datastruktur

Exemplen nedan visar äldre quiz och statistik. Aktuella quiz använder `items` och `settings`; fullständiga format för alla fem quiztyper finns i [AI-guiden](docs/ai-connection.md) och valideras i `ai/validation.php`.

### Quiz-objekt
```php
[
    'id' => 'unique_id',
    'title' => 'Quiz-titel',
    'type' => 'fact' eller 'glossary', // Detta exempel visar det äldre questions-formatet.
    'language' => 'sv' eller 'es',
    'spelling_mode' => 'easy', 'puritan' eller 'student_choice',
    'subject' => 'Matematik',
    'grade' => 'År 6',
    'tags' => 'algebra, grunder',
    'teacher_id' => 'teacher_id',
    'teacher_name' => 'Lärarens namn',
    'created' => '2025-10-05 12:00:00',
    'questions' => [...]
]
```

### Statistik-objekt
```php
[
    'quiz_id' => [
        'total_attempts' => 10,
        'completed' => 8,
        'avg_time_seconds' => 120,
        'avg_errors' => 2.5,
        'attempts' => [
            [
                'student_name' => 'Anna',
                'timestamp' => '2025-10-05 14:30:00',
                'completed' => true,
                'time_seconds' => 115,
                'errors' => 2,
                'question_errors' => [0, 1, 0, 1, 0] // Fel per fråga
            ]
        ],
        'question_errors' => [5, 12, 3, 8, 2], // Totalt fel per fråga
        'misspellings' => [], // För glosquiz
        'wrong_answers' => [] // För kunskapsquiz
    ]
]
```

## Teknisk stack
- PHP (backend, sessions, JSON-filhantering)
- React 18 och vanlig JavaScript (frontend-logik)
- TailwindCSS 3, statiskt byggd CSS (styling)
- Exakt låsta lokala bibliotek i `assets/vendor/`; se `package.json`
- Web Speech API (text-to-speech för glosquiz)

## Specialfunktioner

### iPad/iOS-anpassningar
- TTS-röstval (Paulina som standard för spanska)
- Text-markering aktiverad i quiz-innehåll
- Hjälpinstruktioner för nedladdning av bättre röster

### Batch-import format
**Kunskapsquiz CSV**:
```
Titel på quiz 1
Fråga 1;Rätt svar;Fel 1;Fel 2;Fel 3
Fråga 2;Rätt svar;Fel 1;Fel 2;Fel 3

Titel på quiz 2
Fråga 1;Rätt svar;Fel 1;Fel 2;Fel 3
```

**Glosquiz CSV**:
```
Titel på quiz 1
Mening 1;Ord;Rätt översättning;Fel övers 1;Fel övers 2;Fel övers 3;Fel ord 1;Fel ord 2;Fel ord 3
Mening 2;Ord;Rätt översättning;Fel övers 1;Fel övers 2;Fel övers 3;Fel ord 1;Fel ord 2;Fel ord 3

Titel på quiz 2
Mening 1;Ord;Rätt översättning;Fel övers 1;Fel övers 2;Fel övers 3;Fel ord 1;Fel ord 2;Fel ord 3
```

## Tester

Samlad kontroll: `npm test` i projektroten. Se [utvecklingsguiden](docs/development.md) för byggsteg och verktygskrav.

All testdata är syntetisk och körs i engångskopior av appen; riktiga konton och quiz rörs aldrig.

- Enhetstester: `node --test tests/*.test.js engine/*.test.js` och `php tests/ai-service.test.php` och `php tests/repetition.test.php`
- Klassrum via HTTP: `python tests/classroom-http.test.py`, med `--robots` för 32 robotelever samtidigt i alla quiztyper
- Webbläsare (kräver PHP, Python och Node): `cd tests/browser && npm ci --ignore-scripts && npx playwright install chromium && npm test`
  - spelar sorterings- och ordningsquiz, sorteringsredigeraren och håll-för-uppläsning med mus och touch
  - `pupil-robots.js`: fyra elever (dator, iPad, iPhone) loggar in och spelar alla uppdrag till slut
  - `npm run themes` tar skärmdumpar av alla teman; bilderna hamnar i `tests/browser/screenshots/`

## Utvecklingshistorik

### Tidigare uppdateringar (2026-02-11)
1. **Omvänd glosträning**: Glosquiz kör nu både framåt och bakåt med separata inställningar för svarsläge och antal korrekta svar per fas.
2. **Glosformat utökat**: CSV/Excel/AI-prompt stödjer omvända felsvar (`Fel ord 1..3`) för riktning ord ↔ översättning.
3. **Semikolon standardiserat**: Import använder enbart `;` som separator, utan fallback till `,`.
4. **Importflöden synkade**: CSV-fil, klistra-in CSV, manuell inmatning och batch-import stödjer samma kärninställningar.
5. **Metadata konsekvent**: Ämne, årskurs och taggar finns nu även för klistra-in CSV och batch-import.
6. **Statistikkompatibilitet**: Felstavningar i glosquiz hanteras för både nytt och äldre format vid sparning.

## Säkerhet
- Lärar-autentisering via PHP-sessioner
- `requireTeacher()` skyddar admin-endpoints
- Publika quizlänkar kräver ingen autentisering (öppet för klassrum)
- Elevkonton: ordkod/QR + hashad PIN, sessioner på åtta timmar, begränsning av misslyckade inloggningar
- Lärare ser endast sina egna quiz
