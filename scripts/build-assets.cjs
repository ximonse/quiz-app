// Reproducible local assets. Commit generated files so PHP hosting needs no Node.
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const vendor = path.join(root, 'assets/vendor');
fs.mkdirSync(vendor, {recursive:true});
const copies = {
    'react/umd/react.production.min.js':'react-18.3.1.min.js',
    'react-dom/umd/react-dom.production.min.js':'react-dom-18.3.1.min.js',
    '@babel/standalone/babel.min.js':'babel-7.29.11.min.js',
    'qrcodejs/qrcode.min.js':'qrcode-1.0.0.min.js'
};
function copyText(source,target) {
    // Keep generated output identical across Windows and Linux checkouts.
    fs.writeFileSync(target,fs.readFileSync(source,'utf8').replace(/\r\n/g,'\n'));
}
for (const [source,target] of Object.entries(copies)) copyText(path.join(root,'node_modules',source),path.join(vendor,target));
for (const name of ['react','react-dom','@babel/standalone','qrcodejs']) {
    const dir=path.join(root,'node_modules',name);
    const license=fs.readdirSync(dir).find(file=>/^licen[cs]e(?:\.txt|\.md)?$/i.test(file));
    if (!license) throw new Error(`Missing license for ${name}`);
    copyText(path.join(dir,license),path.join(vendor,name.replaceAll('/','-').replace('@','')+'.LICENSE'));
}
execFileSync(process.execPath,[path.join(root,'node_modules/tailwindcss/lib/cli.js'),'-c','tailwind.config.cjs','-i','assets/tailwind.css','-o','assets/vendor/tailwind-3.4.19.css','--minify'],{cwd:root,stdio:'inherit'});
console.log('Built pinned browser assets.');
