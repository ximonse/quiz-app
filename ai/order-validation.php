<?php
const ORDER_MAX_OBJECTS = 8;
function aiValidateOrderQuestion(array $item, string $teacherId): array {
    if (array_diff(array_keys($item), ['question','image','orientation','labels','objects']))
        throw new QuizAiError('Order questions support question, image, orientation, labels and objects.');
    $orientation = $item['orientation'] ?? 'horizontal';
    if (!in_array($orientation, ['horizontal','vertical'], true)) throw new QuizAiError('orientation must be horizontal or vertical.');
    $labels = $item['labels'] ?? [];
    if (!is_array($labels) || array_diff(array_keys($labels), ['start','middle','end'])) throw new QuizAiError('labels support start, middle and end.');
    $validLabels = [];
    foreach (['start','middle','end'] as $key) $validLabels[$key] = aiText($labels[$key] ?? '', "labels.$key", 100, false);
    $objects = $item['objects'] ?? null;
    if (!is_array($objects) || !array_is_list($objects) || count($objects) < 2 || count($objects) > ORDER_MAX_OBJECTS)
        throw new QuizAiError('Each order question needs 2 to '.ORDER_MAX_OBJECTS.' objects, listed in the correct order.');
    $seen = []; $valid = [];
    foreach ($objects as $object) {
        if (!is_array($object) || array_diff(array_keys($object), ['id','text','image'])) throw new QuizAiError('Order objects support id, text and image.');
        $entry = ['id'=>aiSortId($object['id'] ?? '', $seen), 'text'=>aiText($object['text'] ?? '', 'object.text', 200, false),
            'image'=>aiValidateImage($object['image'] ?? null, $teacherId)];
        if ($entry['text'] === '' && !$entry['image']) throw new QuizAiError('Each object needs text or an image.');
        $valid[] = $entry;
    }
    return ['question'=>aiText($item['question'] ?? '', 'question'), 'image'=>aiValidateImage($item['image'] ?? null, $teacherId),
        'orientation'=>$orientation, 'labels'=>$validLabels, 'objects'=>$valid];
}
function aiOrderMarkdown(array $document): string {
    $text = '# '.$document['title']."\n\n";
    foreach ($document['items'] as $i=>$item) {
        $text .= '## '.($i+1).'. '.$item['question']."\n\n";
        $labels = array_filter($item['labels']);
        $text .= ($item['orientation'] === 'vertical' ? 'Lodrät linje' : 'Vågrät linje').($labels ? ' · '.implode(' → ', $labels) : '')."\n\n";
        foreach ($item['objects'] as $j=>$object) $text .= ($j+1).'. '.aiSortLabel($object)."\n";
        $text .= "\n";
    }
    return $text;
}
