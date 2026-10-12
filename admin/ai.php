<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../ai/auth.php';
if (!isLoggedInAsTeacher()) { header('Location: ../index.php'); exit; }
$teacherId=getCurrentTeacherID(); $secret=null; $error='';
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        requireValidCsrf();
        if (($_POST['action']??'')==='create') $secret=aiIssueToken($teacherId,$_POST['label']??'',isset($_POST['write']))['secret'];
        elseif (($_POST['action']??'')==='revoke') {
            $id=$_POST['id']??'';
            aiUpdate(aiTokenFile(),function (&$tokens) use ($id,$teacherId) {
                if (($tokens[$id]['teacher_id']??null)===$teacherId) $tokens[$id]['revoked']=true;
            });
        }
    }
    $tokens=array_filter(aiRead(aiTokenFile()),fn($token)=>$token['teacher_id']===$teacherId);
} catch (QuizAiError $e) { $error=$e->getMessage(); $tokens=[]; }
header('Cache-Control: no-store');
function aiEscape($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
?>
<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AI-koppling</title>
<link rel="stylesheet" href="../engine/themes.css"><style>
body{font:16px system-ui;background:#f5f7fb;color:#172033;margin:0}main{max-width:760px;margin:auto;padding:24px}section{background:white;border:1px solid #dce2ec;border-radius:12px;padding:20px;margin:18px 0}input[type=text]{padding:10px;border:1px solid #aab6c8;border-radius:6px;width:95%}button{padding:10px 16px;background:#245bb2;color:white;border:0;border-radius:6px;cursor:pointer}code{overflow-wrap:anywhere}label{display:block;margin:12px 0}li{margin:16px 0}.error{color:#a31919}
</style></head><body><main><a href="dashboard.php">← Mina quiz</a><h1>AI-koppling</h1>
<p>Låt din AI hitta, läsa och skapa quiz. Visa frågorna och bilderna i ett Markdown-utkast och godkänn innan AI:n sparar. Varje nyckel gäller bara ditt lärarkonto, aldrig elevresultat.</p>
<?php if ($error): ?><p class="error"><?= aiEscape($error) ?></p><?php endif; ?>
<?php if ($secret): ?><section><h2>Din nya nyckel</h2><p>Visas bara nu. Spara den i AI-tjänstens inställningar för kopplingen.</p><code><?= aiEscape($secret) ?></code></section><?php endif; ?>
<section><h2>Skapa åtkomst</h2><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="create">
<label for="label">Namn på kopplingen</label><input id="label" name="label" type="text" maxlength="100" required placeholder="Till exempel Codex eller min quizassistent">
<label><input type="checkbox" name="write"> Tillåt att skapa, redigera och ladda upp bilder</label><p>Nyckeln gäller i 90 dagar. Du kan återkalla den när som helst.</p><button>Skapa nyckel</button></form></section>
<section><h2>Anslut AI-tjänsten</h2><p>MCP-adress: <code><?= aiEscape(aiBaseUrl()) ?>/api/mcp.php</code></p>
<p>För AI-tjänster med API-actions: <a href="../api/ai-openapi.php">OpenAPI-schema</a>. Välj autentisering med API-nyckel som Bearer.</p>
<p>Klienten behöver stöd för MCP med Bearer-nyckel eller API-actions. Klienter som kräver OAuth behöver ytterligare en inloggningskoppling.</p></section>
<section><h2>Dina kopplingar</h2><ul><?php foreach ($tokens as $id=>$token): ?><li><strong><?= aiEscape($token['label']) ?></strong> — <?= $token['write']?'Skapa och redigera':'Läsa' ?>,
<?= !empty($token['revoked'])?'återkallad':(($token['expires']<=time())?'utgången':'giltig till '.date('Y-m-d',$token['expires'])) ?>
<?php if (empty($token['revoked'])): ?><form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= aiEscape($id) ?>"><button>Återkalla</button></form><?php endif; ?></li><?php endforeach; ?></ul></section>
</main></body></html>
