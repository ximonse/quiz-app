<?php
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/publication.php';
function aiDocument(array $quiz): array {
    $keys = isset($quiz['items']) ? ['title','type','subject','grade','tags','settings','items'] : ['title','type','subject','grade','tags','questions'];
    return array_intersect_key($quiz, array_flip($keys));
}
function aiRevision(array $quiz): string {
    return hash('sha256', json_encode([aiDocument(aiEffectiveQuiz($quiz)), aiReviewState($quiz), array_intersect_key(aiEffectiveQuiz($quiz),array_flip(['language','spelling_mode','answer_mode','quiz_mode','required_correct_phase1','required_correct_phase2','reverse_enabled','reverse_answer_mode','reverse_required_correct_phase1','reverse_required_correct_phase2']))], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}
function aiOwn(array $quizzes, string $id, string $teacherId): array {
    if (!isset($quizzes[$id]) || ($quizzes[$id]['teacher_id'] ?? null) !== $teacherId) throw new QuizAiError('Quiz not found in your account.', 404);
    return $quizzes[$id];
}
function aiMarkdown(array $document): string {
    if (($document['type']??'') === 'image_choice') return aiImageMarkdown($document);
    if (($document['type']??'') === 'sort') return aiSortMarkdown($document);
    if (($document['type']??'') === 'order') return aiOrderMarkdown($document);
    $text = '# ' . ($document['title'] ?? '') . "\n\n";
    foreach (($document['items'] ?? $document['questions'] ?? []) as $i => $item) {
        $legacy = isset($document['questions']); $reverse = !$legacy && ($document['settings']['question_direction'] ?? '') === 'description';
        if ($legacy) { $prompt = $item['question']; $answer = $item['answer']; $wrongs = array_values(array_filter($item['options'] ?? [], fn($v) => $v !== $answer)); }
        elseif ($document['type'] === 'glossary') { $prompt = $item['sentence'] . ' — ' . $item['word']; $answer = $item['translation']; $wrongs = $item['wrong_options']; }
        else { $prompt = $reverse ? $item['description'] : $item['concept']; $answer = $reverse ? $item['concept'] : $item['description'];
            $wrongs = $reverse ? (($item['reverse_wrong_options'] ?? []) ?: array_values(array_map(fn($v) => $v['concept'], array_filter($document['items'], fn($v) => $v['concept'] !== $answer)))) : $item['wrong_options']; }
        $text .= '## ' . ($i+1) . '. ' . $prompt . "\n\n- **Rätt svar: " . $answer . "**\n";
        foreach (array_slice($wrongs, 0, 3) as $wrong) $text .= '- ' . $wrong . "\n";
        if (!empty($item['image'])) {
            $image = is_array($item['image']) ? $item['image'] : ['file'=>$item['image'], 'alt'=>$item['image_alt'] ?? 'Frågebild'];
            $text .= "\n![" . str_replace([']', "\n"], '', $image['alt']) . '](' . aiBaseUrl() . '/api/question-image.php?file=' . rawurlencode($image['file']) . ")\n";
        }
        $text .= "\n";
    }
    return $text;
}
function aiView(array $quiz): array {
    $stored=$quiz; $quiz=aiEffectiveQuiz($quiz); $state=aiReviewState($stored);
    $legacy = !isset($quiz['items']); $id = $quiz['id'];
    return ['id'=>$id, 'revision'=>aiRevision($stored), 'publication'=>$state, 'review_url'=>aiBaseUrl().'/admin/review.php?id='.rawurlencode($id), 'format'=>$legacy ? 'legacy' : 'v2', 'quiz'=>aiDocument($quiz),
        'student_url'=>$state==='published' ? aiBaseUrl() . ($legacy ? '/q/' . rawurlencode($id) . '.html' : '/play/index.php?id=' . rawurlencode($id)) : null,
        'edit_url'=>aiBaseUrl() . ($legacy ? '/edit-quiz.php?quiz_id=' : '/admin/'.quizTypeRoute($quiz['type'])['editor'].'?id=') . rawurlencode($id),
        'markdown'=>aiMarkdown(aiDocument($quiz))];
}
function aiValidateLegacy(array $input, string $teacherId): array {
    if (array_diff(array_keys($input), ['title','type','subject','grade','tags','questions'])) throw new QuizAiError('Legacy edits support title, metadata and questions; settings remain unchanged.');
    $items = [];
    if (!is_array($input['questions'] ?? null) || !array_is_list($input['questions'])) throw new QuizAiError('Invalid questions.');
    foreach ($input['questions'] as $q) {
        if (!is_array($q) || array_diff(array_keys($q), ['question','answer','word','options','reverse_wrong_options','image','image_alt'])) throw new QuizAiError('Invalid legacy question fields.');
        if (!is_array($q['options'] ?? null) || !array_is_list($q['options'])) throw new QuizAiError('Legacy options must be an array.');
        $wrong = array_values(array_filter($q['options'] ?? [], fn($v) => $v !== ($q['answer'] ?? null)));
        $item = ($input['type'] ?? '') === 'glossary' ? ['sentence'=>$q['question'] ?? '', 'word'=>$q['word'] ?? '', 'translation'=>$q['answer'] ?? ''] :
            ['concept'=>$q['question'] ?? '', 'description'=>$q['answer'] ?? ''];
        $item['wrong_options'] = $wrong; $item['reverse_wrong_options'] = $q['reverse_wrong_options'] ?? [];
        $item['image'] = empty($q['image']) ? null : ['file'=>$q['image'], 'alt'=>$q['image_alt'] ?? 'Frågebild'];
        $items[] = $item;
    }
    $modern = $input; unset($modern['questions']); $modern['items'] = $items;
    $validated = aiValidateDocument($modern, $teacherId);
    $out = array_intersect_key($validated, array_flip(['title','type','subject','grade','tags'])); $out['questions'] = [];
    foreach ($validated['items'] as $item) {
        $glossary = $validated['type'] === 'glossary';
        $q = ['question'=>$item[$glossary ? 'sentence' : 'concept'], 'answer'=>$item[$glossary ? 'translation' : 'description'],
            'options'=>array_merge([$item[$glossary ? 'translation' : 'description']], $item['wrong_options'])];
        if ($glossary) $q['word'] = $item['word'];
        $q['reverse_wrong_options'] = $item['reverse_wrong_options'];
        if ($item['image']) { $q['image'] = $item['image']['file']; $q['image_alt'] = $item['image']['alt']; }
        $out['questions'][] = $q;
    }
    return $out;
}
function aiSearch(array $actor, array $args): array {
    $query = aiText($args['query'] ?? '', 'query', 200, false);
    $limit = $args['limit'] ?? 20; $offset = $args['offset'] ?? 0;
    if (!is_int($limit) || $limit < 1 || $limit > 50 || !is_int($offset) || $offset < 0) throw new QuizAiError('limit must be 1-50; offset must be nonnegative.');
    $found = [];
    foreach (aiRead(QUIZZES_FILE) as $id=>$quiz) {
        if (($quiz['teacher_id'] ?? '') !== $actor['teacher_id']) continue;
        $quiz=aiEffectiveQuiz($quiz);
        $haystack = implode(' ', [$quiz['title'] ?? '', $quiz['subject'] ?? '', $quiz['tags'] ?? '', $id]);
        if ($query !== '' && preg_match('/' . preg_quote($query, '/') . '/iu', $haystack) !== 1) continue;
        $view = aiView($quiz);
        $found[] = ['id'=>$id,'title'=>$quiz['title'],'type'=>$quiz['type'] ?? 'fact','subject'=>$quiz['subject'] ?? '',
            'question_count'=>count($quiz['items'] ?? $quiz['questions'] ?? []), 'format'=>$view['format'], 'publication'=>$view['publication'], 'review_url'=>$view['review_url'], 'revision'=>$view['revision'], 'student_url'=>$view['student_url']];
    }
    usort($found, fn($a,$b)=>strcmp($a['title'],$b['title'])); $total = count($found);
    return ['quizzes'=>array_slice($found,$offset,$limit),'total_count'=>$total,'has_more'=>$offset+$limit<$total,'next_offset'=>$offset+$limit<$total ? $offset+$limit : null];
}
function aiPublish(array $actor, array $args): array {
    aiRequireWrite($actor);
    if (($args['approved'] ?? null) !== true) throw new QuizAiError('Publishing requires explicit user approval (approved: true).',403);
    $id=aiText($args['id'] ?? '', 'id',100);
    $revision=aiText($args['revision'] ?? '', 'revision',64);
    if (!preg_match('/^[a-f0-9]{64}$/D',$revision)) throw new QuizAiError('Provide the revision returned by quiz_get.');
    $requestId=aiText($args['request_id'] ?? '', 'request_id',100);
    if (!preg_match('/^[A-Za-z0-9_-]{8,100}$/D',$requestId)) throw new QuizAiError('Use a unique request_id of 8-100 letters, digits, underscores or hyphens.');
    return aiUpdate(QUIZZES_FILE,function (&$quizzes) use ($actor,$id,$revision,$requestId) {
        $quiz=aiOwn($quizzes,$id,$actor['teacher_id']);
        foreach ($quizzes as $prior) {
            $receipt=$prior['ai_publications'][$requestId] ?? null;
            if (($prior['teacher_id'] ?? '') !== $actor['teacher_id'] || !$receipt) continue;
            if ($prior['id'] !== $id || $receipt['revision'] !== $revision)
                throw new QuizAiError('request_id was already used for a different publication.',409);
            // A retry must never publish edits staged after this request succeeded.
            return aiView($quiz);
        }
        $quiz=aiPublishDocument($quiz,$revision);
        $quiz['ai_publications'][$requestId]=['revision'=>$revision];
        $quizzes[$id]=$quiz;
        return aiView($quiz);
    });
}
function aiSave(array $actor, array $args, bool $create): array {
    aiRequireWrite($actor);
    // Saves remain unpublished; explicitly approved publication is a separate operation.
    if (!is_array($args['quiz'] ?? null)) throw new QuizAiError('Provide the complete quiz document.');
    $legacy = isset($args['quiz']['questions']);
    if ($create && $legacy) throw new QuizAiError('Create new quizzes with v2 items.');
    $doc = $legacy ? aiValidateLegacy($args['quiz'], $actor['teacher_id']) : aiValidateDocument($args['quiz'], $actor['teacher_id']);
    $requestId = aiText($args['request_id'] ?? '', 'request_id', 100);
    if (!preg_match('/^[A-Za-z0-9_-]{8,100}$/D', $requestId)) throw new QuizAiError('Use a unique request_id of 8-100 letters, digits, underscores or hyphens.');
    $requestHash = hash('sha256', json_encode($doc, JSON_THROW_ON_ERROR));
    $id = $create ? 'q_' . bin2hex(random_bytes(8)) : aiText($args['id'] ?? '', 'id', 100);
    $revision = $create ? '' : aiText($args['revision'] ?? '', 'revision', 64);
    return aiUpdate(QUIZZES_FILE, function (&$quizzes) use ($actor,$doc,$requestId,$requestHash,$id,$revision,$create,$legacy) {
        foreach ($quizzes as $prior) {
            $receipt = $prior['ai_requests'][$requestId] ?? null;
            if (($prior['teacher_id'] ?? '') === $actor['teacher_id'] && $receipt) {
                if ($receipt['hash'] !== $requestHash || $receipt['create'] !== $create || (!$create && $prior['id'] !== $id))
                    throw new QuizAiError('request_id was already used for different content.', 409);
                return aiView($prior);
            }
        }
        if ($create) $quiz = ['id'=>$id, 'teacher_id'=>$actor['teacher_id'], 'created'=>date('Y-m-d H:i:s'), 'results'=>[], 'publication'=>'draft'];
        else {
            $quiz = aiOwn($quizzes,$id,$actor['teacher_id']);
            if ($legacy !== !isset($quiz['items']) || $doc['type'] !== ($quiz['type'] ?? 'fact')) throw new QuizAiError('Keep the existing quiz format and type.');
            if (!hash_equals(aiRevision($quiz),$revision)) throw new QuizAiError('Quiz changed since it was read. Read it again before saving another review version.', 409);
        }
        // Merge only the editable document; keep results, progress and unrelated fields.
        aiStageDocument($quiz,$doc); // Keep the currently published document available to students.
        $quiz['ai_requests'][$requestId] = ['hash'=>$requestHash,'create'=>$create];
        $quizzes[$id] = $quiz;
        return aiView($quiz);
    });
}
