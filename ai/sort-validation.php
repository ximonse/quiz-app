<?php
const SORT_MAX_FIELDS = 5;
const SORT_MAX_OBJECTS = 12;
function aiSortId($value, array &$seen): string {
    $id = aiText($value ?? '', 'id', 60);
    if (!preg_match('/^[A-Za-z0-9_-]+$/D', $id) || in_array($id, $seen, true))
        throw new QuizAiError('Field and object IDs must be unique letters, digits, underscores or hyphens.');
    $seen[] = $id; return $id;
}
function aiValidateSortQuestion(array $item, string $teacherId): array {
    if (array_diff(array_keys($item), ['question','flashcard_prompt','image','fields','objects']))
        throw new QuizAiError('Sort questions support question, flashcard_prompt, image, fields and objects.');
    $fields = $item['fields'] ?? null; $objects = $item['objects'] ?? null;
    if (!is_array($fields) || !array_is_list($fields) || count($fields) < 1 || count($fields) > SORT_MAX_FIELDS)
        throw new QuizAiError('Each sort question needs 1 to '.SORT_MAX_FIELDS.' fields.');
    if (!is_array($objects) || !array_is_list($objects) || count($objects) < 2 || count($objects) > SORT_MAX_OBJECTS)
        throw new QuizAiError('Each sort question needs 2 to '.SORT_MAX_OBJECTS.' objects.');
    $seen = []; $validFields = [];
    foreach ($fields as $field) {
        if (!is_array($field) || array_diff(array_keys($field), ['id','title','text','image'])) throw new QuizAiError('Fields support id, title, text and image.');
        $entry = ['id'=>aiSortId($field['id'] ?? '', $seen), 'title'=>aiText($field['title'] ?? '', 'field.title', 100, false),
            'text'=>aiText($field['text'] ?? '', 'field.text', 500, false), 'image'=>aiValidateImage($field['image'] ?? null, $teacherId)];
        if ($entry['title'] === '' && !$entry['image']) throw new QuizAiError('Each field needs a title or an image.');
        $validFields[] = $entry;
    }
    $fieldIds = array_column($validFields, 'id'); $validObjects = []; $placed = 0;
    foreach ($objects as $object) {
        if (!is_array($object) || array_diff(array_keys($object), ['id','text','image','field'])) throw new QuizAiError('Objects support id, text, image and field.');
        $entry = ['id'=>aiSortId($object['id'] ?? '', $seen), 'text'=>aiText($object['text'] ?? '', 'object.text', 200, false),
            'image'=>aiValidateImage($object['image'] ?? null, $teacherId), 'field'=>$object['field'] ?? null];
        if ($entry['text'] === '' && !$entry['image']) throw new QuizAiError('Each object needs text or an image.');
        if ($entry['field'] !== null && !in_array($entry['field'], $fieldIds, true)) throw new QuizAiError('object.field must be a field ID or null.');
        $placed += (int)($entry['field'] !== null); $validObjects[] = $entry;
    }
    if (!$placed) throw new QuizAiError('At least one object must belong to a field.');
    return ['question'=>aiText($item['question'] ?? '', 'question'), 'flashcard_prompt'=>aiText($item['flashcard_prompt'] ?? '', 'flashcard_prompt', 300, false),
        'image'=>aiValidateImage($item['image'] ?? null, $teacherId), 'fields'=>$validFields, 'objects'=>$validObjects];
}
function aiSortLabel(array $entry): string { return $entry['title'] ?? '' ?: ($entry['text'] ?? '' ?: ($entry['image']['alt'] ?? '')); }
function aiSortMarkdown(array $document): string {
    $text = '# '.$document['title']."\n\n";
    foreach ($document['items'] as $i=>$item) {
        $text .= '## '.($i+1).'. '.$item['question']."\n\n";
        if ($item['flashcard_prompt'] !== '') $text .= 'Ledtråd för flashcards: '.$item['flashcard_prompt']."\n\n";
        foreach ($item['fields'] as $field) {
            $text .= '### '.aiSortLabel($field).($field['text'] !== '' && $field['title'] !== '' ? ' — '.$field['text'] : '')."\n\n";
            foreach ($item['objects'] as $object) if ($object['field'] === $field['id']) $text .= '- '.aiSortLabel($object)."\n";
            $text .= "\n";
        }
        $loose = array_filter($item['objects'], fn($o) => $o['field'] === null);
        if ($loose) { $text .= "### Hör inte till något fält\n\n"; foreach ($loose as $object) $text .= '- '.aiSortLabel($object)."\n"; $text .= "\n"; }
    }
    return $text;
}
