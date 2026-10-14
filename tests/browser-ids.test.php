<?php
require_once __DIR__ . '/../includes/browser-ids.php';

function checkBrowserIds($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}

$id = '12345678-1234-4234-8234-123456789abc';
$otherId = '87654321-1234-4234-8234-123456789abc';
checkBrowserIds(browserIdFromInput(strtoupper($id)) === $id, 'Valid UUID is normalized');
checkBrowserIds(browserIdFromInput('not-an-id') === null, 'Invalid UUID is ignored');
checkBrowserIds(browserIdFromInput(null) === null, 'Old results have no ID');

$quizzes = [
    'new' => ['teacher_id' => 'teacher-1', 'results' => [
        ['browser_id' => $id, 'student_name' => 'Anna', 'timestamp' => '2026-10-14 09:00:00'],
        ['browser_id' => $id, 'student_name' => 'Annie', 'timestamp' => '2026-10-14 10:00:00'],
        ['student_name' => 'Older result']
    ]],
    'legacy' => ['teacher_id' => 'teacher-1'],
    'other' => ['teacher_id' => 'teacher-2', 'results' => [
        ['browser_id' => $id, 'student_name' => 'Private']
    ]]
];
$stats = [
    'legacy' => ['attempts' => [['browser_id' => $id, 'student_name' => 'Anna', 'timestamp' => '2026-10-14 11:00:00']]],
    'deck' => ['attempts' => [['browser_id' => $otherId, 'student_name' => 'Bo', 'timestamp' => '2026-10-14 12:00:00']]]
];
$flashcards = ['deck' => ['teacher_id' => 'teacher-1']];
$groups = browserIdentityGroups('teacher-1', $quizzes, $stats, $flashcards);
checkBrowserIds(count($groups) === 2, 'Only IDs owned by this teacher are listed');
checkBrowserIds(array_keys($groups[$id]['names']) === ['Anna', 'Annie'], 'All entered names are retained');
checkBrowserIds($groups[$id]['count'] === 3, 'Modern and legacy attempts are combined');
checkBrowserIds($groups[$id]['last_seen'] === '2026-10-14 11:00:00', 'Latest result is retained');
checkBrowserIds(!isset($groups[$id]['names']['Private']), 'Other teacher names stay private');
checkBrowserIds(array_key_first($groups) === $otherId, 'Groups sort by latest result');
echo "Browser ID checks passed\n";
