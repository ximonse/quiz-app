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
        require $local_config;
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

// Hjälpfunktioner
function readJSON($file) {
    if (!file_exists($file)) {
        file_put_contents($file, '{}');
        return [];
    }
    $content = file_get_contents($file);
    return json_decode($content, true) ?: [];
}

function writeJSON($file, $data) {
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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

// Utökad statistik - uppdatera när quiz skapas
function updateStatsOnQuizCreate($teacher_id, $quiz_type) {
    // Uppdatera teacher_stats.json
    $teacher_stats_file = DATA_DIR . 'teacher_stats.json';
    $teacher_stats = file_exists($teacher_stats_file) ? json_decode(file_get_contents($teacher_stats_file), true) : [];

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
    $teacher_stats[$teacher_id]['quiz_types'][$quiz_type]++;
    $teacher_stats[$teacher_id]['last_activity'] = date('Y-m-d H:i:s');

    file_put_contents($teacher_stats_file, json_encode($teacher_stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // Uppdatera system_stats.json
    $system_stats_file = DATA_DIR . 'system_stats.json';
    $system_stats = file_exists($system_stats_file) ? json_decode(file_get_contents($system_stats_file), true) : [
        'total_teachers' => 0,
        'total_quizzes' => 0,
        'total_attempts' => 0,
        'total_completed' => 0,
        'quiz_types' => ['fact' => 0, 'glossary' => 0],
        'last_updated' => null
    ];

    $system_stats['total_quizzes']++;
    $system_stats['quiz_types'][$quiz_type]++;
    $system_stats['last_updated'] = date('Y-m-d H:i:s');

    // Uppdatera antal lärare
    $teachers = readJSON(TEACHERS_FILE);
    $system_stats['total_teachers'] = count($teachers);

    file_put_contents($system_stats_file, json_encode($system_stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $file_type = mime_content_type($file['tmp_name']);

        if (!in_array($file_type, $allowed_types)) {
            return ['success' => false, 'error' => 'Ogiltig filtyp (' . $file_type . '). Endast JPG, PNG, GIF och WebP tillåts.'];
        }

        // Validera filstorlek (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            return ['success' => false, 'error' => 'Filen är för stor (' . round($file['size']/1024/1024, 2) . ' MB). Max 5MB tillåts.'];
        }

        // Generera unikt filnamn
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
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
