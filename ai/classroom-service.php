<?php
// AI access to classes and assignments. Deliberately narrow: an AI may see which
// classes and groups exist and hand out published content to one of them. It can
// never read pupil names, login codes, attempts or results — the same boundary
// the rest of the connection keeps.
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../includes/classroom-admin.php';

// Classes and groups with their sizes. No pupil is named.
function aiClassroomList(array $actor): array {
    $data = aiClassroomRead();
    $teacher = $actor['teacher_id'];
    $active = array_filter($data['students'] ?? [], fn($s) => $s['teacher_id'] === $teacher && !empty($s['active']));
    $classes = [];
    foreach ($data['classes'] ?? [] as $id => $class) {
        if ($class['teacher_id'] !== $teacher) continue;
        $classes[] = ['id' => $id, 'name' => $class['name'],
            'pupils' => count(array_filter($active, fn($s) => $s['class_id'] === $id))];
    }
    $groups = [];
    foreach ($data['groups'] ?? [] as $id => $group) {
        if ($group['teacher_id'] !== $teacher) continue;
        $groups[] = ['id' => $id, 'name' => $group['name'],
            'pupils' => count(array_filter($group['students'], fn($sid) => isset($active[$sid])))];
    }
    return ['classes' => $classes, 'groups' => $groups,
        'note' => 'Assign with quiz_assign only when the user names the quiz and the class or group. The content must be published first, and an assignment cannot be withdrawn from the app.'];
}
// Hand published content to one class or group. Idempotent on request_id: a retry
// returns the assignment the first call made instead of a duplicate, because a
// duplicate appears in every pupil's list straight away.
function aiClassroomAssign(array $actor, array $args): array {
    aiRequireWrite($actor);
    $requestId = aiText($args['request_id'] ?? '', 'request_id', 100);
    if (!preg_match('/^[A-Za-z0-9_-]{8,100}$/D', $requestId)) throw new QuizAiError('request_id must be 8-100 letters, digits, underscores or hyphens.');
    $targetType = aiText($args['target_type'] ?? '', 'target_type', 20);
    $targetId = aiText($args['target_id'] ?? '', 'target_id', 100);
    $kind = aiText($args['kind'] ?? '', 'kind', 20);
    $contentId = aiText($args['content_id'] ?? '', 'content_id', 100);
    $teacher = $actor['teacher_id'];
    $receiptKey = 'ai:' . $teacher . ':' . $requestId;
    try {
        $result = classroomUpdate(function (&$data) use ($teacher, $targetType, $targetId, $kind, $contentId, $receiptKey) {
            $existing = $data['assignment_receipts'][$receiptKey] ?? null;
            if ($existing !== null) {
                $assignment = $data['assignments'][$existing] ?? null;
                if (!$assignment) throw new DomainException('Uppgiften finns inte kvar.');
                $target = $data[$assignment['target_type']][$assignment['target_id']] ?? null;
                return ['assignment' => $assignment, 'target_name' => $target['name'] ?? '',
                    'recipients' => count(classroomRecipients($data, $assignment)), 'repeated' => true];
            }
            $created = classroomAssign($data, $teacher, $targetType, $targetId, $kind, $contentId, 'ai');
            $data['assignment_receipts'][$receiptKey] = $created['assignment']['id'];
            return $created + ['repeated' => false];
        });
    } catch (DomainException $e) {
        throw new QuizAiError($e->getMessage(), 400);
    }
    return ['assignment_id' => $result['assignment']['id'], 'kind' => $result['assignment']['kind'],
        'title' => $result['assignment']['title'], 'target_type' => $result['assignment']['target_type'],
        'target_name' => $result['target_name'], 'recipients' => $result['recipients'],
        'repeated' => $result['repeated'],
        'note' => 'The pupils see this now. Withdrawing an assignment is not possible in the app.'];
}
function aiClassroomRead(): array {
    try { return classroomRead(); }
    catch (Throwable $e) { throw new QuizAiError('Class data could not be read.', 503); }
}
