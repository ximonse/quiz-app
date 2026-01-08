# Quiz App - Projektdokumentation

## Syfte
En webbbaserad quiz-applikation för lärare och elever. Lärare skapar quiz (kunskapsquiz eller glosquiz) och elever genomför dem. Systemet samlar statistik för att hjälpa lärare identifiera svåra frågor och följa elevernas framsteg.

## Användarroller

### Lärare
- Loggar in via `login.php`
- Skapar och hanterar quiz via `admin.php`
- Ser statistik för sina quiz via `stats.php`

### Elever
- Behöver ingen inloggning
- Skriver in sitt namn
- Genomför quiz via `q/index.php`

## Quiz-typer

### Kunskapsquiz (fact)
- Fråga med 4 svarsalternativ (1 rätt, 3 fel)
- Format: Fråga, Rätt svar, Fel 1, Fel 2, Fel 3
- Exempel: "Vad är huvudstaden i Sverige?" → "Stockholm", "Göteborg", "Malmö", "Uppsala"

### Glosquiz (glossary)
- Mening med markerat ord som ska översättas
- Format: Mening, Ord, Rätt översättning, Fel 1, Fel 2, Fel 3
- 6 kolumner totalt
- Exempel: "The cat is sleeping", "cat", "katt", "hund", "mus", "fågel"
- Stöd för stavningslägen: Easy mode (förslag), Puritan mode (eget svar), Elevval
- Språk: Svenska eller Spanska
- TTS (text-to-speech) med röstval för iPad

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

### Quiz-gränssnitt (q/index.php)
**För Kunskapsquiz**
- Visa fråga
- 4 svarsalternativ i slumpmässig ordning
- Feedback vid fel/rätt svar
- Räknare för fel

**För Glosquiz**
- Visa mening med markerat ord
- Röst-uppläsning (TTS)
  - Automatisk uppläsning
  - Manuell uppspelningsknapp
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
- `login.php` - Lärar-inloggning
- `admin.php` - Quiz-hantering för lärare
- `stats.php` - Statistikvy
- `q/index.php` - Quiz-genomförande för elever
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

## Datastruktur

### Quiz-objekt
```php
[
    'id' => 'unique_id',
    'title' => 'Quiz-titel',
    'type' => 'fact' eller 'glossary',
    'language' => 'sv' eller 'es',
    'spelling_mode' => 'easy', 'puritan' eller 'student_choice',
    'subject' => 'Matematik',
    'grade' => 'År 6',
    'tags' => ['algebra', 'grunder'],
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
- Vanilla JavaScript (frontend-logik)
- TailwindCSS (styling)
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
Fråga 1,Rätt svar,Fel 1,Fel 2,Fel 3
Fråga 2,Rätt svar,Fel 1,Fel 2,Fel 3

Titel på quiz 2
Fråga 1,Rätt svar,Fel 1,Fel 2,Fel 3
```

**Glosquiz CSV**:
```
Titel på quiz 1
Mening 1,Ord,Rätt översättning,Fel 1,Fel 2,Fel 3
Mening 2,Ord,Rätt översättning,Fel 1,Fel 2,Fel 3

Titel på quiz 2
Mening 1,Ord,Rätt översättning,Fel 1,Fel 2,Fel 3
```

## Utvecklingshistorik

### Senaste uppdateringar
1. **Statistiksystem**: Omarbetning med flik-baserad vy, per-elev statistik, filtrering och visualisering
2. **Tagging-system**: Ämne, årskurs och anpassade taggar för bättre organisation
3. **Batch-import**: Möjlighet att ladda upp flera quiz samtidigt
4. **AI-integration**: Dynamiska AI-prompter baserat på quiz-typ
5. **iPad-förbättringar**: Röstval och textmarkering
6. **UI-förbättring**: Tydlig quiz-typväljare och visuell feedback

## Säkerhet
- Lärar-autentisering via PHP-sessioner
- `requireTeacher()` skyddar admin-endpoints
- Elever behöver ingen autentisering (öppet för klassrum)
- Lärare ser endast sina egna quiz
