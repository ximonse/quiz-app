<?php
// admin/dashboard.php — biblioteket: listan över lärarens quiz, med
// utdelning per rad. Skapandet ligger i create.php.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ai/quiz-service.php';
require_once __DIR__ . '/../includes/teacher-shell.php';
require_once __DIR__ . '/../includes/classroom-admin.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }

$csrfToken = getCsrfToken();
$teacherId = getCurrentTeacherID();
$teacherName = $_SESSION['teacher_name'] ?? 'Lärare';
$error = '';
$success = '';


// --- Dela ut till klass eller grupp ---
// Samma handling som i Klasser, via classroomAdminAction(), sa reglerna om
// agarskap, publicering och dubbletter bara finns pa ett stalle.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign') {
    requireValidCsrf();
    try {
        $_SESSION['dashboard_message'] = classroomAdminAction($teacherId, $_POST, []);
    } catch (DomainException $e) { $_SESSION['dashboard_error'] = $e->getMessage(); }
    catch (RuntimeException $e) { $_SESSION['dashboard_error'] = 'Utdelningen kunde inte sparas. Forsok igen.'; }
    header('Location: dashboard.php'); exit;
}

// --- Handle delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    requireValidCsrf();
    $deleteId = $_POST['quiz_id'] ?? '';
    $deleted = updateJSONLocked(QUIZZES_FILE, function (&$lockedQuizzes) use ($deleteId, $teacherId) {
        if (isset($lockedQuizzes[$deleteId]) && ($lockedQuizzes[$deleteId]['teacher_id'] ?? '') === $teacherId) {
            unset($lockedQuizzes[$deleteId]);
            return true;
        }
        return false;
    });
    if ($deleted) {
        $success = 'Quiz raderad!';
    }
}


// Load quizzes for list
$quizzes = readJSON(QUIZZES_FILE);
$myQuizzes = array_filter($quizzes, fn($q) => ($q['teacher_id'] ?? '') === $teacherId);
$myQuizzes = array_values($myQuizzes);
// Klasser och grupper att dela ut till, och vad varje quiz redan natt.
$classroom = classroomRead();
$targets = [];
foreach ($classroom['classes'] ?? [] as $cid => $c) if ($c['teacher_id'] === $teacherId) $targets['classes:'.$cid] = $c['name'];
foreach ($classroom['groups'] ?? [] as $gid => $g) if ($g['teacher_id'] === $teacherId) $targets['groups:'.$gid] = 'Grupp: '.$g['name'];
$assignedTo = [];
foreach ($classroom['assignments'] ?? [] as $a) {
    if ($a['teacher_id'] !== $teacherId || $a['kind'] !== 'quiz') continue;
    $name = $classroom[$a['target_type']][$a['target_id']]['name'] ?? null;
    if ($name !== null) $assignedTo[$a['content_id']][$name] = true;
}
$dashboardMessage = $_SESSION['dashboard_message'] ?? ''; unset($_SESSION['dashboard_message']);
$dashboardError = $_SESSION['dashboard_error'] ?? ''; unset($_SESSION['dashboard_error']);
if ($dashboardMessage) $success = $dashboardMessage;
if ($dashboardError) $error = $dashboardError;
usort($myQuizzes, fn($a, $b) => strcmp($b['created'] ?? '', $a['created'] ?? ''));
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Innehåll</title>
    <link rel="stylesheet" href="../assets/vendor/tailwind-3.4.19.css">
    <script src="../assets/vendor/qrcode-1.0.0.min.js"></script>
    <link rel="stylesheet" href="../engine/themes.css">
    <link rel="stylesheet" href="../assets/teacher-shell.css">
