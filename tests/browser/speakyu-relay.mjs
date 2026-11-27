import {pathToFileURL} from 'node:url';
import fs from 'node:fs/promises';
const {chromium}=await import(pathToFileURL(process.env.CEC_PLAYWRIGHT_PATH).href);
const fixturePath=process.env.CEC_CONNECTIVITY_FIXTURE;
let browser;
try{
 const config=JSON.parse(await fs.readFile(fixturePath,'utf8'));
 const servers=config.iceServers.map(server=>({...server,urls:[server.urls].flat().filter(url=>/^turns:[^\s]+:443\?transport=tcp$/i.test(url))})).filter(server=>server.urls.length);
 if(!servers.length)throw Error('No hay una ruta TURN TLS por el puerto 443.');
 browser=await chromium.launch({headless:true,args:['--no-sandbox','--autoplay-policy=no-user-gesture-required']});
 const page=await browser.newPage();
 await page.setContent('<video id="one" autoplay muted playsinline></video><video id="two" autoplay muted playsinline></video>');
 await page.evaluate(async iceServers=>{
  const canvas=document.createElement('canvas');canvas.width=320;canvas.height=240;const ctx=canvas.getContext('2d');let n=0;
  window.__animation=setInterval(()=>{ctx.fillStyle=n++%2?'#212a5e':'#5278b8';ctx.fillRect(0,0,320,240);},100);
  const ac=new AudioContext();await ac.resume();const oscillator=ac.createOscillator(),destination=ac.createMediaStreamDestination();oscillator.connect(destination);oscillator.start();
  const source=canvas.captureStream(10);source.addTrack(destination.stream.getAudioTracks()[0]);
  const a=new RTCPeerConnection({iceServers,iceTransportPolicy:'relay'}),b=new RTCPeerConnection({iceServers,iceTransportPolicy:'relay'});window.__pcs=[a,b];
  for(const pc of[a,b])for(const track of source.getTracks())pc.addTrack(track,source);
  a.ontrack=e=>{document.getElementById('one').srcObject=e.streams[0];document.getElementById('one').play().catch(()=>{});};
  b.ontrack=e=>{document.getElementById('two').srcObject=e.streams[0];document.getElementById('two').play().catch(()=>{});};
  const pendingA=[],pendingB=[];
  a.onicecandidate=e=>{if(e.candidate){if(b.remoteDescription)b.addIceCandidate(e.candidate).catch(()=>{});else pendingB.push(e.candidate);}};
  b.onicecandidate=e=>{if(e.candidate){if(a.remoteDescription)a.addIceCandidate(e.candidate).catch(()=>{});else pendingA.push(e.candidate);}};
  await a.setLocalDescription(await a.createOffer());await b.setRemoteDescription(a.localDescription);
  for(const candidate of pendingB)await b.addIceCandidate(candidate);
  await b.setLocalDescription(await b.createAnswer());await a.setRemoteDescription(b.localDescription);
  for(const candidate of pendingA)await a.addIceCandidate(candidate);
 },servers);
 await page.waitForFunction(async()=>{
  for(const pc of window.__pcs){
   if(pc.connectionState!=='connected')return false;
   const stats=await pc.getStats();let relay=false,audio=false,video=false;
   for(const report of stats.values()){
    if(report.type==='candidate-pair'&&report.state==='succeeded'&&report.nominated&&stats.get(report.localCandidateId)?.candidateType==='relay')relay=true;
    if(report.type==='inbound-rtp'&&report.kind==='audio'&&report.bytesReceived>0)audio=true;
    if(report.type==='inbound-rtp'&&report.kind==='video'&&report.framesDecoded>0)video=true;
   }
   if(!relay||!audio||!video)return false;
  }
  return true;
 },null,{timeout:45000});
 console.log('SPEAKYU_TURN_TLS_443=true');
 console.log('SPEAKYU_TURN_AUDIO_BIDIRECCIONAL=true');
 console.log('SPEAKYU_TURN_VIDEO_BIDIRECCIONAL=true');
}catch(error){
 console.error('No se ha podido verificar audio y vídeo por el TURN configurado. No se muestran claves ni datos de Speakyu.');
 process.exitCode=1;
}finally{
 await browser?.close();
 await fs.unlink(fixturePath).catch(()=>{});
}
