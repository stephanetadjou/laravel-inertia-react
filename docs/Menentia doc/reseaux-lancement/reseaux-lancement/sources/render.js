const {chromium}=require('playwright');const fs=require('fs'),path=require('path');
(async()=>{const b=await chromium.launch();const pg=await b.newPage({viewport:{width:1080,height:1350},deviceScaleFactor:1});
const dir=path.join(__dirname,'html'),out=path.join(__dirname,'..','affiches');
for(const f of fs.readdirSync(dir).filter(x=>x.endsWith('.html')).sort()){await pg.goto('file://'+path.join(dir,f));await pg.waitForTimeout(250);
await pg.screenshot({path:path.join(out,f.replace('.html','.png'))});}
await b.close();})();
