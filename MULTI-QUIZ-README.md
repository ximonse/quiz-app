# Multi-Quiz System - Dokumentation

## Översikt
Multi-Quiz systemet låter dig skapa flera quiz-varianter från samma CSV/Excel-data. Perfekt för både språkglosor och begreppsövning!

## CSV-Format

### Kolumner (separerade med semikolon `;`)
1. **Begrepp/glosa** - Huvudordet/begreppet
2. **Beskrivning** - Förklaring av begreppet
3. **Exempelmening** - Mening där begreppet används
4. **Översättning** - Översättning (för språkglosor)
5. **Fråga** - Fråga där begreppet är svaret
6. **Felsvar 1** - Första felsvaret
7. **Felsvar 2** - Andra felsvaret
8. **Felsvar 3** - Tredje felsvaret
9. **Felsvar 4+** - Fler felsvar (valfritt)

### Exempel: Språkglosa
```csv
Begrepp/glosa;Beskrivning;Exempelmening;Översättning;Fråga;Felsvar1;Felsvar2;Felsvar3
Hungry;när man inte ätit på länge;I'm very hungry today;Hungrig;Vad kallas det när man inte ätit på länge;mätt;trött;glad
```

### Exempel: Begrepp
```csv
Begrepp/glosa;Beskrivning;Exempelmening;Översättning;Fråga;Felsvar1;Felsvar2;Felsvar3
Fotosyntes;När växter skapar glukos och syre med hjälp av vatten, solljus och koldioxid;Fotosyntes sker med hjälp av solljus, vatten och koldioxid;;Vad kallas det när växter skapar glukos och syre;Cellandning;Översvämning;Fotoframkallning
```

**OBS:** Tomma fält (;;) accepteras! Om något inte är aktuellt, lämna bara fältet tomt.

## Quiz-varianter

### 1. 📚 Glosquiz
- **Input:** Begrepp + Exempelmening
- **Output:** Översättning
- **Inställningar:** Antal flerval + antal skrivsvar

### 2. 🔄 Omvänd Glosquiz
- **Input:** Översättning
- **Output:** Begrepp
- **Inställningar:** Antal flerval + antal skrivsvar

### 3. 🗂️ Flashcard
- **Input:** Begrepp
- **Output:** Beskrivning
- **Typ:** Flashcards (vändkort)

### 4. 🔄 Omvänd Flashcard
- **Input:** Beskrivning
- **Output:** Begrepp
- **Typ:** Flashcards (vändkort)

### 5. ❓ Vanligt Quiz
- **Input:** Fråga
- **Output:** Begrepp (rätt svar)
- **Inställningar:** Antal flervalsfrågor

## Användning

### För Lärare

1. **Logga in** på admin-sidan
2. **Klicka på "🎯 Multi-Quiz"** i headern
3. **Fyll i formuläret:**
   - Titel (t.ex. "Prepositioner")
   - Ämne (t.ex. "Engelska")
   - Årskurs (t.ex. "åk 7")
   - Taggar (valfritt)
   - CSV-data (klistra in eller ladda upp)
4. **Välj varianter** att skapa (checkboxar)
5. **Ange antal frågor** för varje variant
6. **Klicka "✨ Skapa Multi-Quiz"**
7. **Kopiera länken** till elevvyn och dela med eleverna

### För Elever

1. **Öppna länken** från läraren
2. **Logga in med ditt elev-ID** (t.ex. "anna123")
3. **Välj en variant** att börja med
4. **Genomför övningen**
5. **Din progress sparas** automatiskt (både på servern och lokalt)
6. **Fortsätt med nästa variant** när du är klar

## Progress-tracking

### Lokal lagring (iPad/dator)
- Elevens progress sparas i webbläsarens localStorage
- Fungerar även offline efter första besöket
- Visar vilka varianter som är klara med ✓ KLAR-märke

### Server-lagring
- Progress sparas även på servern
- Läraren kan se vilka elever som gjort vilka varianter
- Synkas automatiskt när eleven är klar

## Filer

### Admin-filer
- `multi-quiz-admin.php` - Admin-gränssnitt för att skapa multi-quiz
- `admin.php` - Vanliga quiz-admin (uppdaterad med länk till multi-quiz)

### Elev-filer
- `multi-quiz-student.php` - Elevvy med länkar till alla varianter
- `multi-quiz-variant.php` - Själva quiz-spelaren (kommer skapas)

### Data-filer
- `data/multi_quizzes.json` - Alla multi-quizzes
- `data/multi_quiz_progress.json` - Elevernas progress

## Tips & Tricks

### För bästa resultat:
1. **Använd tydliga beskrivningar** - Gör det lätt för eleverna att förstå
2. **Välj trovärdiga felsvar** - Inte för lätta, inte för svåra
3. **Variera antalet frågor** - Anpassa efter elevernas nivå
4. **Kombinera varianter** - Låt eleverna träna på olika sätt
5. **Följ upp progress** - Se vilka som behöver extra hjälp

### Felsökning:
- **CSV parsas inte korrekt?** Kontrollera att du använder semikolon (;) som separator
- **Tomma fält?** Det är okej! Lämna bara fältet tomt mellan två semikolon (;;)
- **Progress sparas inte?** Kontrollera att eleven har loggat in med sitt ID
- **Variant syns inte?** Kontrollera att du kryssat i checkboxen när du skapade multi-quizet

## Framtida förbättringar

- [ ] Excel-uppladdning (inte bara CSV)
- [ ] Redigera befintliga multi-quizzes
- [ ] Detaljerad statistik per elev
- [ ] Export av elevresultat
- [ ] Tidsbaserade quiz (tidsgräns)
- [ ] Poängsystem och leaderboard

## Support

Vid problem, kontakta systemadministratören eller skapa ett issue i projektet.
