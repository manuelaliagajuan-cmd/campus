"""Publish only the reviewed public website files, with backup and rollback."""
import base64,hashlib,hmac,json,os,posixpath,secrets,shlex,socket,stat,time,urllib.request
from pathlib import Path
import paramiko
import tempfile
files={'index.html':Path('website/index.html'),'comprar.html':Path('website/comprar.html'),'js/cec-store.js':Path('website/js/cec-store.js'),'pdf/precios-horarios-ingles-cec-2026-2027.pdf':Path('website/pdf/precios-horarios-ingles-cec-2026-2027.pdf')}
photo_names=[f'cec-provisional-{kind}-{width}.jpg' for kind in ['sobre-nosotros','actividad-tabarca','actividad-lectura','actividad-kayak','actividad-campus'] for width in ([600,1200] if kind=='sobre-nosotros' else [700,1400])]
files.update({'img/'+name:Path('website/img')/name for name in photo_names})
files.update({str(v.relative_to('website')):v for pattern in ['img/*.webp','img/cec-alicante-clases-720p.mp4','css/*.css','js/cec-performance.js','robots.txt','sitemap.xml'] for v in Path('website').glob(pattern)})
expected_original='224e3672baedcdd1b9effb96a582c7305a1f1ae6b999ecb2c1bf61530fe26f26'
transport=None
try:
 transport=paramiko.Transport(socket.create_connection((os.environ['IONOS_HOST'].strip(),22),timeout=20));transport.start_client(timeout=20)
 fp='SHA256:'+base64.b64encode(hashlib.sha256(transport.get_remote_server_key().asbytes()).digest()).decode().rstrip('=')
 if not hmac.compare_digest(fp,'SHA256:1gx2w8Rtv3wCgi7Jh8myf/KVd72cRQbow03UP8P095Q'):raise RuntimeError('Identidad SSH no reconocida.')
 transport.auth_password(os.environ['IONOS_USER'].strip(),os.environ['IONOS_PASSWORD'],fallback=False);ftp=paramiko.SFTPClient.from_transport(transport);base=ftp.normalize('.')
 def p(rel):return posixpath.join(base,rel)
 def exists(rel):
  try:ftp.lstat(p(rel));return True
  except FileNotFoundError:return False
 def read(rel):
  with ftp.open(p(rel)) as f:return f.read(4194305)
 def write(rel,data,mode=0o600):
  with ftp.open(p(rel),'wb') as f:f.write(data)
  ftp.chmod(p(rel),mode)
 def fetch(url):
  for attempt in range(3):
   try:
    with urllib.request.urlopen(url,timeout=25) as r:return r.read(4194305)
   except OSError:
    if attempt==2:raise
    time.sleep(2)
 def atomic(rel,data):
  temp=rel+'.cec-'+secrets.token_hex(8);write(temp,data,0o644)
  try:ftp.posix_rename(p(temp),p(rel))
  except Exception:
   if exists(temp):ftp.remove(p(temp))
   raise
 if not stat.S_ISDIR(ftp.lstat(p('public')).st_mode):raise RuntimeError('La raíz pública no es un directorio normal.')
 # Refuse to publish the website CTA before its actual shared catalogue is live.
 catalog=json.loads(fetch('https://campus.cecalicante.es/catalogo-publico'))
 if not isinstance(catalog.get('items'),list):raise RuntimeError('El catálogo del campus no está listo.')
 manifest_path='campus-cec/shared/website-managed.json';manifest=json.loads(read(manifest_path)) if exists(manifest_path) else {}
 # Preserve all existing hosting rules; replace only our previously managed block.
 htaccess='public/.htaccess'
 if exists(htaccess) and not stat.S_ISREG(ftp.lstat(p(htaccess)).st_mode):raise RuntimeError('Configuración pública no regular.')
 previous=read(htaccess) if exists(htaccess) else b''
 fragment=Path('website/build/performance.htaccess.fragment').read_bytes().strip()+b'\n'
 begin=b'# BEGIN CEC PERFORMANCE';end=b'# END CEC PERFORMANCE'
 if previous.count(begin)!=previous.count(end) or previous.count(begin)>1:raise RuntimeError('Bloque de rendimiento ambiguo.')
 if begin in previous:
  start=previous.index(begin);stop=previous.index(end)+len(end)
  block=previous[start:stop].strip()+b'\n'
  if hashlib.sha256(block).hexdigest() not in {manifest.get('_htaccess_block'),hashlib.sha256(fragment).hexdigest()}:raise RuntimeError('Configuración de rendimiento modificada externamente.')
  merged=previous[:start]+fragment.rstrip()+previous[stop:]
 else:merged=previous+b'\n'+fragment
 temporary=tempfile.TemporaryDirectory();configuration=Path(temporary.name)/'htaccess';configuration.write_bytes(merged);files['.htaccess']=configuration
 prior={}
 for name,source in files.items():
  rel='public/'+name
  if exists(rel) and not stat.S_ISREG(ftp.lstat(p(rel)).st_mode):raise RuntimeError('Archivo público no regular: '+name)
  current=read(rel) if exists(rel) else None;prior[name]=current
  if current is not None:
   digest=hashlib.sha256(current).hexdigest();allowed={hashlib.sha256(source.read_bytes()).hexdigest(),manifest.get(name)}
   if name=='index.html':allowed.add(expected_original)
   if name!='.htaccess' and digest not in allowed:raise RuntimeError('Hay cambios no revisados en '+name+'. No se sobrescribirá.')
  elif name=='index.html':raise RuntimeError('No se encontró la web principal.')
 backups='campus-cec/shared/website-backups'
 if not exists(backups):ftp.mkdir(p(backups),0o700)
 backup=backups+'/'+str(int(time.time()))+'-'+secrets.token_hex(4);ftp.mkdir(p(backup),0o700)
 for name,data in prior.items():
  if data is not None:write(backup+'/'+name.replace('/','__'),data)
 for directory in ['js','css']:
  if not exists('public/'+directory):ftp.mkdir(p('public/'+directory),0o755)
  if not stat.S_ISDIR(ftp.lstat(p('public/'+directory)).st_mode):raise RuntimeError('Directorio público no regular.')
 if not stat.S_ISDIR(ftp.lstat(p('public/pdf')).st_mode):raise RuntimeError('El directorio de documentos no es regular.')
 if not stat.S_ISDIR(ftp.lstat(p('public/js')).st_mode):raise RuntimeError('El directorio de scripts no es regular.')
 if not stat.S_ISDIR(ftp.lstat(p('public/img')).st_mode):raise RuntimeError('El directorio de imágenes no es regular.')
 published=[]
 try:
  for name in sorted(files,key=lambda n: (n=='.htaccess',n in ['index.html','comprar.html'],n)):
   if prior[name]!=files[name].read_bytes():
    atomic('public/'+name,files[name].read_bytes());published.append(name)
  for name,source in files.items():
   actual=read('public/'+name) if name=='.htaccess' else fetch('https://cecalicante.es/'+name+'?cec-release='+os.environ['GITHUB_SHA'])
   if actual!=source.read_bytes():raise RuntimeError('La verificación pública no coincide: '+name)
 except Exception:
  for name in reversed(published):
   if read('public/'+name)!=files[name].read_bytes():raise RuntimeError('Cambios simultáneos detectados; revisar restauración manual.')
   if prior[name] is None:ftp.remove(p('public/'+name))
   else:atomic('public/'+name,prior[name])
  raise
 write(manifest_path,json.dumps({**{n:hashlib.sha256(v.read_bytes()).hexdigest() for n,v in files.items()},'_htaccess_block':hashlib.sha256(fragment).hexdigest()}).encode())
 print('CEC_WEB_PUBLICADA=cecalicante.es');print('CEC_WEB_BACKUP='+backup);print('CEC_WEB_ARCHIVOS='+str(len(files)))
finally:
 if transport:transport.close()
