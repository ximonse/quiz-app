<?php
// admin/create.php — Skapa ett nytt quiz. Låg ut ur dashboard.php, som blev
// ett bibliotek att överblicka i stället för en sida att scrolla förbi.
// Vid fel renderas formuläret om med allt ifyllt; old*() nedan håller utkastet.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/teacher-shell.php';
require_once __DIR__ . '/../ai/quiz-service.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }

$csrfToken = getCsrfToken();
$teacherId = getCurrentTeacherID();
$error = '';
$success = '';

// Håll formuläret ifyllt om ett POST-försök misslyckas (t.ex. CSV-parsefel),
// så läraren slipper skriva om titel/CSV/inställningar från noll.
function old($key, $default = '') {
    return htmlspecialchars(($GLOBALS['error'] && ($_POST['action'] ?? '') === 'create') ? ($_POST[$key] ?? $default) : $default, ENT_QUOTES, 'UTF-8');
}
function oldChecked($key, $defaultOnGet = false) {
    if (!$GLOBALS['error'] || ($_POST['action'] ?? '') !== 'create') return $defaultOnGet ? 'checked' : '';
    return isset($_POST[$key]) ? 'checked' : '';
}
function oldSelected($key, $value, $default) {
    $selected = ($GLOBALS['error'] && ($_POST['action'] ?? '') === 'create') ? ($_POST[$key] ?? $default) : $default;
    return ($selected === $value) ? 'selected' : '';
}

// --- Handle create ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    requireValidCsrf();
    $type = $_POST['type'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $csvData = trim($_POST['csv_data'] ?? '');

    if (!$title || !$csvData || !in_array($type, ['glossary', 'fact'])) {
        $error = 'Fyll i alla fält.';
    } else {
        $items = parseCSV($csvData, $type);
        if (empty($items)) {
            $error = 'Kunde inte tolka CSV-data. Kontrollera formatet.';
        } else {
            $quizId = 'q_' . bin2hex(random_bytes(5));
            $answerMode = $_POST['answer_mode'] ?? 'multiple_choice';
            $questionDirection = $_POST['question_direction'] ?? 'concept';
            $reverseEnabled = isset($_POST['reverse_enabled']);
            $reverseAnswerMode = $_POST['reverse_answer_mode'] ?? 'multiple_choice';

            $quiz = [
                'id' => $quizId,
                'title' => $title,
                'type' => $type,
                'subject' => trim($_POST['subject'] ?? ''),
                'tags' => trim($_POST['tags'] ?? ''),
                'created' => date('Y-m-d H:i:s'),
                'teacher_id' => $teacherId,
                'settings' => [
                    'answer_mode' => $answerMode,
                    'question_direction' => $type === 'fact' ? $questionDirection : 'concept',
                    'concept_language' => (($_POST['concept_language'] ?? 'sv') === 'same') ? 'same' : 'sv',
                    'required_correct' => max(1, intval($_POST['required_correct'] ?? 1)),
                    'reverse_enabled' => $reverseEnabled,
                    'reverse_answer_mode' => $reverseAnswerMode,
                    'reverse_required_correct' => max(1, intval($_POST['reverse_required_correct'] ?? 1)),
                    'quiz_mode' => $_POST['quiz_mode'] ?? 'training',
                    'tts_enabled' => isset($_POST['tts_enabled']),
                    'language' => $_POST['language'] ?? 'sv',
                    'spelling_mode' => $_POST['spelling_mode'] ?? 'student_choice',
                    'time_lock' => null,
                    'generate_flashcards' => isset($_POST['generate_flashcards']),
                    'flashcard_mix_directions' => isset($_POST['flashcard_mix_directions']),
                ],
                'items' => $items,
                'publication' => 'draft',
                'results' => []
            ];

            $opens = trim($_POST['time_lock_opens'] ?? '');
            $closes = trim($_POST['time_lock_closes'] ?? '');
            if ($opens || $closes) {
                $quiz['settings']['time_lock'] = [
                    'opens' => $opens ?: null,
                    'closes' => $closes ?: null
                ];
            }

            updateJSONLocked(QUIZZES_FILE, function (&$lockedQuizzes) use ($quizId, $quiz) {
                $lockedQuizzes[$quizId] = $quiz;
            });
            // Ett lyckat skapande hör hemma i biblioteket, med kvittot där.
            $_SESSION['dashboard_message'] = "Quizet \"{$title}\" är skapat. Granska och publicera det innan du delar ut det.";
            header('Location: dashboard.php'); exit;
        }
    }
}

