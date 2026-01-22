<?php
require_once 'config.php';
requireTeacher();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();
}

$csrf_field = csrfField();

$teacher_id = getCurrentTeacherID();
$teacher_name = $_SESSION['teacher_name'] ?? 'Lärare';

$teachers = readJSON(TEACHERS_FILE);
$quizzes = readJSON(QUIZZES_FILE);
$stats = readJSON(STATS_FILE);

// Filtrera bara denna lärarens quizzes
$my_quizzes = array_filter($quizzes, function($q) use ($teacher_id) {
    return $q['teacher_id'] === $teacher_id;
});

// Hantera quiz-skapande
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create_quiz_manual') {
        $title = trim($_POST['title'] ?? '');
        $questions_json = $_POST['questions'] ?? '';
        $quiz_type = $_POST['quiz_type'] ?? 'fact';
        $language = $_POST['language'] ?? 'sv';
        $spelling_mode = $_POST['spelling_mode'] ?? 'student_choice';
        $subject = trim($_POST['subject'] ?? '');
        $grade = trim($_POST['grade'] ?? '');
        $tags = trim($_POST['tags'] ?? '');
        $answer_mode = $_POST['answer_mode'] ?? 'hybrid';
        $required_phase1 = intval($_POST['required_phase1'] ?? 2);
        $required_phase2 = intval($_POST['required_phase2'] ?? 4);

        if ($title && $questions_json) {
            $questions = json_decode($questions_json, true);
            if ($questions && count($questions) > 0) {
                $quiz_id = generateID();
                $quizzes[$quiz_id] = [
                    'id' => $quiz_id,
                    'title' => $title,
                    'type' => $quiz_type,
                    'language' => $language,
                    'spelling_mode' => $spelling_mode,
                    'answer_mode' => $answer_mode,
                    'required_correct_phase1' => $required_phase1,
                    'required_correct_phase2' => $required_phase2,
                    'subject' => $subject,
                    'grade' => $grade,
                    'tags' => $tags,
                    'teacher_id' => $teacher_id,
                    'teacher_name' => $teacher_name,
                    'created' => date('Y-m-d H:i:s'),
                    'questions' => $questions
                ];
                writeJSON(QUIZZES_FILE, $quizzes);

                // Initiera statistik
                $stats[$quiz_id] = [
                    'total_attempts' => 0,
                    'completed' => 0,
                    'avg_time_seconds' => 0,
                    'avg_errors' => 0,
                    'attempts' => [],
                    'question_errors' => [],
                    'misspellings' => [] // För glosquiz: ['word' => ['misspelling1', 'misspelling2']]
                ];
                writeJSON(STATS_FILE, $stats);

                // Uppdatera utökad statistik
                updateStatsOnQuizCreate($teacher_id, $quiz_type);

                $success = "Quiz skapad! ID: $quiz_id";
                $my_quizzes = array_filter($quizzes, function($q) use ($teacher_id) {
                    return $q['teacher_id'] === $teacher_id;
                });
            }
        }
    }

    if ($action === 'delete_quiz') {
        $quiz_id = $_POST['quiz_id'] ?? '';
        if (isset($quizzes[$quiz_id]) && $quizzes[$quiz_id]['teacher_id'] === $teacher_id) {
            unset($quizzes[$quiz_id]);
            writeJSON(QUIZZES_FILE, $quizzes);

            // Ta bort statistik också
            if (isset($stats[$quiz_id])) {
                unset($stats[$quiz_id]);
                writeJSON(STATS_FILE, $stats);
            }

            $success = "Quiz raderad!";
            $my_quizzes = array_filter($quizzes, function($q) use ($teacher_id) {
                return $q['teacher_id'] === $teacher_id;
            });
        }
    }

    if ($action === 'toggle_quiz') {
        $quiz_id = $_POST['quiz_id'] ?? '';
        if (isset($quizzes[$quiz_id]) && $quizzes[$quiz_id]['teacher_id'] === $teacher_id) {
            $quizzes[$quiz_id]['active'] = !($quizzes[$quiz_id]['active'] ?? true);
            writeJSON(QUIZZES_FILE, $quizzes);

            $status = $quizzes[$quiz_id]['active'] ? 'aktiverat' : 'inaktiverat';
            $success = "Quiz $status!";
            $my_quizzes = array_filter($quizzes, function($q) use ($teacher_id) {
                return $q['teacher_id'] === $teacher_id;
            });
        }
    }
}

// Hantera CSV-uppladdning eller inklistrad CSV
if ((isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) || !empty($_POST['csv_paste'])) {
    $title = trim($_POST['csv_title'] ?? '');
    $quiz_type = $_POST['csv_quiz_type'] ?? 'fact';
    $language = $_POST['csv_language'] ?? 'sv';
    $spelling_mode = $_POST['csv_spelling_mode'] ?? 'student_choice';
    $answer_mode = $_POST['csv_answer_mode'] ?? 'hybrid';
    $required_phase1 = intval($_POST['csv_required_phase1'] ?? 2);
    $required_phase2 = intval($_POST['csv_required_phase2'] ?? 4);
    $subject = trim($_POST['csv_subject'] ?? '');
    $grade = trim($_POST['csv_grade'] ?? '');
    $tags = trim($_POST['csv_tags'] ?? '');

    if (!$title) {
        $error = "Du måste ange en titel för quizet";
    } else {
        $questions = [];

        // Om inklistrad CSV, skapa temporär fil
        if (!empty($_POST['csv_paste'])) {
            $file = tmpfile();
            fwrite($file, $_POST['csv_paste']);
            fseek($file, 0);
        } else {
            $file = $_FILES['csv_file']['tmp_name'];
            $file = fopen($file, 'r');
        }

        if ($file) {
            $row = 0;
            while (($data = fgetcsv($file, 1000, ',')) !== FALSE) {
                $row++;
                if ($row === 1) continue; // Skippa header

                // För glosquiz: minst 4 kolumner (Mening,Ord,Översättning,Fel1,...)
                // För faktaquiz: minst 3 kolumner (Fråga,Rätt svar,Fel1,...)
                if ($quiz_type === 'glossary' && count($data) >= 4) {
                    $sentence = trim($data[0]);
                    $word = trim($data[1]);
                    $translation = trim($data[2]);

                    // Samla alla felaktiga alternativ (från kolumn 3 och framåt)
                    $wrongOptions = [];
                    for ($i = 3; $i < count($data); $i++) {
                        $wrong = trim($data[$i]);
                        if ($wrong) {
                            $wrongOptions[] = $wrong;
                        }
                    }

                    if ($sentence && $word && $translation && count($wrongOptions) > 0) {
                        $questions[] = [
                            'question' => $sentence,
                            'word' => $word,
                            'answer' => $translation,
                            'options' => [$translation, ...$wrongOptions]
                        ];
                    }
                } elseif ($quiz_type === 'fact' && count($data) >= 3) {
                    $question = trim($data[0]);
                    $answer = trim($data[1]);

                    // Samla alla felaktiga alternativ (från kolumn 2 och framåt)
                    $wrongOptions = [];
                    for ($i = 2; $i < count($data); $i++) {
                        $wrong = trim($data[$i]);
                        if ($wrong) {
                            $wrongOptions[] = $wrong;
                        }
                    }

                    if ($question && $answer && count($wrongOptions) > 0) {
                        $questions[] = [
                            'question' => $question,
                            'answer' => $answer,
                            'options' => [$answer, ...$wrongOptions]
                        ];
                    }
                }
            }
            fclose($file);

            if (count($questions) > 0) {
                $quiz_id = generateID();
                $quizzes[$quiz_id] = [
                    'id' => $quiz_id,
                    'title' => $title,
                    'type' => $quiz_type,
                    'language' => $language,
                    'spelling_mode' => $spelling_mode,
                    'answer_mode' => $answer_mode,
                    'required_correct_phase1' => $required_phase1,
                    'required_correct_phase2' => $required_phase2,
                    'subject' => $subject,
                    'grade' => $grade,
                    'tags' => $tags,
                    'teacher_id' => $teacher_id,
                    'teacher_name' => $teacher_name,
                    'created' => date('Y-m-d H:i:s'),
                    'questions' => $questions
                ];
                writeJSON(QUIZZES_FILE, $quizzes);

                // Initiera statistik
                $stats[$quiz_id] = [
                    'total_attempts' => 0,
                    'completed' => 0,
                    'avg_time_seconds' => 0,
                    'avg_errors' => 0,
                    'attempts' => [],
                    'question_errors' => [],
                    'misspellings' => [] // För glosquiz: ['word' => ['misspelling1', 'misspelling2']]
                ];
                writeJSON(STATS_FILE, $stats);

                // Uppdatera utökad statistik
                updateStatsOnQuizCreate($teacher_id, $quiz_type);

                $success = "Quiz skapad från CSV! " . count($questions) . " frågor laddades. ID: $quiz_id";
                $my_quizzes = array_filter($quizzes, function($q) use ($teacher_id) {
                    return $q['teacher_id'] === $teacher_id;
                });
            } else {
                $error = "Inga giltiga frågor hittades i CSV-filen";
            }
        } else {
            $error = "Kunde inte läsa CSV-filen";
        }
    }
}

