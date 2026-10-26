<?php
header('Content-Type: application/json');
require_once '../config.php';
require_once '../includes/browser-ids.php';
require_once __DIR__.'/../includes/classroom.php';
require_once __DIR__.'/../includes/result-storage.php';

// Läs inkommande JSON
$input = json_decode(file_get_contents('php://input'), true);
if (is_array($input)) classroomResultIdentity($input);

if (!$input || !isset($input['quiz_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$quiz_id = $input['quiz_id'];
$student_name = classroomStudent()['name'] ?? $input['student_name'] ?? 'Okänd';
$completed = $input['completed'] ?? false;
$time_seconds = $input['time_seconds'] ?? 0;
$total_errors = $input['total_errors'] ?? 0;
$question_errors = $input['question_errors'] ?? [];
$misspellings = $input['misspellings'] ?? [];

// Keep the whole read-modify-write operation under the shared statistics lock.
try {
    updateJSONLocked(STATS_FILE, function (&$stats) use ($quiz_id, $student_name, $input, $completed, $time_seconds, $total_errors, $question_errors, $misspellings) {
        if (!isset($stats[$quiz_id])) {
            $stats[$quiz_id] = [
                'total_attempts' => 0,
                'completed' => 0,
                'avg_time_seconds' => 0,
                'avg_errors' => 0,
                'attempts' => [],
                'question_errors' => []
            ];
        }

        if (!resultRequest($stats[$quiz_id], $input)) return;

        // Uppdatera statistik
        $stats[$quiz_id]['total_attempts']++;
        if ($completed) {
            $stats[$quiz_id]['completed']++;
        }

        // Lägg till detta försök
        $stats[$quiz_id]['attempts'][] = [
            'student_id' => classroomStudent()['id'] ?? null,
            'student_name' => $student_name,
            'browser_id' => browserIdFromInput($input['browser_id'] ?? null),
            'timestamp' => date('Y-m-d H:i:s'),
            'completed' => $completed,
            'time_seconds' => $time_seconds,
            'errors' => $total_errors,
            'question_errors' => $question_errors
        ];

        // Uppdatera genomsnitt
        $total_time = 0;
        $total_err = 0;
        $count = count($stats[$quiz_id]['attempts']);
        foreach ($stats[$quiz_id]['attempts'] as $attempt) {
            $total_time += $attempt['time_seconds'];
            $total_err += $attempt['errors'];
        }
        $stats[$quiz_id]['avg_time_seconds'] = $count > 0 ? round($total_time / $count) : 0;
        $stats[$quiz_id]['avg_errors'] = $count > 0 ? round($total_err / $count, 1) : 0;

        // Uppdatera fråge-fel statistik
        foreach ($question_errors as $q_index => $errors) {
            if (!isset($stats[$quiz_id]['question_errors'][$q_index])) {
                $stats[$quiz_id]['question_errors'][$q_index] = 0;
            }
            $stats[$quiz_id]['question_errors'][$q_index] += $errors;
        }

        // Spara felstavningar för glosquiz
        if (!empty($misspellings)) {
            foreach ($misspellings as $correct => $misspelled) {
                // Stödjer både nytt format [{correct, misspelled}, ...]
                // och äldre key/value-format ['correct' => 'misspelled'].
                if (is_array($misspelled) && isset($misspelled['correct']) && isset($misspelled['misspelled'])) {
                    $correct_word = trim((string)$misspelled['correct']);
                    $misspelled_word = trim((string)$misspelled['misspelled']);
                } else {
                    $correct_word = is_string($correct) ? trim($correct) : '';
                    $misspelled_word = is_string($misspelled) ? trim($misspelled) : '';
                }

                if ($correct_word === '' || $misspelled_word === '') {
                    continue;
                }

                if (!isset($stats[$quiz_id]['misspellings'][$correct_word])) {
                    $stats[$quiz_id]['misspellings'][$correct_word] = [];
                }
                if (!in_array($misspelled_word, $stats[$quiz_id]['misspellings'][$correct_word], true)) {
                    $stats[$quiz_id]['misspellings'][$correct_word][] = $misspelled_word;
                }
            }
        }

        // Uppdatera senaste användning och completion rate
        $stats[$quiz_id]['last_attempt'] = date('Y-m-d H:i:s');
        $stats[$quiz_id]['completion_rate'] = $stats[$quiz_id]['total_attempts'] > 0
            ? round(($stats[$quiz_id]['completed'] / $stats[$quiz_id]['total_attempts']) * 100, 1)
            : 0;

    });

    // Uppdatera teacher_stats.json och system_stats.json
    updateExtendedStats($quiz_id, $completed, $input);
} catch (RuntimeException $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Statistiken kunde inte sparas. Försök igen.']);
    exit;
}

echo json_encode(['success' => true]);

// Funktion för att uppdatera utökad statistik
function updateExtendedStats($quiz_id, $completed, $input) {
    // Läs quiz-data för att få teacher_id och quiz-typ
    $quizzes = readJSON(QUIZZES_FILE);
    if (!isset($quizzes[$quiz_id])) return;

    $quiz = $quizzes[$quiz_id];
    $teacher_id = $quiz['teacher_id'];

    // Uppdatera teacher_stats.json
    $teacher_stats_file = __DIR__ . '/../data/teacher_stats.json';
    updateJSONLocked($teacher_stats_file, function (&$teacher_stats) use ($teacher_id, $completed, $input) {

        if (!isset($teacher_stats[$teacher_id])) {
            $teacher_stats[$teacher_id] = [
                'total_quizzes' => 0,
                'total_attempts' => 0,
                'total_completed' => 0,
                'last_activity' => null,
                'quiz_types' => ['fact' => 0, 'glossary' => 0]
            ];
        }

        if (!resultRequest($teacher_stats[$teacher_id], $input)) return;
        $teacher_stats[$teacher_id]['total_attempts']++;
        if ($completed) {
            $teacher_stats[$teacher_id]['total_completed']++;
        }
        $teacher_stats[$teacher_id]['last_activity'] = date('Y-m-d H:i:s');

    });

    // Uppdatera system_stats.json
    $system_stats_file = __DIR__ . '/../data/system_stats.json';
    updateJSONLocked($system_stats_file, function (&$system_stats) use ($completed, $input) {
        if (!$system_stats) $system_stats = [
            'total_teachers' => 0, 'total_quizzes' => 0, 'total_attempts' => 0,
            'total_completed' => 0, 'quiz_types' => ['fact' => 0, 'glossary' => 0], 'last_updated' => null
        ];

        if (!resultRequest($system_stats, $input)) return;
        $system_stats['total_attempts']++;
        if ($completed) {
            $system_stats['total_completed']++;
        }
        $system_stats['last_updated'] = date('Y-m-d H:i:s');

    });
}