</head>
<body class="min-h-screen bg-gradient-to-br from-blue-50 to-purple-50">
<div class="teacher-page">

    <?php teacherShell('innehall','Hej '.$teacherName.'!','Dina quiz, flashcards och det du delar ut.'); ?>
    <p class="-mt-4 mb-6"><button type="button" onclick="toggleInfoModal()" class="text-blue-700 text-sm underline">Så funkar appen</button></p>

    <!-- Info-modal: hjälp för att komma igång -->
    <div id="info-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4" onclick="if (event.target === this) toggleInfoModal()">
        <div class="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[85vh] overflow-y-auto p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-bold text-gray-800">Så funkar appen</h2>
                <button type="button" onclick="toggleInfoModal()" class="text-gray-700 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <div class="space-y-4 text-sm text-gray-700">
                <div>
                    <h3 class="font-bold text-gray-800 mb-1">📝 Skapa ett quiz</h3>
                    <p>Välj Glosquiz (ord + översättning) eller Faktaquiz (begrepp + beskrivning), skriv en titel och klistra in CSV-data — eller använd 🤖-knappen för AI-hjälp att generera CSV:n åt dig.</p>
                </div>
                <div>
                    <h3 class="font-bold text-gray-800 mb-1">⚙️ Inställningar</h3>
                    <ul class="list-disc list-inside space-y-1">
                        <li><strong>Svarsläge:</strong> Flerval, Skrivsvar, eller Hybrid (flerval först, sedan skrivsvar).</li>
                        <li><strong>Quizläge:</strong> <em>Träning</em> — eleven övar tills alla frågor klarats, får försöka om vid fel. <em>Test</em> — en genomgång utan omtag, med poäng vid slutet.</li>
                        <li><strong>Rätt per fråga:</strong> hur många gånger i rad eleven måste svara rätt på en fråga innan den räknas som klar (gäller i Träning).</li>
                        <li><strong>Omvänd riktning:</strong> efter första rundan körs en till runda åt andra hållet (t.ex. från översättning till ord).</li>
                        <li><strong>Språk:</strong> styr rösten för uppläsning och vilket språk skrivsvar bedöms mot.</li>
                        <li><strong>Stavningsläge:</strong> Generös (tillåter mindre stavfel), Exakt, eller Eleven väljer själv i quizet.</li>
                    </ul>
                </div>
                <div>
                    <h3 class="font-bold text-gray-800 mb-1">🔊 Uppläsning (TTS)</h3>
                    <p>Läser upp meningen och ordet (glosquiz) eller frågan (faktaquiz) på valt språk. Eleven kan stänga av/på ljudet med 🔊/🔇-knappen uppe i quizet.</p>
                </div>
                <div>
                    <h3 class="font-bold text-gray-800 mb-1">📇 Flashcards</h3>
                    <p>Om aktiverat kan eleven bläddra igenom alla glosor som kort (fram/baksida) utan att bli bedömd — bra repetition inför ett test. Avmarkeras automatiskt om du väljer Quizläge "Test", men går att bocka i igen manuellt.</p>
                </div>
                <div>
                    <h3 class="font-bold text-gray-800 mb-1">Dela ut eller dela fritt</h3>
                    <p><strong>Dela ut</strong> ger quizet till en klass eller grupp, så eleverna hittar det när de loggar in och repetitionen kan börja samla kort. <strong>Dela fritt</strong> ligger i ⋯-menyn på raden och ger en länk eller QR-kod som vem som helst kan spela utan konto; då skriver eleven in sitt namn själv.</p>
                </div>
                <div>
                    <h3 class="font-bold text-gray-800 mb-1">📊 Statistik</h3>
                    <p>Klicka på 📊-ikonen på raden för att se: antal genomförda quiz, snitträtt i procent, de svåraste frågorna (flest fel) och en lista över de senaste 20 resultaten med namn och poäng. Du kan även rensa alla resultat därifrån.</p>
                </div>
                <div>
                    <h3 class="font-bold text-gray-800 mb-1">✏️ Redigera / Radera</h3>
                    <p>Ändra inställningar eller uppdatera CSV-datan via ✏️. Radering ligger i ⋯-menyn på raden, är permanent och tar bort alla sparade elevresultat för det quizet.</p>
                </div>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="mb-4 p-3 bg-green-100 text-green-700 rounded-lg text-sm"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-700 rounded-lg text-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>


    <!-- ═══════════ MINA QUIZ ═══════════ -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-bold text-gray-800">Dina quiz (<?= count($myQuizzes) ?>)</h2>
            <div class="flex items-center gap-2">
                <a href="create.php" class="px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium">Skapa quiz</a>
                <input type="text" id="search-input" oninput="filterQuizzes()" placeholder="Sök..." class="text-sm px-2 py-1 border border-gray-500 rounded w-40">
                <label class="text-xs text-gray-700">Sortera:</label>
                <select id="sort-select" onchange="sortQuizzes()" class="text-sm px-2 py-1 border border-gray-500 rounded">
                    <option value="created-desc">Nyast först</option>
                    <option value="created-asc">Äldst först</option>
                    <option value="title-asc">Titel A-Ö</option>
                    <option value="title-desc">Titel Ö-A</option>
                    <option value="type">Typ</option>
                    <option value="subject">Ämne</option>
                </select>
            </div>
        </div>

        <?php if (empty($myQuizzes)): ?>
            <p class="text-center py-8 text-gray-700">Inga quiz ännu. <a href="create.php" class="text-blue-700 underline">Skapa ditt första</a>.</p>
        <?php else: ?>
        <div id="quiz-list" class="space-y-1">
            <?php foreach ($myQuizzes as $quiz):
                $storedQuiz=$quiz; $quiz=aiEffectiveQuiz($quiz);
                $type = $quiz['type'] ?? 'glossary';
                $typeBadge = quizTypeRoute($type)['label'];
                $reviewState=aiReviewState($storedQuiz);
                $badgeColor = $type === 'glossary' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700';
                $itemCount = count($quiz['items'] ?? []);
                $resultCount = count($quiz['results'] ?? []);
                // Inloggade elever och oinloggade spelare är två olika tal, inte ett.
                $pupilResults = count(array_filter($quiz['results'] ?? [], fn($r) => !empty($r['student_id'])));
                $guestResults = $resultCount - $pupilResults;
                $reachedNames = array_keys($assignedTo[$quiz['id']] ?? []);
                // Repetition kräver rätt typ, publicerat OCH utdelat; säg vilket som fattas.
                $repetitionOn = !empty($quiz['settings']['spaced_repetition']) && in_array($type, ['fact','glossary'], true);
                $liveContent = classroomContent(['kind' => 'quiz', 'content_id' => $quiz['id'], 'teacher_id' => $teacherId]);
                $canAssign = $liveContent && $targets;
                $itemWord = $type === 'glossary' ? 'glosor' : 'frågor';
                $subject = htmlspecialchars($quiz['subject'] ?? '');
                $tags = htmlspecialchars($quiz['tags'] ?? '');
                $created = $quiz['created'] ?? '';
                $createdDate = $created ? date('Y-m-d', strtotime($created)) : '';
                $playUrl = '../play/index.php?id=' . urlencode($quiz['id']);
            ?>
            <div class="quiz-row flex items-center justify-between px-4 py-2 rounded-lg bg-gray-50 hover:bg-gray-100 border border-gray-400 transition"
                 data-created="<?= htmlspecialchars($created) ?>"
                 data-title="<?= htmlspecialchars($quiz['title']) ?>"
                 data-type="<?= $type ?>"
                 data-subject="<?= $subject ?>"
                 data-tags="<?= $tags ?>">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-gray-800 truncate"><?= htmlspecialchars($quiz['title']) ?></span>
                        <span class="text-xs px-2 py-0.5 rounded-full <?= $badgeColor ?>"><?= $typeBadge ?></span>
                        <?php if ($reviewState!=='published'): ?><span class="text-xs text-amber-800"><?= $reviewState==='draft'?'Opublicerat':'Ändringar att granska' ?></span><?php endif; ?>
                        <?php if ($repetitionOn): ?><span class="text-xs px-2 py-0.5 rounded-full <?= ($reachedNames && $liveContent) ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' ?>"><?= $reachedNames ? ($liveContent ? 'Repetition' : 'Repetition: inte publicerat') : 'Repetition: inte utdelad' ?></span><?php endif; ?>
                        <?php if ($subject): ?>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600"><?= $subject ?></span>
                        <?php endif; ?>
                        <?php if ($tags): foreach (explode(',', $tags) as $tag): $tag = trim($tag); if ($tag): ?>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700"><?= htmlspecialchars($tag) ?></span>
                        <?php endif; endforeach; endif; ?>
                    </div>
                    <div class="text-xs text-gray-700">
                        <?= $itemCount ?> <?= $itemWord ?><?= $createdDate ? ' &middot; '.htmlspecialchars($createdDate) : '' ?>
                        &middot; <?= $reachedNames ? 'utdelad till '.htmlspecialchars(implode(', ', $reachedNames)) : 'inte utdelad' ?>
                        &middot; <?= $pupilResults ?> <?= $pupilResults===1?'elevsvar':'elevsvar' ?><?= $guestResults ? ' &middot; '.$guestResults.($guestResults===1?' oinloggad':' oinloggade') : '' ?>
                    </div>
                </div>
                <div class="flex items-center gap-1 ml-4">
                    <?php if ($canAssign): ?><button type="button" data-assign="<?= htmlspecialchars($quiz['id']) ?>" class="px-3 py-1 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm">Dela ut</button><?php endif; ?>
                    <a href="review.php?id=<?= urlencode($quiz['id']) ?>" class="text-blue-700 text-sm px-1"><?= $reviewState==='published'?'Granska':'Granska och publicera' ?></a>
                    <a href="<?= quizTypeRoute($type)['editor'] ?>?id=<?= urlencode($quiz['id']) ?>" class="p-1.5 rounded hover:bg-gray-200 text-gray-700" title="Redigera">✏️</a>
                    <a href="stats.php?id=<?= urlencode($quiz['id']) ?>" class="p-1.5 rounded hover:bg-gray-200 text-gray-700" title="Statistik">📊</a>
                    <details class="relative" data-more>
                        <summary class="cursor-pointer p-1.5 rounded hover:bg-gray-200 text-gray-700" title="Fler">&#8943;</summary>
                        <div class="absolute right-0 top-full z-10 mt-1 w-56 rounded-lg border border-gray-300 bg-white p-1 shadow-lg">
                            <?php if ($reviewState==='published'): ?>
                            <button onclick="copyLink('<?= htmlspecialchars($playUrl) ?>')" class="block w-full text-left text-sm px-2 py-1.5 rounded hover:bg-gray-100">Dela fritt: kopiera länk</button>
                            <button onclick="showQrCode('<?= htmlspecialchars($playUrl) ?>', '<?= htmlspecialchars($quiz['title'], ENT_QUOTES) ?>')" class="block w-full text-left text-sm px-2 py-1.5 rounded hover:bg-gray-100">Dela fritt: QR-kod</button>
                            <?php endif; ?>
                            <form method="POST" onsubmit="return confirm('Radera detta quiz?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="quiz_id" value="<?= htmlspecialchars($quiz['id']) ?>">
                                <button type="submit" class="block w-full text-left text-sm px-2 py-1.5 rounded hover:bg-red-100">Radera quizet</button>
                            </form>
                        </div>
                    </details>
                </div>
            </div>
            <?php if ($canAssign): ?>
            <form method="POST" data-assign-panel="<?= htmlspecialchars($quiz['id']) ?>" class="hidden mb-2 px-4 py-3 rounded-lg bg-blue-50 border border-blue-200 text-sm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="assign">
                <input type="hidden" name="content" value="quiz:<?= htmlspecialchars($quiz['id']) ?>">
                <label class="font-medium mr-2" for="target-<?= htmlspecialchars($quiz['id']) ?>">Dela ut till</label>
                <select id="target-<?= htmlspecialchars($quiz['id']) ?>" name="target" required class="px-2 py-1 border border-gray-500 rounded text-sm">
                    <?php foreach ($targets as $value => $label): ?><option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="ml-2 px-3 py-1 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm">Dela ut</button>
                <button type="button" data-assign-cancel class="ml-1 px-3 py-1 rounded-lg border border-gray-400 bg-white text-blue-700 text-sm">Avbryt</button>
                <?php if ($repetitionOn && !$reachedNames): ?><p class="mt-1 text-xs text-gray-700">Repetitionen börjar samla kort när klassens elever övat på quizet.</p><?php endif; ?>
            </form>
            <?php elseif ($liveContent): ?>
            <p class="mb-2 px-4 text-xs text-gray-700">Skapa en klass i <a href="classes.php" class="text-blue-700 underline">Klasser</a> för att kunna dela ut quizet.</p>
            <?php else: ?>
            <p class="mb-2 px-4 text-xs text-gray-700">Publicera quizet för att kunna dela ut det till en klass.</p>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="flex justify-center pb-6">
        <a href="ai.php" class="inline-block px-4 py-2 rounded-lg bg-white border border-blue-200 text-blue-700 hover:bg-blue-50 font-medium text-sm">ai-api</a>
    </div>
