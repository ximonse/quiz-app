<?php
function aiValidateImageQuestion(array $item, string $teacherId): array {
    if (array_diff(array_keys($item),['question','choices','image'])) throw new QuizAiError('Image questions support question, choices and optional information image.');
    $choices=$item['choices']??null;
    if (!is_array($choices) || !array_is_list($choices) || count($choices)<2 || count($choices)>6)
        throw new QuizAiError('Each image question needs 2 to 6 pictures.');
    $validated=[]; $ids=[]; $files=[]; $correct=0;
    foreach ($choices as $choice) {
        if (!is_array($choice) || array_diff(array_keys($choice),['id','image','correct'])) throw new QuizAiError('Each choice needs id, image and correct.');
        $id=aiText($choice['id']??'', 'choice.id',60);
        if (!preg_match('/^[A-Za-z0-9_-]+$/D',$id) || in_array($id,$ids,true)) throw new QuizAiError('Choice IDs must be unique letters, digits, underscores or hyphens.');
        $image=aiValidateImage($choice['image']??null,$teacherId);
        if (!$image || in_array($image['file'],$files,true)) throw new QuizAiError('Each answer choice needs its own uploaded image.');
        if (!is_bool($choice['correct']??null)) throw new QuizAiError('choice.correct must be boolean.');
        $correct+=(int)$choice['correct']; $ids[]=$id; $files[]=$image['file'];
        $validated[]=['id'=>$id,'image'=>$image,'correct'=>$choice['correct']];
    }
    if (!$correct) throw new QuizAiError('Mark at least one picture as correct.');
    return ['question'=>aiText($item['question']??'', 'question'), 'choices'=>$validated,
        'image'=>aiValidateImage($item['image']??null,$teacherId)];
}
function aiImageMarkdown(array $document): string {
    $text='# '.$document['title']."\n\n";
    foreach ($document['items'] as $i=>$item) {
        $text.='## '.($i+1).'. '.$item['question']."\n\n";
        if ($item['image']) $text.='Infobild: !['.$item['image']['alt'].']('.aiBaseUrl().'/api/question-image.php?file='.rawurlencode($item['image']['file']).")\n\n";
        foreach ($item['choices'] as $j=>$choice) {
            $text.='Bild '.($j+1).' — '.($choice['correct']?'**Rätt**':'Fel')."\n\n![";
            $text.=str_replace([']',"\n"],'',$choice['image']['alt']).']('.aiBaseUrl().'/api/question-image.php?file='.rawurlencode($choice['image']['file']).")\n\n";
        }
    }
    return $text;
}
