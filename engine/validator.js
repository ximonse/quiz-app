// engine/validator.js — Answer validation for all quiz types

function levenshteinDistance(a, b) {
    const matrix = [];
    for (let i = 0; i <= b.length; i++) matrix[i] = [i];
    for (let j = 0; j <= a.length; j++) matrix[0][j] = j;
    for (let i = 1; i <= b.length; i++) {
        for (let j = 1; j <= a.length; j++) {
            if (b.charAt(i - 1) === a.charAt(j - 1)) {
                matrix[i][j] = matrix[i - 1][j - 1];
            } else {
                matrix[i][j] = Math.min(
                    matrix[i - 1][j - 1] + 1,
                    matrix[i][j - 1] + 1,
                    matrix[i - 1][j] + 1
                );
            }
        }
    }
    return matrix[b.length][a.length];
}

function isAnswerCorrect(userAnswer, correctAnswer, spellingMode) {
    const user = userAnswer.toLowerCase().trim().replace(/\s+/g, ' ');
    const correct = correctAnswer.toLowerCase().trim().replace(/\s+/g, ' ');

    if (user === correct) return { correct: true, type: 'exact' };

    if (spellingMode === 'puritan') return { correct: false, type: 'wrong' };

    // Easy / student_choice: suffix tolerance
    if (user.startsWith(correct) || correct.startsWith(user)) {
        const diff = Math.abs(user.length - correct.length);
        if (diff <= 3) return { correct: true, type: 'suffix' };
    }

    // Levenshtein tolerance: 20% of word length
    const maxDistance = Math.ceil(correct.length * 0.2);
    const distance = levenshteinDistance(user, correct);
    if (distance <= maxDistance && distance > 0) return { correct: true, type: 'typo' };

    return { correct: false, type: 'wrong' };
}

function isMultipleChoiceCorrect(selected, correct) {
    return selected.trim().toLowerCase() === correct.trim().toLowerCase();
}
