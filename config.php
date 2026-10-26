<?php
// Starta output buffering FÖRST för att förhindra headers-problem
ob_start();

// Sessionshantering
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Super admin password is loaded from env or config.local.php.
$super_admin_password = getenv('SUPER_ADMIN_PASSWORD');
if ($super_admin_password === false || $super_admin_password === '') {
    $local_config = __DIR__ . '/config.local.php';
    if (file_exists($local_config)) {
        // Stray output (blanksteg runt PHP-taggarna) i config.local.php får inte hamna i svaren,
        // t.ex. före PNG-signaturen i bilder.
        ob_start();
        require $local_config;
        ob_end_clean();
    }
}
if (!defined('SUPER_ADMIN_PASSWORD')) {
    define('SUPER_ADMIN_PASSWORD', $super_admin_password ?: 'CHANGE_ME');
}
// Filsökvägar
define('DATA_DIR', __DIR__ . '/data/');
define('TEACHERS_FILE', DATA_DIR . 'teachers.json');
define('QUIZZES_FILE', DATA_DIR . 'quizzes.json');
define('STATS_FILE', DATA_DIR . 'stats.json');
define('IMAGE_DIR', DATA_DIR . 'images/');

// Fail safely for web requests; never expose server paths or silently claim success.
if (PHP_SAPI !== 'cli') set_exception_handler(function (Throwable $error): void {
    error_log(get_class($error).': '.$error->getMessage());
    http_response_code($error instanceof DomainException ? 409 : 503);
    $message = 'Kunde inte sparas eller läsas. Försök igen eller kontakta din lärare.';
    if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>$message], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
});

// Hjälpfunktioner
function writeFileLocked($file, $contents) {
    return file_put_contents($file, $contents, LOCK_EX);
}

function readJSON($file) {
    if (!file_exists($file)) return [];
    $content = @file_get_contents($file);
    if ($content === false) throw new RuntimeException('Data could not be read. No changes were saved.');
    try { $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new RuntimeException('Invalid JSON. No changes were saved.', 0, $e); }
    if (!is_array($data)) throw new RuntimeException('Invalid data file. No changes were saved.');
    return $data;
}

function writeJSON($file, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    $temporary = $file . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($temporary, $json) !== strlen($json)) {
        @unlink($temporary);
        return false;
    }
    // Windows readers can briefly deny replacement of an otherwise valid file.
    // Retry the atomic rename; never remove the existing destination first.
    $attempts = PHP_OS_FAMILY === 'Windows' ? 10 : 1;
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        if (@rename($temporary, $file)) return true;
        if ($attempt + 1 < $attempts) usleep(10000 * ($attempt + 1));
    }
    @unlink($temporary);
    return false;
}

