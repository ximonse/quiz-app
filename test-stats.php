<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Test 1: PHP fungerar<br>";

require_once 'config.php';
echo "Test 2: Config laddad<br>";

requireTeacher();
echo "Test 3: Teacher check OK<br>";

$teacher_id = getCurrentTeacherID();
echo "Test 4: Teacher ID: " . $teacher_id . "<br>";

$quiz_id = $_GET['quiz_id'] ?? '';
echo "Test 5: Quiz ID: " . $quiz_id . "<br>";

$quizzes = readJSON(QUIZZES_FILE);
echo "Test 6: Quizzes laddad: " . count($quizzes) . " quiz<br>";

$stats = readJSON(STATS_FILE);
echo "Test 7: Stats laddad<br>";

if (!isset($quizzes[$quiz_id])) {
    echo "ERROR: Quiz finns inte!<br>";
} else {
    echo "Test 8: Quiz finns<br>";
    echo "<pre>";
    print_r($quizzes[$quiz_id]);
    echo "</pre>";
}

echo "Test klar!";
