# Classroom robot verification

Local verification on 2026-10-21. Synthetic accounts and disposable copies only; no production requests or real pupil data.

## Reproduce

Run python tests/classroom-http.test.py --robots. PHP CLI and Python standard library are sufficient. Add --serve to keep the synthetic fixture available for browser QA until interrupted.

The traffic suite starts four independent PHP processes because PHP's built-in server on Windows otherwise handles requests serially. All four share the same disposable data files and fixture-only PHP session directory.

## Final automated result

- 32 simulated pupils; 1,039 HTTP requests; 1,151 assertions passed.
- 62 existing classroom HTTP assertions passed.
- Concurrent quiz and flashcard work retained correct pupil identity and counters.
- Eight attempts were abandoned and remained partial; eight pupils resumed with cumulative snapshots after simulated disconnection.
- Late and duplicate snapshots did not regress progress; two concurrent sessions for one pupil could not overwrite a newer snapshot with an older one.
- All 16 authorized PDF-download records remained; other groups were denied access and could not forge activity.
- Logout, pupil switch, PIN reset and removal rejected stale-tab writes.
- All 32 modern results and all 128 legacy results were preserved. Legacy attempt/completion totals and teacher/system totals agreed with the stored attempts.
- No PHP warnings or fatal errors were found in worker logs.

## Defects reproduced and fixed

1. Windows could reject an atomic replacement while another process was reading the destination. Atomic rename now retries briefly and leaves the original destination intact if replacement still fails. Classroom storage failures return a retryable JSON error with HTTP 503.
2. Legacy quiz and standalone flashcard endpoints read and replaced the same statistics file without a shared transaction lock. One failing run retained only 33 of 64 quiz attempts and 19 of 64 flashcard attempts. Both endpoints and the legacy aggregate counters now use locked read-modify-write transactions.

## Browser verification

Chrome, separate pupil and teacher contexts:

- A real quiz answer submitted with the page offline showed the save warning. Restoring the network automatically cleared it; the teacher then saw the partial attempt with one wrong answer.
- A second tab logged out pupil Robin and logged in pupil Morgan. Answering in Robin's old tab was rejected; Morgan still had no attempt, and Robin's previously acknowledged partial work remained unchanged.

## Limits

This is local functional/concurrency verification, not a production performance benchmark. Physical QR scanning and Safari/iPad still need a device check. Pending activity is retried while the player page is open; closing the page while offline does not persist the unsaved queue across reopening.
