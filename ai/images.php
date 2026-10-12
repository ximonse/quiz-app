<?php
require_once __DIR__ . '/common.php';
function aiImageName(string $name): bool {
    return (bool)preg_match('/^ai_[a-f0-9]{32}\.(png|jpg|webp|gif)$/D', $name);
}
function aiStoreImage(string $bytes, string $teacherId): array {
    if (strlen($bytes) > 5 * 1024 * 1024) throw new QuizAiError('Image exceeds 5 MB.', 413);
    $info = @getimagesizefromstring($bytes);
    $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!$info || !isset($types[$info['mime']]) || $info[0] > 8000 || $info[1] > 8000 || $info[0] * $info[1] > 24000000)
        throw new QuizAiError('Use PNG, JPG, WebP or GIF, at most 8000 pixels per side and 24 megapixels.');
    if (!is_dir(IMAGE_DIR) && !mkdir(IMAGE_DIR, 0755, true)) throw new QuizAiError('Image storage unavailable.', 503);
    $name = 'ai_' . bin2hex(random_bytes(16)) . '.' . $types[$info['mime']];
    if (file_put_contents(IMAGE_DIR . $name, $bytes, LOCK_EX) === false) throw new QuizAiError('Could not save image.', 503);
    try {
        aiUpdate(DATA_DIR . 'ai-images.json', function (&$images) use ($name, $teacherId) {
            $images[$name] = ['teacher_id' => $teacherId, 'created' => time()];
        });
    } catch (Throwable $e) { unlink(IMAGE_DIR . $name); throw $e; }
    return ['image' => $name, 'url' => aiBaseUrl() . '/api/question-image.php?file=' . $name];
}
function aiValidateImage($image, string $teacherId): ?array {
    if ($image === null) return null;
    if (!is_array($image)) throw new QuizAiError('image must contain file and alt.');
    $name = aiText($image['file'] ?? '', 'image.file', 100);
    $owner = aiRead(DATA_DIR . 'ai-images.json')[$name]['teacher_id'] ?? null;
    $legacyOwned = false;
    if (preg_match('/^img_[a-f0-9]{8}\.(png|jpg|jpeg|webp|gif)$/Di', $name)) {
        foreach (aiRead(QUIZZES_FILE) as $quiz) {
            if (($quiz['teacher_id'] ?? null) !== $teacherId) continue;
            foreach ($quiz['questions'] ?? [] as $question) if (($question['image'] ?? null) === $name) $legacyOwned = true;
        }
    }
    if ((!aiImageName($name) && !$legacyOwned) || ($owner !== $teacherId && !$legacyOwned) || !is_file(IMAGE_DIR . $name))
        throw new QuizAiError('Upload the image with this teacher account first.');
    return ['file' => $name, 'alt' => aiText($image['alt'] ?? '', 'image.alt', 500)];
}
