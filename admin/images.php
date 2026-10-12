<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ai/quiz-service.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }
$id=$_GET['id']??''; $teacherId=getCurrentTeacherID(); $error=''; $success='';
try {
    $quiz=aiOwn(aiRead(QUIZZES_FILE),$id,$teacherId);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        requireValidCsrf(); $index=filter_var($_POST['index']??'',FILTER_VALIDATE_INT);
        $revision=$_POST['revision']??''; $key=isset($quiz['items'])?'items':'questions';
        if ($index===false || !isset($quiz[$key][$index])) throw new QuizAiError('Frågan finns inte.');
        $image=null;
        if (($_POST['action']??'')!=='remove') {
            $alt=aiText($_POST['alt']??'','bildbeskrivning',500);
            $upload=$_FILES['image']??null;
            if (!$upload || $upload['error']!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) throw new QuizAiError('Välj en bild (högst 5 MB).');
            $stored=aiStoreImage(file_get_contents($upload['tmp_name']),$teacherId);
            $image=['file'=>$stored['image'],'alt'=>$alt];
        }
        aiUpdate(QUIZZES_FILE,function (&$quizzes) use ($id,$teacherId,$revision,$key,$index,$image) {
            $current=aiOwn($quizzes,$id,$teacherId);
            if (!hash_equals(aiRevision($current),$revision)) throw new QuizAiError('Quizet ändrades. Ladda om sidan och försök igen.',409);
            if ($key==='items') $quizzes[$id][$key][$index]['image']=$image;
            else { $quizzes[$id][$key][$index]['image']=$image['file']??null; $quizzes[$id][$key][$index]['image_alt']=$image['alt']??''; }
        });
        $success='Bilden uppdaterades.'; $quiz=aiOwn(aiRead(QUIZZES_FILE),$id,$teacherId);
    }
} catch (QuizAiError $e) { $error=$e->getMessage(); if (!isset($quiz)) { http_response_code($e->status); echo htmlspecialchars($error); exit; } }
function imageEscape($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
$key=isset($quiz['items'])?'items':'questions';
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bilder till frågor</title>
<style>body{font:16px system-ui;background:#f5f7fb;color:#172033;margin:0}main{max-width:760px;margin:auto;padding:24px}section{background:white;border:1px solid #dce2ec;border-radius:12px;padding:20px;margin:18px 0}img{display:block;max-width:100%;max-height:260px;object-fit:contain;margin:12px 0}label{display:block;margin:12px 0}input[type=text]{padding:10px;width:95%;border:1px solid #aab6c8;border-radius:6px}button{padding:10px 16px;background:#245bb2;color:white;border:0;border-radius:6px;cursor:pointer}.error{color:#a31919}.success{color:#11652d}</style>
</head><body><main><a href="dashboard.php">← Mina quiz</a><h1>Bilder: <?= imageEscape($quiz['title']) ?></h1><p>Ladda upp en bild till valfri fråga. PNG, JPG, WebP eller GIF, högst 5 MB. Beskriv bilden för elever som använder skärmläsare.</p>
<?php if ($error): ?><p class="error"><?= imageEscape($error) ?></p><?php endif; ?><?php if ($success): ?><p class="success" role="status"><?= imageEscape($success) ?></p><?php endif; ?>
<?php foreach ($quiz[$key] as $index=>$item): $image=$item['image']??null; $name=is_array($image)?$image['file']:$image; ?>
<section><h2><?= $index+1 ?>. <?= imageEscape($item['concept']??$item['sentence']??$item['question']??'') ?></h2>
<?php if ($name): ?><img src="../api/question-image.php?file=<?= rawurlencode($name) ?>" alt="<?= imageEscape(is_array($image)?$image['alt']:($item['image_alt']??'Frågebild')) ?>"><?php endif; ?>
<form method="post" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="index" value="<?= $index ?>"><input type="hidden" name="revision" value="<?= aiRevision($quiz) ?>">
<label for="image-<?= $index ?>">Bildfil</label><input id="image-<?= $index ?>" type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif" required>
<label for="alt-<?= $index ?>">Bildbeskrivning</label><input id="alt-<?= $index ?>" type="text" name="alt" maxlength="500" required value="<?= imageEscape(is_array($image)?$image['alt']:($item['image_alt']??'')) ?>"><p><button>Spara bild</button></p></form>
<?php if ($name): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="index" value="<?= $index ?>"><input type="hidden" name="revision" value="<?= aiRevision($quiz) ?>"><button>Ta bort bilden från frågan</button></form><?php endif; ?></section>
<?php endforeach; ?></main></body></html>
