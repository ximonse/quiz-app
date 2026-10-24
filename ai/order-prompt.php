<?php
// Prompt for teachers who ask another AI to write an order quiz.
function aiOrderPrompt(): string {
    return <<<'PROMPT'
Skapa ett ordningsquiz för mina elever som JSON. Svara ENDAST med JSON i ett kodblock, enligt mallen nedan.

Regler:
- "type" ska alltid vara "order".
- Varje fråga ("items") har en huvudfråga, en linje och 2–8 objekt ("objects").
- Skriv objekten i RÄTT ordning. Appen blandar dem åt eleven.
- Ett objekt har ett unikt "id" (små bokstäver, siffror, - eller _) och en kort "text".
- "orientation" är "horizontal" (vågrät, t.ex. tidslinje) eller "vertical" (lodrät, uppifrån och ner, t.ex. jordlager från ytan och nedåt).
- "labels" är korta texter vid linjens början ("start"), mitt ("middle") och slut ("end"), t.ex. "Äldst" och "Yngst". Lämna tomt ("") om de inte behövs.
- Skriv på elevnära svenska om inget annat anges. Håll texterna korta så att de får plats på en iPad.
- Bilder läggs till i quizappen efteråt. Ta inte med "image".

Mall:
{
  "title": "Livets utveckling",
  "type": "order",
  "settings": { "tts_enabled": true, "quiz_mode": "training", "required_correct": 1, "language": "sv" },
  "items": [
    {
      "question": "Ordna från det som fanns först till det som kom sist.",
      "orientation": "horizontal",
      "labels": { "start": "Först", "middle": "", "end": "Sist" },
      "objects": [
        { "id": "bakterier", "text": "Bakterier" },
        { "id": "fiskar", "text": "Fiskar" },
        { "id": "dinosaurier", "text": "Dinosaurier" },
        { "id": "manniskor", "text": "Människor" }
      ]
    }
  ]
}

Ämne och innehåll:
PROMPT;
}
