# 📚 Quiz App - Komplett dokumentation

AI-koppling för att hitta, skapa och redigera quiz samt lägga till frågebilder: [anslutningsguide](docs/ai-connection.md).

En interaktiv quiz-app för lärare och elever med spaced repetition och statistikspårning.

## 🎯 Funktioner

### För Super Admin (dig):
- ✅ Skapa och hantera lärarkonton
- ✅ Se alla lärares quizzes
- ✅ Översikt över all statistik
- ✅ Aktivera/inaktivera lärare
- ✅ Återställ lösenord

### För Lärare:
- ✅ Skapa quizzes via CSV-upload eller manuell input
- ✅ Få unik länk för varje quiz
- ✅ Se detaljerad statistik för egna quizzes
- ✅ Radera egna quizzes
- ✅ Endast se sina egna quizzes och statistik

### För Elever:
- ✅ Ingen inloggning behövs
- ✅ Ange namn (endast för diplom)
- ✅ Två-fas quiz system (flerval → fritext)
- ✅ Automatisk repetition av fel svar
- ✅ Talsyntes (lyssna på frågor)
- ✅ Förbättrad stavfelstolerans
- ✅ PDF-diplom vid klarat quiz
- ✅ Fungerar perfekt på iPad/mobil

## 📁 Filstruktur

```
quiz-app/
├── config.php              # Konfiguration & hjälpfunktioner
├── index.php               # Inloggningssida
├── super-admin.php         # Super admin panel
├── admin.php               # Lärarpanel
├── stats.php               # Detaljerad statistik
├── exempel_fragor.csv      # Exempel på CSV-format
├── .htaccess               # Skyddar data-mappen
├── api/
│   └── save-stats.php      # Sparar statistik från quiz
├── data/
│   ├── teachers.json       # Lärarkonton
│   ├── quizzes.json        # Alla quizzes
│   ├── stats.json          # Statistik
│   └── .htaccess           # Blockerar direkt åtkomst
├── q/
│   ├── index.php           # Quiz-rendering
│   └── .htaccess           # Routing för quiz-sidor
└── assets/
    ├── css/
    └── js/
```

## 🚀 Installation

### 1. Ladda upp filer
Ladda upp hela `quiz-app/` mappen till din webbserver.

**Exempel:**
```
yoursite.com/
├── wordpress/          (din befintliga WP-site)
└── quiz-app/          (ny quiz-app)
```

### 2. Konfigurera super admin lösenord
Redigera `config.php` rad 8:
```php
define('SUPER_ADMIN_PASSWORD', 'DittSuperSäkraLösenord123!');
```

### 3. Sätt behörigheter
```bash
chmod 755 quiz-app/
chmod 777 quiz-app/data/
chmod 666 quiz-app/data/*.json
```

### 4. Testa installationen
Besök: `yoursite.com/quiz-app/`

Logga in som super admin:
- Användarnamn: `superadmin`
- Lösenord: (det du satte i config.php)

## 👥 Användning

### Super Admin

1. **Logga in** med `superadmin` / ditt lösenord
2. **Skapa lärarkonto:**
   - Användarnamn: `anna.andersson`
   - Namn: `Anna Andersson`
   - Lösenord: `ValfriLösenord123`
3. **Hantera lärare:**
   - Aktivera/inaktivera konton
   - Återställ lösenord
   - Radera konton
   - Se antal quizzes per lärare

### Lärare

1. **Logga in** med ditt användarnamn/lösenord
2. **Skapa quiz:**

   **Alternativ A: CSV-uppladdning**
   - Fyll i quiz-titel (t.ex. "Arters anpassningar")
   - Ladda upp CSV-fil i formatet:
     ```csv
     Fråga;Rätt svar;Fel svar 1;Fel svar 2;Fel svar 3
     Vad kallas djur som äter växter?;Växtätare;Köttätare;Allätare;Rovdjur
     ```
   - Klicka "Skapa quiz från CSV"

   **Alternativ B: Manuell inmatning**
   - Fyll i quiz-titel
   - Klicka "+ Lägg till fråga" för varje fråga
   - Fyll i fråga, rätt svar och 3 felaktiga svar
   - Klicka "Skapa quiz"

   **Alternativ C: Klistra in CSV**
   - Klistra in CSV-text direkt i formuläret (semikolonseparerat)
   - Samma inställningar som CSV-uppladdning, inklusive ämne/årskurs/taggar

   **Alternativ D: Batch-import**
   - Ladda upp en CSV med flera quiz i samma fil
   - Rubrikrad per quiz + tom rad mellan quizen
   - Stöd för metadata (ämne/årskurs/taggar)

