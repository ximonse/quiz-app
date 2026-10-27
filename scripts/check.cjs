const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const root=path.resolve(__dirname,'..');
function run(command,args) { execFileSync(command,args,{cwd:root,stdio:'inherit'}); }
function phpFiles(dir) {
    return fs.readdirSync(path.join(root,dir),{withFileTypes:true}).flatMap(entry => {
        const file=path.join(dir,entry.name);
        if (entry.isDirectory()) return phpFiles(file);
        return entry.name.endsWith('.php') && entry.name!=='config.local.php' ? [file] : [];
    });
}
const roots=fs.readdirSync(root).filter(file=>file.endsWith('.php') && file!=='config.local.php');
for (const file of [...roots,...['admin','ai','api','includes','play','q','student','tests'].flatMap(phpFiles)]) {
    execFileSync('php',['-l',file],{cwd:root,stdio:'pipe'});
}
console.log('PASS: PHP syntax');
const jsTests=['tests','engine'].flatMap(dir=>fs.readdirSync(path.join(root,dir)).filter(file=>file.endsWith('.test.js')).map(file=>path.join(dir,file)));
run(process.execPath,['--test',...jsTests]);
for (const file of fs.readdirSync(path.join(root,'tests')).filter(file=>file.endsWith('.test.php'))) run('php',[path.join('tests',file)]);
run(process.platform==='win32'?'python':'python3',['tests/classroom-http.test.py','--robots']);