// Räkna statistik för denna lärare
$my_total_attempts = 0;
$my_total_completed = 0;
foreach ($my_quizzes as $qid => $quiz) {
    if (isset($stats[$qid])) {
        $my_total_attempts += $stats[$qid]['total_attempts'] ?? 0;
        $my_total_completed += $stats[$qid]['completed'] ?? 0;
    }
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lärar-admin - <?= htmlspecialchars($teacher_name) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-50 to-purple-50 min-h-screen p-4">
    <div class="max-w-6xl mx-auto">
        <!-- Header -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">👋 Hej <?= htmlspecialchars($teacher_name) ?>!</h1>
                    <p class="text-gray-500">Hantera dina quizzes här</p>
                </div>
                <div class="flex gap-2">
                    <a href="multi-quiz-admin.php" class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg">
                        🎯 Multi-Quiz
                    </a>
                    <a href="flashcards-admin.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        🗂️ Flashcard Admin
                    </a>
                    <a href="index.php?logout=1" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg">
                        Logga ut
                    </a>
                </div>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Statistik -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow p-6">
                <div class="text-gray-500 text-sm">Mina quizzes</div>
                <div class="text-3xl font-bold text-blue-600"><?= count($my_quizzes) ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-6">
                <div class="text-gray-500 text-sm">Totalt antal försök</div>
                <div class="text-3xl font-bold text-purple-600"><?= $my_total_attempts ?></div>
            </div>
            <div class="bg-white rounded-xl shadow p-6">
                <div class="text-gray-500 text-sm">Antal klarat</div>
                <div class="text-3xl font-bold text-green-600"><?= $my_total_completed ?></div>
            </div>
        </div>

        <!-- Quiz-typ väljare -->
        <div class="bg-gradient-to-r from-blue-50 to-green-50 rounded-xl shadow-lg p-6 mb-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-4 text-center">Vad vill du skapa idag?</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <button onclick="selectQuizMode('fact', 'hybrid')" id="mode-fact-hybrid"
                        class="px-6 py-6 rounded-xl border-3 transition-all transform hover:scale-105 bg-blue-500 text-white border-blue-600 shadow-lg">
                    <div class="text-4xl mb-2">🔄</div>
                    <div class="text-xl font-bold">Hybrid</div>
                    <div class="text-sm opacity-90 mt-2">Flerval → Fritext</div>
                </button>
                <button onclick="selectQuizMode('fact', 'multiple_choice')" id="mode-fact-multiple-choice"
                        class="px-6 py-6 rounded-xl border-3 transition-all transform hover:scale-105 bg-white border-gray-300 text-gray-600 hover:border-purple-400">
                    <div class="text-4xl mb-2">☑️</div>
                    <div class="text-xl font-bold">Flervalsfrågor</div>
                </button>
                <button onclick="selectQuizMode('fact', 'text_only')" id="mode-fact-text-only"
                        class="px-6 py-6 rounded-xl border-3 transition-all transform hover:scale-105 bg-white border-gray-300 text-gray-600 hover:border-orange-400">
                    <div class="text-4xl mb-2">✍️</div>
                    <div class="text-xl font-bold">Skrivsvar</div>
                </button>
                <button onclick="selectQuizMode('glossary', 'hybrid')" id="mode-glossary"
                        class="px-6 py-6 rounded-xl border-3 transition-all transform hover:scale-105 bg-white border-gray-300 text-gray-600 hover:border-green-400">
                    <div class="text-4xl mb-2">📚</div>
                    <div class="text-xl font-bold">Glosquiz</div>
                    <div class="text-sm opacity-70 mt-2">Språkträning med kontext</div>
                </button>
            </div>
        </div>

        <!-- Skapa nytt quiz -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">
                <span id="create-title">➕ Skapa nytt quiz</span>
            </h2>

            <!-- Tabs -->
            <div class="flex gap-2 mb-4 border-b">
                <button onclick="showTab('csv')" id="tab-csv" class="px-4 py-2 font-medium border-b-2 border-blue-500 text-blue-600">
                    CSV-uppladdning
                </button>
                <button onclick="showTab('paste')" id="tab-paste" class="px-4 py-2 font-medium text-gray-500 hover:text-gray-700">
                    Klistra in CSV
                </button>
                <button onclick="showTab('manual')" id="tab-manual" class="px-4 py-2 font-medium text-gray-500 hover:text-gray-700">
                    Manuell inmatning
                </button>
                <button onclick="showTab('batch')" id="tab-batch" class="px-4 py-2 font-medium text-gray-500 hover:text-gray-700">
                    📦 Batch-import
                </button>
            </div>

            <!-- CSV Tab -->
            <div id="content-csv" class="tab-content">
                <form method="POST" enctype="multipart/form-data" class="space-y-4" onsubmit="saveQuizSettings()">
                    <?= $csrf_field ?>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Quiz-titel</label>
                        <input type="text" name="csv_title" required
                               placeholder="t.ex. Arters anpassningar"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Ämne</label>
                            <input type="text" name="csv_subject"
                                   placeholder="t.ex. Biologi, Engelska"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Årskurs</label>
                            <input type="text" name="csv_grade"
                                   placeholder="t.ex. åk 6, åk 9"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Egna taggar</label>
                            <input type="text" name="csv_tags"
                                   placeholder="Komma-separerade"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Quiz-typ</label>
                            <select name="csv_quiz_type" id="csv_quiz_type" onchange="updateCSVFormat('csv')"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="fact">Fakta-quiz</option>
                                <option value="glossary">Glosquiz</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Språk</label>
                            <select name="csv_language" onchange="showUkrainianHelp(this.value)"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="sv">Svenska</option>
                                <option value="en">Engelska</option>
                                <option value="es">Spanska</option>
                                <option value="fr">Franska</option>
                                <option value="de">Tyska</option>
                                <option value="uk">Ukrainska</option>
                            </select>
                            <div id="ukrainian-help-csv" class="hidden mt-2">
                                <button onclick="toggleUkrainianInfo('csv')" class="text-sm text-gray-500 hover:text-gray-700">
                                    Uppläsning fungerar inte på ukrainska? Klicka här för hjälp
                                </button>
                                <div id="ukrainian-info-csv" class="hidden mt-2 p-3 bg-blue-50 border border-blue-200 rounded text-sm">
                                    <p class="font-bold mb-2">📱 iPad/iPhone:</p>
                                    <p class="mb-3">Inställningar → Tillgänglighet → Talat innehåll → Röster → Ukrainska</p>

                                    <p class="font-bold mb-2">🤖 Android:</p>
                                    <p class="mb-3">Inställningar → System → Språk och inmatning → Text-till-tal → Ladda ner ukrainska röster</p>

                                    <p class="font-bold mb-2">💻 Windows:</p>
                                    <p class="mb-3">Inställningar → Tid och språk → Tal → Lägg till röster → Ukrainska</p>

                                    <p class="font-bold mb-2">🍎 Mac:</p>
                                    <p>Systeminställningar → Tillgänglighet → Talat innehåll → Systemröst → Anpassa → Ukrainska</p>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Stavningsläge</label>
                            <select name="csv_spelling_mode"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="student_choice">Eleven väljer</option>
                                <option value="easy">Easy mode</option>
                                <option value="puritan">Puritan mode</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Svarsläge</label>
                            <select name="csv_answer_mode" id="csv_answer_mode"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="hybrid">Hybrid (Flerval → Fritext)</option>
                                <option value="multiple_choice">Bara flerval</option>
                                <option value="text_only">Bara fritext</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Rätt svar fas 1 (flerval)</label>
                            <input type="number" name="csv_required_phase1" value="2" min="1" max="10"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Rätt svar fas 2 (fritext)</label>
                            <input type="number" name="csv_required_phase2" value="2" min="1" max="10"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-medium mb-2">CSV-fil</label>
                        <input type="file" name="csv_file" accept=".csv" required
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                        <p id="csv_format_hint" class="text-sm text-gray-500 mt-2">
                            <strong>Fakta-quiz format:</strong> Fråga,Rätt svar,Fel alternativ 1,Fel alternativ 2,...<br>
                            <strong>Glosquiz format:</strong> Mening,Ord,Rätt översättning,Fel översättning 1,Fel översättning 2,...
                        </p>
                        <div class="mt-3">
                            <a href="templates/glossary-template.csv" download class="inline-flex items-center bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm">
                                📥 Ladda ner Excel-mall (glosquiz)
                            </a>
                        </div>
                    </div>
                    <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg">
                        Skapa quiz från CSV-fil
                    </button>
                </form>
            </div>

            <!-- Klistra in CSV Tab -->
            <div id="content-paste" class="tab-content hidden">
                <form method="POST" class="space-y-4" onsubmit="saveQuizSettings()">
                    <?= $csrf_field ?>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Quiz-titel</label>
                        <input type="text" name="csv_title" required
                               placeholder="t.ex. Arters anpassningar"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Quiz-typ</label>
                            <select name="csv_quiz_type" id="paste_quiz_type" onchange="updatePasteFormat()"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="fact">Fakta-quiz</option>
                                <option value="glossary">Glosquiz</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Språk</label>
                            <select name="csv_language" onchange="showUkrainianHelp(this.value)"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="sv">Svenska</option>
                                <option value="en">Engelska</option>
                                <option value="es">Spanska</option>
                                <option value="fr">Franska</option>
                                <option value="de">Tyska</option>
                                <option value="uk">Ukrainska</option>
                            </select>
                            <div id="ukrainian-help-paste" class="hidden mt-2">
                                <button onclick="toggleUkrainianInfo('paste')" type="button" class="text-sm text-gray-500 hover:text-gray-700">
                                    Uppläsning fungerar inte på ukrainska? Klicka här för hjälp
                                </button>
                                <div id="ukrainian-info-paste" class="hidden mt-2 p-3 bg-blue-50 border border-blue-200 rounded text-sm">
                                    <p class="font-bold mb-2">📱 iPad/iPhone:</p>
                                    <p class="mb-3">Inställningar → Tillgänglighet → Talat innehåll → Röster → Ukrainska</p>

                                    <p class="font-bold mb-2">🤖 Android:</p>
                                    <p class="mb-3">Inställningar → System → Språk och inmatning → Text-till-tal → Ladda ner ukrainska röster</p>

                                    <p class="font-bold mb-2">💻 Windows:</p>
                                    <p class="mb-3">Inställningar → Tid och språk → Tal → Lägg till röster → Ukrainska</p>

                                    <p class="font-bold mb-2">🍎 Mac:</p>
                                    <p>Systeminställningar → Tillgänglighet → Talat innehåll → Systemröst → Anpassa → Ukrainska</p>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Stavningsläge</label>
                            <select name="csv_spelling_mode"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="student_choice">Eleven väljer</option>
                                <option value="easy">Easy mode</option>
                                <option value="puritan">Puritan mode</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Svarsläge</label>
                            <select name="csv_answer_mode" id="paste_answer_mode"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="hybrid">Hybrid (Flerval → Fritext)</option>
                                <option value="multiple_choice">Bara flerval</option>
                                <option value="text_only">Bara fritext</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Rätt svar fas 1 (flerval)</label>
                            <input type="number" name="csv_required_phase1" value="2" min="1" max="10"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Rätt svar fas 2 (fritext)</label>
                            <input type="number" name="csv_required_phase2" value="2" min="1" max="10"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Klistra in CSV-data</label>
                        <textarea name="csv_paste" rows="10" required id="paste_textarea"
                                  placeholder="Fråga,Rätt svar,Fel alternativ 1,Fel alternativ 2,...&#10;Vad kallas djur som äter växter?,Växtätare,Köttätare,Allätare,Rovdjur"
                                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 font-mono text-sm"></textarea>
                        <div id="paste_format_hint" class="text-sm text-gray-600 mt-2 p-3 bg-blue-50 border border-blue-200 rounded">
                            <strong class="text-blue-800">📝 Fakta-quiz format:</strong><br>
                            <code class="bg-white px-2 py-1 rounded">Fråga,Rätt svar,Fel alternativ 1,Fel alternativ 2,...</code><br>
                            <span class="text-xs text-gray-500 mt-1 block">Exempel: Vad kallas djur som äter växter?,Växtätare,Köttätare,Allätare,Rovdjur</span>
                        </div>
                    </div>

                    <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg">
                        Skapa quiz från inklistrad text
                    </button>
                </form>
            </div>

            <!-- Manuell Tab -->
            <div id="content-manual" class="tab-content hidden">
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Quiz-titel</label>
                        <input type="text" id="manual_title"
                               placeholder="t.ex. Arters anpassningar"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Ämne</label>
                            <input type="text" id="manual_subject"
                                   placeholder="t.ex. Biologi, Engelska"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Årskurs</label>
                            <input type="text" id="manual_grade"
                                   placeholder="t.ex. åk 6, åk 9"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Egna taggar</label>
                            <input type="text" id="manual_tags"
                                   placeholder="Komma-separerade"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Quiz-typ</label>
                            <select id="manual_quiz_type" onchange="updateManualQuizType()"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="fact">Fakta-quiz</option>
                                <option value="glossary">Glosquiz</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Språk</label>
                            <select id="manual_language"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="sv">Svenska</option>
                                <option value="en">Engelska</option>
                                <option value="es">Spanska</option>
                                <option value="fr">Franska</option>
                                <option value="de">Tyska</option>
                                <option value="uk">Ukrainska</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Stavningsläge</label>
                            <select id="manual_spelling_mode"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="student_choice">Eleven väljer</option>
                                <option value="easy">Easy mode</option>
                                <option value="puritan">Puritan mode</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Svarsläge</label>
                            <select id="manual_answer_mode"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="hybrid">Hybrid (Flerval → Fritext)</option>
                                <option value="multiple_choice">Bara flerval</option>
                                <option value="text_only">Bara fritext</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Rätt svar fas 1 (flerval)</label>
                            <input type="number" id="manual_required_phase1" value="2" min="1" max="10"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2">Rätt svar fas 2 (fritext)</label>
                            <input type="number" id="manual_required_phase2" value="2" min="1" max="10"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div id="questions-container" class="space-y-4">
                        <!-- Frågor läggs till här -->
                    </div>

                    <button onclick="addQuestion()" type="button" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                        + Lägg till fråga
                    </button>

                    <button onclick="createManualQuiz()" type="button" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg">
                        Skapa quiz
                    </button>
                </div>
            </div>

            <!-- Batch-import Tab -->
            <div id="content-batch" class="tab-content hidden">
                <div class="bg-yellow-50 border border-yellow-300 rounded-lg p-4 mb-4">
                    <h3 class="font-bold text-yellow-800 mb-2">📦 Importera flera quiz samtidigt</h3>
                    <p class="text-sm text-yellow-700">
                        Ladda upp en Excel-fil med flera quiz separerade med tomma rader.<br>
                        <strong>Format:</strong> Rubrik → Frågor → Tom rad → Nästa rubrik → Frågor...
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Batch Fakta-quiz -->
                    <div class="border-2 border-blue-200 rounded-lg p-4">
                        <h3 class="text-lg font-bold text-blue-800 mb-3">📝 Batch-import: Fakta-quiz</h3>
                        <form id="batch-fact-form" class="space-y-3">
                            <?= $csrf_field ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Excel-fil (.csv)</label>
                                <input type="file" id="batch_fact_file" accept=".csv" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Svarsläge</label>
                                <select id="batch_fact_answer_mode" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                    <option value="hybrid">Hybrid (Flerval → Fritext)</option>
                                    <option value="multiple_choice">Bara flerval</option>
                                    <option value="text_only">Bara fritext</option>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Rätt svar fas 1</label>
                                    <input type="number" id="batch_fact_required_phase1" value="2" min="1" max="10"
                                           class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Rätt svar fas 2</label>
                                    <input type="number" id="batch_fact_required_phase2" value="2" min="1" max="10"
                                           class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                </div>
                            </div>
                            <div class="bg-gray-50 p-3 rounded text-xs">
                                <strong>Format per quiz:</strong><br>
                                Quiz rubrik<br>
                                Fråga,Rätt svar,Fel alternativ 1,Fel alternativ 2,...<br>
                                [Tom rad]<br>
                                Nästa quiz rubrik<br>
                                ...
                            </div>
                            <button type="button" onclick="processBatchFact()"
                                    class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">
                                Importera fakta-quiz
                            </button>
                        </form>
                    </div>

                    <!-- Batch Glosquiz -->
                    <div class="border-2 border-green-200 rounded-lg p-4">
                        <h3 class="text-lg font-bold text-green-800 mb-3">📚 Batch-import: Glosquiz</h3>
                        <form id="batch-gloss-form" class="space-y-3">
                            <?= $csrf_field ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Excel-fil (.csv)</label>
                                <input type="file" id="batch_gloss_file" accept=".csv" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Språk</label>
                                <select id="batch_gloss_language"
                                        class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                    <option value="sv">Svenska</option>
                                    <option value="en">Engelska</option>
                                    <option value="es">Spanska</option>
                                    <option value="fr">Franska</option>
                                    <option value="de">Tyska</option>
                                    <option value="uk">Ukrainska</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Stavningsläge</label>
                                <select id="batch_gloss_spelling"
                                        class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                    <option value="student_choice">Eleven väljer</option>
                                    <option value="easy">Easy mode</option>
                                    <option value="puritan">Puritan mode</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Svarsläge</label>
                                <select id="batch_gloss_answer_mode" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                    <option value="hybrid">Hybrid (Flerval → Fritext)</option>
                                    <option value="multiple_choice">Bara flerval</option>
                                    <option value="text_only">Bara fritext</option>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Rätt svar fas 1</label>
                                    <input type="number" id="batch_gloss_required_phase1" value="2" min="1" max="10"
                                           class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Rätt svar fas 2</label>
                                    <input type="number" id="batch_gloss_required_phase2" value="2" min="1" max="10"
                                           class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                </div>
                            </div>
                            <div class="bg-gray-50 p-3 rounded text-xs">
                                <strong>Format per quiz:</strong><br>
                                Quiz rubrik<br>
                                Mening,Ord,Rätt översättning,Fel översättning 1,Fel översättning 2,...<br>
                                [Tom rad]<br>
                                Nästa quiz rubrik<br>
                                ...
                            </div>
                            <button type="button" onclick="processBatchGloss()"
                                    class="w-full bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded">
                                Importera glosquiz
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- AI Prompt Generator -->
        <div class="bg-gradient-to-r from-purple-50 to-pink-50 border-2 border-purple-200 rounded-xl shadow-lg p-4 mb-6">
            <button onclick="toggleAIHelp()" class="w-full flex justify-between items-center">
                <h2 id="ai-help-title" class="text-xl font-bold text-gray-800">🤖 AI-hjälp: Generera quiz</h2>
                <span id="ai-help-icon" class="text-2xl">▼</span>
            </button>

            <div id="ai-help-content" class="hidden mt-4">
                <p id="ai-help-intro" class="text-gray-700 mb-4">Använd denna prompt i ChatGPT, Claude eller annan AI för att snabbt skapa quiz:</p>

                <div class="bg-white rounded-lg p-4 border border-purple-300 mb-4">
                <div class="flex justify-between items-center mb-2">
                    <span class="font-bold text-sm text-gray-600">PROMPT (klicka för att kopiera):</span>
                    <button onclick="copyPrompt()" class="bg-purple-500 hover:bg-purple-600 text-white text-sm px-4 py-1 rounded">
                        Kopiera prompt
                    </button>
                </div>
                <pre id="ai_prompt" class="text-sm bg-gray-50 p-3 rounded border border-gray-200 overflow-x-auto whitespace-pre-wrap" data-fact-prompt="Skapa ett kunskapsquiz i CSV-format för mina elever.

FORMAT:
Fråga,Rätt svar,Fel alternativ 1,Fel alternativ 2,Fel alternativ 3,...

REGLER:
- Varje rad = 1 fråga
- Minst 2 svarsalternativ (1 rätt + 1 fel)
- Max 10 svarsalternativ rekommenderat
- Felaktiga alternativ ska vara trovärdiga men felaktiga
- Inga citattecken runt text
- Inga radbrytningar i frågor/svar

EXEMPEL:
Vad kallas djur som äter växter?,Växtätare,Köttätare,Allätare,Rovdjur
Vilket organ pumpar blod?,Hjärtat,Lungorna,Levern,Njurarna
Vad heter Sveriges huvudstad?,Stockholm,Göteborg,Malmö,Uppsala

UPPGIFT:
Skapa 15 frågor om: [BESKRIV ÄMNET HÄR - t.ex. &quot;Sveriges kungar&quot;, &quot;Fotosyntesen&quot;, &quot;Andra världskriget&quot;]

VIKTIGT: Svara ENDAST med CSV-text. Inga kodblock, inga förklaringar, bara CSV-rader." data-glossary-prompt="Skapa glosor i CSV-format för mina elever.

FORMAT:
Exempelmening,Ord att lära,Rätt översättning,Fel översättning 1,Fel översättning 2,...

REGLER:
- Varje rad = 1 glos
- Meningen ska vara på målspråket (det språk eleven tränar)
- &quot;Ord att lära&quot; ska finnas i meningen
- Minst 2 översättningar (1 rätt + 1 fel)
- Felaktiga översättningar ska vara trovärdiga
- Inga citattecken, inga radbrytningar

EXEMPEL (Engelska → Svenska):
hello my name is Robert,name,namn,hej,Robert
the cat is sleeping,cat,katt,hund,sover
I like to read books,read,läsa,bok,gilla

UPPGIFT:
Skapa 15 glosor från [SPRÅK] till svenska med dessa ord:
[LISTA ORD HÄR - t.ex. &quot;cat, dog, house, tree, water, book, car, sun&quot;]

VIKTIGT: Svara ENDAST med CSV-text. Inga kodblock, inga förklaringar.">Skapa ett kunskapsquiz i CSV-format för mina elever.

FORMAT:
Fråga,Rätt svar,Fel alternativ 1,Fel alternativ 2,Fel alternativ 3,...

REGLER:
- Varje rad = 1 fråga
- Minst 2 svarsalternativ (1 rätt + 1 fel)
- Max 10 svarsalternativ rekommenderat
- Felaktiga alternativ ska vara trovärdiga men felaktiga
- Inga citattecken runt text
- Inga radbrytningar i frågor/svar

EXEMPEL:
Vad kallas djur som äter växter?,Växtätare,Köttätare,Allätare,Rovdjur
Vilket organ pumpar blod?,Hjärtat,Lungorna,Levern,Njurarna
Vad heter Sveriges huvudstad?,Stockholm,Göteborg,Malmö,Uppsala

UPPGIFT:
Skapa 15 frågor om: [BESKRIV ÄMNET HÄR - t.ex. "Sveriges kungar", "Fotosyntesen", "Andra världskriget"]

VIKTIGT: Svara ENDAST med CSV-text. Inga kodblock, inga förklaringar, bara CSV-rader.</pre>
            </div>

            <div class="bg-purple-100 border-l-4 border-purple-500 p-4 rounded">
                <p class="text-sm text-gray-700">
                    <strong>💡 Tips för bästa resultat:</strong>
                </p>
                <ul class="text-sm text-gray-700 list-disc list-inside mt-2 space-y-1">
                    <li><strong>Var specifik:</strong> "Sveriges kungar 1500-1800" ger bättre frågor än bara "kungar"</li>
                    <li><strong>Be om olika svårighetsgrad:</strong> Lägg till "för årskurs 6" eller "avancerad nivå"</li>
                    <li><strong>Testa först:</strong> Be om 5 frågor, granska, justera prompen, be sedan om 15-20 frågor</li>
                    <li><strong>Om AI lägger till kodblock:</strong> Kopiera bara CSV-raderna (inte ```csv eller ```)</li>
                    <li><strong>Klistra in direkt:</strong> Markera allt i "Klistra in CSV-data" och ersätt med AI:ns svar</li>
                </ul>
            </div>
            </div>
        </div>

        <!-- Lista över quizzes -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">📚 Mina quizzes (<?= count($my_quizzes) ?>)</h2>

            <?php if (!empty($my_quizzes)): ?>
                <!-- Filter -->
                <div class="mb-6 p-4 bg-gray-50 rounded-lg">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Typ</label>
                            <select id="filter-type" onchange="filterQuizzes()" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                <option value="">Alla</option>
                                <option value="fact">📝 Kunskapsquiz</option>
                                <option value="glossary">📚 Glosquiz</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Ämne</label>
                            <select id="filter-subject" onchange="filterQuizzes()" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                <option value="">Alla</option>
                                <?php
                                    $subjects = array_unique(array_filter(array_map(function($q) { return $q['subject'] ?? ''; }, $my_quizzes)));
                                    foreach ($subjects as $subject):
                                ?>
                                    <option value="<?= htmlspecialchars($subject) ?>"><?= htmlspecialchars($subject) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Årskurs</label>
                            <select id="filter-grade" onchange="filterQuizzes()" class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                                <option value="">Alla</option>
                                <?php
                                    $grades = array_unique(array_filter(array_map(function($q) { return $q['grade'] ?? ''; }, $my_quizzes)));
                                    foreach ($grades as $grade):
                                ?>
                                    <option value="<?= htmlspecialchars($grade) ?>"><?= htmlspecialchars($grade) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Sök</label>
                            <input type="text" id="filter-search" onkeyup="filterQuizzes()" placeholder="Titel eller taggar..."
                                   class="w-full px-3 py-2 border border-gray-300 rounded text-sm">
                        </div>
                    </div>
                    <div class="mt-2 text-right">
                        <button onclick="clearFilters()" class="text-xs text-gray-500 hover:text-gray-700 underline">
                            Rensa filter
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($my_quizzes)): ?>
                <p class="text-gray-500 text-center py-8">Inga quizzes ännu. Skapa ditt första!</p>
            <?php else: ?>
                <div id="quiz-list" class="space-y-2">
                    <?php foreach ($my_quizzes as $qid => $quiz): ?>
                        <?php
                            $quiz_stats = $stats[$qid] ?? ['total_attempts' => 0, 'completed' => 0];
                            $is_active = $quiz['active'] ?? true;
                            $card_class = $is_active ? 'border-gray-200' : 'border-gray-300 bg-gray-50';
                            $text_class = $is_active ? 'text-gray-800' : 'text-gray-400';
                        ?>
                        <div class="quiz-card border rounded-lg p-3 hover:shadow-md transition <?= $card_class ?>"
                             data-type="<?= $quiz['type'] ?>"
                             data-subject="<?= htmlspecialchars($quiz['subject'] ?? '') ?>"
                             data-grade="<?= htmlspecialchars($quiz['grade'] ?? '') ?>"
                             data-tags="<?= htmlspecialchars($quiz['tags'] ?? '') ?>"
                             data-title="<?= htmlspecialchars($quiz['title']) ?>">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-xl"><?= $quiz['type'] === 'glossary' ? '📚' : '📝' ?></span>
                                        <h3 class="text-lg font-bold <?= $text_class ?>"><?= htmlspecialchars($quiz['title']) ?></h3>
                                    </div>
                                    <p class="text-sm <?= $is_active ? 'text-gray-500' : 'text-gray-400' ?>">
                                        <?= count($quiz['questions']) ?> frågor •
                                        Skapad <?= date('Y-m-d', strtotime($quiz['created'])) ?>
                                        <?php if (!$is_active): ?>
                                            • <span class="text-red-500 font-medium">INAKTIV</span>
                                        <?php endif; ?>
                                    </p>
                                    <?php if (!empty($quiz['subject']) || !empty($quiz['grade']) || !empty($quiz['tags'])): ?>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <?php if (!empty($quiz['subject'])): ?>
                                                <span class="inline-block px-2 py-1 bg-blue-100 text-blue-700 text-xs rounded">
                                                    📖 <?= htmlspecialchars($quiz['subject']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($quiz['grade'])): ?>
                                                <span class="inline-block px-2 py-1 bg-purple-100 text-purple-700 text-xs rounded">
                                                    🎓 <?= htmlspecialchars($quiz['grade']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($quiz['tags'])): ?>
                                                <?php foreach (explode(',', $quiz['tags']) as $tag): ?>
                                                    <span class="inline-block px-2 py-1 bg-gray-100 text-gray-700 text-xs rounded">
                                                        🏷️ <?= htmlspecialchars(trim($tag)) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="mt-2 flex gap-4 text-sm">
                                        <span class="<?= $is_active ? 'text-gray-600' : 'text-gray-400' ?>">
                                            🎯 <?= $quiz_stats['total_attempts'] ?> försök
                                        </span>
                                        <span class="<?= $is_active ? 'text-green-600' : 'text-gray-400' ?>">
                                            ✅ <?= $quiz_stats['completed'] ?> klarat
                                        </span>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-1.5">
                                    <form method="POST" class="inline">
                                        <?= $csrf_field ?>
                                        <input type="hidden" name="action" value="toggle_quiz">
                                        <input type="hidden" name="quiz_id" value="<?= $qid ?>">
                                        <button type="submit" class="<?= $is_active ? 'bg-yellow-500 hover:bg-yellow-600' : 'bg-green-500 hover:bg-green-600' ?> text-white px-2 py-1 rounded text-xs whitespace-nowrap">
                                            <?= $is_active ? '👁️ Inaktivera' : '✅ Aktivera' ?>
                                        </button>
                                    </form>
                                    <a href="q/<?= $qid ?>.html" target="_blank"
                                       class="bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded text-center text-xs whitespace-nowrap">
                                        Öppna
                                    </a>
                                    <button onclick="copyLink('<?= $qid ?>')"
                                            class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-2 py-1 rounded text-xs whitespace-nowrap">
                                        Kopiera
                                    </button>
                                    <a href="stats.php?quiz_id=<?= $qid ?>"
                                       class="bg-purple-500 hover:bg-purple-600 text-white px-2 py-1 rounded text-center text-xs whitespace-nowrap">
                                        Statistik
                                    </a>
                                    <a href="edit-quiz.php?quiz_id=<?= $qid ?>"
                                       class="bg-amber-500 hover:bg-amber-600 text-white px-2 py-1 rounded text-center text-xs whitespace-nowrap">
                                        Redigera
                                    </a>
                                    <form method="POST" class="inline" onsubmit="return confirm('Är du säker?')">
                                        <?= $csrf_field ?>
                                        <input type="hidden" name="action" value="delete_quiz">
                                        <input type="hidden" name="quiz_id" value="<?= $qid ?>">
                                        <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded text-xs whitespace-nowrap">
                                            Radera
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const csrfToken = <?= json_encode(getCsrfToken()) ?>;
        let questionCount = 0;
        let currentQuizMode = 'fact'; // 'fact' or 'glossary'
        let currentAnswerMode = 'hybrid'; // 'hybrid', 'multiple_choice', 'text_only'

        // Spara quiz-inställningar till localStorage
        function saveQuizSettings() {
            const settings = {
                quizMode: currentQuizMode,
                answerMode: currentAnswerMode,
                language: document.getElementById('csv_language')?.value || 'sv',
                spellingMode: document.getElementById('csv_spelling_mode')?.value || 'student_choice',
                subject: document.getElementById('csv_subject')?.value || '',
                grade: document.getElementById('csv_grade')?.value || '',
                tags: document.getElementById('csv_tags')?.value || '',
                requiredPhase1: document.getElementById('csv_required_phase1')?.value || '2',
                requiredPhase2: document.getElementById('csv_required_phase2')?.value || '2'
            };
            localStorage.setItem('quizSettings', JSON.stringify(settings));
        }

        // Ladda sparade quiz-inställningar
        function loadSavedSettings() {
            const saved = localStorage.getItem('quizSettings');
            if (!saved) return;

            try {
                const settings = JSON.parse(saved);

                // Sätt quiz-typ och answer mode
                currentQuizMode = settings.quizMode || 'fact';
                currentAnswerMode = settings.answerMode || 'hybrid';
                selectQuizMode(currentQuizMode, currentAnswerMode);

                // Fyll i alla fält i alla flikar
                // CSV-fliken
                if (document.getElementById('csv_language')) document.getElementById('csv_language').value = settings.language;
                if (document.getElementById('csv_spelling_mode')) document.getElementById('csv_spelling_mode').value = settings.spellingMode;
                if (document.getElementById('csv_subject')) document.getElementById('csv_subject').value = settings.subject;
                if (document.getElementById('csv_grade')) document.getElementById('csv_grade').value = settings.grade;
                if (document.getElementById('csv_tags')) document.getElementById('csv_tags').value = settings.tags;
                if (document.getElementById('csv_required_phase1')) document.getElementById('csv_required_phase1').value = settings.requiredPhase1;
                if (document.getElementById('csv_required_phase2')) document.getElementById('csv_required_phase2').value = settings.requiredPhase2;

                // Paste-fliken (samma fält)
                const pasteFields = document.querySelectorAll('[id^="paste_"]');
                pasteFields.forEach(field => {
                    const csvId = field.id.replace('paste_', 'csv_');
                    const csvField = document.getElementById(csvId);
                    if (csvField && field.tagName === csvField.tagName) {
                        field.value = csvField.value;
                    }
                });

                // Manual-fliken
                if (document.getElementById('manual_language')) document.getElementById('manual_language').value = settings.language;
                if (document.getElementById('manual_spelling_mode')) document.getElementById('manual_spelling_mode').value = settings.spellingMode;
                if (document.getElementById('manual_subject')) document.getElementById('manual_subject').value = settings.subject;
                if (document.getElementById('manual_grade')) document.getElementById('manual_grade').value = settings.grade;
                if (document.getElementById('manual_tags')) document.getElementById('manual_tags').value = settings.tags;
                if (document.getElementById('manual_required_phase1')) document.getElementById('manual_required_phase1').value = settings.requiredPhase1;
                if (document.getElementById('manual_required_phase2')) document.getElementById('manual_required_phase2').value = settings.requiredPhase2;

            } catch (e) {
                console.error('Kunde inte ladda sparade inställningar:', e);
            }
        }

        // Ladda sparade inställningar när sidan laddas
        window.addEventListener('DOMContentLoaded', loadSavedSettings);

        function selectQuizMode(mode, answerMode) {
            currentQuizMode = mode;
            currentAnswerMode = answerMode;

            // Färgschema för varje knapp
            const buttonColors = {
                'mode-fact-hybrid': 'blue',
                'mode-fact-multiple-choice': 'purple',
                'mode-fact-text-only': 'orange',
                'mode-glossary': 'green'
            };

            // Uppdatera knapparnas utseende - alla får vit bakgrund först
            const buttons = ['mode-fact-hybrid', 'mode-fact-multiple-choice', 'mode-fact-text-only', 'mode-glossary'];
            buttons.forEach(btnId => {
                const btn = document.getElementById(btnId);
                if (btn) {
                    const hoverColor = buttonColors[btnId];
                    btn.className = `px-6 py-6 rounded-xl border-3 transition-all transform hover:scale-105 bg-white border-gray-300 text-gray-600 hover:border-${hoverColor}-400`;
                }
            });

            // Den valda knappen får färgad bakgrund
            const selectedBtnId = `mode-${mode === 'glossary' ? 'glossary' : 'fact-' + answerMode.replace('_', '-')}`;
            const selectedBtn = document.getElementById(selectedBtnId);
            if (selectedBtn) {
                const color = buttonColors[selectedBtnId];
                selectedBtn.className = `px-6 py-6 rounded-xl border-3 transition-all transform hover:scale-105 bg-${color}-500 text-white border-${color}-600 shadow-lg`;
            }

            // Uppdatera titel
            const titles = {
                'fact-hybrid': '➕ Skapa kunskapsquiz - Hybrid',
                'fact-multiple_choice': '➕ Skapa kunskapsquiz - Flervalsfrågor',
                'fact-text_only': '➕ Skapa kunskapsquiz - Skrivsvar',
                'glossary-hybrid': '➕ Skapa glosquiz'
            };
            document.getElementById('create-title').textContent = titles[`${mode}-${answerMode}`] || '➕ Skapa nytt quiz';

            // Sätt rätt quiz-typ i alla formulär
            const csvType = document.getElementById('csv_quiz_type');
            const pasteType = document.getElementById('paste_quiz_type');
            const manualType = document.getElementById('manual_quiz_type');

            if (csvType) csvType.value = mode;
            if (pasteType) pasteType.value = mode;
            if (manualType) manualType.value = mode;

            // Sätt rätt answer_mode i alla formulär
            const csvAnswerMode = document.getElementById('csv_answer_mode');
            const pasteAnswerMode = document.getElementById('paste_answer_mode');
            const manualAnswerMode = document.getElementById('manual_answer_mode');

            if (csvAnswerMode) csvAnswerMode.value = answerMode;
            if (pasteAnswerMode) pasteAnswerMode.value = answerMode;
            if (manualAnswerMode) manualAnswerMode.value = answerMode;

            // Uppdatera format-hints
            if (typeof updateCSVFormat === 'function') updateCSVFormat('csv');
            if (typeof updatePasteFormat === 'function') updatePasteFormat();

            // Uppdatera AI-hjälp
            updateAIPrompt(mode);

            // Töm manuella frågor om mode ändras
            if (document.getElementById('questions-container').children.length > 0) {
                document.getElementById('questions-container').innerHTML = '';
                questionCount = 0;
            }
        }

        function updateAIPrompt(mode) {
            const promptElement = document.getElementById('ai_prompt');
            const titleElement = document.getElementById('ai-help-title');
            const introElement = document.getElementById('ai-help-intro');

            if (mode === 'fact') {
                titleElement.textContent = '🤖 AI-hjälp: Generera kunskapsquiz';
                introElement.textContent = 'Använd denna prompt i ChatGPT, Claude eller annan AI för att snabbt skapa kunskapsquiz:';
                promptElement.textContent = promptElement.dataset.factPrompt;
            } else {
                titleElement.textContent = '🤖 AI-hjälp: Generera glosor';
                introElement.textContent = 'Använd denna prompt i ChatGPT, Claude eller annan AI för att snabbt skapa glosor:';
                promptElement.textContent = promptElement.dataset.glossaryPrompt;
            }
        }

        function showTab(tab) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('[id^="tab-"]').forEach(el => {
                el.classList.remove('border-blue-500', 'text-blue-600');
                el.classList.add('text-gray-500');
            });

            document.getElementById('content-' + tab).classList.remove('hidden');
            document.getElementById('tab-' + tab).classList.add('border-blue-500', 'text-blue-600');
            document.getElementById('tab-' + tab).classList.remove('text-gray-500');
        }

        function addQuestion() {
            questionCount++;
            const container = document.getElementById('questions-container');
            const quizType = document.getElementById('manual_quiz_type').value;
            const div = document.createElement('div');
            div.className = 'border border-gray-200 rounded-lg p-4';
            div.dataset.questionId = questionCount;

            if (quizType === 'glossary') {
                div.innerHTML = `
                    <div class="flex justify-between items-center mb-2">
                        <h4 class="font-bold">📚 Glosfråga ${questionCount}</h4>
                        <button type="button" onclick="this.parentElement.parentElement.remove()" class="text-red-600 hover:text-red-800">Radera</button>
                    </div>
                    <div class="space-y-2">
                        <input type="text" placeholder="Mening (t.ex. 'the cat is sleeping')" class="question-text w-full px-3 py-2 border rounded">
                        <input type="text" placeholder="Ord i meningen (t.ex. 'cat')" class="question-word w-full px-3 py-2 border rounded">
                        <input type="text" placeholder="Rätt översättning (t.ex. 'katt')" class="question-answer w-full px-3 py-2 border rounded">
                        <div class="wrong-options-container space-y-2">
                            <input type="text" placeholder="Fel översättning 1" class="question-wrong w-full px-3 py-2 border rounded">
                        </div>
                        <button type="button" onclick="addWrongOption(${questionCount})" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-1 rounded text-sm">+ Lägg till alternativ</button>
                    </div>
                `;
            } else {
                div.innerHTML = `
                    <div class="flex justify-between items-center mb-2">
                        <h4 class="font-bold">📝 Fråga ${questionCount}</h4>
                        <button type="button" onclick="this.parentElement.parentElement.remove()" class="text-red-600 hover:text-red-800">Radera</button>
                    </div>
                    <div class="space-y-2">
                        <input type="text" placeholder="Fråga" class="question-text w-full px-3 py-2 border rounded">
                        <input type="text" placeholder="Rätt svar" class="question-answer w-full px-3 py-2 border rounded">
                        <div class="wrong-options-container space-y-2">
                            <input type="text" placeholder="Fel svar 1" class="question-wrong w-full px-3 py-2 border rounded">
                        </div>
                        <button type="button" onclick="addWrongOption(${questionCount})" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-3 py-1 rounded text-sm">+ Lägg till alternativ</button>
                    </div>
                `;
            }
            container.appendChild(div);
        }

        function addWrongOption(questionId) {
            const questionDiv = document.querySelector(`[data-question-id="${questionId}"]`);
            if (!questionDiv) return;

            const container = questionDiv.querySelector('.wrong-options-container');
            const currentCount = container.querySelectorAll('.question-wrong').length;

            const wrapper = document.createElement('div');
            wrapper.className = 'flex gap-2';
            wrapper.innerHTML = `
                <input type="text" placeholder="Fel svar ${currentCount + 1}" class="question-wrong flex-1 px-3 py-2 border rounded">
                <button type="button" onclick="this.parentElement.remove()" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm">Ta bort</button>
            `;
            container.appendChild(wrapper);
        }

        function updateManualQuizType() {
            // Rensa befintliga frågor när quiz-typ ändras
            const container = document.getElementById('questions-container');
            if (container.children.length > 0) {
                if (confirm('Byter du quiz-typ kommer alla tillagda frågor att tas bort. Fortsätta?')) {
                    container.innerHTML = '';
                    questionCount = 0;
                } else {
                    // Återställ till föregående val - skippa för nu
                }
            }
        }

        function createManualQuiz() {
            const title = document.getElementById('manual_title').value.trim();
            const quizType = document.getElementById('manual_quiz_type').value;
            const language = document.getElementById('manual_language').value;
            const spellingMode = document.getElementById('manual_spelling_mode').value;
            const answerMode = document.getElementById('manual_answer_mode').value;
            const requiredPhase1 = document.getElementById('manual_required_phase1').value;
            const requiredPhase2 = document.getElementById('manual_required_phase2').value;
            const subject = document.getElementById('manual_subject').value.trim();
            const grade = document.getElementById('manual_grade').value.trim();
            const tags = document.getElementById('manual_tags').value.trim();

            if (!title) {
                alert('Du måste ange en titel');
                return;
            }

            const questions = [];
            document.querySelectorAll('#questions-container > div').forEach(div => {
                if (quizType === 'glossary') {
                    const question = div.querySelector('.question-text').value.trim();
                    const word = div.querySelector('.question-word').value.trim();
                    const answer = div.querySelector('.question-answer').value.trim();

                    // Samla alla felaktiga alternativ
                    const wrongOptions = [];
                    div.querySelectorAll('.question-wrong').forEach(input => {
                        const val = input.value.trim();
                        if (val) wrongOptions.push(val);
                    });

                    if (question && word && answer && wrongOptions.length > 0) {
                        questions.push({
                            question: question,
                            word: word,
                            answer: answer,
                            options: [answer, ...wrongOptions]
                        });
                    }
                } else {
                    const question = div.querySelector('.question-text').value.trim();
                    const answer = div.querySelector('.question-answer').value.trim();

                    // Samla alla felaktiga alternativ
                    const wrongOptions = [];
                    div.querySelectorAll('.question-wrong').forEach(input => {
                        const val = input.value.trim();
                        if (val) wrongOptions.push(val);
                    });

                    if (question && answer && wrongOptions.length > 0) {
                        questions.push({
                            question: question,
                            answer: answer,
                            options: [answer, ...wrongOptions]
                        });
                    }
                }
            });

            if (questions.length === 0) {
                alert('Du måste lägga till minst en fråga');
                return;
            }

            // Spara inställningar innan submit
            saveQuizSettings();

            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="create_quiz_manual">
                <input type="hidden" name="csrf_token" value="${csrfToken}">
                <input type="hidden" name="title" value="${title}">
                <input type="hidden" name="quiz_type" value="${quizType}">
                <input type="hidden" name="language" value="${language}">
                <input type="hidden" name="spelling_mode" value="${spellingMode}">
                <input type="hidden" name="answer_mode" value="${answerMode}">
                <input type="hidden" name="required_phase1" value="${requiredPhase1}">
                <input type="hidden" name="required_phase2" value="${requiredPhase2}">
                <input type="hidden" name="subject" value="${subject}">
                <input type="hidden" name="grade" value="${grade}">
                <input type="hidden" name="tags" value="${tags}">
                <input type="hidden" name="questions" value='${JSON.stringify(questions)}'>
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function copyPrompt() {
            const prompt = document.getElementById('ai_prompt').textContent;
            navigator.clipboard.writeText(prompt).then(() => {
                alert('Prompt kopierad! Nu kan du klistra in den i ChatGPT, Claude eller annan AI.');
            });
        }

        function copyLink(quizId) {
            const url = window.location.origin + window.location.pathname.replace('admin.php', '') + 'q/' + quizId + '.html';
            navigator.clipboard.writeText(url).then(() => {
                alert('Länk kopierad! ' + url);
            });
        }

        function filterQuizzes() {
            const typeFilter = document.getElementById('filter-type').value.toLowerCase();
            const subjectFilter = document.getElementById('filter-subject').value.toLowerCase();
            const gradeFilter = document.getElementById('filter-grade').value.toLowerCase();
            const searchFilter = document.getElementById('filter-search').value.toLowerCase();

            const cards = document.querySelectorAll('.quiz-card');
            cards.forEach(card => {
                const type = card.dataset.type.toLowerCase();
                const subject = card.dataset.subject.toLowerCase();
                const grade = card.dataset.grade.toLowerCase();
                const tags = card.dataset.tags.toLowerCase();
                const title = card.dataset.title.toLowerCase();

                const matchType = !typeFilter || type === typeFilter;
                const matchSubject = !subjectFilter || subject === subjectFilter;
                const matchGrade = !gradeFilter || grade === gradeFilter;
                const matchSearch = !searchFilter ||
                                   title.includes(searchFilter) ||
                                   tags.includes(searchFilter) ||
                                   subject.includes(searchFilter);

                if (matchType && matchSubject && matchGrade && matchSearch) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function clearFilters() {
            document.getElementById('filter-type').value = '';
            document.getElementById('filter-subject').value = '';
            document.getElementById('filter-grade').value = '';
            document.getElementById('filter-search').value = '';
            filterQuizzes();
        }

        function showUkrainianHelp(lang) {
            const helpCsv = document.getElementById('ukrainian-help-csv');
            if (lang === 'uk') {
                if (helpCsv) helpCsv.classList.remove('hidden');
            } else {
                if (helpCsv) helpCsv.classList.add('hidden');
            }
        }

        function toggleUkrainianInfo(type) {
            const info = document.getElementById('ukrainian-info-' + type);
            info.classList.toggle('hidden');
        }

        function toggleAIHelp() {
            const content = document.getElementById('ai-help-content');
            const icon = document.getElementById('ai-help-icon');
            content.classList.toggle('hidden');
            icon.textContent = content.classList.contains('hidden') ? '▼' : '▲';
        }

        function updateCSVFormat(type) {
            const quizType = document.getElementById('csv_quiz_type').value;
            const hint = document.getElementById('csv_format_hint');

            if (quizType === 'glossary') {
                hint.innerHTML = `
                    <strong class="text-green-800">📚 Glosquiz format:</strong> Mening,Ord,Rätt översättning,Fel översättning 1,Fel översättning 2,...<br>
                    <span class="text-xs text-gray-500 mt-1 block">Exempel: the cat is sleeping,cat,katt,hund,sover,säng</span>
                `;
            } else {
                hint.innerHTML = `
                    <strong class="text-blue-800">📝 Fakta-quiz format:</strong> Fråga,Rätt svar,Fel alternativ 1,Fel alternativ 2,...<br>
                    <span class="text-xs text-gray-500 mt-1 block">Exempel: Vad kallas djur som äter växter?,Växtätare,Köttätare,Allätare,Rovdjur</span>
                `;
            }
        }

        function updatePasteFormat() {
            const quizType = document.getElementById('paste_quiz_type').value;
            const hint = document.getElementById('paste_format_hint');
            const textarea = document.getElementById('paste_textarea');

            if (quizType === 'glossary') {
                hint.innerHTML = `
                    <strong class="text-green-800">📚 Glosquiz format:</strong><br>
                    <code class="bg-white px-2 py-1 rounded">Mening,Ord,Rätt översättning,Fel översättning 1,Fel översättning 2,...</code><br>
                    <span class="text-xs text-gray-500 mt-1 block">Exempel: the cat is sleeping,cat,katt,hund,sover,säng</span>
                `;
                hint.className = 'text-sm text-gray-600 mt-2 p-3 bg-green-50 border border-green-200 rounded';
                textarea.placeholder = 'Mening,Ord,Rätt översättning,Fel 1,Fel 2,...\nthe cat is sleeping,cat,katt,hund,sover,säng';
            } else {
                hint.innerHTML = `
                    <strong class="text-blue-800">📝 Fakta-quiz format:</strong><br>
                    <code class="bg-white px-2 py-1 rounded">Fråga,Rätt svar,Fel alternativ 1,Fel alternativ 2,...</code><br>
                    <span class="text-xs text-gray-500 mt-1 block">Exempel: Vad kallas djur som äter växter?,Växtätare,Köttätare,Allätare,Rovdjur</span>
                `;
                hint.className = 'text-sm text-gray-600 mt-2 p-3 bg-blue-50 border border-blue-200 rounded';
                textarea.placeholder = 'Fråga,Rätt svar,Fel alternativ 1,Fel alternativ 2,...\nVad kallas djur som äter växter?,Växtätare,Köttätare,Allätare,Rovdjur';
            }
        }

        // Lägg till en fråga automatiskt vid start
        if (document.getElementById('questions-container').children.length === 0) {
            // Vänta tills sidan laddats
        }

        // Batch-import för fakta-quiz
        async function processBatchFact() {
            const fileInput = document.getElementById('batch_fact_file');
            const file = fileInput.files[0];

            if (!file) {
                alert('Välj en fil först');
                return;
            }

            console.log('Batch import fakta - fil:', file.name);

            const formData = new FormData();
            formData.append('batch_fact_file', file);
            formData.append('action', 'batch_import_fact');
            formData.append('csrf_token', csrfToken);
            formData.append('answer_mode', document.getElementById('batch_fact_answer_mode').value);
            formData.append('required_phase1', document.getElementById('batch_fact_required_phase1').value);
            formData.append('required_phase2', document.getElementById('batch_fact_required_phase2').value);

            try {
                const response = await fetch('api/batch-import.php', {
                    method: 'POST',
                    body: formData
                });

                console.log('Response status:', response.status);
                const responseText = await response.text();
                console.log('Response text:', responseText);

                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (e) {
                    console.error('Failed to parse JSON:', e);
                    alert('Serverfel: Ogiltigt svar från servern. Se konsolen för detaljer.');
                    return;
                }

                console.log('Result:', result);

                if (result.success) {
                    alert(`Grattis! ${result.created} quiz har skapats.`);
                    location.reload();
                } else {
                    alert('Fel: ' + (result.error || 'Okänt fel'));
                }
            } catch (error) {
                console.error('Fetch error:', error);
                alert('Fel vid uppladdning: ' + error.message);
            }
        }

        // Batch-import för glosquiz
        async function processBatchGloss() {
            const fileInput = document.getElementById('batch_gloss_file');
            const file = fileInput.files[0];
            const language = document.getElementById('batch_gloss_language').value;
            const spelling = document.getElementById('batch_gloss_spelling').value;

            if (!file) {
                alert('Välj en fil först');
                return;
            }

            console.log('Batch import glos - fil:', file.name);

            const formData = new FormData();
            formData.append('batch_gloss_file', file);
            formData.append('language', language);
            formData.append('spelling_mode', spelling);
            formData.append('answer_mode', document.getElementById('batch_gloss_answer_mode').value);
            formData.append('required_phase1', document.getElementById('batch_gloss_required_phase1').value);
            formData.append('required_phase2', document.getElementById('batch_gloss_required_phase2').value);
            formData.append('action', 'batch_import_gloss');
            formData.append('csrf_token', csrfToken);

            try {
                const response = await fetch('api/batch-import.php', {
                    method: 'POST',
                    body: formData
                });

                console.log('Response status:', response.status);
                const responseText = await response.text();
                console.log('Response text:', responseText);

                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (e) {
                    console.error('Failed to parse JSON:', e);
                    alert('Serverfel: Ogiltigt svar från servern. Se konsolen för detaljer.');
                    return;
                }

                console.log('Result:', result);

                if (result.success) {
                    alert(`Grattis! ${result.created} quiz har skapats.`);
                    location.reload();
                } else {
                    alert('Fel: ' + (result.error || 'Okänt fel'));
                }
            } catch (error) {
                console.error('Fetch error:', error);
                alert('Fel vid uppladdning: ' + error.message);
            }
        }
    </script>
</body>
</html>
