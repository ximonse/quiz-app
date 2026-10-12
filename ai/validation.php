<?php
require_once __DIR__ . '/images.php';
require_once __DIR__ . '/image-validation.php';
function aiValidateDocument(array $input, string $teacherId): array {
    $allowed = ['title', 'type', 'subject', 'grade', 'tags', 'settings', 'items'];
    if (array_diff(array_keys($input), $allowed)) throw new QuizAiError('Unknown quiz fields. Do not include id, results or teacher_id.');
    $type = $input['type'] ?? '';
    if (!in_array($type, ['fact', 'glossary', 'image_choice'], true)) throw new QuizAiError('type must be fact, glossary or image_choice.');
    $items = $input['items'] ?? null;
    if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > 200)
        throw new QuizAiError('Provide 1 to 200 items.');
    $normalized = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) throw new QuizAiError('Invalid item.');
        if ($type === 'image_choice') { $normalized[] = aiValidateImageQuestion($item,$teacherId); continue; }
        $fields = $type === 'fact' ? ['concept', 'description'] : ['sentence', 'word', 'translation'];
        if (array_diff(array_keys($item), array_merge($fields, ['wrong_options', 'reverse_wrong_options', 'image'])))
            throw new QuizAiError('Unknown item fields.');
        $entry = [];
        foreach ($fields as $field) $entry[$field] = aiText($item[$field] ?? '', "items.$index.$field");
        foreach (['wrong_options', 'reverse_wrong_options'] as $field) {
            $values = $item[$field] ?? [];
            if (!is_array($values) || !array_is_list($values) || count($values) > 3) throw new QuizAiError("$field needs at most three answers.");
            $entry[$field] = array_map(fn($v) => aiText($v, $field), $values);
            $correct = $field === 'wrong_options' ? ($type === 'fact' ? $entry['description'] : $entry['translation']) :
                ($type === 'fact' ? $entry['concept'] : $entry['word']);
            $choices = array_map(fn($v) => function_exists('mb_strtolower') ? mb_strtolower($v) : strtolower($v), array_merge([$correct], $entry[$field]));
            if (count(array_unique($choices)) !== count($choices)) throw new QuizAiError('Answers must be distinct, including the correct answer.');
        }
        $entry['image'] = aiValidateImage($item['image'] ?? null, $teacherId);
        $normalized[] = $entry;
    }
    $settings = $input['settings'] ?? [];
    if (!is_array($settings)) throw new QuizAiError('Invalid settings.');
    $defaults = ['answer_mode'=>'multiple_choice', 'question_direction'=>'concept', 'concept_language'=>'sv',
        'required_correct'=>1, 'reverse_enabled'=>false, 'reverse_answer_mode'=>'multiple_choice',
        'reverse_required_correct'=>1, 'quiz_mode'=>'training', 'tts_enabled'=>true, 'language'=>'sv',
        'spelling_mode'=>'student_choice', 'time_lock'=>null, 'generate_flashcards'=>false, 'flashcard_mix_directions'=>false];
    if (array_diff(array_keys($settings), array_keys($defaults))) throw new QuizAiError('Unknown settings.');
    $settings = array_replace($defaults, $settings);
    foreach (['answer_mode'=>['multiple_choice','text_only','hybrid'], 'reverse_answer_mode'=>['multiple_choice','text_only','hybrid'],
        'question_direction'=>['concept','description'], 'concept_language'=>['sv','same'], 'quiz_mode'=>['training','test'],
        'spelling_mode'=>['easy','puritan','student_choice']] as $key=>$values)
        if (!in_array($settings[$key], $values, true)) throw new QuizAiError("Invalid $key.");
    foreach (['required_correct','reverse_required_correct'] as $key)
        if (!is_int($settings[$key]) || $settings[$key] < 1 || $settings[$key] > 10) throw new QuizAiError("$key must be 1 to 10.");
    foreach (['reverse_enabled','tts_enabled','generate_flashcards','flashcard_mix_directions'] as $key)
        if (!is_bool($settings[$key])) throw new QuizAiError("$key must be boolean.");
    if (!is_string($settings['language']) || !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/D', $settings['language'])) throw new QuizAiError('Invalid language.');
    if ($settings['time_lock'] !== null) {
        if (!is_array($settings['time_lock']) || array_diff(array_keys($settings['time_lock']), ['opens','closes'])) throw new QuizAiError('Invalid time_lock.');
        foreach (['opens','closes'] as $field) {
            $value = $settings['time_lock'][$field] ?? null;
            if ($value !== null) {
                $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value) : false;
                if (!$date || $date->format('Y-m-d H:i') !== $value) throw new QuizAiError('Use local YYYY-MM-DD HH:MM for time locks.');
            }
            $settings['time_lock'][$field] = $value;
        }
        if ($settings['time_lock']['opens'] && $settings['time_lock']['closes'] && $settings['time_lock']['opens'] >= $settings['time_lock']['closes'])
            throw new QuizAiError('Opening must be before closing.');
    }
    if ($type === 'image_choice' && ($settings['answer_mode'] !== 'multiple_choice' || $settings['reverse_enabled'] || $settings['generate_flashcards'])) throw new QuizAiError('Image choices use multiple_choice, without reverse mode or flashcards.');
    return ['title'=>aiText($input['title'] ?? '', 'title', 300), 'type'=>$type,
        'subject'=>aiText($input['subject'] ?? '', 'subject', 100, false), 'grade'=>aiText($input['grade'] ?? '', 'grade', 100, false),
        'tags'=>aiText($input['tags'] ?? '', 'tags', 500, false), 'settings'=>$settings, 'items'=>$normalized];
}
