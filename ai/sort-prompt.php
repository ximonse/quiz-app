<?php
// Prompt for teachers who ask another AI (ChatGPT, Claude, Gemini) to write a sort quiz.
function aiSortPrompt(): string {
    return <<<'PROMPT'
Skapa ett sorteringsquiz för mina elever som JSON. Svara ENDAST med JSON i ett kodblock, enligt mallen nedan.

Regler:
- "type" ska alltid vara "sort".
- Varje fråga ("items") har en huvudfråga, 1–5 fält ("fields") och 2–12 objekt ("objects").
- Ett fält har ett unikt "id" (små bokstäver, siffror, - eller _), en kort "title" och gärna en kort förklarande "text".
- Ett objekt har ett unikt "id", en kort "text" och "field" = id för fältet det hör till.
- Objekt som inte hör till något fält (distraktorer) har "field": null. Använd högst ett par sådana.
- Varje objekt hör till exakt ett fält. Inga id:n får upprepas inom samma fråga.
- "flashcard_prompt" är en ledtråd som visas på flashcards före objektet, t.ex. "Vilket rike tillhör". Den ska bygga på huvudfrågan.
- Skriv på elevnära svenska om inget annat anges. Håll texterna korta så att de får plats på en iPad.
- Bilder läggs till i quizappen efteråt. Ta inte med "image".

Mall:
{
  "title": "Rikena i naturen",
  "type": "sort",
  "settings": { "tts_enabled": true, "quiz_mode": "training", "required_correct": 1, "language": "sv", "generate_flashcards": true },
  "items": [
    {
      "question": "Vilket rike tillhör de här organismerna?",
      "flashcard_prompt": "Vilket rike tillhör",
      "fields": [
        { "id": "svampar", "title": "Svampar", "text": "Bryter ner döda växter och djur" },
        { "id": "vaxter", "title": "Växter", "text": "Gör egen näring med fotosyntes" }
      ],
      "objects": [
        { "id": "champinjon", "text": "Champinjon", "field": "svampar" },
        { "id": "ek", "text": "Ek", "field": "vaxter" },
        { "id": "sten", "text": "Sten", "field": null }
      ]
    }
  ]
}

Ämne och innehåll:
PROMPT;
}
