<?php
// One source for the quiz settings contract.
//
// aiValidateDocument() enforces these arrays, and aiQuizSchemaText() renders the
// description the AI tools advertise from the same arrays. Before this, the rules
// lived in validation.php while two hand-written copies of the text lived in
// tools.php, so an added setting reached the validator and never the AI — which
// then guessed values and got "Unknown settings" back. Add a setting here once.
function aiSettingDefaults(): array {
    return ['answer_mode'=>'multiple_choice', 'question_direction'=>'concept', 'concept_language'=>'sv',
        'required_correct'=>1, 'reverse_enabled'=>false, 'reverse_answer_mode'=>'multiple_choice',
        'reverse_required_correct'=>1, 'quiz_mode'=>'training', 'tts_enabled'=>true, 'language'=>'sv',
        'spelling_mode'=>'student_choice', 'time_lock'=>null, 'generate_flashcards'=>false, 'flashcard_mix_directions'=>false,
        'spaced_repetition'=>false];
}
// Settings limited to a fixed set of values.
function aiSettingChoices(): array {
    return ['answer_mode'=>['multiple_choice','text_only','hybrid'], 'reverse_answer_mode'=>['multiple_choice','text_only','hybrid'],
        'question_direction'=>['concept','description'], 'concept_language'=>['sv','same'], 'quiz_mode'=>['training','test'],
        'spelling_mode'=>['easy','puritan','student_choice']];
}
function aiSettingIntegers(): array {
    return ['required_correct'=>[1,10], 'reverse_required_correct'=>[1,10]];
}
function aiSettingBooleans(): array {
    return ['reverse_enabled','tts_enabled','generate_flashcards','flashcard_mix_directions','spaced_repetition'];
}
// Types answered by selection only. 'flashcards' says whether the type can also
// produce flashcards; sort can, picture and order questions cannot.
function aiTypeRules(): array {
    return [
        'image_choice'=>['flashcards'=>false,'message'=>'Image choices use multiple_choice, without reverse mode or flashcards.'],
        'sort'=>['flashcards'=>true,'message'=>'Sort quizzes use drag and drop, without reverse mode.'],
        'order'=>['flashcards'=>false,'message'=>'Order quizzes use drag and drop, without reverse mode or flashcards.'],
    ];
}
// Types whose questions the review player can show, so spaced_repetition is
// accepted rather than stored as a flag that silently does nothing.
function aiRepetitionTypes(): array {
    return ['fact', 'glossary'];
}
// The description every AI client reads. Generated, so it cannot drift from the
// validator above.
function aiQuizSchemaText(): string {
    $settings = [];
    $defaults = aiSettingDefaults();
    $choices = aiSettingChoices();
    $integers = aiSettingIntegers();
    foreach ($defaults as $key => $default) {
        if (isset($choices[$key])) $values = implode('|', $choices[$key]);
        elseif (isset($integers[$key])) $values = 'integer '.$integers[$key][0].'-'.$integers[$key][1];
        elseif (in_array($key, aiSettingBooleans(), true)) $values = 'boolean';
        elseif ($key === 'language') $values = 'language tag like sv or en-GB';
        elseif ($key === 'time_lock') $values = 'null or {opens,closes} as local YYYY-MM-DD HH:MM, opens before closes';
        else $values = 'string';
        $shown = $default === null ? 'null' : (is_bool($default) ? ($default ? 'true' : 'false') : (string)$default);
        $settings[] = $key.'='.$values.' (default '.$shown.')';
    }
    $repetition = implode('/', aiRepetitionTypes());
    return 'Complete editable quiz. Fields: title (required, max 300 BYTES - a, a and o cost two each), '
        .'type (fact/glossary/image_choice/sort/order), optional subject and grade (100 bytes), tags (500 bytes), '
        .'settings, items (1-200). Never send id, results or teacher_id; any other field is rejected. '
        ."\n\nITEMS. Fact: concept=the question, description=the correct answer, wrong_options (up to 3), "
        .'optional reverse_wrong_options, optional image:{file,alt}. Glossary: sentence, word, translation - all three '
        .'REQUIRED - plus the same optional fields. Every wrong option must differ from the other options and from the '
        .'correct answer. Image choice: {question, choices:[{id,image:{file,alt,optional crop:{x,y,zoom}},correct}], '
        .'optional image for information}, 2-6 choices, at least one correct; generate each answer picture separately in '
        .'landscape 4:3, subject clear of the edges, no text or labels. Sort (drag objects into fields): {question, '
        .'optional flashcard_prompt shown before the object on flashcards, fields:[{id,title,optional text,optional image}] '
        .'(1-5, each needs a title or an image), objects:[{id,text and/or image,field:a field id or null for a distractor}] '
        .'(2-12, at least one placed)}. Order (place objects on a line): {question, orientation:horizontal|vertical, '
        .'optional labels:{start,middle,end}, objects:[{id,text and/or image}] (2-8) listed in the CORRECT order - the app '
        .'shuffles them}. Sort and order IDs are unique per question and must stay stable when editing. Upload image bytes '
        .'with quiz_upload_image first and use the returned file reference. Existing legacy quizzes use questions exactly '
        .'as returned by quiz_get.'
        ."\n\nSETTINGS (all optional, any unlisted key is rejected): ".implode('; ', $settings).'. '
        .'image_choice, sort and order require answer_mode=multiple_choice and reverse_enabled=false; of those three only '
        .'sort allows generate_flashcards. spaced_repetition is accepted on '.$repetition.' quizzes only, and it does '
        .'nothing on its own: the teacher must publish the quiz and assign it to a class or group, and only a logged-in '
        .'pupil answering it that way builds repetition cards. Nothing is due for review the same day.';
}
