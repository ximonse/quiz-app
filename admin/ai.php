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
<!doctype html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ai-api – Quiz App</title>
<link rel="stylesheet" href="../engine/themes.css">
<style>
body{font:16px/1.55 system-ui;background:#f5f7fb;color:#172033;margin:0}
main{max-width:760px;margin:auto;padding:24px}
section{background:white;border:1px solid #dce2ec;border-radius:12px;padding:20px;margin:18px 0}
h1,h2{line-height:1.25}h2{margin-top:0}
input[type=text]{padding:10px;border:1px solid #aab6c8;border-radius:6px;width:min(95%,480px)}
button{padding:10px 16px;background:#245bb2;color:white;border:0;border-radius:6px;cursor:pointer}
button:hover{background:#194685}button:focus-visible,a:focus-visible{outline:3px solid #f7b53b;outline-offset:2px}
code{overflow-wrap:anywhere}label{display:block;margin:12px 0}li{margin:10px 0}
pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f1f4f9;border:1px solid #dce2ec;border-radius:8px;padding:14px;font:14px/1.5 ui-monospace,monospace}
.note{background:#fff7df;border-left:4px solid #d99800;padding:12px 14px;border-radius:4px}
.error{color:#a31919}.secret{display:block;background:#f1f4f9;padding:12px;border-radius:6px}
</style>
</head>
<body><main>
<a href="dashboard.php">← Mina quiz</a>
<h1>ai-api</h1>
<p>Med en AI-klient som stöder en egen MCP-koppling kan du låta AI:n hitta, läsa, skapa och föreslå ändringar i dina quiz. Nya quiz och ändringar sparas för granskning. Du kontrollerar innehållet och publicerar själv i appen. Kopplingen ger aldrig åtkomst till elevresultat.</p>
<p class="note"><strong>Förutsättningar:</strong> Du behöver ett konto i en AI-tjänst och en klient som kan ansluta till en extern MCP-server med <code>Authorization: Bearer</code>. Funktionerna kan kräva en betalversion eller ett särskilt abonnemang, till exempel hos ChatGPT, Claude eller Google Antigravity. Kontrollera vad just din version stöder innan du skapar en nyckel: ett betalkonto i sig garanterar inte att den här typen av anslutning fungerar. <a href="https://help.openai.com/en/articles/12584461-developer-mode-and-full-mcp-connectors-in-chatgpt">ChatGPT</a>, <a href="https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp">Claude</a> och <a href="https://www.antigravity.google/docs/mcp">Antigravity</a> har egna instruktioner.</p>
<section>
<h2>Kom igång</h2>
<ol>
<li>Kontrollera att din AI-klient kan använda en egen fjärransluten MCP-server med Bearer-nyckel. Om klienten bara stöder OAuth behövs en annan anslutningslösning.</li>
<li>Skapa en nyckel nedan. Ge den ett namn som visar vilken klient du använder. Kryssa i <strong>Tillåt att skapa, redigera och ladda upp bilder</strong> om du vill att AI:n ska kunna göra mer än att läsa.</li>
<li>Kopiera nyckeln direkt när den visas. Den visas bara en gång och gäller i 90 dagar. Lägg den i klientens inställningar som <code>Authorization: Bearer DIN_NYCKEL</code>. Klistra inte in nyckeln i en vanlig AI-chatt.</li>
<li>Ange MCP-adressen nedan i samma inställningar. Testa genom att be AI:n hitta ett quiz. Be den sedan skapa ett nytt quiz om du gav skrivbehörighet.</li>
<li>Öppna quizet här i appen, granska frågorna och svaren och publicera när du är nöjd. En AI-klient kan inte publicera åt dig.</li>
</ol>
</section>
<?php if ($error): ?><p class="error"><?= aiEscape($error) ?></p><?php endif; ?>
<?php if ($secret): ?><section><h2>Din nya nyckel</h2><p>Visas bara nu. Spara den i AI-klientens inställningar.</p><code class="secret"><?= aiEscape($secret) ?></code></section><?php endif; ?>
<section><h2>Skapa åtkomst</h2><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="create">
<label for="label">Namn på kopplingen</label><input id="label" name="label" type="text" maxlength="100" required placeholder="Till exempel Claude Code">
<label><input type="checkbox" name="write"> Tillåt att skapa, redigera och ladda upp bilder</label>
<p>Nyckeln gäller i 90 dagar. Du kan återkalla den när som helst.</p><button type="submit">Skapa nyckel</button></form></section>
<section><h2>Adresser till klienten</h2>
<p>MCP-adress: <code><?= aiEscape(aiBaseUrl()) ?>/api/mcp.php</code></p>
<p>För klienter som stöder API-actions finns också ett <a href="../api/ai-openapi.php">OpenAPI-schema</a>. Använd Bearer-autentisering.</p>
</section>
<section><h2>Prompt att kopiera</h2>
<p>Klistra in den här texten i ChatGPT, Claude eller en annan AI-klient för att få hjälp med själva anslutningen. API-nyckeln ska bara läggas i klientens inställningar.</p>
<pre id="setup-prompt">Hjälp mig att ansluta min quiz-app till den AI-klient jag använder. Quiz-appens MCP-adress är <?= aiEscape(aiBaseUrl()) ?>/api/mcp.php och autentiseringen är Authorization: Bearer med en nyckel som jag skapar i quiz-appen. Kontrollera först om min klient och mitt abonnemang stöder en egen fjärransluten MCP-server med Bearer-nyckel. Visa sedan exakta steg för att lägga in adressen och nyckeln i klientens inställningar. Be mig aldrig klistra in nyckeln i chatten. När kopplingen fungerar, testa att hitta mina quiz och hjälp mig skapa ett quiz som sparas för granskning. Om quizet har bilder som svarsalternativ: gör en separat bild per alternativ i liggande 4:3-format, utan text eller etiketter i bilden, och placera hela motivet med marginal till kanterna. Jag publicerar själv i appen.</pre>
<button type="button" id="copy-prompt">Kopiera prompt</button><span id="copy-status" role="status" aria-live="polite"></span>
<p>Om AI:n inte hittar quizverktygen: kontrollera adressen, Bearer-nyckeln och att klienten faktiskt stöder den här typen av MCP-anslutning.</p>
</section>
<section><h2>Dina kopplingar</h2><ul><?php foreach ($tokens as $id=>$token): ?><li><strong><?= aiEscape($token['label']) ?></strong> — <?= $token['write']?'Skapa och redigera':'Läsa' ?>,
<?= !empty($token['revoked'])?'återkallad':(($token['expires']<=time())?'utgången':'giltig till '.date('Y-m-d',$token['expires'])) ?>
<?php if (empty($token['revoked'])): ?><form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= aiEscape($id) ?>"><button type="submit">Återkalla</button></form><?php endif; ?></li><?php endforeach; ?></ul></section>
<p class="note"><strong>Under uppdatering:</strong> Appen och AI-API:t utvecklas fortfarande. API-funktionen och hur anslutningen görs kan ändras utan förvarning.</p>
</main>
<script>
document.getElementById('copy-prompt').addEventListener('click', async () => {
    const status = document.getElementById('copy-status');
    try {
        await navigator.clipboard.writeText(document.getElementById('setup-prompt').textContent);
        status.textContent = ' Kopierad!';
    } catch {
        status.textContent = ' Kopieringen misslyckades. Markera och kopiera texten ovan.';
    }
});
</script>
</body></html>