</div>

<!-- QR-kod modal -->
<div id="qr-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50" onclick="if(event.target===this) closeQrModal()">
    <div class="bg-white rounded-xl shadow-lg p-6 max-w-sm w-full mx-4 text-center">
        <h3 id="qr-modal-title" class="text-lg font-bold text-gray-800 mb-3"></h3>
        <div id="qr-modal-code" class="flex justify-center mb-3"></div>
        <p id="qr-modal-url" class="text-xs text-gray-700 break-all mb-4"></p>
        <div class="flex gap-2 justify-center">
            <a id="qr-modal-download" download="quiz-qr.png" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1.5 rounded text-sm">
                Ladda ner
            </a>
            <button onclick="closeQrModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-3 py-1.5 rounded text-sm">
                Stäng
            </button>
        </div>
    </div>
</div>

<script>
function copyLink(path) {
    const url = new URL(path, window.location.href).href;
    navigator.clipboard.writeText(url).then(() => alert('Länk kopierad!'));
}

function showQrCode(path, title) {
    const url = new URL(path, window.location.href).href;
    const container = document.getElementById('qr-modal-code');
    container.innerHTML = '';
    new QRCode(container, {
        text: url,
        width: 220,
        height: 220
    });
    document.getElementById('qr-modal-title').textContent = title || 'QR-kod';
    document.getElementById('qr-modal-url').textContent = url;
    document.getElementById('qr-modal').classList.remove('hidden');
    document.getElementById('qr-modal').classList.add('flex');

    setTimeout(() => {
        const canvas = container.querySelector('canvas');
        const downloadLink = document.getElementById('qr-modal-download');
        if (canvas) {
            downloadLink.href = canvas.toDataURL('image/png');
        } else {
            const img = container.querySelector('img');
            downloadLink.href = img ? img.src : '#';
        }
    }, 50);
}

