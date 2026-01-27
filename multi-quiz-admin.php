<?php
require_once 'config.php';
requireTeacher();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();
}

$csrf_field = csrfField();
$teacher_id = getCurrentTeacherID();
$teacher_name = $_SESSION['teacher_name'] ?? 'Lärare';

// Läs multi-quiz data
$multi_quizzes_file = DATA_DIR . 'multi_quizzes.json';
$multi_quizzes = file_exists($multi_quizzes_file) ? json_decode(file_get_contents($multi_quizzes_file), true) : [];

// Filtrera bara denna lärarens multi-quizzes
$my_multi_quizzes = array_filter($multi_quizzes, function($mq) use ($teacher_id) {
    return $mq['teacher_id'] === $teacher_id;
});

// Sortera efter skapelsedatum (senaste först)
usort($my_multi_quizzes, function($a, $b) {
    return strtotime($b['created']) - strtotime($a['created']);
});

// Hantera quiz-skapande
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'create_multi_quiz') {
        $title = trim($_POST['title'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $grade = trim($_POST['grade'] ?? '');
        $tags = trim($_POST['tags'] ?? '');
        $csv_data = $_POST['csv_data'] ?? '';
        
        // Vilka varianter ska skapas
        $create_glossary = isset($_POST['create_glossary']);
        $create_reverse_glossary = isset($_POST['create_reverse_glossary']);
        $create_flashcard = isset($_POST['create_flashcard']);
        $create_reverse_flashcard = isset($_POST['create_reverse_flashcard']);
        $create_quiz = isset($_POST['create_quiz']);
        
        // Inställningar för varje variant
        $glossary_mc_count = intval($_POST['glossary_mc_count'] ?? 10);
        $glossary_text_count = intval($_POST['glossary_text_count'] ?? 5);
        $reverse_glossary_mc_count = intval($_POST['reverse_glossary_mc_count'] ?? 10);
        $reverse_glossary_text_count = intval($_POST['reverse_glossary_text_count'] ?? 5);
        $quiz_mc_count = intval($_POST['quiz_mc_count'] ?? 10);
        
        if ($title && $csv_data) {
            // Parsa CSV-data
            $lines = explode("\n", $csv_data);
            $items = [];
            $has_header = false;
            
            foreach ($lines as $i => $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Första raden är header
                if ($i === 0 || !$has_header) {
                    $has_header = true;
                    continue;
                }
                
                // Parsa rad: Begrepp;Beskrivning;Exempelmening;Översättning;Fråga;Felsvar1;Felsvar2;...
                $parts = str_getcsv($line, ';');
                
                if (count($parts) >= 2) {
                    $item = [
                        'concept' => trim($parts[0] ?? ''),
                        'description' => trim($parts[1] ?? ''),
                        'example_sentence' => trim($parts[2] ?? ''),
                        'translation' => trim($parts[3] ?? ''),
                        'question' => trim($parts[4] ?? ''),
                        'wrong_answers' => []
                    ];
                    
                    // Samla alla felsvar (från index 5 och framåt)
                    for ($j = 5; $j < count($parts); $j++) {
                        $wrong = trim($parts[$j]);
                        if (!empty($wrong)) {
                            $item['wrong_answers'][] = $wrong;
                        }
                    }
                    
                    $items[] = $item;
                }
            }
            
            if (count($items) > 0) {
                $multi_quiz_id = generateID('mq_');
                
                $multi_quizzes[$multi_quiz_id] = [
                    'id' => $multi_quiz_id,
                    'title' => $title,
                    'subject' => $subject,
                    'grade' => $grade,
                    'tags' => $tags,
                    'teacher_id' => $teacher_id,
                    'teacher_name' => $teacher_name,
                    'created' => date('Y-m-d H:i:s'),
                    'items' => $items,
                    'variants' => [
                        'glossary' => $create_glossary ? ['mc_count' => $glossary_mc_count, 'text_count' => $glossary_text_count] : null,
                        'reverse_glossary' => $create_reverse_glossary ? ['mc_count' => $reverse_glossary_mc_count, 'text_count' => $reverse_glossary_text_count] : null,
                        'flashcard' => $create_flashcard ? true : null,
                        'reverse_flashcard' => $create_reverse_flashcard ? true : null,
                        'quiz' => $create_quiz ? ['mc_count' => $quiz_mc_count] : null,
                    ]
                ];
                
                writeJSON($multi_quizzes_file, $multi_quizzes);
                
                $success = "Multi-quiz skapad! " . count($items) . " objekt laddades. ID: $multi_quiz_id";
                
                // Uppdatera listan
                $my_multi_quizzes = array_filter($multi_quizzes, function($mq) use ($teacher_id) {
                    return $mq['teacher_id'] === $teacher_id;
                });
                usort($my_multi_quizzes, function($a, $b) {
                    return strtotime($b['created']) - strtotime($a['created']);
                });
            } else {
                $error = "Inga giltiga objekt hittades i CSV-datan";
            }
        } else {
            $error = "Titel och CSV-data krävs";
        }
    }
    
    if ($action === 'delete_multi_quiz') {
        $mq_id = $_POST['mq_id'] ?? '';
        if (isset($multi_quizzes[$mq_id]) && $multi_quizzes[$mq_id]['teacher_id'] === $teacher_id) {
            unset($multi_quizzes[$mq_id]);
            writeJSON($multi_quizzes_file, $multi_quizzes);
            $success = "Multi-quiz raderad!";
            
            $my_multi_quizzes = array_filter($multi_quizzes, function($mq) use ($teacher_id) {
                return $mq['teacher_id'] === $teacher_id;
            });
            usort($my_multi_quizzes, function($a, $b) {
                return strtotime($b['created']) - strtotime($a['created']);
            });
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multi-Quiz Admin - <?= htmlspecialchars($teacher_name) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .compact-card {
            transition: all 0.2s ease;
        }
        .compact-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .variant-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-indigo-50 via-purple-50 to-pink-50 min-h-screen">
    <div class="max-w-7xl mx-auto p-4">
        <!-- Tight Header -->
        <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-xl p-4 mb-4 border border-purple-100">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold bg-gradient-to-r from-purple-600 to-pink-600 bg-clip-text text-transparent">
                        🎯 Multi-Quiz Studio
                    </h1>
                    <p class="text-sm text-gray-600">Skapa flera quiz-varianter från samma data</p>
                </div>
                <div class="flex gap-2">
                    <a href="admin.php" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-2 rounded-lg text-sm font-medium transition">
                        ← Tillbaka till vanliga quiz
                    </a>
                    <a href="index.php?logout=1" class="bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded-lg text-sm font-medium transition">
                        Logga ut
                    </a>
                </div>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <div class="bg-green-50 border-l-4 border-green-500 text-green-700 px-4 py-3 rounded-lg mb-4 shadow">
                ✅ <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded-lg mb-4 shadow">
                ❌ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Compact Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <div class="bg-white/80 backdrop-blur rounded-xl shadow p-3 border border-purple-100">
                <div class="text-xs text-gray-500 font-medium">Multi-Quizzes</div>
                <div class="text-2xl font-bold text-purple-600"><?= count($my_multi_quizzes) ?></div>
            </div>
            <div class="bg-white/80 backdrop-blur rounded-xl shadow p-3 border border-blue-100">
                <div class="text-xs text-gray-500 font-medium">Totalt objekt</div>
                <div class="text-2xl font-bold text-blue-600">
                    <?= array_sum(array_map(function($mq) { return count($mq['items']); }, $my_multi_quizzes)) ?>
                </div>
            </div>
            <div class="bg-white/80 backdrop-blur rounded-xl shadow p-3 border border-green-100">
                <div class="text-xs text-gray-500 font-medium">Aktiva varianter</div>
                <div class="text-2xl font-bold text-green-600">
                    <?php
                        $total_variants = 0;
                        foreach ($my_multi_quizzes as $mq) {
                            foreach ($mq['variants'] as $v) {
                                if ($v !== null) $total_variants++;
                            }
                        }
                        echo $total_variants;
                    ?>
                </div>
            </div>
            <div class="bg-white/80 backdrop-blur rounded-xl shadow p-3 border border-pink-100">
                <div class="text-xs text-gray-500 font-medium">Senaste</div>
                <div class="text-sm font-bold text-pink-600">
                    <?= !empty($my_multi_quizzes) ? date('Y-m-d', strtotime($my_multi_quizzes[0]['created'])) : '-' ?>
                </div>
            </div>
        </div>

        <!-- Create New Multi-Quiz -->
        <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-xl p-5 mb-4 border border-purple-100">
            <h2 class="text-xl font-bold text-gray-800 mb-4">➕ Skapa nytt Multi-Quiz</h2>
            
            <form method="POST" class="space-y-4">
                <?= $csrf_field ?>
                <input type="hidden" name="action" value="create_multi_quiz">
                
                <!-- Basic Info -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Titel *</label>
                        <input type="text" name="title" required
                               placeholder="t.ex. Prepositioner"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Ämne</label>
                        <input type="text" name="subject"
                               placeholder="t.ex. Engelska"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Årskurs</label>
                        <input type="text" name="grade"
                               placeholder="t.ex. åk 7"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Taggar</label>
                        <input type="text" name="tags"
                               placeholder="Komma-separerade"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 text-sm">
                    </div>
                </div>

                <!-- CSV Data -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">CSV-data *</label>
                    <textarea name="csv_data" rows="8" required
                              placeholder="Begrepp/glosa;Beskrivning;Exempelmening;Översättning;Fråga;Felsvar1;Felsvar2;Felsvar3&#10;Hungry;när man inte ätit på länge;I'm very hungry today;Hungrig;Vad kallas det när man inte ätit på länge;mätt;trött;glad"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 text-sm font-mono"></textarea>
                    
                    <!-- AI Prompt Helper -->
                    <div class="mt-2 text-sm">
                        <button type="button" onclick="toggleAiPrompt()" class="flex items-center text-purple-600 hover:text-purple-800 font-medium text-xs">
                            <span class="mr-1">🤖</span> Behöver du hjälp att skapa CSV? Klicka här för AI-prompt
                        </button>
                        
                        <div id="ai-prompt-box" class="hidden mt-2 p-3 bg-purple-50 border border-purple-100 rounded-lg">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-xs font-bold text-purple-800">Kopiera denna prompt till ChatGPT/Claude:</span>
                                <button type="button" onclick="copyAiPrompt()" class="bg-purple-200 hover:bg-purple-300 text-purple-800 px-2 py-1 rounded text-xs transition">
                                    📋 Kopiera prompt
                                </button>
                            </div>
                            <pre id="prompt-text" class="text-xs bg-white p-2 rounded border border-gray-200 overflow-x-auto whitespace-pre-wrap text-gray-600">
Du är en expert på pedagogik. Jag vill skapa quiz-material.

Processen sker i två steg:

STEG 1: ANALYS & FÖRSLAG
1. Analysera texten/bilden jag bifogar.
2. Föreslå 10-20 relevanta begrepp/glosor.
3. Fråga mig om jag vill ändra något.

STEG 2: GENERERING (Efter mitt godkännande)
Skapa CSV-text med semikolon som separator.

FORMAT:
Begrepp/glosa;Beskrivning;Exempelmening;Översättning;Fråga;Felsvar1;Felsvar2;Felsvar3

REGLER:
- "Begrepp/glosa": Ordet som ska läras.
- "Beskrivning": Förklaring (för flashcards).
- "Exempelmening": Ordet i sammanhang (eller tomt).
- "Översättning": Bara för språkglosor (annars tomt ;;).
- "Fråga": Fråga där begreppet är svaret.
- "Felsvar": 3 trovärdiga felalternativ.
- Inga citattecken. Inga radbrytningar i celler.

Nu, invänta mitt material.
                            </pre>
                        </div>
                    </div>

                    <p class="text-xs text-gray-500 mt-2">
                        Format: <code class="bg-gray-100 px-1 rounded">Begrepp;Beskrivning;Exempelmening;Översättning;Fråga;Felsvar1;Felsvar2;...</code>
                    </p>
                </div>

                <script>
                    function toggleAiPrompt() {
                        const box = document.getElementById('ai-prompt-box');
                        box.classList.toggle('hidden');
                    }
                    
                    function copyAiPrompt() {
                        const text = document.getElementById('prompt-text').innerText;
                        navigator.clipboard.writeText(text).then(() => {
                            alert('📋 Prompt kopierad! Klistra in den i din AI-chatt.');
                        });
                    }
                </script>

                <!-- Variant Selection -->
                <div class="bg-gradient-to-r from-purple-50 to-pink-50 rounded-xl p-4 border border-purple-200">
                    <h3 class="font-bold text-gray-800 mb-3 text-sm">Välj varianter att skapa:</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                        <!-- Glosquiz -->
                        <div class="bg-white rounded-lg p-3 border border-gray-200">
                            <label class="flex items-center gap-2 mb-2">
                                <input type="checkbox" name="create_glossary" class="w-4 h-4 text-purple-600">
                                <span class="font-semibold text-sm">📚 Glosquiz</span>
                            </label>
                            <div class="ml-6 space-y-1">
                                <div class="flex items-center gap-2">
                                    <label class="text-xs text-gray-600">Flerval:</label>
                                    <input type="number" name="glossary_mc_count" value="10" min="0" max="50"
                                           class="w-16 px-2 py-1 border border-gray-300 rounded text-xs">
                                </div>
                                <div class="flex items-center gap-2">
                                    <label class="text-xs text-gray-600">Skrivsvar:</label>
                                    <input type="number" name="glossary_text_count" value="5" min="0" max="50"
                                           class="w-16 px-2 py-1 border border-gray-300 rounded text-xs">
                                </div>
                            </div>
                        </div>

                        <!-- Omvänd Glosquiz -->
                        <div class="bg-white rounded-lg p-3 border border-gray-200">
                            <label class="flex items-center gap-2 mb-2">
                                <input type="checkbox" name="create_reverse_glossary" class="w-4 h-4 text-blue-600">
                                <span class="font-semibold text-sm">🔄 Omvänd Glosquiz</span>
                            </label>
                            <div class="ml-6 space-y-1">
                                <div class="flex items-center gap-2">
                                    <label class="text-xs text-gray-600">Flerval:</label>
                                    <input type="number" name="reverse_glossary_mc_count" value="10" min="0" max="50"
                                           class="w-16 px-2 py-1 border border-gray-300 rounded text-xs">
                                </div>
                                <div class="flex items-center gap-2">
                                    <label class="text-xs text-gray-600">Skrivsvar:</label>
                                    <input type="number" name="reverse_glossary_text_count" value="5" min="0" max="50"
                                           class="w-16 px-2 py-1 border border-gray-300 rounded text-xs">
                                </div>
                            </div>
                        </div>

                        <!-- Flashcard -->
                        <div class="bg-white rounded-lg p-3 border border-gray-200">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="create_flashcard" class="w-4 h-4 text-green-600">
                                <span class="font-semibold text-sm">🗂️ Flashcard</span>
                            </label>
                            <p class="text-xs text-gray-500 ml-6 mt-1">Begrepp → Beskrivning</p>
                        </div>

                        <!-- Omvänd Flashcard -->
                        <div class="bg-white rounded-lg p-3 border border-gray-200">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="create_reverse_flashcard" class="w-4 h-4 text-yellow-600">
                                <span class="font-semibold text-sm">🔄 Omvänd Flashcard</span>
                            </label>
                            <p class="text-xs text-gray-500 ml-6 mt-1">Beskrivning → Begrepp</p>
                        </div>

                        <!-- Quiz -->
                        <div class="bg-white rounded-lg p-3 border border-gray-200">
                            <label class="flex items-center gap-2 mb-2">
                                <input type="checkbox" name="create_quiz" class="w-4 h-4 text-red-600">
                                <span class="font-semibold text-sm">❓ Vanligt Quiz</span>
                            </label>
                            <div class="ml-6">
                                <div class="flex items-center gap-2">
                                    <label class="text-xs text-gray-600">Flerval:</label>
                                    <input type="number" name="quiz_mc_count" value="10" min="0" max="50"
                                           class="w-16 px-2 py-1 border border-gray-300 rounded text-xs">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white px-6 py-3 rounded-lg font-bold shadow-lg transition transform hover:scale-[1.02]">
                    ✨ Skapa Multi-Quiz
                </button>
            </form>
        </div>

        <!-- Multi-Quiz List -->
        <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-xl p-5 border border-purple-100">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-bold text-gray-800">📚 Mina Multi-Quizzes</h2>
                <div class="text-sm text-gray-500">Sorterat: Senaste först</div>
            </div>

            <?php if (empty($my_multi_quizzes)): ?>
                <p class="text-gray-500 text-center py-12 text-sm">Inga multi-quizzes ännu. Skapa ditt första!</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($my_multi_quizzes as $mq): ?>
                        <div class="compact-card bg-white border border-gray-200 rounded-xl p-4">
                            <div class="flex justify-between items-start gap-4">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-2">
                                        <h3 class="text-lg font-bold text-gray-800 truncate"><?= htmlspecialchars($mq['title']) ?></h3>
                                        <span class="text-xs text-gray-400">•</span>
                                        <span class="text-xs text-gray-500"><?= count($mq['items']) ?> objekt</span>
                                    </div>
                                    
                                    <?php if (!empty($mq['subject']) || !empty($mq['grade']) || !empty($mq['tags'])): ?>
                                        <div class="flex flex-wrap gap-1.5 mb-2">
                                            <?php if (!empty($mq['subject'])): ?>
                                                <span class="variant-badge bg-blue-100 text-blue-700">
                                                    📖 <?= htmlspecialchars($mq['subject']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($mq['grade'])): ?>
                                                <span class="variant-badge bg-purple-100 text-purple-700">
                                                    🎓 <?= htmlspecialchars($mq['grade']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($mq['tags'])): ?>
                                                <?php foreach (explode(',', $mq['tags']) as $tag): ?>
                                                    <span class="variant-badge bg-gray-100 text-gray-600">
                                                        🏷️ <?= htmlspecialchars(trim($tag)) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="flex flex-wrap gap-1.5 mb-2">
                                        <?php if ($mq['variants']['glossary']): ?>
                                            <span class="variant-badge bg-purple-100 text-purple-700">📚 Glosquiz</span>
                                        <?php endif; ?>
                                        <?php if ($mq['variants']['reverse_glossary']): ?>
                                            <span class="variant-badge bg-blue-100 text-blue-700">🔄 Omvänd Glosquiz</span>
                                        <?php endif; ?>
                                        <?php if ($mq['variants']['flashcard']): ?>
                                            <span class="variant-badge bg-green-100 text-green-700">🗂️ Flashcard</span>
                                        <?php endif; ?>
                                        <?php if ($mq['variants']['reverse_flashcard']): ?>
                                            <span class="variant-badge bg-yellow-100 text-yellow-700">🔄 Omvänd Flashcard</span>
                                        <?php endif; ?>
                                        <?php if ($mq['variants']['quiz']): ?>
                                            <span class="variant-badge bg-red-100 text-red-700">❓ Quiz</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="text-xs text-gray-500">
                                        Skapad <?= date('Y-m-d H:i', strtotime($mq['created'])) ?>
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2">
                                    <a href="multi-quiz-student.php?id=<?= $mq['id'] ?>" target="_blank"
                                       class="bg-gradient-to-r from-purple-500 to-pink-500 hover:from-purple-600 hover:to-pink-600 text-white px-4 py-2 rounded-lg text-sm font-medium text-center whitespace-nowrap transition shadow">
                                        🎯 Öppna elevvy
                                    </a>
                                    <button onclick="copyStudentLink('<?= $mq['id'] ?>')"
                                            class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                                        📋 Kopiera länk
                                    </button>
                                    <a href="multi-quiz-edit.php?id=<?= $mq['id'] ?>" 
                                       class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg text-sm font-medium text-center transition">
                                        ✏️ Redigera
                                    </a>
                                    <form method="POST" class="inline" onsubmit="return confirm('Är du säker på att du vill radera detta multi-quiz?')">
                                        <?= $csrf_field ?>
                                        <input type="hidden" name="action" value="delete_multi_quiz">
                                        <input type="hidden" name="mq_id" value="<?= $mq['id'] ?>">
                                        <button type="submit" class="w-full bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                                            🗑️ Radera
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
        function copyStudentLink(mqId) {
            const url = window.location.origin + window.location.pathname.replace('multi-quiz-admin.php', 'multi-quiz-student.php') + '?id=' + mqId;
            navigator.clipboard.writeText(url).then(() => {
                alert('✅ Länk kopierad till urklipp!\n\n' + url);
            });
        }
    </script>
</body>
</html>
