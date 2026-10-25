<?php
// Spaced repetition for pupils: a small FSRS (Free Spaced Repetition Scheduler)
// implementation. One "card" per student+quiz+question. Only questions from
// quizzes the teacher marked settings.spaced_repetition feed this file — see
// classroomRecord() in classroom.php, which is the only writer.
//
// NOTE on FSRS_WEIGHTS below: these are the FSRS-4.5 default parameters as
// published by the open-spaced-repetition project (the same defaults Anki
// 23.10+ and the ts-fsrs library ship with) — not refit on local data, which
// is normal for FSRS; the defaults are already tuned on very large review
// datasets. They were copied in from memory while building this feature, so
// it is worth a one-time diff against the current upstream DEFAULT_PARAMETERS
// array (ts-fsrs or fsrs4anki on GitHub) before leaning on exact values.
if (!defined('REPETITION_FILE')) define('REPETITION_FILE', DATA_DIR . 'repetition.json');

const FSRS_WEIGHTS = [
    0.4072, 1.1829, 3.1262, 15.4722, 7.2102, 0.5316, 1.0651, 0.0234,
    1.616, 0.1544, 1.0824, 1.9813, 0.0953, 0.2975, 2.2042, 0.2407, 2.9466,
];
const FSRS_TARGET_RETENTION = 0.9;

function repetitionRead(): array {
    if (!is_file(REPETITION_FILE)) return ['cards' => []];
    $data = json_decode(file_get_contents(REPETITION_FILE), true);
    if (!is_array($data)) throw new RuntimeException('Repetitionsdata kunde inte läsas.');
    $data['cards'] = $data['cards'] ?? [];
    return $data;
}
function repetitionUpdate(callable $change) {
    return updateJSONLocked(REPETITION_FILE, function (&$data) use ($change) {
        $data['cards'] = $data['cards'] ?? [];
        return $change($data);
    });
}

// R(t,S) = (1 + t/(9S))^-1 — the FSRS forgetting curve (R(S)=0.9 by construction).
function fsrsRetrievability(float $elapsedDays, float $stability): float {
    if ($stability <= 0) return 0;
    return (1 + $elapsedDays / (9 * $stability)) ** -1;
}
// Days until retrievability drops to the target (default 90%).
function fsrsInterval(float $stability, float $targetRetention = FSRS_TARGET_RETENTION): int {
    $days = 9 * $stability * (1 / $targetRetention - 1);
    return max(1, (int) round($days));
}
function fsrsInitialDifficulty(int $rating): float {
    $w = FSRS_WEIGHTS;
    $d = $w[4] - exp(($rating - 1) * $w[5]) + 1;
    return min(10, max(1, $d));
}
function fsrsNextDifficulty(float $difficulty, int $rating): float {
    $w = FSRS_WEIGHTS;
    $delta = $difficulty - $w[6] * ($rating - 3);
    // Mean-reversion toward the baseline "Easy" difficulty, so difficulty
    // doesn't drift to an extreme after many reviews.
    $reverted = $w[7] * fsrsInitialDifficulty(4) + (1 - $w[7]) * $delta;
    return min(10, max(1, $reverted));
}
function fsrsNextStability(float $difficulty, float $stability, float $retrievability, int $rating): float {
    $w = FSRS_WEIGHTS;
    if ($rating === 1) { // Again: lapse formula, stability shrinks
        $next = $w[11] * ($difficulty ** -$w[12]) * ((($stability + 1) ** $w[13]) - 1) * exp((1 - $retrievability) * $w[14]);
        return max(0.1, min($next, $stability));
    }
    $hardPenalty = $rating === 2 ? $w[15] : 1.0;
    $easyBonus = $rating === 4 ? $w[16] : 1.0;
    $next = $stability * (1 + exp($w[8]) * (11 - $difficulty) * ($stability ** -$w[9])
        * (exp((1 - $retrievability) * $w[10]) - 1) * $hardPenalty * $easyBonus);
    return max(0.1, $next);
}
// $rating: 1=Again (lapse), 2=Hard, 3=Good. We never derive a 4=Easy rating —
// nothing in how pupils answer gives a trustworthy "knew it instantly" signal.
function fsrsReview(array $card, int $rating): array {
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    if (($card['reps'] ?? 0) === 0) {
        $stability = max(0.1, FSRS_WEIGHTS[$rating - 1]);
        $difficulty = fsrsInitialDifficulty($rating);
    } else {
        $lastReview = new DateTimeImmutable($card['last_review'], new DateTimeZone('UTC'));
        $elapsed = max(0, (int) $today->diff($lastReview)->days);
        $retrievability = fsrsRetrievability((float) $elapsed, (float) $card['stability']);
        $difficulty = fsrsNextDifficulty((float) $card['difficulty'], $rating);
        $stability = fsrsNextStability($difficulty, (float) $card['stability'], $retrievability, $rating);
    }
    $interval = fsrsInterval($stability);
    return [
        'stability' => round($stability, 4),
        'difficulty' => round($difficulty, 4),
        'reps' => ($card['reps'] ?? 0) + 1,
        'lapses' => ($card['lapses'] ?? 0) + ($rating === 1 ? 1 : 0),
        'last_review' => $today->format('Y-m-d'),
        'due' => $today->modify("+{$interval} days")->format('Y-m-d'),
    ];
}
// 0 fel = Good, 1 fel = Hard, 2+ fel = Again (lapse) — se plandokumentet för
// varför antal fel-försök innan frågan klarades används som svårighetssignal.
function repetitionRatingFromWrongAttempts(int $wrongAttempts): int {
    if ($wrongAttempts <= 0) return 3;
    if ($wrongAttempts === 1) return 2;
    return 1;
}
function repetitionCardKey(string $studentId, string $quizId, int $questionIndex): string {
    return $studentId . ':' . $quizId . ':' . $questionIndex;
}
// $answers: list of ['q' => question index, 'w' => wrong attempts before mastery].
function repetitionRecordAnswers(string $studentId, string $quizId, array $answers): void {
    if (!$answers) return;
    repetitionUpdate(function (&$data) use ($studentId, $quizId, $answers) {
        foreach ($answers as $a) {
            $rating = repetitionRatingFromWrongAttempts((int) $a['w']);
            $key = repetitionCardKey($studentId, $quizId, (int) $a['q']);
            $card = $data['cards'][$key] ?? [
                'student_id' => $studentId, 'quiz_id' => $quizId, 'question_index' => (int) $a['q'],
                'reps' => 0, 'lapses' => 0,
            ];
            $data['cards'][$key] = array_merge($card, fsrsReview($card, $rating));
        }
    });
}
// Mest försenade (lägst stability bland de förfallna) frågorna, max $limit — se
// till att elevens dagliga repetition aldrig känns som en hög av frågor.
function repetitionDueQueue(string $studentId, int $limit = 10): array {
    $data = repetitionRead();
    $today = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d');
    $due = array_values(array_filter($data['cards'], fn($c) => ($c['student_id'] ?? null) === $studentId && ($c['due'] ?? '') <= $today));
    usort($due, fn($a, $b) => [$a['due'], $a['stability']] <=> [$b['due'], $b['stability']]);
    return array_slice($due, 0, $limit);
}
