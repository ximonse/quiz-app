<?php
// Retired diagnostics must also fail closed without Apache rewrite rules.
http_response_code(410);
exit('Diagnossidan har tagits bort.');
