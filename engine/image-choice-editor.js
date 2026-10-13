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
        const preview=node('div',null,'editor-crop-preview');
        const previewImg=node('img'); previewImg.alt=choice.image?.alt||'Förhandsvisning av bildval';
        const placeholder=node('span','Välj en bild för att se utsnittet','crop-placeholder');
        if (choice.image?.file) {
            previewImg.src='../api/question-image.php?file='+encodeURIComponent(choice.image.file);
            placeholder.hidden=true;
        } else previewImg.hidden=true;
        preview.append(previewImg,placeholder); card.append(preview);
        const file=input('Bildfil','file'); file.el.accept='image/png,image/jpeg,image/webp,image/gif'; file.el.dataset.field='file';
        file.el.required=!choice.image?.file; card.dataset.file=choice.image?.file||''; card.append(file.wrap);
        let previewUrl=null;
        file.el.addEventListener('change',()=>{
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl=file.el.files[0] ? URL.createObjectURL(file.el.files[0]) : null;
            if (previewUrl) {
                previewImg.src=previewUrl; previewImg.hidden=false; placeholder.hidden=true;
            } else if (card.dataset.file) {
                previewImg.src='../api/question-image.php?file='+encodeURIComponent(card.dataset.file);
                previewImg.hidden=false; placeholder.hidden=true;
            } else {
                previewImg.removeAttribute('src'); previewImg.hidden=true; placeholder.hidden=false;
            }
        });
        const alt=input('Bildbeskrivning','text',choice.image?.alt); alt.el.required=true; alt.el.maxLength=500; alt.el.dataset.field='alt'; card.append(alt.wrap);
        const details=node('details',null,'crop-details');
        details.append(node('summary','Beskär bildens utsnitt'));
        const controls=node('div',null,'editor-crop-controls');
        const crop=choice.image?.crop||{x:50,y:50,zoom:100};
        const ranges={};
        for (const [field,labelText,min,max,value] of [
            ['crop-x','Horisontellt',0,100,crop.x],
            ['crop-y','Vertikalt',0,100,crop.y],
            ['crop-zoom','Zoom',100,300,crop.zoom]
        ]) {
            const label=node('label',labelText+' ');
            const valueText=node('output'); label.append(valueText);
            const range=document.createElement('input'); range.type='range'; range.min=min; range.max=max; range.value=value; range.dataset.field=field;
            label.append(range); controls.append(label); ranges[field]={range,valueText};
        }
        const updateCrop=()=>{
            const x=ranges['crop-x'].range.value, y=ranges['crop-y'].range.value, zoom=ranges['crop-zoom'].range.value;
            previewImg.style.setProperty('--crop-x',x+'%');
            previewImg.style.setProperty('--crop-y',y+'%');
            previewImg.style.setProperty('--crop-zoom',String(Number(zoom)/100));
            ranges['crop-x'].valueText.textContent=x+' %';
            ranges['crop-y'].valueText.textContent=y+' %';
            ranges['crop-zoom'].valueText.textContent=zoom+' %';
        };
        for (const {range} of Object.values(ranges)) range.addEventListener('input',updateCrop);
        updateCrop(); details.append(controls); card.append(details);
        const correct=input(' Rätt bild','checkbox',choice.correct); correct.el.dataset.field='correct'; card.append(correct.wrap);
        const remove=node('button','Ta bort bild','secondary choice-remove'); remove.type='button'; remove.addEventListener('click',()=>{
            if (grid.children.length<=2) { alert('Varje fråga behöver minst två bilder.'); return; }
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            card.remove();
        }); card.append(remove); grid.append(card);
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
                    image:{file:card.dataset.file,alt:card.querySelector('[data-field=alt]').value.trim(),
                        crop:{x:Number(card.querySelector('[data-field=crop-x]').value),
                            y:Number(card.querySelector('[data-field=crop-y]').value),
                            zoom:Number(card.querySelector('[data-field=crop-zoom]').value)}}});
            }
            if (!choices.some(c=>c.correct)) { event.preventDefault(); alert('Markera minst en rätt bild i varje fråga.'); return; }
            items.push({question:row.querySelector('textarea').value.trim(),choices,image:JSON.parse(row.dataset.infoImage)});
        }
        document.getElementById('items-json').value=JSON.stringify(items);
    });
})();
