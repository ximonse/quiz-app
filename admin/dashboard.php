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
$error = '';
$success = '';


// --- Dela ut till klass eller grupp ---
// Samma handling som i Klasser, via classroomAdminAction(), sa reglerna om
// agarskap, publicering och dubbletter bara finns pa ett stalle.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign') {
    requireValidCsrf();
    try {
        $receipt = null;
        classroomAdminAction($teacherId, $_POST, [], $receipt);
        $_SESSION['dashboard_assignment'] = $receipt;
    } catch (DomainException $e) { $_SESSION['dashboard_error'] = $e->getMessage(); }
    catch (RuntimeException $e) { $_SESSION['dashboard_error'] = 'Utdelningen kunde inte sparas. Försök igen.'; }
    $view = array_filter([
        'q' => is_string($_GET['q'] ?? null) ? $_GET['q'] : '',
        'sort' => is_string($_GET['sort'] ?? null) ? $_GET['sort'] : '',
    ], fn($value) => $value !== '');
    header('Location: dashboard.php'.($view ? '?'.http_build_query($view) : '')); exit;
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
    if ($name !== null) {
        $label = $a['target_type'] === 'groups' ? 'gruppen '.$name : $name;
        $assignedTo[$a['content_id']][$a['target_type'].':'.$a['target_id']] = $label;
    }
}
$dashboardMessage = $_SESSION['dashboard_message'] ?? ''; unset($_SESSION['dashboard_message']);
$dashboardError = $_SESSION['dashboard_error'] ?? ''; unset($_SESSION['dashboard_error']);
$assignmentReceipt = $_SESSION['dashboard_assignment'] ?? null; unset($_SESSION['dashboard_assignment']);
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
    <style>
        @media print {
            body.print-quiz-qr { background: white; }
            body.print-quiz-qr > .teacher-page, .print-quiz-qr .qr-modal-actions { display: none; }
            .print-quiz-qr #qr-modal { position: static; background: white; }
            .print-quiz-qr #qr-modal > div { box-shadow: none; }
            .print-quiz-qr #qr-modal-code { padding: 24px; }
        }
    </style>
