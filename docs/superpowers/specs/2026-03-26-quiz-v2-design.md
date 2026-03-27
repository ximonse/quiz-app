# Quiz-app v2 — Design Spec

## Bakgrund

Nuvarande multiquiz har en komplex CSV-prompt som AI:er har svårt att generera korrekt. Koden är inkonsekvent med duplicerad logik, monolitiska filer (2000+ rader), och återkommande buggar i settings-validering och faslogik.

**Beslut:** Bygg ny quiz-motor och spelarvyer. Behåll det som fungerar (config, auth, CSRF). Ersätt multiquiz med två tydliga quiztyper.

## Quiztyper

### Glosquiz

Tränar ordförråd med exempelmeningar.

**CSV-format:** `mening;ord;översättning;fel1;fel2;fel3;omvänt_fel1;omvänt_fel2;omvänt_fel3`

**Vanlig riktning:** Mening visas, ord markeras. Eleven svarar med översättning.
- Svarsläge: flerval, skrivsvar, eller hybrid (flerval först, sedan skrivsvar)

**Omvänd riktning:** Översättning visas. Eleven svarar med ordet.
- Svarsläge: flerval, skrivsvar, eller hybrid

**TTS:** På som standard (kan stängas av av läraren).

### Faktaquiz

Tränar begrepp och definitioner.

**CSV-format:** `begrepp;beskrivning;fel1;fel2;fel3`

**Vanlig riktning:** Begreppet visas. Flerval för beskrivningen (aldrig skrivsvar — beskrivningar är för långa att skriva).

**Omvänd riktning:** Beskrivningen visas. Eleven svarar med begreppet.
- Svarsläge: flerval, skrivsvar, eller hybrid

**TTS:** Av som standard (kan slås på av läraren).

### Flashcards

Genereras automatiskt från samma data som glosquiz/faktaquiz. Framsida/baksida-vy. Ingen poängsättning.

### Testläge

Fungerar för båda typer. En genomgång utan repetition. Varje fråga visas en gång. Svarsläge konfigurerbart (flerval, skrivsvar, eller hybrid). Ingen omvänd riktning erbjuds.

## Riktningsbyte

När eleven klarar vanlig riktning erbjuds omvänd automatiskt. Eleven kan inte hoppa över — omvänd startar direkt. Gäller båda quiztyper i träningsläge. Gäller inte i testläge.

## Datamodell

### Quiz-objekt

```json
{
  "id": "q_abc123",
  "title": "Spanska vecka 12",
  "type": "glossary",
  "created": "2026-03-26 10:00",
  "teacher_id": "teacher_xxx",
  "settings": {
    "answer_mode": "hybrid",
    "mc_count": 10,
    "text_count": 5,
    "reverse_enabled": true,
    "reverse_answer_mode": "hybrid",
    "reverse_mc_count": 10,
    "reverse_text_count": 5,
    "quiz_mode": "training",
    "tts_enabled": true,
    "language": "es",
    "spelling_mode": "student_choice",
    "time_lock": {
      "opens": "2026-03-27 08:00",
      "closes": "2026-03-28 17:00"
    },
    "generate_flashcards": true
  },
  "items": [],
  "results": []
}
```

### Items per typ

**Glosquiz:**
```json
{
  "sentence": "Hola, me llamo Roberto",
  "word": "llamo",
  "translation": "heter",
  "wrong_options": ["bor", "läser", "springer"],
  "reverse_wrong_options": ["vive", "toma", "mira"]
}
```

**Faktaquiz:**
```json
{
  "concept": "Fotosyntes",
  "description": "Process där växter omvandlar solljus till energi",
  "wrong_options": [
    "Process där djur bryter ner föda",
    "Transport av vatten i rötter",
    "Celldelning i blad"
  ]
}
```

### Resultat (rensningsbart)

```json
{
  "student_name": "Anna",
  "timestamp": "2026-03-27 09:15",
  "score": 18,
  "total": 20,
  "errors": [
    { "item_index": 3, "given": "correr", "correct": "comer" }
  ]
}
```

## Studentflöde

1. Eleven öppnar quiz-länk, skriver sitt förnamn (ingen autentisering)
2. Dashboard visar aktiverade aktiviteter i sektioner: Träna (flashcards), Öva (quiz), Testa (testläge)
3. Under quizet: rätt/fel-räknare löpande, rätt svar visas direkt vid fel
4. Träningsläge: fel frågor repeteras tills rätt
5. Vanlig riktning klar → omvänd startar automatiskt (ej valbart)
6. Slutsammanfattning: alla fel med rätt svar, totalpoäng
7. Diplom: "Flawless" vid 0 fel, annars vanligt diplom med poäng

