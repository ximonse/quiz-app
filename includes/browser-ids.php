<?php
// Optional browser identifier; it never authenticates a pupil.
function browserIdFromInput($value): ?string {
    if (!is_string($value)) return null;
    $id = strtolower(trim($value));
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id) ? $id : null;
}

function browserIdentityGroups($teacherId, array $quizzes, array $stats, array $flashcards): array {
    $groups = [];
    $addAttempts = static function ($attempts) use (&$groups): void {
        if (!is_array($attempts)) return;
        foreach ($attempts as $attempt) {
            if (!is_array($attempt)) continue;
            $id = browserIdFromInput($attempt['browser_id'] ?? null);
            if ($id === null) continue;
            $name = trim((string)($attempt['student_name'] ?? ''));
            if ($name === '') $name = 'Okänt namn';
            if (!isset($groups[$id])) $groups[$id] = ['names' => [], 'count' => 0, 'last_seen' => ''];
            $groups[$id]['names'][$name] = true;
            $groups[$id]['count']++;
            $timestamp = (string)($attempt['timestamp'] ?? '');
            if ($timestamp > $groups[$id]['last_seen']) $groups[$id]['last_seen'] = $timestamp;
        }
    };

    foreach ($quizzes as $id => $quiz) {
        if (!is_array($quiz) || ($quiz['teacher_id'] ?? null) !== $teacherId) continue;
        $addAttempts($quiz['results'] ?? []);
        $addAttempts($stats[$id]['attempts'] ?? []);
    }
    foreach ($flashcards as $id => $deck) {
        if (!is_array($deck) || ($deck['teacher_id'] ?? null) !== $teacherId) continue;
        $addAttempts($stats[$id]['attempts'] ?? []);
    }
    uasort($groups, static fn($a, $b) => strcmp($b['last_seen'], $a['last_seen']));
    return $groups;
}