function closeQrModal() {
    document.getElementById('qr-modal').classList.add('hidden');
    document.getElementById('qr-modal').classList.remove('flex');
}

function sortQuizzes() {
    const list = document.getElementById('quiz-list');
    if (!list) return;
    const rows = [...list.querySelectorAll('.quiz-row')];
    const val = document.getElementById('sort-select').value;

    rows.sort((a, b) => {
        switch (val) {
            case 'created-desc': return b.dataset.created.localeCompare(a.dataset.created);
            case 'created-asc': return a.dataset.created.localeCompare(b.dataset.created);
            case 'title-asc': return a.dataset.title.localeCompare(b.dataset.title, 'sv');
            case 'title-desc': return b.dataset.title.localeCompare(a.dataset.title, 'sv');
            case 'type': return a.dataset.type.localeCompare(b.dataset.type);
            case 'subject': return a.dataset.subject.localeCompare(b.dataset.subject, 'sv');
            default: return 0;
        }
    });
    rows.forEach(r => list.appendChild(r));
}

// Dela ut: panelen ligger under raden och öppnas av radens knapp.
document.querySelectorAll('[data-assign]').forEach(function (button) {
    button.addEventListener('click', function () {
        var panel = document.querySelector('[data-assign-panel="' + button.dataset.assign + '"]');
        if (!panel) return;
        document.querySelectorAll('[data-assign-panel]').forEach(function (other) {
            if (other !== panel) other.classList.add('hidden');
        });
        panel.classList.toggle('hidden');
        if (!panel.classList.contains('hidden')) panel.querySelector('select').focus();
    });
});
document.querySelectorAll('[data-assign-cancel]').forEach(function (button) {
    button.addEventListener('click', function () { button.closest('[data-assign-panel]').classList.add('hidden'); });
});
// En öppen rad-meny ska stängas när man klickar någon annanstans.
document.addEventListener('click', function (event) {
    document.querySelectorAll('details[data-more][open]').forEach(function (menu) {
        if (!menu.contains(event.target)) menu.open = false;
    });
});

function filterQuizzes() {
    const list = document.getElementById('quiz-list');
    if (!list) return;
    const query = document.getElementById('search-input').value.trim().toLowerCase();
    list.querySelectorAll('.quiz-row').forEach(row => {
        const haystack = [row.dataset.title, row.dataset.subject, row.dataset.tags].join(' ').toLowerCase();
        row.style.display = (query !== '' && !haystack.includes(query)) ? 'none' : '';
    });
}

function toggleInfoModal() {
    document.getElementById('info-modal').classList.toggle('hidden');
}
</script>
</body>
</html>
