<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "PHP version: " . phpversion() . "<br><br>";

try {
    require_once 'config.php';
    echo "✅ Config loaded OK<br>";
} catch (Exception $e) {
    echo "❌ Config error: " . $e->getMessage() . "<br>";
    exit;
}

try {
    $teachers = readJSON(TEACHERS_FILE);
    echo "✅ Teachers loaded: " . count($teachers) . " teachers<br>";
} catch (Exception $e) {
    echo "❌ Teachers error: " . $e->getMessage() . "<br>";
}

try {
    $quizzes = readJSON(QUIZZES_FILE);
    echo "✅ Quizzes loaded: " . count($quizzes) . " quizzes<br>";
} catch (Exception $e) {
    echo "❌ Quizzes error: " . $e->getMessage() . "<br>";
}

try {
    $stats = readJSON(STATS_FILE);
    echo "✅ Stats loaded: " . count($stats) . " stats<br>";
} catch (Exception $e) {
    echo "❌ Stats error: " . $e->getMessage() . "<br>";
}

echo "<br>✅ All files loaded successfully!";
?>
