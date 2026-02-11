<?php
require_once '../config.php';
requireTeacher();

header('Content-Type: application/json');
requireValidCsrf(true);

// Debugging
error_log('Batch import called - action: ' . ($_POST['action'] ?? 'none'));

$teacher_id = getCurrentTeacherID();
$teacher_name = $_SESSION['teacher_name'] ?? 'Lärare';

$action = $_POST['action'] ?? '';

if (!function_exists('readBatchCsvRow')) {
    function readBatchCsvRow($handle) {
        $data = fgetcsv($handle, 0, ';');
        if ($data === false) {
            return false;
        }

        // Bakåtkompatibilitet för äldre komma-separerade filer.
        if (count($data) === 1 && strpos($data[0], ',') !== false) {
            $fallback = str_getcsv($data[0], ',');
            if (count($fallback) > 1) {
                return $fallback;
            }
        }

        return $data;
    }
}

error_log('Teacher ID: ' . $teacher_id . ', Action: ' . $action);

if ($action === 'batch_import_fact') {
    // Batch-import för fakta-quiz
    if (!isset($_FILES['batch_fact_file']) || $_FILES['batch_fact_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Ingen fil uppladdad']);
        exit;
    }

    $answer_mode = $_POST['answer_mode'] ?? 'hybrid';
    $required_phase1 = intval($_POST['required_phase1'] ?? 2);
    $required_phase2 = intval($_POST['required_phase2'] ?? 2);

    $file = fopen($_FILES['batch_fact_file']['tmp_name'], 'r');
    if (!$file) {
        echo json_encode(['success' => false, 'error' => 'Kunde inte läsa filen']);
        exit;
    }

    $quizzes = readJSON(QUIZZES_FILE);
    $stats = readJSON(STATS_FILE);
    $created_count = 0;

    $current_title = null;
    $current_questions = [];

    while (($data = readBatchCsvRow($file)) !== false) {
        // Kolla om raden är tom (separator mellan quiz)
        if (empty(array_filter($data, 'trim'))) {
            // Tom rad - skapa quiz om vi har data
            if ($current_title && count($current_questions) > 0) {
                $quiz_id = generateID();
                $quizzes[$quiz_id] = [
                    'id' => $quiz_id,
                    'title' => $current_title,
                    'type' => 'fact',
                    'language' => 'sv',
                    'spelling_mode' => 'student_choice',
                    'answer_mode' => $answer_mode,
                    'required_correct_phase1' => $required_phase1,
                    'required_correct_phase2' => $required_phase2,
                    'reverse_enabled' => false,
                    'reverse_answer_mode' => 'hybrid',
                    'reverse_required_correct_phase1' => 2,
                    'reverse_required_correct_phase2' => 2,
                    'teacher_id' => $teacher_id,
                    'teacher_name' => $teacher_name,
                    'created' => date('Y-m-d H:i:s'),
                    'questions' => $current_questions
                ];

                // Initiera statistik
                $stats[$quiz_id] = [
                    'total_attempts' => 0,
                    'completed' => 0,
                    'avg_time_seconds' => 0,
                    'avg_errors' => 0,
                    'attempts' => [],
                    'question_errors' => [],
                    'misspellings' => []
                ];

                // Uppdatera utökad statistik
                updateStatsOnQuizCreate($teacher_id, 'fact');

                $created_count++;
                $current_title = null;
                $current_questions = [];
            }
            continue;
        }

        // Om vi inte har titel än, första raden är titeln
        if ($current_title === null) {
            $current_title = trim($data[0]);
            continue;
        }

        // Annars är det en fråga (minst 3 kolumner: Fråga,Rätt svar,Fel1,...)
        if (count($data) >= 3) {
            $question = trim($data[0]);
            $answer = trim($data[1]);

            // Samla alla felaktiga alternativ (från kolumn 2 och framåt)
            $wrongOptions = [];
            for ($i = 2; $i < count($data); $i++) {
                $wrong = trim($data[$i]);
                if ($wrong) {
                    $wrongOptions[] = $wrong;
                }
            }

            if ($question && $answer && count($wrongOptions) > 0) {
                $current_questions[] = [
                    'question' => $question,
                    'answer' => $answer,
                    'options' => [$answer, ...$wrongOptions]
                ];
            }
        }
    }

    // Skapa sista quizet om det finns
    if ($current_title && count($current_questions) > 0) {
        $quiz_id = generateID();
        $quizzes[$quiz_id] = [
            'id' => $quiz_id,
            'title' => $current_title,
            'type' => 'fact',
            'language' => 'sv',
            'spelling_mode' => 'student_choice',
            'answer_mode' => $answer_mode,
            'required_correct_phase1' => $required_phase1,
            'required_correct_phase2' => $required_phase2,
            'reverse_enabled' => false,
            'reverse_answer_mode' => 'hybrid',
            'reverse_required_correct_phase1' => 2,
            'reverse_required_correct_phase2' => 2,
            'teacher_id' => $teacher_id,
            'teacher_name' => $teacher_name,
            'created' => date('Y-m-d H:i:s'),
            'questions' => $current_questions
        ];

        $stats[$quiz_id] = [
            'total_attempts' => 0,
            'completed' => 0,
            'avg_time_seconds' => 0,
            'avg_errors' => 0,
            'attempts' => [],
            'question_errors' => [],
            'misspellings' => [],
            'wrong_answers' => []
        ];

        // Uppdatera utökad statistik
        updateStatsOnQuizCreate($teacher_id, 'fact');

        $created_count++;
    }

    fclose($file);

    error_log('Batch fact: Created ' . $created_count . ' quizzes');
    error_log('Total quizzes in array: ' . count($quizzes));

    $write_result = writeJSON(QUIZZES_FILE, $quizzes);
    error_log('Write quizzes result: ' . ($write_result ? 'success' : 'failed'));

    writeJSON(STATS_FILE, $stats);

    echo json_encode(['success' => true, 'created' => $created_count]);
}