3. **Dela quiz:**
   - Kopiera länken (t.ex. `yoursite.com/quiz-app/q/abc123.html`)
   - Dela med elever via e-post, LMS, etc.

4. **Se statistik:**
   - Klicka "Se statistik" på ett quiz
   - Se totalt antal försök, klarat, genomsnittstid
   - Se svåraste frågorna
   - Se senaste försöken

### Elever

1. **Besök länk** som läraren delat (t.ex. `yoursite.com/quiz-app/q/abc123.html`)
2. **Skriv ditt namn** (används bara för diplomet)
3. **Kör quizet:**

   **Fas 1: Flerval** (4 svarsalternativ)
   - Klicka på rätt svar
   - Rätt svar = grönt blink ✅
   - Fel svar = rött + rätt svar markeras 🔴
   - Varje fråga måste besvaras rätt **2 gånger**
   - Fel frågor flyttas till slutet av kön

   **Fas 2: Fritext**
   - Skriv svaret själv
   - Stavfel tolereras (1-3 bokstäver fel okej)
   - "schimpanser" = "schimpansernas" accepteras
   - Varje fråga måste besvaras rätt **2 gånger till** (totalt 4)
   - Fel frågor flyttas till slutet av kön

4. **Få diplom:**
   - När alla frågor är klara = 🏆 Bra jobbat!
   - Klicka "Ladda ner diplom" för PDF
   - Diplomet innehåller: namn, quiz-titel, datum, tid, genomförandetid

## 📊 Statistik som sparas (anonymt)

För varje quiz sparas:
- ✅ Totalt antal försök
- ✅ Antal som klarade hela quizet
- ✅ Genomsnittstid
- ✅ Genomsnittligt antal fel per omgång
- ✅ Vilka specifika frågor som är svårast (flest fel)
- ✅ Tidsstämpel för varje försök

**OBS:** Elevernas namn sparas ALDRIG. Statistik är helt anonym.

## 🎨 Quiz-funktioner

### Stavfelstolerans
Systemet accepterar små stavfel:

```javascript
✅ "Växtätare" → "växtätare" (gemener)
✅ "Växtätare" → "Växtätares" (3 extra bokstäver i slutet)
✅ "Merkurius" → "Merkurus" (1 bokstav fel, 20% tolerans)
❌ "Växtätare" → "Köttätare" (helt fel ord)
```

### Talsyntes
Klicka på 🔊-knappen för att höra frågan uppläst med svensk röst.

### Progress-staplar
Visar framsteg för varje fråga:
- 4 segment per fråga
- Grönt segment = ett rätt svar
- Fortsätter mellan fas 1 och fas 2

### Tidräknare
- Startar när quizet börjar
- Stannar när quizet är klart
- Visas i diplom

## 🔒 Säkerhet

- ✅ Super admin lösenord hårdkodat och säkert gömt
- ✅ Lärarlösenord hashas med bcrypt
- ✅ Data-mappen skyddad med .htaccess
- ✅ Inga SQL-injections (JSON-filer)
- ✅ Elevernas namn sparas aldrig
- ✅ Session-baserad autentisering

## 🛠️ Felsökning

### Problem: "404 Not Found" på quiz-sidor
**Lösning:** Aktivera mod_rewrite i Apache:
```bash
sudo a2enmod rewrite
sudo service apache2 restart
```

### Problem: Kan inte spara data
**Lösning:** Sätt rätt behörigheter:
```bash
chmod 777 quiz-app/data/
chmod 666 quiz-app/data/*.json
```

### Problem: CSV-uppladdning fungerar inte
**Lösning:**
1. Kolla att filen är UTF-8 encoded
2. Använd semikolon (`;`) som separator
3. Faktaquiz behöver minst 3 kolumner, glosquiz minst 4 kolumner

### Problem: Talsyntes fungerar inte
**Lösning:**
- Fungerar bara på HTTPS (eller localhost)
- Kräver moderna webbläsare (Chrome, Safari, Edge)
- Firefox på iPad kan ha problem

## 📱 iPad/Mobil-anpassning

