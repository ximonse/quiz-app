<?php
require_once 'config.php';
requireTeacher();

$teacher_id = getCurrentTeacherID();

// Definiera flashcards-fil om den inte finns
if (!defined('FLASHCARDS_FILE')) {
    define('FLASHCARDS_FILE', DATA_DIR . 'flashcards.json');
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

// Nu ladda deck-data (efter att AJAX-anrop hanterats)
$deck_id = $_GET['deck_id'] ?? '';
$flashcards = readJSON(FLASHCARDS_FILE);

// Kolla att decket finns och tillhör läraren
if (!isset($flashcards[$deck_id]) || $flashcards[$deck_id]['teacher_id'] !== $teacher_id) {
    header('Location: flashcards-admin.php');
    exit;
}

$deck = $flashcards[$deck_id];

// Hantera uppdatering
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_deck') {
    $updated_cards = json_decode($_POST['cards'], true);

    // Uppdatera alla deck-inställningar
    $flashcards[$deck_id]['title'] = trim($_POST['title'] ?? $deck['title']);
    $flashcards[$deck_id]['subject'] = trim($_POST['subject'] ?? '');
    $flashcards[$deck_id]['grade'] = trim($_POST['grade'] ?? '');
    $flashcards[$deck_id]['tags'] = trim($_POST['tags'] ?? '');
    $flashcards[$deck_id]['language'] = $_POST['language'] ?? $deck['language'];

    if ($updated_cards && count($updated_cards) > 0) {
        $flashcards[$deck_id]['cards'] = $updated_cards;
        writeJSON(FLASHCARDS_FILE, $flashcards);
        header('Location: flashcards-admin.php?updated=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redigera - <?= htmlspecialchars($deck['title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-green-50 to-blue-50 min-h-screen p-4">
    <div class="max-w-4xl mx-auto">
        <!-- Header -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">✏️ Redigera flashcard-deck</h1>
                    <p class="text-gray-500"><?= htmlspecialchars($deck['title']) ?></p>
                </div>
                <a href="flashcards-admin.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                    Avbryt
                </a>
            </div>
        </div>

        <!-- Deck-inställningar -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Deck-inställningar</h2>
            <div class="space-y-4">
                <div>
                    <label class="block text-gray-700 font-medium mb-2">Titel</label>
                    <input type="text" id="deck_title" value="<?= htmlspecialchars($deck['title']) ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Ämne</label>
                        <input type="text" id="deck_subject" value="<?= htmlspecialchars($deck['subject'] ?? '') ?>"
                               placeholder="t.ex. Biologi, Engelska"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Årskurs</label>
                        <input type="text" id="deck_grade" value="<?= htmlspecialchars($deck['grade'] ?? '') ?>"
                               placeholder="t.ex. åk 6, åk 9"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2">Taggar</label>
                        <input type="text" id="deck_tags" value="<?= htmlspecialchars($deck['tags'] ?? '') ?>"
                               placeholder="Komma-separerade"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                    </div>
                </div>

                <div>
                    <label class="block text-gray-700 font-medium mb-2">Språk</label>
                    <select id="deck_language" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                        <option value="sv" <?= ($deck['language'] ?? 'sv') === 'sv' ? 'selected' : '' ?>>Svenska</option>
                        <option value="en" <?= ($deck['language'] ?? '') === 'en' ? 'selected' : '' ?>>Engelska</option>
                        <option value="es" <?= ($deck['language'] ?? '') === 'es' ? 'selected' : '' ?>>Spanska</option>
                        <option value="fr" <?= ($deck['language'] ?? '') === 'fr' ? 'selected' : '' ?>>Franska</option>
                        <option value="de" <?= ($deck['language'] ?? '') === 'de' ? 'selected' : '' ?>>Tyska</option>
                        <option value="uk" <?= ($deck['language'] ?? '') === 'uk' ? 'selected' : '' ?>>Ukrainska</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Kort -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">Flashcards</h2>

            <div id="cards-container" class="space-y-4">
                <!-- Fylls av JavaScript -->
            </div>

            <button onclick="addCard()" class="mt-4 bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                + Lägg till kort
            </button>

            <div class="mt-6 flex gap-4">
                <button onclick="saveDeck()" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg font-bold">
                    Spara ändringar
                </button>
                <a href="flashcards-admin.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg inline-block">
                    Avbryt
                </a>
            </div>
        </div>
    </div>

    <script>
        let cards = <?= json_encode($deck['cards'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function renderCards() {
            const container = document.getElementById('cards-container');
            container.innerHTML = '';

            cards.forEach((card, index) => {
                const div = document.createElement('div');
                div.className = 'border border-gray-200 rounded-lg p-4';

                div.innerHTML = `
                    <div class="flex justify-between items-start mb-3">
                        <span class="font-bold text-gray-700">Kort ${index + 1}</span>
                        <button onclick="deleteCard(${index})" class="text-red-500 hover:text-red-700">🗑️ Radera</button>
                    </div>
                    <div class="space-y-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Framsida</label>
                            <input type="text" value="${escapeHtml(card.front)}"
                                   onchange="updateCard(${index}, 'front', this.value)"
                                   class="w-full px-3 py-2 border border-gray-300 rounded">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Baksida</label>
                            <input type="text" value="${escapeHtml(card.back)}"
                                   onchange="updateCard(${index}, 'back', this.value)"
                                   class="w-full px-3 py-2 border border-gray-300 rounded">
                        </div>
                        <div class="mt-4 p-4 bg-gray-50 rounded border border-gray-200">
                            <label class="block text-sm font-medium text-gray-700 mb-2">📷 Bild (valfri)</label>
                            ${card.image ? `
                                <div class="mb-2">
                                    <img src="data/images/${escapeHtml(card.image)}" class="max-w-xs rounded shadow" style="max-height: 200px;">
                                    <div class="mt-2 flex gap-2">
                                        <button onclick="removeImage(${index})" type="button" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm">
                                            🗑️ Ta bort bild
                                        </button>
                                        <span class="text-sm text-gray-600 py-1">
                                            Visas på: <strong>${card.image_side === 'back' ? 'Baksidan' : 'Framsidan'}</strong>
                                        </span>
                                    </div>
                                </div>
                            ` : ''}
                            <input type="file" accept="image/*" onchange="uploadImage(${index}, this)"
                                   class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100">
                            ${!card.image ? `
                                <div class="mt-2">
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Visa bild på:</label>
                                    <select onchange="updateCard(${index}, 'image_side', this.value)"
                                            class="px-2 py-1 border border-gray-300 rounded text-sm">
                                        <option value="front" ${(card.image_side || 'front') === 'front' ? 'selected' : ''}>Framsidan</option>
                                        <option value="back" ${card.image_side === 'back' ? 'selected' : ''}>Baksidan</option>
                                    </select>
                                </div>
                            ` : ''}
                            <p class="text-xs text-gray-500 mt-1">Max 5MB. JPG, PNG, GIF eller WebP.</p>
                        </div>
                    </div>
                `;

                container.appendChild(div);
            });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function updateCard(index, field, value) {
            cards[index][field] = value;
        }

        function deleteCard(index) {
            if (confirm('Är du säker på att du vill radera detta kort?')) {
                cards.splice(index, 1);
                renderCards();
            }
        }

        function addCard() {
            cards.push({
                front: '',
                back: '',
                image_side: 'front'
            });
            renderCards();
        }

        async function uploadImage(index, input) {
            const file = input.files[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('image', file);
            formData.append('action', 'upload_image');

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    cards[index].image = result.filename;
                    // Sätt default image_side om det inte finns
                    if (!cards[index].image_side) {
                        cards[index].image_side = 'front';
                    }
                    renderCards();
                } else {
                    alert('Fel vid uppladdning: ' + (result.error || 'Okänt fel'));
                }
            } catch (error) {
                alert('Fel vid uppladdning: ' + error.message);
            }
        }

        async function removeImage(index) {
            if (!confirm('Är du säker på att du vill ta bort bilden?')) return;

            const filename = cards[index].image;
            if (!filename) return;

            const formData = new FormData();
            formData.append('action', 'delete_image');
            formData.append('filename', filename);

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    delete cards[index].image;
                    delete cards[index].image_side;
                    renderCards();
                } else {
                    alert('Kunde inte ta bort bilden');
                }
            } catch (error) {
                alert('Fel: ' + error.message);
            }
        }

        function saveDeck() {
            // Validera titel
            const title = document.getElementById('deck_title').value.trim();
            if (!title) {
                alert('Titel får inte vara tom');
                return;
            }

            // Validera kort
            for (let i = 0; i < cards.length; i++) {
                const card = cards[i];
                if (!card.front || !card.back) {
                    alert(`Kort ${i + 1} saknar text på fram- eller baksidan`);
                    return;
                }
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="update_deck">
                <input type="hidden" name="title" value="${escapeHtml(title)}">
                <input type="hidden" name="subject" value="${escapeHtml(document.getElementById('deck_subject').value.trim())}">
                <input type="hidden" name="grade" value="${escapeHtml(document.getElementById('deck_grade').value.trim())}">
                <input type="hidden" name="tags" value="${escapeHtml(document.getElementById('deck_tags').value.trim())}">
                <input type="hidden" name="language" value="${document.getElementById('deck_language').value}">
                <input type="hidden" name="cards" value='${JSON.stringify(cards)}'>
            `;
            document.body.appendChild(form);
            form.submit();
        }

        // Initial render
        renderCards();
    </script>
</body>
</html>
