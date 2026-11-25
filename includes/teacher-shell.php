<?php
// Gemensam header för lärarsidorna. Samma länkar, samma ordning och samma
// markering av var man är, oavsett vilken stilmall sidan i övrigt använder:
// stilen ligger i assets/teacher-shell.css och är scopad till .teacher-shell,
// så den inte rör någon annan del av sidan.
//
// $current är fliken sidan hör till, inte sidan själv: klass-, elev- och
// kortvyerna hör till Klasser, statistiken hör till Innehåll.

function teacherShellEscape($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }

// Andra raden hör till fliken man står i, inte till appen som helhet: att visa
// elevkort i quizbiblioteket och importfunktionen i klassvyn gör bara raden
// längre utan att hjälpa. Logga ut finns överallt.
function teacherShellSideLinks(string $current): array {
    $perTab=[
        'innehall'=>[['batch-import.php','Importera flera quiz'],['browser-ids.php','Oinloggade spelare']],
        'klasser'=>[['pupil.php','Hitta elev'],['cards.php','Inloggningskort']],
        'uppfoljning'=>[['pupil.php','Hitta elev'],['cards.php','Inloggningskort']],
    ];
    return array_merge($perTab[$current] ?? [],[['../index.php?logout=1','Logga ut']]);
}
// $title är sidans egen rubrik, $meta en kort rad under den (valfri).
// Alla sidor som använder den här ligger i admin/, så länkarna är relativa dit.
function teacherShell(string $current, string $title, string $meta=''): void {
    $tabs=[['innehall','dashboard.php','Innehåll'],['klasser','classes.php','Klasser'],['uppfoljning','followup.php','Uppföljning']];
    echo '<div class="teacher-shell"><div class="teacher-shell-top"><div><h1>'.teacherShellEscape($title).'</h1>';
    if ($meta!=='') echo '<p class="teacher-shell-meta">'.teacherShellEscape($meta).'</p>';
    echo '</div></div><nav class="teacher-tabs" aria-label="Huvudmeny">';
    foreach ($tabs as [$key,$href,$label]) {
        $here=$key===$current;
        echo '<a href="'.teacherShellEscape($href).'"'.($here?' aria-current="page"':'').'>'.teacherShellEscape($label).'</a>';
    }
    echo '</nav><div class="teacher-side">';
    foreach (teacherShellSideLinks($current) as [$href,$label]) {
        echo '<a href="'.teacherShellEscape($href).'">'.teacherShellEscape($label).'</a>';
    }
    echo '</div></div>';
}
