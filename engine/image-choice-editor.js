(function () {
    const container=document.getElementById('editor-questions'); let serial=0;
    function node(tag,text,className) { const el=document.createElement(tag); if (text) el.textContent=text; if (className) el.className=className; return el; }
    function input(labelText,type,value) {
        const wrap=node('label',labelText); const el=document.createElement('input'); el.type=type;
        if (type==='checkbox') el.checked=!!value; else el.value=value||'';
        wrap.append(el); return {wrap,el};
    }
    function addChoice(grid,choice={id:'picture_'+Date.now()+'_'+(++serial),image:null,correct:false}) {
        if (grid.children.length>=6) return;
        const card=node('div',null,'editor-choice'); card.dataset.choiceId=choice.id;
        if (choice.image?.file) { const img=node('img'); img.src='../api/question-image.php?file='+encodeURIComponent(choice.image.file); img.alt=choice.image.alt; card.append(img); }
        const file=input('Bildfil','file'); file.el.accept='image/png,image/jpeg,image/webp,image/gif'; file.el.dataset.field='file';
        file.el.required=!choice.image?.file; card.dataset.file=choice.image?.file||''; card.append(file.wrap);
        const alt=input('Bildbeskrivning','text',choice.image?.alt); alt.el.required=true; alt.el.maxLength=500; alt.el.dataset.field='alt'; card.append(alt.wrap);
        const correct=input(' Rätt bild','checkbox',choice.correct); correct.el.dataset.field='correct'; card.append(correct.wrap);
        const remove=node('button','Ta bort bild','secondary choice-remove'); remove.type='button'; remove.addEventListener('click',()=>{
            if (grid.children.length<=2) { alert('Varje fråga behöver minst två bilder.'); return; } card.remove(); }); card.append(remove); grid.append(card);
    }
    function addQuestion(item={question:'',choices:[{id:'picture_'+(++serial),image:null,correct:true},{id:'picture_'+(++serial),image:null,correct:false}],image:null}) {
        const row=node('section',null,'question-card question-row'); row.dataset.infoImage=JSON.stringify(item.image||null);
        const top=node('div',null,'question-top'); top.append(node('h2','Fråga'));
        const remove=node('button','Ta bort fråga','secondary question-remove'); remove.type='button'; remove.addEventListener('click',()=>{
            if (container.children.length<=1) { alert('Quizet behöver minst en fråga.'); return; } row.remove(); }); top.append(remove); row.append(top);
        const question=node('textarea'); question.dataset.field='question'; question.required=true; question.rows=2; question.value=item.question||'';
        const label=node('label','Frågetext (kan läsas upp)'); label.append(question); row.append(label);
        const grid=node('div',null,'choice-grid'); for (const choice of item.choices) addChoice(grid,choice); row.append(grid);
        const add=node('button','+ Lägg till bild','secondary'); add.type='button'; add.addEventListener('click',()=>{
            if (grid.children.length>=6) { alert('Högst sex bilder per fråga.'); return; } addChoice(grid); }); row.append(add);
        row.append(node('p','En extra infobild kan läggas till efter att quizet sparats.','small')); container.append(row);
    }
    for (const item of INITIAL_IMAGE_ITEMS) addQuestion(item);
    document.getElementById('add-question').addEventListener('click',()=>addQuestion());
    document.getElementById('image-editor').addEventListener('submit',event=>{
        const items=[];
        for (const [i,row] of Array.from(container.children).entries()) {
            const choices=[];
            for (const [j,card] of Array.from(row.querySelector('.choice-grid').children).entries()) {
                const file=card.querySelector('[data-field=file]'); file.name=`choice_files[${i}][${j}]`;
                choices.push({id:card.dataset.choiceId,correct:card.querySelector('[data-field=correct]').checked,
                    image:{file:card.dataset.file,alt:card.querySelector('[data-field=alt]').value.trim()}});
            }
            if (!choices.some(c=>c.correct)) { event.preventDefault(); alert('Markera minst en rätt bild i varje fråga.'); return; }
            items.push({question:row.querySelector('textarea').value.trim(),choices,image:JSON.parse(row.dataset.infoImage)});
        }
        document.getElementById('items-json').value=JSON.stringify(items);
    });
})();
