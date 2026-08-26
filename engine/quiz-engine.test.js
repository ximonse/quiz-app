const assert = require('node:assert');
const fs = require('node:fs');
const vm = require('node:vm');

const context = {
  isMultipleChoiceCorrect: (answer, correct) => answer === correct,
  isAnswerCorrect: (answer, correct) => ({ correct: answer === correct })
};
vm.createContext(context);
vm.runInContext(fs.readFileSync(__dirname + '/quiz-engine.js', 'utf8'), context);

function createEngine(type, settings = {}) {
  const items = type === 'glossary'
    ? [{ word: 'uno', sentence: 'uno', translation: 'ett', wrong_options: ['två'] }, { word: 'dos', sentence: 'dos', translation: 'två', wrong_options: ['ett'] }]
    : [{ concept: 'A', description: 'a', wrong_options: ['b'] }, { concept: 'B', description: 'b', wrong_options: ['a'] }];
  return context.QuizEngine({ items, quizType: type, settings: { quiz_mode: 'training', answer_mode: 'multiple_choice', required_correct: 2, ...settings } });
}

for (const type of ['glossary', 'fact']) {
  const engine = createEngine(type);
  const first = engine.currentItem()._index;
  engine.submitAnswer('wrong', 'easy');
  assert.notStrictEqual(engine.currentItem()._index, first, `${type}: wrong answer must rotate`);
  for (let i = 0; i < 4; i++) engine.submitAnswer(engine.getQuestion(engine.currentItem()).answer, 'easy');
  assert.ok(engine.isComplete(), `${type}: two correct answers per item must complete`);
}

const testEngine = createEngine('glossary', { quiz_mode: 'test' });
testEngine.submitAnswer(testEngine.getQuestion(testEngine.currentItem()).answer, 'easy');
testEngine.submitAnswer(testEngine.getQuestion(testEngine.currentItem()).answer, 'easy');
assert.ok(testEngine.isComplete(), 'test mode must require one correct answer per item');
const reverseEngine = createEngine('glossary', { required_correct: 1, reverse_enabled: true, reverse_required_correct: 2 });
for (let i = 0; i < 2; i++) reverseEngine.submitAnswer(reverseEngine.getQuestion(reverseEngine.currentItem()).answer, 'easy');
assert.strictEqual(reverseEngine.currentDirection(), 'reverse', 'reverse phase must start after forward mastery');
assert.strictEqual(reverseEngine.getProgress().requiredCorrect, 2, 'reverse phase must use its own mastery requirement');
for (let i = 0; i < 3; i++) reverseEngine.submitAnswer(reverseEngine.getQuestion(reverseEngine.currentItem()).answer, 'easy');
assert.ok(!reverseEngine.isComplete(), 'reverse phase must keep repeating until each item reaches its requirement');
reverseEngine.submitAnswer(reverseEngine.getQuestion(reverseEngine.currentItem()).answer, 'easy');
assert.ok(reverseEngine.isComplete(), 'reverse phase must complete after its own mastery requirement is met');
console.log('quiz-engine tests passed');
