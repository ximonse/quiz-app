<?php
require_once __DIR__ . '/common.php';
function aiTokenFile(): string { return DATA_DIR . 'ai-tokens.json'; }
function aiIssueToken(string $teacherId, string $label, bool $write): array {
    $label = aiText($label, 'label', 100);
    $secret = 'qa_' . bin2hex(random_bytes(32)); $id = bin2hex(random_bytes(8));
    $record = ['teacher_id' => $teacherId, 'label' => $label, 'write' => $write,
        'hash' => hash('sha256', $secret), 'created' => time(), 'expires' => time() + 90 * 86400];
    aiUpdate(aiTokenFile(), function (&$tokens) use ($id, $record) { $tokens[$id] = $record; });
    return ['id' => $id, 'secret' => $secret];
}
function aiAuthenticate(): array {
    aiTransportGuard();
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer (qa_[a-f0-9]{64})$/D', $header, $match))
        throw new QuizAiError('A quiz API key is required in the Authorization: Bearer header.', 401);
    $hash = hash('sha256', $match[1]);
    foreach (aiRead(aiTokenFile()) as $id => $token) {
        if (!isset($token['hash']) || !hash_equals($token['hash'], $hash)) continue;
        $teacher = aiRead(TEACHERS_FILE)[$token['teacher_id']] ?? null;
        if (($token['expires'] ?? 0) <= time() || !empty($token['revoked']) || !$teacher || empty($teacher['active']))
            throw new QuizAiError('API key expired, revoked, or account inactive.', 401);
        return ['teacher_id' => $token['teacher_id'], 'name' => $teacher['name'] ?? 'Lärare',
            'write' => !empty($token['write']), 'token_id' => $id];
    }
    throw new QuizAiError('Invalid API key.', 401);
}
function aiRequireWrite(array $actor): void {
    if (empty($actor['write'])) throw new QuizAiError('This API key is read-only.', 403);
}