</head>
<body class="teacher-workflow min-h-screen">
<div class="teacher-page">

    <?php teacherShell('innehall','Innehåll'); ?>

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
                    <p><strong>Dela ut</strong> lägger quizet i elevernas uppgifter. Under ⋯ hittar du <strong>QR-kod med elevinloggning</strong> för att öppna quizet direkt och spara resultatet på elevkontot. <strong>Dela fritt</strong> låter även gäster spela.</p>
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
        <div class="mb-4 p-3 bg-red-100 text-red-700 rounded-lg text-sm" role="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>


    <!-- ═══════════ MINA QUIZ ═══════════ -->
    <section class="workflow-panel" aria-labelledby="library-title">
        <div class="workflow-heading">
            <h2 id="library-title">Dina quiz <span id="quiz-count" class="workflow-muted" aria-live="polite"><?= count($myQuizzes) ?></span></h2>
            <a href="create.php" class="workflow-primary">Skapa quiz</a>
        </div>
        <?php if ($myQuizzes): ?>
        <div class="workflow-toolbar">
                <label for="search-input" class="workflow-muted">Sök quiz</label>
                <input type="search" id="search-input" oninput="filterQuizzes()" placeholder="Sök titel, ämne eller tagg" class="workflow-search">
                <label for="sort-select" class="workflow-muted">Sortera</label>
                <select id="sort-select" onchange="sortQuizzes()" class="text-sm px-2 py-1 border border-gray-500 rounded">
                    <option value="created-desc">Nyast först</option>
                    <option value="created-asc">Äldst först</option>
                    <option value="title-asc">Titel A-Ö</option>
                    <option value="title-desc">Titel Ö-A</option>
                    <option value="type">Typ</option>
                    <option value="subject">Ämne</option>
                </select>
        </div>
        <?php endif; ?>
        <?php if (empty($myQuizzes)): ?>
            <p class="text-center py-8 text-gray-700">Inga quiz ännu. <a href="create.php" class="text-blue-700 underline">Skapa ditt första</a>.</p>
        <?php else: ?>
        <div id="quiz-list">
            <?php foreach ($myQuizzes as $quiz):
                $storedQuiz=$quiz; $quiz=aiEffectiveQuiz($quiz);
                $type = $quiz['type'] ?? 'glossary';
                $typeBadge = quizTypeRoute($type)['label'];
                $reviewState=aiReviewState($storedQuiz);
                $itemCount = count($quiz['items'] ?? []);
                $resultCount = count($quiz['results'] ?? []);
                // Inloggade elever och oinloggade spelare är två olika tal, inte ett.
                $pupilResults = count(array_filter($quiz['results'] ?? [], fn($r) => !empty($r['student_id'])));
                $guestResults = $resultCount - $pupilResults;
                $reachedNames = array_values($assignedTo[$quiz['id']] ?? []);
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
            <article class="workflow-item"
                 data-created="<?= htmlspecialchars($created) ?>"
                 data-title="<?= htmlspecialchars($quiz['title']) ?>"
                 data-type="<?= $type ?>"
                 data-subject="<?= $subject ?>"
                 data-tags="<?= $tags ?>">
                <div class="quiz-row workflow-row">
                <div class="workflow-info">
                    <h3><?= htmlspecialchars($quiz['title']) ?></h3>
                    <div class="workflow-meta">
                        <span><?= $typeBadge ?></span>
                        <?php if ($reviewState!=='published'): ?><span class="text-xs text-amber-800"><?= $reviewState==='draft'?'Opublicerat':'Ändringar att granska' ?></span><?php endif; ?>
                        <?php if ($repetitionOn): ?><span class="text-xs px-2 py-0.5 rounded-full <?= ($reachedNames && $liveContent) ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' ?>"><?= $reachedNames ? ($liveContent ? 'Repetition' : 'Repetition: inte publicerat') : 'Repetition: inte utdelad' ?></span><?php endif; ?>
                        <?php if ($subject): ?>
                            <span><?= $subject ?></span>
                        <?php endif; ?>
                        <?php if ($tags): foreach (explode(',', $tags) as $tag): $tag = trim($tag); if ($tag): ?>
                            <span><?= htmlspecialchars($tag) ?></span>
                        <?php endif; endforeach; endif; ?>
                    </div>
                    <div class="workflow-meta">
                        <?= $itemCount ?> <?= $itemCount === 1 ? ($type === 'glossary' ? 'glosa' : 'fråga') : $itemWord ?><?= $createdDate ? ' &middot; '.htmlspecialchars($createdDate) : '' ?>
                        &middot; <?= $reachedNames ? 'utdelad till '.htmlspecialchars(implode(', ', $reachedNames)) : 'inte utdelad' ?>
                        &middot; <?= $pupilResults ?> elevsvar<?= $guestResults ? ' &middot; '.$guestResults.($guestResults===1?' oinloggad':' oinloggade') : '' ?>
                    </div>
                </div>
                <div class="workflow-actions">
                    <?php if ($canAssign): ?><button type="button" data-assign="<?= htmlspecialchars($quiz['id']) ?>" aria-expanded="false" aria-controls="assign-<?= htmlspecialchars($quiz['id']) ?>" class="workflow-primary">Dela ut</button><?php endif; ?>
                    <a href="review.php?id=<?= urlencode($quiz['id']) ?>" class="workflow-link"><?= $reviewState==='published'?'Granska':'Granska och publicera' ?></a>
                    <a href="<?= quizTypeRoute($type)['editor'] ?>?id=<?= urlencode($quiz['id']) ?>" class="workflow-link">Redigera</a>
                    <a href="stats.php?id=<?= urlencode($quiz['id']) ?>" class="workflow-link">Statistik</a>
                    <details class="relative" data-more>
                        <summary class="cursor-pointer p-1.5 rounded hover:bg-gray-200 text-gray-700" aria-label="Fler åtgärder för <?= htmlspecialchars($quiz['title']) ?>">&#8943;</summary>
                        <div class="absolute right-0 top-full z-10 mt-1 w-56 rounded-lg border border-gray-300 bg-white p-1 shadow-lg">
                            <?php if (isset($liveContent['items'])): ?>
                            <button type="button" data-quiz-qr="../student/?quiz=<?= rawurlencode($quiz['id']) ?>" data-quiz-title="<?= htmlspecialchars($storedQuiz['title'], ENT_QUOTES) ?>" onclick="showQrCode(this.dataset.quizQr, this.dataset.quizTitle, true)" class="block w-full text-left text-sm px-2 py-1 rounded hover:bg-gray-100">QR-kod med elevinloggning</button>
                            <?php endif; ?>
                            <?php if ($reviewState==='published'): ?>
                            <button onclick="copyLink('<?= htmlspecialchars($playUrl) ?>')" class="block w-full text-left text-sm px-2 py-1 rounded hover:bg-gray-100">Dela fritt: kopiera länk</button>
                            <button type="button" data-play-url="<?= htmlspecialchars($playUrl) ?>" data-quiz-title="<?= htmlspecialchars($quiz['title'], ENT_QUOTES) ?>" onclick="showQrCode(this.dataset.playUrl, this.dataset.quizTitle)" class="block w-full text-left text-sm px-2 py-1 rounded hover:bg-gray-100">Dela fritt: QR-kod</button>
                            <?php endif; ?>
                            <form method="POST" onsubmit="return confirm('Radera detta quiz?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="quiz_id" value="<?= htmlspecialchars($quiz['id']) ?>">
                                <button type="submit" class="block w-full text-left text-sm px-2 py-1 rounded hover:bg-red-100">Radera quizet</button>
                            </form>
                        </div>
                    </details>
                </div>
            </div>
            <?php if (($assignmentReceipt['content_id'] ?? null) === $quiz['id']): ?>
            <div class="workflow-receipt" role="status">
                <span>Utdelad till <?= $assignmentReceipt['target_type'] === 'groups' ? 'gruppen ' : '' ?><?= htmlspecialchars($assignmentReceipt['recipient']) ?>.</span>
                <a href="followup.php?assignment=<?= urlencode($assignmentReceipt['id']) ?>">Följ upp utdelningen</a>
            </div>
            <?php endif; ?>
            <?php if ($canAssign): ?>
            <form method="POST" id="assign-<?= htmlspecialchars($quiz['id']) ?>" data-assign-panel hidden class="workflow-assign">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="assign">
                <input type="hidden" name="content" value="quiz:<?= htmlspecialchars($quiz['id']) ?>">
                <label for="target-<?= htmlspecialchars($quiz['id']) ?>">Mottagare för <?= htmlspecialchars($quiz['title']) ?></label>
                <div class="workflow-assign-controls">
                <select id="target-<?= htmlspecialchars($quiz['id']) ?>" name="target" required class="px-2 py-1 border border-gray-500 rounded text-sm">
                    <option value="">Välj klass eller grupp</option>
                    <?php foreach ($targets as $value => $label): ?><option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                </select>
                <button type="submit" class="workflow-primary">Dela ut</button>
                <button type="button" data-assign-cancel class="workflow-link">Avbryt</button>
                </div>
                <?php if ($repetitionOn && !$reachedNames): ?><p class="mt-1 text-xs text-gray-700">Repetitionen börjar samla kort när klassens elever övat på quizet.</p><?php endif; ?>
            </form>
            <?php elseif ($liveContent): ?>
            <p class="mb-2 px-4 text-xs text-gray-700">Skapa en klass i <a href="classes.php" class="text-blue-700 underline">Klasser</a> för att kunna dela ut quizet.</p>
            <?php else: ?>
            <p class="mb-2 px-4 text-xs text-gray-700">Publicera quizet för att kunna dela ut det till en klass.</p>
            <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
        <p id="quiz-no-results" class="workflow-muted" hidden>Inga quiz matchar din sökning.</p>
        <?php endif; ?>
    </section>
    <div class="workflow-toolbar workflow-footer">
        <button type="button" onclick="toggleInfoModal()" class="workflow-link">Så funkar appen</button>
        <a href="ai.php" class="inline-block px-4 py-2 rounded-lg bg-white border border-blue-200 text-blue-700 hover:bg-blue-50 font-medium text-sm">ai-api</a>
    </div>