elseif ($action === 'batch_import_gloss') {
    // Batch-import för glosquiz
    if (!isset($_FILES['batch_gloss_file']) || $_FILES['batch_gloss_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Ingen fil uppladdad']);
        exit;
    }

    $language = $_POST['language'] ?? 'sv';
    $spelling_mode = $_POST['spelling_mode'] ?? 'student_choice';
    $answer_mode = $_POST['answer_mode'] ?? 'hybrid';
    $required_phase1 = intval($_POST['required_phase1'] ?? 2);
    $required_phase2 = intval($_POST['required_phase2'] ?? 2);
    $reverse_enabled = (($_POST['reverse_enabled'] ?? '1') === '1');
    $reverse_answer_mode = $_POST['reverse_answer_mode'] ?? 'hybrid';
    $reverse_required_phase1 = intval($_POST['reverse_required_phase1'] ?? 2);
    $reverse_required_phase2 = intval($_POST['reverse_required_phase2'] ?? 2);

    $file = fopen($_FILES['batch_gloss_file']['tmp_name'], 'r');
    if (!$file) {
        echo json_encode(['success' => false, 'error' => 'Kunde inte läsa filen']);
        exit;
    }

    $quizzes = readJSON(QUIZZES_FILE);
    $stats = readJSON(STATS_FILE);
    $created_count = 0;

    $current_title = null;
    $current_questions = [];

    while (($data = readBatchCsvRow($file)) !== false) {
        // Kolla om raden är tom (separator mellan quiz)
        if (empty(array_filter($data, 'trim'))) {
            // Tom rad - skapa quiz om vi har data
            if ($current_title && count($current_questions) > 0) {
                $quiz_id = generateID();
                $quizzes[$quiz_id] = [
                    'id' => $quiz_id,
                    'title' => $current_title,
                    'type' => 'glossary',
                    'language' => $language,
                    'spelling_mode' => $spelling_mode,
                    'answer_mode' => $answer_mode,
                    'required_correct_phase1' => $required_phase1,
                    'required_correct_phase2' => $required_phase2,
                    'reverse_enabled' => $reverse_enabled,
                    'reverse_answer_mode' => $reverse_answer_mode,
                    'reverse_required_correct_phase1' => $reverse_required_phase1,
                    'reverse_required_correct_phase2' => $reverse_required_phase2,
                    'teacher_id' => $teacher_id,
                    'teacher_name' => $teacher_name,
                    'created' => date('Y-m-d H:i:s'),
                    'questions' => $current_questions
                ];

                // Initiera statistik
                $stats[$quiz_id] = [
                    'total_attempts' => 0,
                    'completed' => 0,
                    'avg_time_seconds' => 0,
                    'avg_errors' => 0,
                    'attempts' => [],
                    'question_errors' => [],
                    'misspellings' => []
                ];

                // Uppdatera utökad statistik
                updateStatsOnQuizCreate($teacher_id, 'glossary');

                $created_count++;
                $current_title = null;
                $current_questions = [];
            }
            continue;
        }

        // Om vi inte har titel än, första raden är titeln
        if ($current_title === null) {
            $current_title = trim($data[0]);
            continue;
        }

        // Annars är det en glosfråga (minst 4 kolumner: Mening,Ord,Rätt svar,Fel1,...)
        if (count($data) >= 4) {
            $sentence = trim($data[0]);
            $word = trim($data[1]);
            $answer = trim($data[2]);

            // Samla alla felaktiga alternativ (från kolumn 3 och framåt)
            $wrongOptions = [];
            for ($i = 3; $i < count($data); $i++) {
                $wrong = trim($data[$i]);
                if ($wrong) {
                    $wrongOptions[] = $wrong;
                }
            }

            if ($sentence && $word && $answer && count($wrongOptions) > 0) {
                $current_questions[] = [
                    'question' => $sentence,
                    'word' => $word,
                    'answer' => $answer,
                    'options' => [$answer, ...$wrongOptions]
                ];
            }
        }
    }

    // Skapa sista quizet om det finns
    if ($current_title && count($current_questions) > 0) {
        $quiz_id = generateID();
        $quizzes[$quiz_id] = [
            'id' => $quiz_id,
            'title' => $current_title,
            'type' => 'glossary',
            'language' => $language,
            'spelling_mode' => $spelling_mode,
            'answer_mode' => $answer_mode,
            'required_correct_phase1' => $required_phase1,
            'required_correct_phase2' => $required_phase2,
            'reverse_enabled' => $reverse_enabled,
            'reverse_answer_mode' => $reverse_answer_mode,
            'reverse_required_correct_phase1' => $reverse_required_phase1,
            'reverse_required_correct_phase2' => $reverse_required_phase2,
            'teacher_id' => $teacher_id,
            'teacher_name' => $teacher_name,
            'created' => date('Y-m-d H:i:s'),
            'questions' => $current_questions
        ];

        $stats[$quiz_id] = [
            'total_attempts' => 0,
            'completed' => 0,
            'avg_time_seconds' => 0,
            'avg_errors' => 0,
            'attempts' => [],
            'question_errors' => [],
            'misspellings' => [],
            'wrong_answers' => []
        ];

        // Uppdatera utökad statistik
        updateStatsOnQuizCreate($teacher_id, 'glossary');

        $created_count++;
    }

    fclose($file);

    error_log('Batch gloss: Created ' . $created_count . ' quizzes');
    error_log('Total quizzes in array: ' . count($quizzes));

    $write_result = writeJSON(QUIZZES_FILE, $quizzes);
    error_log('Write quizzes result: ' . ($write_result ? 'success' : 'failed'));

    writeJSON(STATS_FILE, $stats);

    echo json_encode(['success' => true, 'created' => $created_count]);
}

else {
    echo json_encode(['success' => false, 'error' => 'Ogiltig action']);
}
