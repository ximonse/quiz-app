<?php
require_once '../config.php';
require_once '../includes/browser-ids.php';
require_once __DIR__.'/../includes/classroom.php';
require_once __DIR__.'/../includes/result-storage.php';

header('Content-Type: application/json');

// Definiera flashcards-fil om den inte finns
if (!defined('FLASHCARDS_FILE')) {
    define('FLASHCARDS_FILE', DATA_DIR . 'flashcards.json');
}

// Läs POST data
$input = json_decode(file_get_contents('php://input'), true);
if (is_array($input)) classroomResultIdentity($input);

if (!$input || !isset($input['deck_id']) || !isset($input['student_name'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}

$deck_id = $input['deck_id'];
$student_name = classroomStudent()['name'] ?? $input['student_name'];
$completed = $input['completed'] ?? false;
$time_seconds = $input['time_seconds'] ?? 0;
$card_grades = $input['card_grades'] ?? [];
$grade_distribution = $input['grade_distribution'] ?? ['0' => 0, '1' => 0, '2' => 0, '3' => 0];

// Quiz and deck attempts share STATS_FILE and must use the same lock.
try {
    updateJSONLocked(STATS_FILE, function (&$stats) use ($deck_id, $student_name, $input, $completed, $time_seconds, $card_grades, $grade_distribution) {
        // Initiera om decket inte finns
        if (!isset($stats[$deck_id])) {
            $stats[$deck_id] = [
                'type' => 'flashcard',
                'total_attempts' => 0,
                'completed' => 0,
                'avg_time_seconds' => 0,
                'avg_grade' => 0,
                'attempts' => [],
                'card_difficulty' => []
            ];
        }

        if (!resultRequest($stats[$deck_id], $input)) return;

        // Räkna genomsnittlig grade
        $all_grades = [];
        foreach ($card_grades as $grades_array) {
            if (is_array($grades_array)) {
                $all_grades = array_merge($all_grades, $grades_array);
            }
        }
        $avg_grade = count($all_grades) > 0 ? array_sum($all_grades) / count($all_grades) : 0;

        // Lägg till detta försök
        $stats[$deck_id]['total_attempts']++;
        if ($completed) {
            $stats[$deck_id]['completed']++;
        }

        // Uppdatera genomsnittlig tid
        $total_time = $stats[$deck_id]['avg_time_seconds'] * ($stats[$deck_id]['total_attempts'] - 1);
        $stats[$deck_id]['avg_time_seconds'] = round(($total_time + $time_seconds) / $stats[$deck_id]['total_attempts']);

        // Uppdatera genomsnittlig grade
        $total_grade = $stats[$deck_id]['avg_grade'] * ($stats[$deck_id]['total_attempts'] - 1);
        $stats[$deck_id]['avg_grade'] = round(($total_grade + $avg_grade) / $stats[$deck_id]['total_attempts'], 2);

        // Spara försök
        $stats[$deck_id]['attempts'][] = [
            'student_id' => classroomStudent()['id'] ?? null,
            'student_name' => $student_name,
            'browser_id' => browserIdFromInput($input['browser_id'] ?? null),
            'timestamp' => date('Y-m-d H:i:s'),
            'completed' => $completed,
            'time_seconds' => $time_seconds,
            'card_grades' => $card_grades,
            'grade_distribution' => $grade_distribution,
            'avg_grade' => round($avg_grade, 2)
        ];

        // Uppdatera kort-svårighet (antal gånger varje kort fick grade 0 eller 1)
        if (!isset($stats[$deck_id]['card_difficulty']) || !is_array($stats[$deck_id]['card_difficulty'])) {
            $stats[$deck_id]['card_difficulty'] = [];
        }

        foreach ($card_grades as $card_idx => $grades_array) {
            if (!isset($stats[$deck_id]['card_difficulty'][$card_idx])) {
                $stats[$deck_id]['card_difficulty'][$card_idx] = 0;
            }

            // Räkna antal grade 0 och 1
            if (is_array($grades_array)) {
                foreach ($grades_array as $grade) {
                    if ($grade <= 1) {
                        $stats[$deck_id]['card_difficulty'][$card_idx]++;
                    }
                }
            }
        }

    });
} catch (RuntimeException $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Statistiken kunde inte sparas. Försök igen.']);
    exit;
}

echo json_encode(['success' => true]);
?>
