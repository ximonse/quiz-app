<?php
require_once 'config.php';
$is_super_admin = isLoggedInAsSuperAdmin();
if (!$is_super_admin) {
    requireTeacher();
}

$teacher_id = $is_super_admin ? null : getCurrentTeacherID();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $expects_json = isset($_POST['action']) && in_array($_POST['action'], ['upload_image', 'delete_image'], true);
    requireValidCsrf($expects_json);
}

// Hantera AJAX-anrop FÖRST, innan någon data laddas
// Detta förhindrar att något outputtas före JSON-responsen

// Hantera bilduppladdning via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_image') {
    // Rensa all output som kan ha skapats
    ob_clean();

    header('Content-Type: application/json');
    header('Cache-Control: no-cache');

    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $result = uploadImage($_FILES['image']);
        echo json_encode($result);
    } else {
        $error_msg = 'Ingen bild uppladdad';
        if (isset($_FILES['image'])) {
            $error_msg .= ' (Error code: ' . $_FILES['image']['error'] . ')';
        }
        echo json_encode(['success' => false, 'error' => $error_msg]);
    }
    exit;
}

// Hantera bildradering via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_image') {
    // Rensa all output som kan ha skapats
    ob_clean();

    header('Content-Type: application/json');
    header('Cache-Control: no-cache');

    $filename = $_POST['filename'] ?? '';
    $success = deleteImage($filename);
    echo json_encode(['success' => $success]);
    exit;
}

// Nu ladda quiz-data (efter att AJAX-anrop hanterats)
$quiz_id = $_GET['quiz_id'] ?? '';
$quizzes = readJSON(QUIZZES_FILE);

// Kolla att quizet finns och tillhör läraren
if (!isset($quizzes[$quiz_id]) || (!$is_super_admin && $quizzes[$quiz_id]['teacher_id'] !== $teacher_id)) {
    header('Location: ' . ($is_super_admin ? 'super-admin.php' : 'admin.php'));
    exit;
}

$quiz = $quizzes[$quiz_id];

// Hantera uppdatering
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_quiz') {
    $updated_questions = json_decode($_POST['questions'], true);

    // Uppdatera alla quiz-inställningar
    $quizzes[$quiz_id]['title'] = trim($_POST['title'] ?? $quiz['title']);
    $quizzes[$quiz_id]['subject'] = trim($_POST['subject'] ?? '');
    $quizzes[$quiz_id]['grade'] = trim($_POST['grade'] ?? '');
    $quizzes[$quiz_id]['tags'] = trim($_POST['tags'] ?? '');
    $quizzes[$quiz_id]['language'] = $_POST['language'] ?? $quiz['language'];
    $quizzes[$quiz_id]['spelling_mode'] = $_POST['spelling_mode'] ?? $quiz['spelling_mode'];
    $quizzes[$quiz_id]['answer_mode'] = $_POST['answer_mode'] ?? $quiz['answer_mode'];
    $quizzes[$quiz_id]['required_correct_phase1'] = intval($_POST['required_phase1'] ?? $quiz['required_correct_phase1']);
    $quizzes[$quiz_id]['required_correct_phase2'] = intval($_POST['required_phase2'] ?? $quiz['required_correct_phase2']);
    if (isset($quiz['type']) && $quiz['type'] === 'glossary') {
        $quizzes[$quiz_id]['reverse_enabled'] = (($_POST['reverse_enabled'] ?? (($quiz['reverse_enabled'] ?? false) ? '1' : '0')) === '1');
        $quizzes[$quiz_id]['reverse_answer_mode'] = $_POST['reverse_answer_mode'] ?? ($quiz['reverse_answer_mode'] ?? 'hybrid');
        $quizzes[$quiz_id]['reverse_required_correct_phase1'] = intval($_POST['reverse_required_phase1'] ?? ($quiz['reverse_required_correct_phase1'] ?? 2));
        $quizzes[$quiz_id]['reverse_required_correct_phase2'] = intval($_POST['reverse_required_phase2'] ?? ($quiz['reverse_required_correct_phase2'] ?? 2));
    } else {
        $quizzes[$quiz_id]['reverse_enabled'] = false;
        $quizzes[$quiz_id]['reverse_answer_mode'] = 'hybrid';
        $quizzes[$quiz_id]['reverse_required_correct_phase1'] = 2;
        $quizzes[$quiz_id]['reverse_required_correct_phase2'] = 2;
    }

    if ($updated_questions && count($updated_questions) > 0) {
        $quizzes[$quiz_id]['questions'] = $updated_questions;
    }
    writeJSON(QUIZZES_FILE, $quizzes);
    $return_url = $is_super_admin ? 'super-admin.php?updated=1' : 'admin.php?updated=1';
    header('Location: ' . $return_url);
    exit;
}