Appen är helt optimerad för touchskärmar:
- ✅ Responsiv design
- ✅ Stora klickbara knappar
- ✅ Inget zoom vid input
- ✅ Talsyntes fungerar
- ✅ PDF-diplom fungerar

## 🎓 CSV-format exempel

**Faktaquiz:**
```csv
Fråga;Rätt svar;Fel svar 1;Fel svar 2;Fel svar 3
Vad kallas djur som äter växter?;Växtätare;Köttätare;Allätare;Rovdjur
Vad heter Sveriges huvudstad?;Stockholm;Göteborg;Malmö;Uppsala
Hur många månader har ett år?;Tolv;Tio;Åtta;Fjorton
Vad är H2O?;Vatten;Syre;Väte;Koldioxid
Vilken planet är närmast solen?;Merkurius;Venus;Mars;Jorden
```

**Glosquiz (med omvända felsvar):**
```csv
Mening;Ord;Rätt översättning;Fel övers 1;Fel övers 2;Fel övers 3;Fel ord 1;Fel ord 2;Fel ord 3
El gato duerme;gato;katt;hund;fågel;häst;perro;casa;libro
Me gusta leer libros;leer;läsa;skriva;dricka;sova;hablar;comer;jugar
```

**Viktigt:**
- Rad 1 = Header (hoppas över automatiskt)
- Faktaquiz: minst 3 kolumner (`Fråga;Rätt svar;Fel...`)
- Glosquiz: minst 4 kolumner (`Mening;Ord;Rätt översättning;Fel...`)
- Kolumn 7+ i glosquiz används för omvända felsvar (`Fel ord ...`)
- UTF-8 encoding
- Undvik synonymer i svarsalternativen

## 🔗 URL-struktur

```
yoursite.com/quiz-app/                  → Inloggning
yoursite.com/quiz-app/super-admin.php   → Super admin
yoursite.com/quiz-app/admin.php         → Lärarpanel
yoursite.com/quiz-app/stats.php?quiz_id=abc123  → Statistik
yoursite.com/quiz-app/q/abc123.html     → Elevernas quiz
```

## 💡 Tips

### För super admin:
- Ge lärare användarnamn som `fornamn.efternamn`
- Använd starka lösenord (12+ tecken)
- Kolla statistik regelbundet i super-admin-panelen

### För lärare:
- Ge quizzes tydliga namn (t.ex. "Biologi Kap 3: Celler")
- Undvik synonymer i svarsalternativen
- Testa quizet själv innan du delar det
- Använd 5-10 frågor per quiz (inte för långt)

### För elever:
- Använd lyssna-knappen om du är osäker på uttalet
- Ta din tid - det finns ingen tidsgräns
- Stavfel tolereras, men försök stava rätt

## 📝 Checklista för första användning

- [ ] Ladda upp alla filer till servern
- [ ] Ändra super admin lösenord i `config.php`
- [ ] Sätt rätt fil-behörigheter på `data/` mappen
- [ ] Logga in som super admin
- [ ] Skapa minst ett lärarkonto
- [ ] Logga ut och logga in som lärare
- [ ] Skapa ett test-quiz
- [ ] Öppna quiz-länken i ny flik
- [ ] Testa quiz som elev
- [ ] Ladda ner diplom
- [ ] Kolla statistiken i lärarpanelen

## 🤝 Support

Om något inte fungerar:
1. Kolla felsökningsavsnittet ovan
2. Kolla att alla filer finns på rätt plats
3. Kolla filbehörigheter
4. Kolla att .htaccess-filer finns
5. Testa i en annan webbläsare

## 🧾 Ändringslogg

### 2026-02-11
- Glosquiz stödjer nu omvänd träningsriktning med egna inställningar per riktning.
- CSV/AI/import stödjer utökat glosformat med omvända felsvar (`Fel ord 1..3`).
- Import använder nu enbart semikolon (`;`) som separator.
- Misspellings i statistik accepterar både nytt och äldre format.
- Ämne, årskurs och taggar är nu konsekvent tillgängliga i alla importflöden (inklusive klistra-in och batch).

## 🎉 Lycka till!

Nu är du redo att använda quiz-appen! Skapa lärarkonton, ladda upp frågor och börja träna!

---

**Version:** 1.1.0
**Skapad:** 2025-10-01
**Senast uppdaterad:** 2026-02-11