</div>

<!-- QR-kod modal -->
<div id="qr-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50" onclick="if(event.target===this) closeQrModal()">
    <div class="bg-white rounded-xl shadow-lg p-6 max-w-sm w-full mx-4 text-center">
        <h3 id="qr-modal-title" class="text-lg font-bold text-gray-800 mb-3"></h3>
        <p id="qr-modal-instructions" class="text-sm text-gray-700 mb-3" hidden>Skanna med kameran. Logga in om du blir ombedd.</p>
        <div id="qr-modal-code" class="flex justify-center mb-3"></div>
        <p id="qr-modal-url" class="text-xs text-gray-700 break-all mb-4"></p>
        <div class="qr-modal-actions flex gap-2 justify-center">
            <a id="qr-modal-download" download="quiz-qr.png" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1.5 rounded text-sm">
                Ladda ner
            </a>
            <button type="button" onclick="printQuizQr()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-3 py-1.5 rounded text-sm">Skriv ut</button>
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

function showQrCode(path, title, requiresLogin = false) {
    const url = new URL(path, window.location.href).href;
    const container = document.getElementById('qr-modal-code');
    container.innerHTML = '';
    new QRCode(container, {
        text: url,
        width: 220,
        height: 220
    });
    document.getElementById('qr-modal-title').textContent = title || 'QR-kod';
    document.getElementById('qr-modal-instructions').hidden = !requiresLogin;
    document.getElementById('qr-modal-url').textContent = url;
    document.getElementById('qr-modal').classList.remove('hidden');
    document.getElementById('qr-modal').classList.add('flex');

    setTimeout(() => {
        const canvas = container.querySelector('canvas');
        const downloadLink = document.getElementById('qr-modal-download');
        downloadLink.download = (title || 'Quiz').replace(/[\\/:*?"<>|]/g, '-') + (requiresLogin ? ' - elevinloggning' : '') + '.png';
        if (canvas) {
            const margin = 24;
            const printable = document.createElement('canvas');
            printable.width = canvas.width + 2 * margin;
            printable.height = canvas.height + 2 * margin;
            const context = printable.getContext('2d');
            context.fillStyle = '#fff';
            context.fillRect(0, 0, printable.width, printable.height);
            context.drawImage(canvas, margin, margin);
            downloadLink.href = printable.toDataURL('image/png');
        } else {
            const img = container.querySelector('img');
            downloadLink.href = img ? img.src : '#';
        }
    }, 50);
}

function printQuizQr() {
    document.body.classList.add('print-quiz-qr');
    window.addEventListener('afterprint', () => document.body.classList.remove('print-quiz-qr'), {once:true});
    window.print();
}

function closeQrModal() {
    document.getElementById('qr-modal').classList.add('hidden');
    document.getElementById('qr-modal').classList.remove('flex');
}

function sortQuizzes() {
    const list = document.getElementById('quiz-list');
    if (!list) return;
    const rows = [...list.children];
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
    rows.forEach(row => list.appendChild(row));
    updateLibraryUrl();
}

function setAssignmentPanel(item, open) {
    item.querySelector('[data-assign-panel]').hidden = !open;
    item.querySelector('[data-assign]').setAttribute('aria-expanded', String(open));
}

document.querySelectorAll('[data-assign]').forEach(button => {
    const item = button.closest('.workflow-item');
    const panel = item.querySelector('[data-assign-panel]');
    button.addEventListener('click', () => {
        const open = panel.hidden;
        document.querySelectorAll('[data-assign-panel]:not([hidden])').forEach(other => {
            if (other !== panel) setAssignmentPanel(other.closest('.workflow-item'), false);
        });
        setAssignmentPanel(item, open);
        if (open) panel.querySelector('select').focus();
    });
    panel.querySelector('[data-assign-cancel]').addEventListener('click', () => {
        setAssignmentPanel(item, false);
        button.focus();
    });
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
    let visible = 0;
    for (const row of list.children) {
        const haystack = [row.dataset.title, row.dataset.subject, row.dataset.tags].join(' ').toLowerCase();
        row.hidden = !haystack.includes(query);
        if (!row.hidden) visible++;
    }
    document.getElementById('quiz-count').textContent = visible;
    document.getElementById('quiz-no-results').hidden = visible > 0;
    updateLibraryUrl();
}

function updateLibraryUrl() {
    const url = new URL(location.href);
    const query = document.getElementById('search-input').value.trim();
    const sort = document.getElementById('sort-select').value;
    if (query) url.searchParams.set('q', query); else url.searchParams.delete('q');
    if (sort !== 'created-desc') url.searchParams.set('sort', sort); else url.searchParams.delete('sort');
    history.replaceState(history.state, '', url);
}

if (document.getElementById('quiz-list')) {
    const params = new URLSearchParams(location.search);
    const sort = document.getElementById('sort-select');
    document.getElementById('search-input').value = params.get('q') || '';
    if ([...sort.options].some(option => option.value === params.get('sort'))) sort.value = params.get('sort');
    if (sort.value !== 'created-desc') sortQuizzes();
    filterQuizzes();
}

function toggleInfoModal() {
    document.getElementById('info-modal').classList.toggle('hidden');
}
</script>
</body>
</html>
