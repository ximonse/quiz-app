<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$mq_id = $input['mq_id'] ?? '';
$variant = $input['variant'] ?? '';
$student_id = $input['student_id'] ?? '';

if (!$mq_id || !$variant || !$student_id) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// Läs progress-fil
$progress_file = DATA_DIR . 'multi_quiz_progress.json';
$progress = file_exists($progress_file) ? json_decode(file_get_contents($progress_file), true) : [];

// Initiera struktur om den saknas
if (!isset($progress[$mq_id])) {
    $progress[$mq_id] = [];
}
if (!isset($progress[$mq_id][$student_id])) {
    $progress[$mq_id][$student_id] = [];
}

// Spara timestamp för när varianten klarades
$progress[$mq_id][$student_id][$variant] = date('Y-m-d H:i:s');

// Skriv till fil
if (writeJSON($progress_file, $progress)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Could not save progress']);
}
