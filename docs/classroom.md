# Classes, pupil accounts and assignments

Teacher entry: admin/classes.php (linked from both teacher dashboards).
Pupil entry: student/. Public quiz links still work without login.

## Login and pupil status

Each pupil has a unique three-word code, a random QR identifier and a hashed four-digit PIN. Both login routes require the same PIN. New PINs are available on teacher cards for 15 minutes in the creating teacher's session; the data file only stores the hash. Reset rotates PIN and QR, revokes sessions and retains the word code. Sessions expire after eight hours. Failed logins are limited per pupil across both routes and per IP for 15 minutes.

Pupils see assignments and status only. Previous scores and answer history are never rendered on their home page. The current quiz can still show its immediate result. A visible logout/switch-pupil action is provided in players. Old tabs cannot submit activity under a newly logged-in pupil.

## Classes and groups

Pupils belong to one class and may belong to multiple groups across classes owned by the same teacher. Moving class keeps group memberships. New members receive existing assignments to their class/group. Removal deactivates the pupil, removes group memberships and revokes sessions; historical practice remains visible to the teacher, marked as a removed pupil.

## Assignments and activity

Teachers assign published quizzes, generated flashcards, standalone flashcard decks or uploaded PDFs to a class/group. Activity is separate from existing public result storage, in data/classroom.json under the existing file-lock convention. A failed classroom JSON read fails closed instead of replacing the file with empty data.

Each player visit creates one practice attempt when the pupil actually answers or flips a card. Quiz counters count submitted right/wrong answers, including repetitions. Flashcards count unique flipped cards per visit. Partial work is saved after each interaction; monotonic sequence numbers make retries idempotent. Failed saves show a retry action and retry on reconnect while the page is open. Pending activity is not persisted across closing/reopening an offline browser.

PDF status records an authorized download request, not whether the pupil read or retained the file. PDFs have random filenames, are limited to 20 MB (subject to PHP upload limits), validated by signature/extension and MIME when fileinfo is available, and served as attachments through an authorized endpoint. Deploy data/materials/.htaccess with the code: direct requests to that folder must be denied by Apache. Never expose that directory as static public storage on other hosts.

Existing anonymous history is not automatically assigned to pupils from names or browser IDs. Public practice remains available. Logged-in practice on a matching assignment is recorded in the classroom overview; unrelated public quizzes do not become assignments automatically.

## Validation

- python tests/classroom-http.test.py: isolated temporary app copy and synthetic accounts, including ownership, classes/groups, both login routes, PIN limits/reset, partial/completed activity, status privacy, materials and removal.
- Add --robots to run 32 synthetic pupils against four PHP server processes sharing fixture-only data and sessions. The suite checks simultaneous quiz/card activity, partial work, reconnection snapshots, duplicate/out-of-order requests, cross-group isolation, shared-device changes, reset/removal and all 128 legacy result saves.
- Add --serve to leave the synthetic fixture running for browser QA until interrupted. No real data or config.local.php is copied.
- node --test tests/classroom-tracker.test.js: partial progress, unique card flips, offline retry, in-flight updates.
- Existing PHP service, browser-ID and quiz-engine suites remain applicable.