function updateJSONLocked($file, callable $update) {
    $lock = fopen($file . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock data file');
    try {
        $data = readJSON($file);
        $result = $update($data);
        if (!writeJSON($file, $data)) throw new RuntimeException('Could not write data file');
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function generateID($prefix = '') {
    return $prefix . bin2hex(random_bytes(4));
}

function isLoggedInAsSuperAdmin() {
    return isset($_SESSION['super_admin']) && $_SESSION['super_admin'] === true;
}

function isLoggedInAsTeacher() {
    return isset($_SESSION['teacher_id']) && !empty($_SESSION['teacher_id']);
}

function getCurrentTeacherID() {
    return $_SESSION['teacher_id'] ?? null;
}

function requireSuperAdmin() {
    if (!isLoggedInAsSuperAdmin()) {
        header('Location: index.php');
        exit;
    }
}

function requireTeacher() {
    if (!isLoggedInAsTeacher()) {
        header('Location: index.php');
        exit;
    }
}

function getCsrfToken() {
    if (!isset($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    $token = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function requireValidCsrf($expects_json = false) {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $is_valid = isset($_SESSION['csrf_token']) && $token !== '' && hash_equals($_SESSION['csrf_token'], $token);
    if ($is_valid) {
        return;
    }

    http_response_code(403);
    if ($expects_json) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    } else {
        echo 'Invalid CSRF token';
    }
    exit;
}

// Utökad statistik - uppdatera när quiz skapas
function updateStatsOnQuizCreate($teacher_id, $quiz_type) {
    // Uppdatera teacher_stats.json
    $teacher_stats_file = DATA_DIR . 'teacher_stats.json';
    updateJSONLocked($teacher_stats_file, function (&$teacher_stats) use ($teacher_id, $quiz_type) {
        if (!isset($teacher_stats[$teacher_id])) {
            $teacher_stats[$teacher_id] = [
                'total_quizzes' => 0,
                'total_attempts' => 0,
                'total_completed' => 0,
                'last_activity' => null,
                'quiz_types' => ['fact' => 0, 'glossary' => 0]
            ];
        }

        $teacher_stats[$teacher_id]['total_quizzes']++;
        $teacher_stats[$teacher_id]['quiz_types'][$quiz_type] = ($teacher_stats[$teacher_id]['quiz_types'][$quiz_type] ?? 0) + 1;
        $teacher_stats[$teacher_id]['last_activity'] = date('Y-m-d H:i:s');

    });

    // Uppdatera system_stats.json
    $system_stats_file = DATA_DIR . 'system_stats.json';
    updateJSONLocked($system_stats_file, function (&$system_stats) use ($quiz_type) {
        $system_stats += [
            'total_teachers' => 0,
            'total_quizzes' => 0,
            'total_attempts' => 0,
            'total_completed' => 0,
            'quiz_types' => ['fact' => 0, 'glossary' => 0],
            'last_updated' => null
        ];

        $system_stats['total_quizzes']++;
        $system_stats['quiz_types'][$quiz_type] = ($system_stats['quiz_types'][$quiz_type] ?? 0) + 1;
        $system_stats['last_updated'] = date('Y-m-d H:i:s');

        // Uppdatera antal lärare
        $teachers = readJSON(TEACHERS_FILE);
        $system_stats['total_teachers'] = count($teachers);

    });
}

// Bilduppladdning
function uploadImage($file) {
    try {
        // Skapa images-mapp om den inte finns
        if (!file_exists(IMAGE_DIR)) {
            if (!mkdir(IMAGE_DIR, 0755, true)) {
                return ['success' => false, 'error' => 'Kunde inte skapa bildmapp. Kontrollera rättigheter på data/-mappen.'];
            }
        }

        // Kolla att mappen är skrivbar
        if (!is_writable(IMAGE_DIR)) {
            return ['success' => false, 'error' => 'Bildmappen är inte skrivbar. Kontrollera rättigheter (chmod 755 eller 777).'];
        }

        // Validera filtyp
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            return ['success' => false, 'error' => 'Ingen giltig bilduppladdning.'];
        }
        $allowed_types = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/gif'=>'gif', 'image/webp'=>'webp'];
        $info = @getimagesize($file['tmp_name']);
        $file_type = $info['mime'] ?? '';

        if (!isset($allowed_types[$file_type]) || $info[0] > 8000 || $info[1] > 8000 || $info[0] * $info[1] > 24000000) {
            return ['success' => false, 'error' => 'Ogiltig filtyp (' . $file_type . '). Endast JPG, PNG, GIF och WebP tillåts.'];
        }

        // Validera filstorlek (max 5MB)
        if (filesize($file['tmp_name']) > 5 * 1024 * 1024) {
            return ['success' => false, 'error' => 'Filen är för stor (' . round($file['size']/1024/1024, 2) . ' MB). Max 5MB tillåts.'];
        }

        // Generera unikt filnamn
        $extension = $allowed_types[$file_type];
        $filename = generateID('img_') . '.' . $extension;
        $filepath = IMAGE_DIR . $filename;

        // Flytta fil
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            return ['success' => true, 'filename' => $filename];
        } else {
            return ['success' => false, 'error' => 'Kunde inte spara bilden. Kontrollera rättigheter.'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'PHP-fel: ' . $e->getMessage()];
    }
}

// Radera bild
function deleteImage($filename) {
    if (empty($filename)) return false;

    $filepath = IMAGE_DIR . $filename;
    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    return false;
}

// Quiz types with their own player/editor. Text quizzes (fact/glossary) use the defaults.
function quizTypeRoute(?string $type): array {
    $routes = [
        'image_choice' => ['player' => 'image-quiz.php', 'editor' => 'image-edit.php', 'label' => 'bildvalsquiz'],
        'sort' => ['player' => 'sort-quiz.php', 'editor' => 'sort-edit.php', 'label' => 'sorteringsquiz'],
        'order' => ['player' => 'order-quiz.php', 'editor' => 'order-edit.php', 'label' => 'ordningsquiz'],
    ];
    return $routes[$type ?? ''] ?? ['player' => 'quiz.php', 'editor' => 'edit.php', 'label' => $type === 'glossary' ? 'glosquiz' : 'faktaquiz'];
}