$is_glossary = isset($quiz['type']) && $quiz['type'] === 'glossary';
$return_url = $is_super_admin ? 'super-admin.php' : 'admin.php';
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redigera - <?= htmlspecialchars($quiz['title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-blue-50 to-purple-50 min-h-screen p-4">
    <div class="max-w-4xl mx-auto">
        <!-- Header -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">✏️ Redigera quiz</h1>
                    <p class="text-gray-500"><?= htmlspecialchars($quiz['title']) ?></p>
                </div>
                <a href="<?= $return_url ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                    Avbryt
                </a>
            </div>
        </div>

        <!-- Quiz-inställningar -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Quiz-inställningar</h2>
            <div class="space-y-4">
                <div>
                    <label class="block text-gray-700 font-medium mb-2">Titel</label>
                    <input type="text" id="quiz_title" value="<?= htmlspecialchars($quiz['title']) ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Ämne</label>
                        <input type="text" id="quiz_subject" value="<?= htmlspecialchars($quiz['subject'] ?? '') ?>"
                               placeholder="t.ex. Biologi, Engelska"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Årskurs</label>
                        <input type="text" id="quiz_grade" value="<?= htmlspecialchars($quiz['grade'] ?? '') ?>"
                               placeholder="t.ex. åk 6, åk 9"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Taggar</label>
                        <input type="text" id="quiz_tags" value="<?= htmlspecialchars($quiz['tags'] ?? '') ?>"
                               placeholder="Komma-separerade"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Språk</label>
                        <select id="quiz_language" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="sv" <?= ($quiz['language'] ?? 'sv') === 'sv' ? 'selected' : '' ?>>Svenska</option>
                            <option value="en" <?= ($quiz['language'] ?? '') === 'en' ? 'selected' : '' ?>>Engelska</option>
                            <option value="es" <?= ($quiz['language'] ?? '') === 'es' ? 'selected' : '' ?>>Spanska</option>
                            <option value="fr" <?= ($quiz['language'] ?? '') === 'fr' ? 'selected' : '' ?>>Franska</option>
                            <option value="de" <?= ($quiz['language'] ?? '') === 'de' ? 'selected' : '' ?>>Tyska</option>
                            <option value="uk" <?= ($quiz['language'] ?? '') === 'uk' ? 'selected' : '' ?>>Ukrainska</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Stavningsläge</label>
                        <select id="quiz_spelling_mode" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="student_choice" <?= ($quiz['spelling_mode'] ?? 'student_choice') === 'student_choice' ? 'selected' : '' ?>>Eleven väljer</option>
                            <option value="easy" <?= ($quiz['spelling_mode'] ?? '') === 'easy' ? 'selected' : '' ?>>Easy mode</option>
                            <option value="puritan" <?= ($quiz['spelling_mode'] ?? '') === 'puritan' ? 'selected' : '' ?>>Puritan mode</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Svarsläge</label>
                        <select id="quiz_answer_mode" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="hybrid" <?= ($quiz['answer_mode'] ?? 'hybrid') === 'hybrid' ? 'selected' : '' ?>>Hybrid (Flerval → Fritext)</option>
                            <option value="multiple_choice" <?= ($quiz['answer_mode'] ?? '') === 'multiple_choice' ? 'selected' : '' ?>>Bara flerval</option>
                            <option value="text_only" <?= ($quiz['answer_mode'] ?? '') === 'text_only' ? 'selected' : '' ?>>Bara fritext</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Rätt svar fas 1 (flerval)</label>
                        <input type="number" id="quiz_required_phase1" value="<?= $quiz['required_correct_phase1'] ?? 2 ?>" min="1" max="10"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Rätt svar fas 2 (fritext)</label>
                        <input type="number" id="quiz_required_phase2" value="<?= $quiz['required_correct_phase2'] ?? 2 ?>" min="1" max="10"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <?php if ($is_glossary): ?>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4">
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Omvänd glosträning</label>
                        <select id="quiz_reverse_enabled" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="1" <?= ($quiz['reverse_enabled'] ?? false) ? 'selected' : '' ?>>Ja</option>
                            <option value="0" <?= !($quiz['reverse_enabled'] ?? false) ? 'selected' : '' ?>>Nej</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Omvänt svarsläge</label>
                        <select id="quiz_reverse_answer_mode" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="hybrid" <?= ($quiz['reverse_answer_mode'] ?? 'hybrid') === 'hybrid' ? 'selected' : '' ?>>Hybrid (Flerval → Fritext)</option>
                            <option value="multiple_choice" <?= ($quiz['reverse_answer_mode'] ?? '') === 'multiple_choice' ? 'selected' : '' ?>>Bara flerval</option>
                            <option value="text_only" <?= ($quiz['reverse_answer_mode'] ?? '') === 'text_only' ? 'selected' : '' ?>>Bara fritext</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Omvänt fas 1</label>
                        <input type="number" id="quiz_reverse_required_phase1" value="<?= $quiz['reverse_required_correct_phase1'] ?? 2 ?>" min="1" max="10"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Omvänt fas 2</label>
                        <input type="number" id="quiz_reverse_required_phase2" value="<?= $quiz['reverse_required_correct_phase2'] ?? 2 ?>" min="1" max="10"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Frågor -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Frågor</h2>

            <div id="questions-container" class="space-y-4">
                <!-- Fylls av JavaScript -->
            </div>

            <button onclick="addQuestion()" class="mt-4 bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                + Lägg till fråga
            </button>

            <div class="mt-6 flex gap-4">
                <button onclick="saveQuiz()" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg font-bold">
                    Spara ändringar
                </button>
                <a href="<?= $return_url ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg inline-block">
                    Avbryt
                </a>
            </div>
        </div>
    </div>

    <script>
        const csrfToken = <?= json_encode(getCsrfToken()) ?>;
        const isGlossary = <?= $is_glossary ? 'true' : 'false' ?>;
        let questions = <?= json_encode($quiz['questions'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        // --- Disable/enable fält baserat på inställningar ---
        function setFieldDisabled(elementId, disabled) {
            const el = document.getElementById(elementId);
            if (!el) return;
            el.disabled = disabled;
            const container = el.parentElement;
            if (container) {
                container.style.opacity = disabled ? '0.4' : '1';
                container.style.pointerEvents = disabled ? 'none' : '';
            }
        }

        function updatePhaseFields(answerModeId, phase1Id, phase2Id) {
            const mode = document.getElementById(answerModeId)?.value;
            if (!mode) return;
            setFieldDisabled(phase1Id, mode === 'text_only');
            setFieldDisabled(phase2Id, mode === 'multiple_choice');
        }

        function updateReverseFields(reverseEnabledId, reverseAnswerModeId, reverseFas1Id, reverseFas2Id) {
            const el = document.getElementById(reverseEnabledId);
            if (!el) return;
            const enabled = el.value === '1';
            setFieldDisabled(reverseAnswerModeId, !enabled);
            if (!enabled) {
                setFieldDisabled(reverseFas1Id, true);
                setFieldDisabled(reverseFas2Id, true);
            } else {
                updatePhaseFields(reverseAnswerModeId, reverseFas1Id, reverseFas2Id);
            }
        }

        (function initEditFieldToggles() {
            const am = document.getElementById('quiz_answer_mode');
            if (am) {
                am.addEventListener('change', () => updatePhaseFields('quiz_answer_mode', 'quiz_required_phase1', 'quiz_required_phase2'));
                updatePhaseFields('quiz_answer_mode', 'quiz_required_phase1', 'quiz_required_phase2');
            }
            const re = document.getElementById('quiz_reverse_enabled');
            if (re) {
                const update = () => updateReverseFields('quiz_reverse_enabled', 'quiz_reverse_answer_mode', 'quiz_reverse_required_phase1', 'quiz_reverse_required_phase2');
                re.addEventListener('change', update);
                const ram = document.getElementById('quiz_reverse_answer_mode');
                if (ram) ram.addEventListener('change', update);
                update();
            }
        })();

        function renderQuestions() {
            const container = document.getElementById('questions-container');
            container.innerHTML = '';

            questions.forEach((q, index) => {
                const div = document.createElement('div');
                div.className = 'border border-gray-200 rounded-lg p-4';

                if (isGlossary) {
                    div.innerHTML = `
                        <div class="flex justify-between items-start mb-3">
                            <span class="font-bold text-gray-700">Fråga ${index + 1}</span>
                            <button onclick="deleteQuestion(${index})" class="text-red-500 hover:text-red-700">🗑️ Radera</button>
                        </div>
                        <div class="space-y-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Mening</label>
                                <input type="text" value="${escapeHtml(q.question)}"
                                       onchange="updateQuestion(${index}, 'question', this.value)"
                                       class="w-full px-3 py-2 border border-gray-300 rounded">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Ord (som ska markeras)</label>
                                <input type="text" value="${escapeHtml(q.word || '')}"
                                       onchange="updateQuestion(${index}, 'word', this.value)"
                                       class="w-full px-3 py-2 border border-gray-300 rounded">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Rätt översättning</label>
                                <input type="text" value="${escapeHtml(q.answer)}"
                                       onchange="updateQuestion(${index}, 'answer', this.value); updateOptions(${index});"
                                       class="w-full px-3 py-2 border border-gray-300 rounded">
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Fel svar 1</label>
                                    <input type="text" value="${escapeHtml(q.options[1] || '')}"
                                           onchange="updateOption(${index}, 1, this.value)"
                                           class="w-full px-3 py-2 border border-gray-300 rounded">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Fel svar 2</label>
                                    <input type="text" value="${escapeHtml(q.options[2] || '')}"
                                           onchange="updateOption(${index}, 2, this.value)"
                                           class="w-full px-3 py-2 border border-gray-300 rounded">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Fel svar 3</label>
                                    <input type="text" value="${escapeHtml(q.options[3] || '')}"
                                           onchange="updateOption(${index}, 3, this.value)"
                                           class="w-full px-3 py-2 border border-gray-300 rounded">
                                </div>
                            </div>
                            <div class="mt-4 p-4 bg-gray-50 rounded border border-gray-200">
                                <label class="block text-sm font-medium text-gray-700 mb-2">📷 Bild (valfri)</label>
                                ${q.image ? `
                                    <div class="mb-2">
                                        <img src="data/images/${escapeHtml(q.image)}" class="max-w-xs rounded shadow" style="max-height: 200px;">
                                        <button onclick="removeImage(${index})" type="button" class="mt-2 bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm">
                                            🗑️ Ta bort bild
                                        </button>
                                    </div>
                                ` : ''}
                                <input type="file" accept="image/*" onchange="uploadImage(${index}, this)"
                                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                                <p class="text-xs text-gray-500 mt-1">Max 5MB. JPG, PNG, GIF eller WebP.</p>
                            </div>
                        </div>
                    `;
                } else {
                    div.innerHTML = `
                        <div class="flex justify-between items-start mb-3">
                            <span class="font-bold text-gray-700">Fråga ${index + 1}</span>
                            <button onclick="deleteQuestion(${index})" class="text-red-500 hover:text-red-700">🗑️ Radera</button>
                        </div>
                        <div class="space-y-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Fråga</label>
                                <input type="text" value="${escapeHtml(q.question)}"
                                       onchange="updateQuestion(${index}, 'question', this.value)"
                                       class="w-full px-3 py-2 border border-gray-300 rounded">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Rätt svar</label>
                                <input type="text" value="${escapeHtml(q.answer)}"
                                       onchange="updateQuestion(${index}, 'answer', this.value); updateOptions(${index});"
                                       class="w-full px-3 py-2 border border-gray-300 rounded">
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Fel svar 1</label>
                                    <input type="text" value="${escapeHtml(q.options[1] || '')}"
                                           onchange="updateOption(${index}, 1, this.value)"
                                           class="w-full px-3 py-2 border border-gray-300 rounded">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Fel svar 2</label>
                                    <input type="text" value="${escapeHtml(q.options[2] || '')}"
                                           onchange="updateOption(${index}, 2, this.value)"
                                           class="w-full px-3 py-2 border border-gray-300 rounded">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Fel svar 3</label>
                                    <input type="text" value="${escapeHtml(q.options[3] || '')}"
                                           onchange="updateOption(${index}, 3, this.value)"
                                           class="w-full px-3 py-2 border border-gray-300 rounded">
                                </div>
                            </div>
                            <div class="mt-4 p-4 bg-gray-50 rounded border border-gray-200">
                                <label class="block text-sm font-medium text-gray-700 mb-2">📷 Bild (valfri)</label>
                                ${q.image ? `
                                    <div class="mb-2">
                                        <img src="data/images/${escapeHtml(q.image)}" class="max-w-xs rounded shadow" style="max-height: 200px;">
                                        <button onclick="removeImage(${index})" type="button" class="mt-2 bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm">
                                            🗑️ Ta bort bild
                                        </button>
                                    </div>
                                ` : ''}
                                <input type="file" accept="image/*" onchange="uploadImage(${index}, this)"
                                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                                <p class="text-xs text-gray-500 mt-1">Max 5MB. JPG, PNG, GIF eller WebP.</p>
                            </div>
                        </div>
                    `;
                }

                container.appendChild(div);
            });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function updateQuestion(index, field, value) {
            questions[index][field] = value;
        }

        function updateOption(index, optionIndex, value) {
            questions[index].options[optionIndex] = value;
        }

        function updateOptions(index) {
            // Uppdatera options[0] när answer ändras
            questions[index].options[0] = questions[index].answer;
        }

        function deleteQuestion(index) {
            if (confirm('Är du säker på att du vill radera denna fråga?')) {
                questions.splice(index, 1);
                renderQuestions();
            }
        }

        function addQuestion() {
            if (isGlossary) {
                questions.push({
                    question: '',
                    word: '',
                    answer: '',
                    options: ['', '', '', '']
                });
            } else {
                questions.push({
                    question: '',
                    answer: '',
                    options: ['', '', '', '']
                });
            }
            renderQuestions();
        }

        async function uploadImage(index, input) {
            const file = input.files[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('image', file);
            formData.append('action', 'upload_image');
            formData.append('csrf_token', csrfToken);

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    questions[index].image = result.filename;
                    renderQuestions();
                } else {
                    alert('Fel vid uppladdning: ' + (result.error || 'Okänt fel'));
                }
            } catch (error) {
                alert('Fel vid uppladdning: ' + error.message);
            }
        }

        async function removeImage(index) {
            if (!confirm('Är du säker på att du vill ta bort bilden?')) return;

            const filename = questions[index].image;
            if (!filename) return;

            const formData = new FormData();
            formData.append('action', 'delete_image');
            formData.append('filename', filename);
            formData.append('csrf_token', csrfToken);

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    delete questions[index].image;
                    renderQuestions();
                } else {
                    alert('Kunde inte ta bort bilden');
                }
            } catch (error) {
                alert('Fel: ' + error.message);
            }
        }

        function saveQuiz() {
            // Validera titel
            const title = document.getElementById('quiz_title').value.trim();
            if (!title) {
                alert('Titel får inte vara tom');
                return;
            }

            // Validera frågor
            for (let i = 0; i < questions.length; i++) {
                const q = questions[i];
                if (!q.question || !q.answer) {
                    alert(`Fråga ${i + 1} saknar fråga eller svar`);
                    return;
                }
                if (isGlossary && !q.word) {
                    alert(`Fråga ${i + 1} saknar ord`);
                    return;
                }
                if (!q.options[1] || !q.options[2] || !q.options[3]) {
                    alert(`Fråga ${i + 1} saknar felaktiga svarsalternativ`);
                    return;
                }
                // Sätt options[0] till answer
                q.options[0] = q.answer;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="update_quiz">
                <input type="hidden" name="csrf_token" value="${csrfToken}">
                <input type="hidden" name="title" value="${escapeHtml(title)}">
                <input type="hidden" name="subject" value="${escapeHtml(document.getElementById('quiz_subject').value.trim())}">
                <input type="hidden" name="grade" value="${escapeHtml(document.getElementById('quiz_grade').value.trim())}">
                <input type="hidden" name="tags" value="${escapeHtml(document.getElementById('quiz_tags').value.trim())}">
                <input type="hidden" name="language" value="${document.getElementById('quiz_language').value}">
                <input type="hidden" name="spelling_mode" value="${document.getElementById('quiz_spelling_mode').value}">
                <input type="hidden" name="answer_mode" value="${document.getElementById('quiz_answer_mode').value}">
                <input type="hidden" name="required_phase1" value="${document.getElementById('quiz_required_phase1').value}">
                <input type="hidden" name="required_phase2" value="${document.getElementById('quiz_required_phase2').value}">
                <input type="hidden" name="reverse_enabled" value="${document.getElementById('quiz_reverse_enabled') ? document.getElementById('quiz_reverse_enabled').value : '0'}">
                <input type="hidden" name="reverse_answer_mode" value="${document.getElementById('quiz_reverse_answer_mode') ? document.getElementById('quiz_reverse_answer_mode').value : 'hybrid'}">
                <input type="hidden" name="reverse_required_phase1" value="${document.getElementById('quiz_reverse_required_phase1') ? document.getElementById('quiz_reverse_required_phase1').value : '2'}">
                <input type="hidden" name="reverse_required_phase2" value="${document.getElementById('quiz_reverse_required_phase2') ? document.getElementById('quiz_reverse_required_phase2').value : '2'}">
            `;
            document.body.appendChild(form);
            // Sätt questions via .value för att undvika HTML-escaping-problem med apostrofer
            const questionsInput = document.createElement('input');
            questionsInput.type = 'hidden';
            questionsInput.name = 'questions';
            questionsInput.value = JSON.stringify(questions);
            form.appendChild(questionsInput);
            form.submit();
        }

        // Initial render
        renderQuestions();
    </script>
</body>
</html>