function parseCSV($csvText, $type) {
    $lines = array_filter(array_map('trim', explode("\n", $csvText)));
    $items = [];
    $first = strtolower($lines[0] ?? '');
    if (str_contains($first, 'mening') || str_contains($first, 'begrepp')) {
        array_shift($lines);
    }
    foreach ($lines as $line) {
        $cols = array_map('trim', explode(';', $line));
        if ($type === 'glossary') {
            if (count($cols) < 6) continue;
            if ($cols[0] === '' || $cols[1] === '' || $cols[2] === '') continue; // saknar mening/ord/översättning
            $items[] = [
                'sentence' => $cols[0],
                'word' => $cols[1],
                'translation' => $cols[2],
                'wrong_options' => array_filter([$cols[3] ?? '', $cols[4] ?? '', $cols[5] ?? '']),
                'reverse_wrong_options' => array_filter([$cols[6] ?? '', $cols[7] ?? '', $cols[8] ?? ''])
            ];
        } else {
            if (count($cols) < 5) continue;
            if ($cols[0] === '' || $cols[1] === '') continue; // saknar begrepp/beskrivning
            $items[] = [
                'concept' => $cols[0],
                'description' => $cols[1],
                'wrong_options' => array_filter([$cols[2] ?? '', $cols[3] ?? '', $cols[4] ?? ''])
            ];
        }
    }
    return $items;
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Skapa quiz</title>
    <link rel="stylesheet" href="../assets/vendor/tailwind-3.4.19.css">
    <link rel="stylesheet" href="../engine/themes.css">
    <link rel="stylesheet" href="../assets/teacher-shell.css">
</head>
<body class="min-h-screen bg-gradient-to-br from-blue-50 to-purple-50">
<div class="teacher-page">
    <?php teacherShell('innehall','Skapa quiz','Välj typ, ge quizet en titel och fyll på med frågor.'); ?>

    <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-700 rounded-lg text-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="mb-4 p-3 bg-green-100 text-green-700 rounded-lg text-sm"><?= htmlspecialchars($success) ?> <a href="dashboard.php" class="underline">Till Innehåll</a></div>
    <?php endif; ?>

    <!-- ═══════════ SKAPA QUIZ ═══════════ -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <p class="text-sm text-gray-700 mb-2">Glosquiz och faktaquiz byggs här. De andra typerna har egna sidor:</p>
        <a href="image-edit.php" class="inline-block mb-4 px-3 py-2 border rounded-lg font-medium text-blue-700">Skapa bildvalsquiz</a>
        <a href="sort-edit.php" class="inline-block mb-4 ml-2 px-3 py-2 border rounded-lg font-medium text-blue-700">Skapa sorteringsquiz</a>
        <a href="order-edit.php" class="inline-block mb-4 ml-2 px-3 py-2 border rounded-lg font-medium text-blue-700">Skapa ordningsquiz</a>

        <form id="create-quiz-form" method="POST" class="space-y-4" onsubmit="return prepareCreateSubmit();">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">

            <!-- Typ (knappar) -->
            <input type="hidden" name="type" id="type-input" value="<?= old('type', 'glossary') ?>">
            <div class="flex gap-3 mb-2">
                <button type="button" id="btn-glossary" onclick="selectType('glossary')"
                    class="flex-1 py-3 rounded-xl border-2 font-bold text-lg transition-all border-purple-500 bg-purple-50 text-purple-700 shadow-md">
                    Glosquiz
                </button>
                <button type="button" id="btn-fact" onclick="selectType('fact')"
                    class="flex-1 py-3 rounded-xl border-2 font-bold text-lg transition-all border-gray-400 bg-white text-gray-700 hover:border-blue-400">
                    Faktaquiz
                </button>
            </div>

            <!-- Titel + Ämne + Taggar -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Titel</label>
                    <input type="text" name="title" value="<?= old('title') ?>" required class="w-full px-3 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="T.ex. Spanska vecka 12">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Ämne</label>
                    <input type="text" name="subject" value="<?= old('subject') ?>" class="w-full px-3 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="T.ex. Spanska">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Taggar</label>
                    <input type="text" name="tags" value="<?= old('tags') ?>" class="w-full px-3 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="vecka12, åk6">
                </div>
            </div>

            <!-- CSV Data -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">CSV-data (semikolon-separerad)</label>
                <div id="csv-hint-glossary" class="text-xs text-gray-700 mb-1">Format: mening;ord;översättning;fel1;fel2;fel3;omvänt_fel1;omvänt_fel2;omvänt_fel3</div>
                <div id="csv-hint-fact" class="text-xs text-gray-700 mb-1 hidden">Format: begrepp;beskrivning;fel1;fel2;fel3</div>
                <textarea name="csv_data" rows="6" class="w-full px-3 py-2 border border-gray-500 rounded-lg font-mono text-sm focus:ring-2 focus:ring-blue-500" placeholder="Klistra in CSV här..."><?= old('csv_data') ?></textarea>

                <!-- AI Prompt Helper -->
                <div class="mt-2">
                    <button type="button" onclick="toggleAiPrompt()" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-purple-300 bg-purple-50 hover:bg-purple-100 text-purple-800 font-medium text-sm">
                        <span>🤖</span> <span>Hämta AI-prompt för <span id="ai-prompt-type">glosorna</span></span>
                    </button>
                    <p class="text-xs text-gray-700 mt-1">Färdig prompt att klistra in i ChatGPT, Claude eller Copilot. Du laddar upp ett foto eller en lista, och får tillbaka CSV att klistra in här.</p>
                    <div id="ai-prompt-box" class="hidden mt-2 p-3 bg-purple-50 border border-purple-100 rounded-lg">
                        <ol class="text-xs text-purple-900 list-decimal list-inside space-y-1 mb-3">
                            <li>Klicka på "📋 Kopiera prompt" nedan.</li>
                            <li>Gå till valfri AI-chatt (t.ex. ChatGPT, Claude eller Copilot) och klistra in.</li>
                            <li>Tillsammans med prompten kan du ladda upp ett foto på alla glosorna, eller skriva/klistra in en lista med orden.</li>
                            <li>Skicka till AI:n. Den visar då en lista med orden och översättningarna — skriv "ok" om den blev rätt.</li>
                            <li>Du får då ett meddelande med CSV-text — kopiera det och klistra in det i fältet nedan.</li>
                        </ol>
                        <p class="text-xs font-bold text-purple-800 mb-2">PS: Kom ihåg att välja språk i inställningarna innan du skapar quizet.</p>
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-xs font-bold text-purple-800">Kopiera denna prompt till ChatGPT/Claude:</span>
                            <button type="button" onclick="copyAiPrompt()" class="bg-purple-200 hover:bg-purple-300 text-purple-800 px-2 py-1 rounded text-xs transition">
                                📋 Kopiera prompt
                            </button>
                        </div>
                        <pre id="prompt-glossary" class="text-xs bg-white p-2 rounded border border-gray-400 overflow-x-auto whitespace-pre-wrap text-gray-600">Du är en expert på pedagogik. Jag vill skapa glosquiz-material.

Processen sker i två steg:

STEG 1: ANALYS & FÖRSLAG
1. Analysera texten/bilden jag bifogar.
2. OM materialet redan anger färdiga ordpar (t.ex. en gloslista med ord + översättningar): dessa ord och översättningar är FACIT, i den grundform de anges. Använd dem EXAKT som de anges — hitta inte på egna översättningar, byt inte ut mot synonymer, ändra inte betydelsen OCH böj inte om dem. Din uppgift i STEG 2 är att hitta på en exempelmening som passar detta redan bestämda ordpar — inte att ändra ordparet för att passa en mening.
3. OM materialet INTE anger färdiga ordpar (t.ex. en löpande text/bild utan gloslista): föreslå då själv 10-20 relevanta glosor med översättningar.
4. Fråga mig om jag vill ändra något innan du går vidare till steg 2.

STEG 2: GENERERING (Efter mitt godkännande)
Skapa CSV-text med semikolon som separator.

FORMAT (en rad per glosa) — detta format MÅSTE följas EXAKT, utan undantag:
mening;ord;översättning;fel1;fel2;fel3;omvänt_fel1;omvänt_fel2;omvänt_fel3

FÖRKLARING:
- "ord" och "översättning": Det redan bestämda ordparet, i sin grundform (facit — antingen från min ordlista, eller det du själv föreslog och jag godkände i STEG 1). Detta ordpar är FAST — det är meningen som ska anpassas efter det, inte tvärtom.
- "mening": En exempelmening som du konstruerar KRING det redan bestämda ordet. Ordet måste förekomma ORDAGRANT (exakt samma tecken) i meningen — välj numerus, tempus, bestämdhet, person och sammanhang i meningen så att ordets grundform passar rakt av, utan att behöva böjas om. Välj alltså bort meningar som skulle kräva en annan böjning av ordet, och skriv en annan mening istället.
- "fel1-3": 3 trovärdiga felaktiga svenska översättningar, i SAMMA grundform som "översättning".
- "omvänt_fel1-3": 3 trovärdiga felaktiga ord på källspråket, i SAMMA grundform som "ord".

STRIKTA REGLER (avvik ALDRIG från dessa, oavsett hur materialet ser ut):
- Exakt 9 fält per rad, separerade med exakt 8 semikolon (;). Aldrig fler, aldrig färre — även om något fält känns svårt att fylla i, hitta ändå på ett rimligt värde.
- Samma antal fält på ALLA rader, utan undantag.
- Anpassa MENINGEN efter det redan bestämda ordet och översättningen — böj eller ändra ALDRIG "ord" eller "översättning" för att de ska passa en mening du redan skrivit. Om en mening du tänkt dig kräver en annan böjning, välj en annan mening istället.
- Inga semikolon inuti något fält (byt ev. semikolon i meningen mot kommatecken).
- Alla svarsalternativ ("översättning", "fel1-3", "ord", "omvänt_fel1-3") ska skrivas med liten bokstav, om inte ordet i sig ska ha stor bokstav (t.ex. egennamn eller platser).
- Inga citattecken.
- Inga radbrytningar inuti en cell — varje glosa ska vara EN enda textrad.
- Ingen header-rad.
- Lägg alla CSV-rader i ETT enda kodblock (```), inte som vanlig text — det gör att radbrytningarna blir pålitliga och att raderna går att kopiera exakt med kodblockets kopieringsknapp. Inget annat innanför kodblocket (ingen numrering, inga rubriker) och ingen text alls utanför kodblocket.

EXEMPEL:
Hola, me llamo Roberto;llamo;heter;bor;läser;springer;vive;toma;mira
This place is strange.;strange;konstig;läskig;tråkig;vacker;boring;scary;beautiful

(Notera andra exemplet: ordparet strange/konstig är i grundform. Meningen är därför skriven i singular — "this place", INTE "these places" — så att grundformen "konstig" passar utan att behöva böjas om till "konstiga". Hade jag istället gett dig ordparet strange/konstiga [plural] hade du valt en mening i plural för att passa DET.)

Nu, invänta mitt material.</pre>
                        <pre id="prompt-fact" class="text-xs bg-white p-2 rounded border border-gray-400 overflow-x-auto whitespace-pre-wrap text-gray-600 hidden">Du är en expert på pedagogik. Jag vill skapa faktaquiz-material.

Processen sker i två steg:

STEG 1: ANALYS & FÖRSLAG
1. Analysera texten/bilden jag bifogar.
2. Föreslå 10-20 relevanta begrepp/fakta.
3. Fråga mig om jag vill ändra något.

STEG 2: GENERERING (Efter mitt godkännande)
Skapa CSV-text med semikolon som separator.

FORMAT (en rad per begrepp) — detta format MÅSTE följas EXAKT, utan undantag:
begrepp;beskrivning;fel1;fel2;fel3

FÖRKLARING:
- "begrepp": Ordet/begreppet som ska läras.
- "beskrivning": Korrekt förklaring/definition.
- "fel1-3": 3 trovärdiga men felaktiga beskrivningar.

STRIKTA REGLER (avvik ALDRIG från dessa, oavsett hur materialet ser ut):
- Exakt 5 fält per rad, separerade med exakt 4 semikolon (;). Aldrig fler, aldrig färre — även om något fält känns svårt att fylla i, hitta ändå på ett rimligt värde.
- Samma antal fält på ALLA rader, utan undantag.
- Inga semikolon inuti något fält (byt ev. semikolon i beskrivningen mot kommatecken).
- "beskrivning" och "fel1-3" är hela meningar: börja med stor bokstav och avsluta med punkt.
- Inga citattecken.
- Inga radbrytningar inuti en cell — varje begrepp ska vara EN enda textrad.
- Ingen header-rad.
- Lägg alla CSV-rader i ETT enda kodblock (```), inte som vanlig text — det gör att radbrytningarna blir pålitliga och att raderna går att kopiera exakt med kodblockets kopieringsknapp. Inget annat innanför kodblocket (ingen numrering, inga rubriker) och ingen text alls utanför kodblocket.

EXEMPEL:
Fotosyntes;Processen där växter omvandlar solljus till energi.;Nedbrytning av proteiner.;Transport av vatten i rötter.;Cellandning i djur.

Nu, invänta mitt material.</pre>
                    </div>
                </div>

                <!-- Manuell inmatning -->
                <div class="mt-2">
                    <button type="button" onclick="toggleManualBuilder()" class="flex items-center text-blue-600 hover:text-blue-800 font-medium text-xs">
                        <span class="mr-1">🖊️</span> Eller bygg <span id="manual-builder-label-inline">glosorna</span> manuellt, rad för rad
                    </button>
                    <div id="manual-builder-box" class="hidden mt-2 p-3 bg-blue-50 border border-blue-100 rounded-lg">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-blue-800"><span id="manual-builder-label">Glosor</span> (<span id="manual-builder-count">0</span>)</span>
                            <button type="button" onclick="addItemRow()" class="text-xs px-2 py-1 rounded bg-blue-200 hover:bg-blue-300 text-blue-800 transition">+ Lägg till <span id="add-row-label">glosa</span></button>
                        </div>
                        <div id="items-editor" class="space-y-3"></div>
                        <p class="text-xs text-gray-700 mt-2">Raderna byggs ihop till CSV-datan automatiskt när du skapar quizet — det du skriver i CSV-fältet ovan används bara om du inte lägger till några rader här.</p>
                    </div>
                </div>
            </div>

            <!-- Inställningar -->
            <fieldset class="border border-gray-400 rounded-lg p-4">
                <legend class="text-sm font-medium text-gray-700 px-2">Inställningar</legend>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-gray-700 mb-1">Svarsläge</label>
                        <select name="answer_mode" class="w-full px-2 py-1 border border-gray-500 rounded text-sm">
                            <option value="multiple_choice" <?= oldSelected('answer_mode', 'multiple_choice', 'multiple_choice') ?>>Flerval</option>
                            <option value="text_only" <?= oldSelected('answer_mode', 'text_only', 'multiple_choice') ?>>Skrivsvar</option>
                            <option value="hybrid" <?= oldSelected('answer_mode', 'hybrid', 'multiple_choice') ?>>Hybrid (alla ord som flerval, sedan alla som skrivsvar)</option>
                        </select>
                    </div>
                    <div id="fact-direction-field" class="hidden">
                        <label class="block text-xs text-gray-700 mb-1">Vad är frågan?</label>
                        <select name="question_direction" class="w-full px-2 py-1 border border-gray-500 rounded text-sm">
                            <option value="concept" <?= oldSelected('question_direction', 'concept', 'concept') ?>>Begrepp → skriv/välj beskrivning</option>
                            <option value="description" <?= oldSelected('question_direction', 'description', 'concept') ?>>Beskrivning → skriv/välj begrepp</option>
                        </select>
                        <p class="text-xs text-gray-700 mt-1">Vid Skrivsvar/Hybrid: välj "Beskrivning" om beskrivningarna är hela meningar — då skriver eleven det korta begreppet istället.</p>
                    <label class="block text-xs text-gray-700 mb-1 mt-2">Begreppet läses på</label>
                    <select name="concept_language" class="w-full px-2 py-1 border border-gray-500 rounded text-sm">
                        <option value="sv" <?= oldSelected('concept_language', 'sv', 'sv') ?>>Svenska</option>
                        <option value="same" <?= oldSelected('concept_language', 'same', 'sv') ?>>Valt språk</option>
                    </select>
                    <p class="text-xs text-gray-700 mt-1">Läses begreppet på svenska eller på det valda språket? Beskrivningen läses alltid på det valda språket.</p>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-700 mb-1">Quizläge</label>
                        <select name="quiz_mode" id="quiz-mode-select" class="w-full px-2 py-1 border border-gray-500 rounded text-sm" onchange="onQuizModeChange()">
                            <option value="training" <?= oldSelected('quiz_mode', 'training', 'training') ?>>Träning (repetition)</option>
                            <option value="test" <?= oldSelected('quiz_mode', 'test', 'training') ?>>Test (en genomgång)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-700 mb-1">Rätt per fråga</label>
                        <input type="number" name="required_correct" min="1" max="10" value="<?= old('required_correct', '2') ?>" class="w-full px-2 py-1 border border-gray-500 rounded text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-700 mb-1">Språk</label>
                        <select name="language" class="w-full px-2 py-1 border border-gray-500 rounded text-sm">
                            <option value="sv" <?= oldSelected('language', 'sv', 'sv') ?>>Svenska</option>
                            <option value="en" <?= oldSelected('language', 'en', 'sv') ?>>Engelska</option>
                            <option value="es" <?= oldSelected('language', 'es', 'sv') ?>>Spanska</option>
                            <option value="fr" <?= oldSelected('language', 'fr', 'sv') ?>>Franska</option>
                            <option value="de" <?= oldSelected('language', 'de', 'sv') ?>>Tyska</option>
                            <option value="fi" <?= oldSelected('language', 'fi', 'sv') ?>>Finska</option>
                            <option value="uk" <?= oldSelected('language', 'uk', 'sv') ?>>Ukrainska</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-700 mb-1">Stavningsläge</label>
                        <select name="spelling_mode" class="w-full px-2 py-1 border border-gray-500 rounded text-sm">
                            <option value="student_choice" <?= oldSelected('spelling_mode', 'student_choice', 'student_choice') ?>>Eleven väljer</option>
                            <option value="easy" <?= oldSelected('spelling_mode', 'easy', 'student_choice') ?>>Generös</option>
                            <option value="puritan" <?= oldSelected('spelling_mode', 'puritan', 'student_choice') ?>>Exakt</option>
                        </select>
                    </div>
                    <div class="col-span-2 flex flex-wrap gap-4">
                        <label class="flex items-center gap-2 text-sm cursor-pointer text-gray-700">
                            <input type="checkbox" name="reverse_enabled" <?= oldChecked('reverse_enabled') ?> onchange="toggleReverseFields()"> Omvänd riktning
                        </label>
                        <label class="flex items-center gap-2 text-sm cursor-pointer text-gray-700">
                            <input type="checkbox" name="tts_enabled" id="tts-checkbox" <?= oldChecked('tts_enabled', true) ?>> Uppläsning (TTS)
                        </label>
                        <label class="flex items-center gap-2 text-sm cursor-pointer text-gray-700">
                            <input type="checkbox" name="generate_flashcards" id="generate-flashcards-checkbox" <?= oldChecked('generate_flashcards', true) ?>> Generera flashcards
                        </label>
                        <label class="flex items-center gap-2 text-sm cursor-pointer text-gray-700" id="flashcard-mix-field">
                            <input type="checkbox" name="flashcard_mix_directions" <?= oldChecked('flashcard_mix_directions') ?>> Blanda riktning i flashcards (båda hållen för varje kort)
                        </label>
                    </div>
                </div>
            </fieldset>

            <!-- Omvänd riktning -->
            <fieldset id="reverse-fields" class="border border-gray-400 rounded-lg p-4 hidden">
                <legend class="text-sm font-medium text-gray-700 px-2">Omvänd riktning</legend>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-gray-700 mb-1">Omvänt svarsläge</label>
                        <select name="reverse_answer_mode" class="w-full px-2 py-1 border border-gray-500 rounded text-sm">
                            <option value="multiple_choice" <?= oldSelected('reverse_answer_mode', 'multiple_choice', 'multiple_choice') ?>>Flerval</option>
                            <option value="text_only" <?= oldSelected('reverse_answer_mode', 'text_only', 'multiple_choice') ?>>Skrivsvar</option>
                            <option value="hybrid" <?= oldSelected('reverse_answer_mode', 'hybrid', 'multiple_choice') ?>>Hybrid</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-700 mb-1">Rätt per fråga (omvänd)</label>
                        <input type="number" name="reverse_required_correct" min="1" max="10" value="<?= old('reverse_required_correct', '2') ?>" class="w-full px-2 py-1 border border-gray-500 rounded text-sm">
                    </div>
                </div>
            </fieldset>

            <!-- Tidsspärr -->
            <fieldset class="border border-gray-400 rounded-lg p-4">
                <legend class="text-sm font-medium text-gray-700 px-2">Tidsspärr (valfritt)</legend>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-gray-700 mb-1">Öppnar</label>
                        <input type="datetime-local" name="time_lock_opens" value="<?= old('time_lock_opens') ?>" class="w-full px-2 py-1 border border-gray-500 rounded text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-700 mb-1">Stänger</label>
                        <input type="datetime-local" name="time_lock_closes" value="<?= old('time_lock_closes') ?>" class="w-full px-2 py-1 border border-gray-500 rounded text-sm">
                    </div>
                </div>
            </fieldset>

            <button type="submit" class="w-full py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium text-lg shadow-md">Skapa Quiz</button>
        </form>
    </div>
</div>
<script>
function selectType(type, silent) {
    document.getElementById('type-input').value = type;
    const btnG = document.getElementById('btn-glossary');
    const btnF = document.getElementById('btn-fact');
    if (type === 'glossary') {
        btnG.className = 'flex-1 py-3 rounded-xl border-2 font-bold text-lg transition-all border-purple-500 bg-purple-50 text-purple-700 shadow-md';
        btnF.className = 'flex-1 py-3 rounded-xl border-2 font-bold text-lg transition-all border-gray-400 bg-white text-gray-700 hover:border-blue-400';
    } else {
        btnF.className = 'flex-1 py-3 rounded-xl border-2 font-bold text-lg transition-all border-blue-500 bg-blue-50 text-blue-700 shadow-md';
        btnG.className = 'flex-1 py-3 rounded-xl border-2 font-bold text-lg transition-all border-gray-400 bg-white text-gray-700 hover:border-purple-400';
    }
    document.getElementById('csv-hint-glossary').classList.toggle('hidden', type !== 'glossary');
    document.getElementById('csv-hint-fact').classList.toggle('hidden', type !== 'fact');
    document.getElementById('fact-direction-field').classList.toggle('hidden', type !== 'fact');
    document.getElementById('prompt-glossary').classList.toggle('hidden', type !== 'glossary');
    document.getElementById('prompt-fact').classList.toggle('hidden', type !== 'fact');
    document.getElementById('manual-builder-label-inline').textContent = (type === 'glossary') ? 'glosorna' : 'frågorna';
    document.getElementById('ai-prompt-type').textContent = (type === 'glossary') ? 'glosorna' : 'frågorna';
    document.getElementById('manual-builder-label').textContent = (type === 'glossary') ? 'Glosor' : 'Frågor';
    document.getElementById('add-row-label').textContent = (type === 'glossary') ? 'glosa' : 'fråga';
    // silent=true (används vid sticky-återladdning) rör inte TTS-kryssrutan,
    // så ett återställt värde från ett tidigare (misslyckat) försök inte skrivs över.
    if (!silent) document.getElementById('tts-checkbox').checked = (type === 'glossary');
    // Fälten skiljer sig åt mellan glos- och faktarader, så manuellt inlagda
    // rader töms om läraren byter typ (samma mönster som CSV-fältets hint/prompt ovan).
    const editor = document.getElementById('items-editor');
    if (editor) {
        editor.innerHTML = '';
        updateManualBuilderCount();
    }
}
function onQuizModeChange() {
    // Test-läge passar sällan ihop med flashcards (repetitionsverktyg) —
    // avmarkera som förvalt, men läraren kan fritt återaktivera det.
    if (document.getElementById('quiz-mode-select').value === 'test') {
        document.getElementById('generate-flashcards-checkbox').checked = false;
    }
}
function toggleReverseFields() {
    const enabled = document.querySelector('input[name="reverse_enabled"]').checked;
    document.getElementById('reverse-fields').classList.toggle('hidden', !enabled);
}
function toggleAiPrompt() {
    document.getElementById('ai-prompt-box').classList.toggle('hidden');
}
function toggleManualBuilder() {
    document.getElementById('manual-builder-box').classList.toggle('hidden');
}

// Manuell radbyggare — samma fält/mönster som admin/edit.php använder för att
// redigera ett befintligt quiz, återanvänt här för att bygga CSV-datan från grunden.
const GLOSSARY_ROW_HTML = `
    <div class="item-row border border-gray-400 rounded-lg p-3 bg-white">
        <div class="flex items-start gap-2 mb-2">
            <input type="text" data-field="mening" placeholder="Mening" class="flex-1 px-2 py-1 border border-gray-500 rounded text-sm">
            <button type="button" onclick="removeItemRow(this)" title="Ta bort" class="text-red-500 hover:text-red-700 shrink-0 px-1">🗑️</button>
        </div>
        <div class="grid grid-cols-2 gap-2 mb-2">
            <input type="text" data-field="ord" placeholder="Ord" class="px-2 py-1 border border-gray-500 rounded text-sm">
            <input type="text" data-field="oversattning" placeholder="Översättning" class="px-2 py-1 border border-gray-500 rounded text-sm">
        </div>
        <div class="grid grid-cols-3 gap-2 mb-2">
            <input type="text" data-field="fel1" placeholder="Fel 1" class="px-2 py-1 border border-gray-500 rounded text-xs">
            <input type="text" data-field="fel2" placeholder="Fel 2" class="px-2 py-1 border border-gray-500 rounded text-xs">
            <input type="text" data-field="fel3" placeholder="Fel 3" class="px-2 py-1 border border-gray-500 rounded text-xs">
        </div>
        <div class="grid grid-cols-3 gap-2">
            <input type="text" data-field="ofel1" placeholder="Omvänt fel 1 (valfri)" class="px-2 py-1 border border-gray-500 rounded text-xs">
            <input type="text" data-field="ofel2" placeholder="Omvänt fel 2 (valfri)" class="px-2 py-1 border border-gray-500 rounded text-xs">
            <input type="text" data-field="ofel3" placeholder="Omvänt fel 3 (valfri)" class="px-2 py-1 border border-gray-500 rounded text-xs">
        </div>
    </div>`;
const FACT_ROW_HTML = `
    <div class="item-row border border-gray-400 rounded-lg p-3 bg-white">
        <div class="flex items-start gap-2 mb-2">
            <input type="text" data-field="begrepp" placeholder="Begrepp" class="flex-1 px-2 py-1 border border-gray-500 rounded text-sm">
            <button type="button" onclick="removeItemRow(this)" title="Ta bort" class="text-red-500 hover:text-red-700 shrink-0 px-1">🗑️</button>
        </div>
        <textarea data-field="beskrivning" placeholder="Beskrivning" rows="2" class="w-full px-2 py-1 border border-gray-500 rounded text-sm mb-2"></textarea>
        <div class="grid grid-cols-3 gap-2">
            <input type="text" data-field="ffel1" placeholder="Fel 1" class="px-2 py-1 border border-gray-500 rounded text-xs">
            <input type="text" data-field="ffel2" placeholder="Fel 2" class="px-2 py-1 border border-gray-500 rounded text-xs">
            <input type="text" data-field="ffel3" placeholder="Fel 3" class="px-2 py-1 border border-gray-500 rounded text-xs">
        </div>
    </div>`;
const GLOSSARY_FIELDS = ['mening', 'ord', 'oversattning', 'fel1', 'fel2', 'fel3', 'ofel1', 'ofel2', 'ofel3'];
const FACT_FIELDS = ['begrepp', 'beskrivning', 'ffel1', 'ffel2', 'ffel3'];

function addItemRow() {
    const container = document.getElementById('items-editor');
    const wrapper = document.createElement('div');
    wrapper.innerHTML = document.getElementById('type-input').value === 'glossary' ? GLOSSARY_ROW_HTML : FACT_ROW_HTML;
    container.appendChild(wrapper.firstElementChild);
    updateManualBuilderCount();
}
function removeItemRow(button) {
    button.closest('.item-row').remove();
    updateManualBuilderCount();
}
function updateManualBuilderCount() {
    document.getElementById('manual-builder-count').textContent = document.querySelectorAll('#items-editor .item-row').length;
}
function buildCsvFromRows() {
    const fields = document.getElementById('type-input').value === 'glossary' ? GLOSSARY_FIELDS : FACT_FIELDS;
    const rows = document.querySelectorAll('#items-editor .item-row');
    const lines = [];
    rows.forEach(row => {
        const values = fields.map(f => {
            const el = row.querySelector('[data-field="' + f + '"]');
            return (el.value || '').trim().replace(/;/g, ',');
        });
        lines.push(values.join(';'));
    });
    return lines.join('\n');
}
function prepareCreateSubmit() {
    const textarea = document.querySelector('#create-quiz-form textarea[name="csv_data"]');
    const rows = document.querySelectorAll('#items-editor .item-row');
    if (rows.length > 0) {
        textarea.value = buildCsvFromRows();
    }
    if (!textarea.value.trim()) {
        alert('Fyll i CSV-data eller lägg till minst en rad manuellt.');
        return false;
    }
    return true;
}
function copyAiPrompt() {
    const type = document.getElementById('type-input').value;
    const el = document.getElementById('prompt-' + type);
    navigator.clipboard.writeText(el.innerText).then(() => alert('Prompt kopierad! Klistra in den i din AI-chatt.'));
}

// Vid ett sticky-återladdat formulär (efter ett misslyckat försök): visa
// rätt typ-knapp/CSV-hint/inställningsfält utifrån de återställda värdena.
selectType(document.getElementById('type-input').value, true);
toggleReverseFields();
</script>
</body>
</html>
