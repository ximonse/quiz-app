<?php
require_once __DIR__ . '/common.php';
function aiEffectiveQuiz(array $quiz): array {
    return array_replace($quiz, $quiz['pending_document'] ?? []);
}
function aiPublished(array $quiz): bool { return ($quiz['publication'] ?? 'published') === 'published'; }
function aiReviewState(array $quiz): string {
    return !aiPublished($quiz) ? 'draft' : (isset($quiz['pending_document']) ? 'pending' : 'published');
}
function aiStageDocument(array &$quiz, array $document): void {
    if (aiPublished($quiz)) $quiz['pending_document'] = array_replace($quiz['pending_document'] ?? [], $document);
    else $quiz = array_replace($quiz, $document);
    $quiz['updated'] = date('Y-m-d H:i:s');
}
function aiPublishQuiz(string $id, string $teacherId, string $revision): array {
    return aiUpdate(QUIZZES_FILE, function (&$quizzes) use ($id,$teacherId,$revision) {
        $quiz=aiOwn($quizzes,$id,$teacherId);
        if (!hash_equals(aiRevision($quiz),$revision)) throw new QuizAiError('Quizet ändrades. Granska den nya versionen innan du publicerar.',409);
        $quiz=aiEffectiveQuiz($quiz); unset($quiz['pending_document']);
        $quiz['publication']='published'; $quiz['published_at']=date('Y-m-d H:i:s');
        $quizzes[$id]=$quiz; return aiView($quiz);
    });
}
function aiStudentQuiz(array $quiz, bool $preview = false): array {
    if ($preview) {
        if (!isLoggedInAsTeacher() || ($quiz['teacher_id'] ?? null)!==getCurrentTeacherID())
            throw new QuizAiError('Förhandsvisning kräver ditt lärarkonto.',403);
        return aiEffectiveQuiz($quiz);
    }
    if (!aiPublished($quiz)) throw new QuizAiError('Quizet är inte publicerat ännu.',403);
    return $quiz;
}
