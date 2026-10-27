# Utveckling och drift

## Verktyg och beroenden

Verifierad lokal miljö: PHP 8.4, Node 24 och Python 3.14 på Windows. CI använder PHP 8.4, Node 24 och Python 3.12. Python 3.12 eller senare krävs av testernas städning; Node 20 eller senare krävs av byggverktygen. Driften behöver bara PHP och Apache.

Produktionsbiblioteken är exakt låsta i `package.json` och `package-lock.json`: React/ReactDOM 18.3.1, Babel Standalone 7.29.11 och QRCode.js 1.0.0. Tailwind 3.4.19 bygger statisk CSS. Webbläsartesterna har en separat låsfil med Playwright 1.56.1.

`npm ci --ignore-scripts` följt av `npm run build` kopierar bibliotek och licenser samt genererar Tailwind-CSS till `assets/vendor/`. Dessa filer checkas in. Efter ändringar i PHP eller JavaScript som tillför Tailwind-klasser behöver bygget köras igen. Dynamiska färgklasser i äldre admin finns i `tailwind.config.cjs` safelist.

Spelarna hämtar inte längre bibliotek från CDN. Det tidigare laddade jsPDF har tagits bort eftersom ingen produktionskod använde det. PDF-material delas fortfarande ut genom den befintliga PHP-endpointen. Babel kompilerar fortfarande JSX i webbläsaren; att flytta den kompileringen till byggsteget är ett separat framtida arbete. Biblioteks- och CSS-versioner finns i filnamnen. Ändra kopieringslistan och sidreferenserna tillsammans vid en uppgradering.

Kör `npm audit` och `npm audit --prefix tests/browser` vid beroendeändringar. Kontrollen 2026-10-26 rapporterade inga kända sårbarheter i de låsta träden; det är ingen garanti mot framtida rapporter.

## Lagringskontrakt

`readJSON()` returnerar tom data när filen saknas men avbryter vid läsfel, felaktig JSON eller fel rottyp. Läsning skapar inga filer. En befintlig trasig fil ersätts aldrig med tom data.

Alla ändringar av befintliga JSON-dokument går genom `updateJSONLocked()` (AI-lagret använder samma funktion). Funktionen låser en separat `.lock`-fil över hela läs–ändra–skriv-operationen och ersätter målet atomärt. Äldre admin och importer skriver enbart berörda poster. `writeJSON()` är den lågnivåfunktion som används inne i transaktionen och för syntetiska testfixturer; använd den inte för att skriva tillbaka en tidigare inläst produktionsfil.

Resultatsändningar får ett stabilt `request_id` som behålls vid återförsök. Kvittot lagras i samma transaktion som resultatet. Samma begäran ger ingen extra registrering; samma ID med ändrat innehåll avvisas. Äldre klienter utan ID stöds men har inte denna garanti. Kvitton och historik växer med användningen och har ingen automatisk gallring.

Klassrumsaktivitet köar nya repetitionssvar i `repetition_outbox` i samma transaktion som aktiviteten. Leverans till `repetition.json` har eget kvitto. Ett avbrott före leverans eller före kvittering kan därför återupptas utan förlorat eller dubbelt repetitionssvar. Nästa aktivitetsanrop från samma elev återupptar köade leveranser; det finns ingen bakgrundsprocess som tömmer kön.

Spelarna visar skillnad mellan genomförd övning och bekräftat sparat resultat. Vid fel kan eleven försöka igen; anslutningens återkomst utlöser också återförsök. Väntande webbläsarsändningar finns bara medan sidan är öppen. Stäng inte en sida som fortfarande visar osparat resultat.

## Säker publicering och driftdata

Rotens `.htaccess` spärrar pensionerade diagnossidor även om deras gamla filer ligger kvar. PHP-filerna returnerar också HTTP 410 utan att läsa driftdata. Bara rasterbilder får nås direkt under `data/images/`; övrig `data/`, inklusive lås- och temporärfiler, spärras av rotreglerna. Behåll även underkatalogernas skydd.

Vanlig bild- och Excelimport bestämmer lagrad filändelse från bildinnehållet. Rasterbilder begränsas till 5 MB, 8000 pixlar per sida och 24 megapixlar. `.htaccess`-reglernas faktiska genomslag måste verifieras på Apache efter driftsättning; PHP:s inbyggda testserver läser inte dem.

GitHub Actions verifierar PHP-syntax, enhetstester, syntetiska HTTP/elevrobotflöden och webbläsartester före deploy. `npm run build` ska ge samma incheckade resurser. Bara push till `main` får köra SFTP-steget efter godkända kontroller; pull requests kör enbart verifiering.

SFTP-mirror använder inte `--delete`: gamla borttagna filer kan ligga kvar på servern. Därför ska pensionerade känsliga URL:er blockeras i serverreglerna. Driftdata, bilder, PDF:er, testfiler, testverktyg och `node_modules/` ska inte laddas upp. Kodens `data/materials/.htaccess` ska däremot följa med.

En lokal commit är inte push eller driftsättning. Ändringar i workflow är inte bevis för att CI har körts på GitHub.

## Kontroller

`npm test` är den gemensamma ingången för PHP-syntax, JavaScript-tester, varje separat `tests/*.test.php` och `python tests/classroom-http.test.py --robots`.

Enskilda PHP-sviter körs var för sig, exempelvis:

```sh
php tests/storage.test.php
php tests/ai-service.test.php
php tests/repetition.test.php
php tests/browser-ids.test.php
```

Webbläsare: installera med `npm ci --prefix tests/browser --ignore-scripts`, kör `npx playwright install chromium` från `tests/browser/`, sedan `npm test` där. Linux behöver normalt `npx playwright install --with-deps chromium`.

För manuell visuell QA kör `python tests/classroom-http.test.py --serve`. Kommandot skriver ut en lokal URL och syntetiska inloggningsuppgifter; den temporära kopian innehåller inga riktiga konton eller quiz. Använd en separat testflik. Avsluta med Ctrl+C så att servern och kopian städas bort.

Skärmbilder finns i den ignorerade mappen `tests/browser/screenshots/`. Testerna emulerar även touch och mobila skärmstorlekar, men kör Chromium; en fysisk iPad med Safari kräver separat kontroll.