## Tidsspärr

Läraren sätter öppnings- och stängningstid per quiz.

- Före öppning: "Quizet öppnar [datum]"
- Under fönstret: Quizet fungerar normalt, resultat sparas
- Efter stängning: Quizet fungerar i träningsläge, resultat sparas INTE

## Admin-vy

### Quiz-lista (kompakt)

Varje quiz = en rad. Knappar horisontellt till höger: kopiera länk, redigera, statistik, radera.

```
┌──────────────────────────────────────────────────────┐
│ Spanska vecka 12          glosquiz  │ 📋 ✏️ 📊 🗑️  │
│ 15 glosor · Öppen 27/3–28/3                          │
├──────────────────────────────────────────────────────┤
│ NO Kapitel 4              faktaquiz │ 📋 ✏️ 📊 🗑️  │
│ 20 frågor · Alltid öppen                             │
└──────────────────────────────────────────────────────┘
```

### Skapa quiz

Välj typ (glosquiz/faktaquiz) → typ-specifikt formulär. Import via CSV-paste eller AI-generering (en enkel prompt per typ).

### Inställningar per quiz

- Svarsläge (flerval / skrivsvar / hybrid)
- Hybrid-fördelning: läraren väljer antal flervalsfrågor vs skrivfrågor (t.ex. 10 flerval + 5 skrivsvar)
- Omvänd riktning (på/av) + omvänt svarsläge + omvänd hybrid-fördelning
- Quizläge (träning / test)
- TTS (på/av)
- Språk
- Stavningsläge (easy / puritan / student_choice)
- Tidsspärr (öppnar/stänger)
- Generera flashcards (på/av)

### Statistik per quiz

- Antal genomförda försök
- Snitträtt per fråga (vilka är svårast)
- Lista senaste resultat (namn + poäng)
- Knapp: "Rensa alla resultat" (för återanvändning)

## Filstruktur

```
quiz-app/
├── admin/
│   ├── dashboard.php        # Quiz-lista (kompakt)
│   ├── create.php           # Skapa quiz (typ-val → formulär)
│   ├── edit.php             # Redigera quiz
│   └── stats.php            # Statistik + rensa resultat
├── play/
│   ├── index.php            # Studentens dashboard
│   ├── quiz.php             # Quiz-spelare (glos + fakta)
│   └── flashcards.php       # Flashcard-spelare
├── engine/
│   ├── quiz-engine.js       # Köhantering, faser, riktningsbyte
│   ├── validator.js         # Svarsvalidering + Levenshtein
│   └── tts.js               # Text-to-speech
├── api/
│   ├── save-result.php      # Spara resultat (alla typer)
│   └── quiz-data.php        # Hämta quiz + tidsspärrkontroll
├── config.php               # Befintlig (auth, CSRF, helpers)
└── data/
    └── quizzes.json          # All quiz-data
```

## Vad som behålls från nuvarande kodbas

- `config.php` — auth, CSRF, helpers
- Tema-system (light, dark, night-magenta, psychedelic)
- Levenshtein-algoritm och stavningsvalidering (från q/index.php)
- TTS-logik med språkstöd (från q/index.php)
- Teacher-hantering och login

## Vad som tas bort / ersätts

- `multi-quiz-admin.php`, `multi-quiz-student.php`, `multi-quiz-variant.php` — ersätts av ny admin/ och play/
- `q/index.php` (1516 rader) — ersätts av play/quiz.php + engine/
- `admin.php` (2121 rader) — ersätts av admin/dashboard.php + admin/create.php + admin/edit.php
- Separata stats-endpoints → en gemensam api/save-result.php

## Svarsvalidering

**Flerval:** Case-insensitive exakt match.

**Skrivsvar:**
- Easy-läge: Levenshtein-distans ≤ 20% av ordlängden tolereras
- Puritan-läge: Exakt match krävs
- Student choice: Eleven väljer själv easy/puritan under quizet

## Diplom

Vid avklarat quiz visas ett diplom:
- **Flawless:** 0 fel genom hela quizet (speciell design)
- **Vanligt diplom:** Visar poäng (t.ex. "18/20 rätt")
