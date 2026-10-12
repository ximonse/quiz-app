<?php
// Shared strict storage: use the same lock as the existing quiz editor/player.
class QuizAiError extends RuntimeException {
    public int $status;
    public function __construct(string $message, int $status = 400) {
        parent::__construct($message); $this->status = $status;
    }
}
function aiRead(string $path): array {
    if (!is_file($path)) return [];
    try { $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { throw new QuizAiError('Data could not be read. No changes were saved.', 503); }
    if (!is_array($data)) throw new QuizAiError('Invalid data file.', 503);
    return $data;
}
function aiUpdate(string $path, callable $change) {
    $lock = fopen($path . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new QuizAiError('Storage busy. Try again.', 503);
    try {
        $data = aiRead($path); $result = $change($data);
        if (!writeJSON($path, $data)) throw new QuizAiError('Could not save changes.', 503);
        return $result;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function aiText($value, string $field, int $max = 2000, bool $required = true): string {
    if (!is_string($value) || strlen($value) > $max || ($required && trim($value) === ''))
        throw new QuizAiError("Invalid $field (maximum $max bytes).");
    return trim($value);
}
function aiBaseUrl(): string {
    $configured = getenv('QUIZ_AI_BASE_URL');
    return rtrim($configured ?: 'https://ximon.se/quiz-app', '/');
}
function aiJson($value, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function aiBody(): array {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8 * 1024 * 1024) throw new QuizAiError('Request too large.', 413);
    $raw = file_get_contents('php://input', false, null, 0, 8 * 1024 * 1024 + 1);
    if (strlen($raw) > 8 * 1024 * 1024) throw new QuizAiError('Request too large.', 413);
    try { $data = json_decode($raw, true, 128, JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { throw new QuizAiError('Expected a JSON object.'); }
    if (!is_array($data) || array_is_list($data)) throw new QuizAiError('Expected a JSON object.');
    return $data;
}
function aiTransportGuard(): void {
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
    if (!$local && (empty($_SERVER['HTTPS']) || strtolower($_SERVER['HTTPS']) === 'off')) throw new QuizAiError('HTTPS required.', 403);
    if (isset($_SERVER['HTTP_ORIGIN']) && rtrim($_SERVER['HTTP_ORIGIN'], '/') !==
        (parse_url(aiBaseUrl(), PHP_URL_SCHEME) . '://' . parse_url(aiBaseUrl(), PHP_URL_HOST) .
        (parse_url(aiBaseUrl(), PHP_URL_PORT) ? ':' . parse_url(aiBaseUrl(), PHP_URL_PORT) : '')))
        throw new QuizAiError('Origin not allowed.', 403);
}
