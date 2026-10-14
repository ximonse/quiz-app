// Render DOM nodes instead of interpolating teacher/AI text into HTML.
(async function () {
    const root=document.getElementById('image-player');
    const $=id=>document.getElementById(id); const preview=root.dataset.preview==='1';
    let quiz,engine,item,selected=new Set(),submitted=false;
    const imageNode=image=>{
        const img=document.createElement('img'); img.alt=image.alt;
        img.src='../api/question-image.php?file='+encodeURIComponent(image.file);
        img.addEventListener('error',()=>{ img.alt='Bilden kunde inte laddas. '+image.alt; });
        return img;
    };
    function speak(force=false) { if (quiz.settings.tts_enabled && item) speakText(item.question,quiz.settings.language,force); }
    function updateProgress() {
        const p=engine.progress();
        $('progress').textContent=quiz.settings.quiz_mode==='test' ? `${p.attempts} av ${p.total} frågor besvarade` : `${p.mastered} av ${p.total} frågor klara · ${p.required} rätt per fråga`;
    }
    function render() {
        item=engine.current(); selected=new Set(); submitted=false;
        updateProgress();
        if (!item) return finish();
        $('question-card').hidden=false; $('question').textContent=item.question;
        $('listen').hidden=!quiz.settings.tts_enabled;
        const multiple=item.choices.filter(c=>c.correct).length>1;
        $('instructions').textContent=multiple?'Välj alla bilder som stämmer. Tryck sedan på Kontrollera.':'Välj en bild.';
        $('check').hidden=!multiple; $('check').disabled=true; $('next').hidden=true; $('feedback').textContent='';
        $('info-image').replaceChildren(); if (item.image) $('info-image').append(imageNode(item.image));
        $('choices').replaceChildren();
        item.choices.forEach((choice,index)=>{
            const button=document.createElement('button'); button.className='choice'; button.dataset.id=choice.id;
            button.setAttribute('aria-pressed','false'); button.setAttribute('aria-label',`Bild ${index+1}: ${choice.image.alt}`);
            const image=imageNode(choice.image);
            const crop=choice.image.crop||{x:50,y:50,zoom:100};
            image.style.setProperty('--crop-x',crop.x+'%');
            image.style.setProperty('--crop-y',crop.y+'%');
            image.style.setProperty('--crop-zoom',String(crop.zoom/100));
            button.append(image);
            const status=document.createElement('span'); status.className='choice-status'; button.append(status);
            button.addEventListener('click',()=>{
                if (submitted) return;
                if (multiple) {
                    if (selected.has(choice.id)) selected.delete(choice.id); else selected.add(choice.id);
                    button.setAttribute('aria-pressed',String(selected.has(choice.id))); $('check').disabled=selected.size===0;
                } else { selected=new Set([choice.id]); button.setAttribute('aria-pressed','true'); check(); }
            });
            $('choices').append(button);
        });
        speak();
    }
    function check() {
        if (submitted) return; submitted=true;
        const result=engine.submit([...selected]); updateProgress();
        for (const button of $('choices').querySelectorAll('button')) {
            const state=result.statuses[button.dataset.id]; button.disabled=true;
            if (state) { const label={correct:'✓ Rätt!',wrong:'✕ Fel',missed:'✓ Rätt!'}[state]; button.classList.add(state); button.querySelector('.choice-status').textContent=label; button.setAttribute('aria-label',button.getAttribute('aria-label')+'. '+(state==='missed'?'Rätt svar, missat':label)); }
        }
        $('feedback').textContent=`${result.right} rätt · ${result.wrong} fel · ${result.missed} missade`;
        $('check').hidden=true; $('next').hidden=false;
        $('next').textContent=engine.progress().complete?'Visa resultat':'Nästa fråga';
    }
    async function finish() {
        const results=engine.results(); $('question-card').hidden=true;
        $('quiz-title').textContent='Klart!'; $('progress').textContent=`${root.dataset.name}: ${results.score} rätt av ${results.total} besvarade frågor.`;
        if (preview) { $('preview-label').textContent='Förhandsvisning · inget elevresultat sparades'; return; }
        try {
            const response=await fetch('../api/save-result.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({quiz_id:root.dataset.id,student_name:root.dataset.name,browser_id:getQuizBrowserId(),...results})});
            const saved=await response.json();
            if (saved.reason==='training_only') { $('preview-label').textContent='Träning · inget testresultat sparades'; return; }
            if (!response.ok || !saved.saved) throw new Error('Resultatet kunde inte sparas.');
        } catch(e) { $('player-error').textContent=e.message; }
    }
    $('listen').addEventListener('click',()=>speak(true)); $('check').addEventListener('click',check); $('next').addEventListener('click',render);
    try {
        const response=await fetch('../api/quiz-data.php?id='+encodeURIComponent(root.dataset.id)+(preview?'&preview=1':''));
        quiz=await response.json();
        if (!response.ok) throw new Error(quiz.error||'Kunde inte ladda quizet.');
        if (quiz.status==='not_yet') throw new Error('Quizet öppnar '+quiz.opens+'.');
        if (quiz.status==='training_only') quiz.settings.quiz_mode='training';
        if (quiz.type!=='image_choice') throw new Error('Det här är inte ett bildvalsquiz.');
        $('quiz-title').textContent=quiz.title; if (preview) $('preview-label').textContent='Förhandsvisning · bara du kan se det här';
        engine=ImageChoiceEngine(quiz.items,quiz.settings); render();
    } catch(e) { $('quiz-title').textContent='Quiz'; $('player-error').textContent=e.message; }
})();
