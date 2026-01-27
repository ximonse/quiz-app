<?php
require_once 'config.php';
requireTeacher();

$mq_id = $_GET['id'] ?? '';
$multi_quizzes = readJSON(DATA_DIR . 'multi_quizzes.json');

if (!isset($multi_quizzes[$mq_id])) {
    die("Multi-quiz hittades inte");
}

$mq = $multi_quizzes[$mq_id];
$success = '';

// Spara ändringar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mq['title'] = $_POST['title'] ?? $mq['title'];
    $mq['subject'] = $_POST['subject'] ?? $mq['subject'];
    $mq['grade'] = $_POST['grade'] ?? $mq['grade'];
    
    // Uppdatera varianter
    $mq['variants']['glossary'] = isset($_POST['var_glossary']);
    $mq['variants']['reverse_glossary'] = isset($_POST['var_reverse_glossary']);
    $mq['variants']['flashcard'] = isset($_POST['var_flashcard']);
    $mq['variants']['reverse_flashcard'] = isset($_POST['var_reverse_flashcard']);
    $mq['variants']['quiz'] = isset($_POST['var_quiz']);

    // Uppdatera items
    if (isset($_POST['items'])) {
        $mq['items'] = json_decode($_POST['items'], true);
    }

    $multi_quizzes[$mq_id] = $mq;
    writeJSON(DATA_DIR . 'multi_quizzes.json', $multi_quizzes);
    $success = "Ändringar sparade!";
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <title>Redigera Multi-Quiz - <?= htmlspecialchars($mq['title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-gray-100 min-h-screen p-4">
    <div class="max-w-7xl mx-auto">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">✏️ Redigera Multi-Quiz</h1>
            <a href="multi-quiz-admin.php" class="text-gray-600 hover:text-gray-900">Tillbaka</a>
        </div>

        <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?= $success ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="editForm">
            <!-- Grundinställningar -->
            <div class="bg-white p-6 rounded-xl shadow-sm mb-6">
                <h2 class="text-xl font-bold mb-4">Inställningar</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Titel</label>
                        <input type="text" name="title" value="<?= htmlspecialchars($mq['title']) ?>" class="w-full border rounded p-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Ämne</label>
                        <input type="text" name="subject" value="<?= htmlspecialchars($mq['subject']) ?>" class="w-full border rounded p-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Årskurs</label>
                        <input type="text" name="grade" value="<?= htmlspecialchars($mq['grade']) ?>" class="w-full border rounded p-2">
                    </div>
                </div>
                
                <h3 class="font-medium mb-2">Aktiva varianter:</h3>
                <div class="flex flex-wrap gap-4">
                    <label class="flex items-center space-x-2">
                        <input type="checkbox" name="var_glossary" <?= $mq['variants']['glossary'] ? 'checked' : '' ?> class="rounded text-purple-600">
                        <span>Glosor</span>
                    </label>
                    <label class="flex items-center space-x-2">
                        <input type="checkbox" name="var_reverse_glossary" <?= $mq['variants']['reverse_glossary'] ? 'checked' : '' ?> class="rounded text-purple-600">
                        <span>Omvända Glosor</span>
                    </label>
                    <label class="flex items-center space-x-2">
                        <input type="checkbox" name="var_flashcard" <?= $mq['variants']['flashcard'] ? 'checked' : '' ?> class="rounded text-purple-600">
                        <span>Flashcard</span>
                    </label>
                    <label class="flex items-center space-x-2">
                        <input type="checkbox" name="var_reverse_flashcard" <?= $mq['variants']['reverse_flashcard'] ? 'checked' : '' ?> class="rounded text-purple-600">
                        <span>Omvända Flashcard</span>
                    </label>
                    <label class="flex items-center space-x-2">
                        <input type="checkbox" name="var_quiz" <?= $mq['variants']['quiz'] ? 'checked' : '' ?> class="rounded text-purple-600">
                        <span>Quiz</span>
                    </label>
                </div>
            </div>

            <!-- Frågor / Rader -->
            <div class="bg-white p-6 rounded-xl shadow-sm mb-6 overflow-x-auto">
                <h2 class="text-xl font-bold mb-4">Innehåll</h2>
                <table class="w-full text-sm text-left">
                    <thead>
                        <tr class="bg-gray-50 border-b">
                            <th class="p-2">Begrepp / Glosa</th>
                            <th class="p-2">Beskrivning</th>
                            <th class="p-2">Exempelmening</th>
                            <th class="p-2">Översättning</th>
                            <th class="p-2">Fråga (Quiz)</th>
                            <th class="p-2 w-10"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                        <!-- Rader genereras med JS -->
                    </tbody>
                </table>
                <button type="button" onclick="addItem()" class="mt-4 text-purple-600 hover:text-purple-800 font-bold flex items-center">
                    <i data-lucide="plus" class="w-4 h-4 mr-1"></i> Lägg till rad
                </button>
            </div>

            <!-- Form Actions -->
            <div class="flex gap-4 sticky bottom-4 bg-white/90 backdrop-blur p-4 rounded-xl shadow border border-gray-200 justify-end">
                <a href="multi-quiz-admin.php" class="px-6 py-2 text-gray-600 hover:bg-gray-100 rounded-lg">Avbryt</a>
                <button type="button" onclick="saveData()" class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 font-bold shadow-lg">
                    Spara ändringar
                </button>
            </div>
            
            <input type="hidden" name="items" id="itemsInput">
        </form>
    </div>

    <script>
        lucide.createIcons();
        
        let items = <?= json_encode($mq['items']) ?>;
        const tbody = document.getElementById('itemsBody');

        function renderItems() {
            tbody.innerHTML = '';
            items.forEach((item, index) => {
                const tr = document.createElement('tr');
                tr.className = 'border-b hover:bg-gray-50';
                tr.innerHTML = `
                    <td class="p-2"><input type="text" value="${esc(item.concept)}" oninput="updateItem(${index}, 'concept', this.value)" class="w-full border rounded p-1"></td>
                    <td class="p-2"><input type="text" value="${esc(item.description)}" oninput="updateItem(${index}, 'description', this.value)" class="w-full border rounded p-1"></td>
                    <td class="p-2"><input type="text" value="${esc(item.example_sentence)}" oninput="updateItem(${index}, 'example_sentence', this.value)" class="w-full border rounded p-1"></td>
                    <td class="p-2"><input type="text" value="${esc(item.translation)}" oninput="updateItem(${index}, 'translation', this.value)" class="w-full border rounded p-1"></td>
                    <td class="p-2"><input type="text" value="${esc(item.question)}" oninput="updateItem(${index}, 'question', this.value)" class="w-full border rounded p-1"></td>
                    <td class="p-2 text-center">
                        <button type="button" onclick="removeItem(${index})" class="text-red-400 hover:text-red-600">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
            lucide.createIcons();
        }

        function updateItem(index, key, value) {
            items[index][key] = value;
        }

        function addItem() {
            items.push({
                concept: '', description: '', example_sentence: '', 
                translation: '', question: '', wrong_answers: ['','','']
            });
            renderItems();
        }

        function removeItem(index) {
            if(confirm('Ta bort rad?')) {
                items.splice(index, 1);
                renderItems();
            }
        }

        function saveData() {
            document.getElementById('itemsInput').value = JSON.stringify(items);
            document.getElementById('editForm').submit();
        }

        function esc(str) {
            return str ? str.replace(/"/g, '&quot;') : '';
        }

        renderItems();
    </script>
</body>
</html>
