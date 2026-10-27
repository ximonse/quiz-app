# Quiz-app

Quiz och övningar för lärare och elever, med PHP, React 18 och vanlig JavaScript. Data lagras som JSON-filer på webbservern.

Lärare skapar faktaquiz, glosquiz, bildvalsquiz, sorteringsquiz och ordningsquiz. Det finns även flashcards, klasser, grupper, elevkonton och uppdrag. Nya quiz granskas som utkast innan publicering. Äldre publika quizlänkar fungerar fortfarande.

## Kom igång

Driften behöver Apache med `mod_rewrite` och tillåtna `.htaccess`-regler, HTTPS och PHP. PHP 8.4 används i CI; koden kräver minst PHP 8.1. Excelimport behöver tilläggen ZipArchive och SimpleXML. Aktivera även fileinfo för MIME-kontroll av material.

1. Konfigurera superadministratörens lösenord privat i `config.local.php` genom att definiera `SUPER_ADMIN_PASSWORD`. Filen ska aldrig checkas in.
2. Ge PHP-processen läs- och skrivrättigheter i `data/`. Använd ägarskap/grupprättigheter för driftkontot; gör inte mappen globalt skrivbar med `777`.
3. Lägg upp appens kod och genererade `assets/vendor/`, inklusive `.htaccess` i roten, `data/` och `data/materials/`. Bevara driftens JSON-data, bilder och PDF:er.
4. Logga in via `index.php`, skapa lärarkonton och använd `admin/dashboard.php`. Elever loggar in via `student/` eller öppnar en publicerad quizlänk.

Produktionsservern behöver inte Node. Lokala frontendresurser checkas in och följer med koden. Fullständiga bygg-, test- och leveransinstruktioner finns i [utvecklingsguiden](docs/development.md).

## Data och integritet

**Resultaten är inte anonyma.** Publika quiz kan spara det inskrivna elevnamnet, webbläsar-ID, tidpunkt, resultat och felsvar. För inloggade elever kan även elev-ID sparas. Klassrumsaktivitet och repetitionshistorik är kopplade till elevkontot.

Elevens startsida visar uppdrag och status, medan läraren ser sin klass aktivitet. PIN-koder hashas. JSON-filer, låsfiler och tillfälliga datafiler ska vara spärrade från direkt webbåtkomst; uppladdade rasterbilder kan visas publikt. Dela därför bara bilder som är lämpliga för publik visning.

## Dokumentation

- [Projektöversikt och dataformat](PROJECT.md)
- [Klasser, elevkonton, aktivitet och repetition](docs/classroom.md)
- [AI/MCP, quizformat och granskning/publicering](docs/ai-connection.md)
- [Drift, beroenden, testning och leverans](docs/development.md)

`docs/superpowers/` innehåller historiska design- och implementationsbeslut, inte aktuell driftinstruktion.

## Kodens huvuddelar

| Del | Ansvar |
| --- | --- |
| `config.php` | Sessioner, behörighet, CSRF och gemensamma lagringstransaktioner |
| `admin/` | Aktuell lärarpanel, redigerare och klassrumsadministration |
| `play/` och `engine/` | Aktuella elevspelare och quizmotorer |
| `ai/` och `api/` | Validering, publicering, MCP och resultat/aktivitet |
| `includes/classroom.php` | Elevsessioner, uppdrag och aktivitet |
| `includes/repetition.php` | Repetitionskort och schemaläggning |
| `q/`, `admin.php`, `edit-quiz.php`, `flashcards-admin.php` | Äldre format och kompatibilitetsflöden som fortfarande stöds |
| `tests/` | Syntetiska enhets-, HTTP- och webbläsartester |

## Lokal kontroll

```sh
npm ci --ignore-scripts
npm run build
npm test
npm ci --prefix tests/browser --ignore-scripts
cd tests/browser
npx playwright install chromium
npm test
```

Testerna använder temporära appkopior och syntetiska konton. Kör aldrig testanrop mot verklig elevdata. Automatisk testning ersätter inte kontroll på en fysisk iPad/Safari.
