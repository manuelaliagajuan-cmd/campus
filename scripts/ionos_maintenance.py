"""Inspect webspace usage and prune only inactive, validated campus code releases."""
import base64, hashlib, hmac, json, os, posixpath, re, shlex, socket, stat, time
import paramiko
import urllib.request
import urllib.parse
from datetime import datetime, timezone

mode=os.environ.get('MAINTENANCE_MODE','audit')
if mode not in ('audit','prune-releases','archive-legacy-wordpress'):raise SystemExit('Modo no válido.')
transport=None
try:
    transport=paramiko.Transport(socket.create_connection((os.environ['IONOS_HOST'].strip(),22),timeout=20))
    transport.start_client(timeout=20)
    fingerprint='SHA256:'+base64.b64encode(hashlib.sha256(transport.get_remote_server_key().asbytes()).digest()).decode().rstrip('=')
    if not hmac.compare_digest(fingerprint,'SHA256:1gx2w8Rtv3wCgi7Jh8myf/KVd72cRQbow03UP8P095Q'):raise RuntimeError('Identidad SSH no reconocida.')
    transport.auth_password(os.environ['IONOS_USER'].strip(),os.environ['IONOS_PASSWORD'],fallback=False)
    ftp=paramiko.SFTPClient.from_transport(transport)
    def run(command,timeout=180):
        c=transport.open_session(timeout=20);c.settimeout(timeout);c.exec_command(command);out=bytearray();err=bytearray();start=time.monotonic()
        while True:
            if c.recv_ready():out.extend(c.recv(16384))
            if c.recv_stderr_ready():err.extend(c.recv_stderr(16384))
            if len(out)+len(err)>262144 or time.monotonic()-start>timeout:c.close();raise RuntimeError('Consulta remota fuera de límites.')
            if c.exit_status_ready() and not c.recv_ready() and not c.recv_stderr_ready():break
            time.sleep(.05)
        if c.recv_exit_status()!=0:raise RuntimeError('No se pudo completar la operación remota.')
        return out.decode().strip()
    home=run('pwd -P');base=ftp.normalize('.')
    if not home.startswith('/') or '\n' in home:raise RuntimeError('Directorio remoto no válido.')
    def path(relative):return posixpath.join(base,relative)
    def exists(relative):
        try:ftp.lstat(path(relative));return True
        except FileNotFoundError:return False
    def text(relative):
        with ftp.open(path(relative)) as f:return f.read(16385).decode()
    def kb(relative):
        result=run('du -sk -- '+shlex.quote(posixpath.join(home,relative)),timeout=240)
        return int(result.split()[0])
    def emit(key,value):print('CEC_'+key+'='+json.dumps(value,ensure_ascii=False),flush=True)
    inventory=[]
    for entry in (ftp.listdir_attr(base) if mode=='audit' else []):
        name=entry.filename
        if name in ('.','..'):continue
        if stat.S_ISDIR(entry.st_mode):
            row={'directory':name,'KiB':kb(name),'wordpress':exists(name+'/wp-config.php'),'laravel':exists(name+'/artisan')}
            if name in ('public','CampusCEC','campus-cec','gestion','clickandbuilds'):
                row['children']=[{'name':e.filename,'directory':stat.S_ISDIR(e.st_mode),'symlink':stat.S_ISLNK(e.st_mode)} for e in ftp.listdir_attr(path(name)) if stat.S_ISDIR(e.st_mode) or stat.S_ISLNK(e.st_mode)]
            inventory.append(row)
        elif stat.S_ISLNK(entry.st_mode):inventory.append({'symlink':name})
    emit('INVENTORY',inventory)
    root='campus-cec'
    if not exists(root+'/.cec-managed') or text(root+'/.cec-managed').strip()!='manuelaliagajuan-cmd/campus':raise RuntimeError('No se reconoce la instalación gestionada.')
    active=ftp.readlink(path(root+'/current'))
    if not re.fullmatch('releases/[a-f0-9]{40}',active):raise RuntimeError('Versión activa no válida.')
    if not exists(root+'/public/.cec-webroot') or text(root+'/public/.cec-webroot').strip()!='managed':raise RuntimeError('Raíz pública no reconocida.')
    active_sha=active.split('/')[1]
    releases=[]
    for e in ftp.listdir_attr(path(root+'/releases')):
        if not re.fullmatch('[a-f0-9]{40}',e.filename) or not stat.S_ISDIR(e.st_mode):continue
        rel=root+'/releases/'+e.filename
        children={child.filename:child for child in ftp.listdir_attr(path(rel))}
        valid=all(name in children and stat.S_ISREG(children[name].st_mode) for name in ('artisan','composer.lock'))
        for name,target in [('.env','../../shared/.env'),('storage','../../shared/storage')]:
            if name not in children or not stat.S_ISLNK(children[name].st_mode) or ftp.readlink(path(rel+'/'+name))!=target:valid=False
        releases.append({'sha':e.filename,'mtime':e.st_mtime,'KiB':0,'validated':valid})
    if releases:
        sizes=run('du -sk -- '+' '.join(shlex.quote(posixpath.join(home,root,'releases',r['sha'])) for r in releases),timeout=240)
        sizes={posixpath.basename(line.split(None,1)[1]):int(line.split(None,1)[0]) for line in sizes.splitlines()}
        for release in releases:release['KiB']=sizes[release['sha']]
    releases.sort(key=lambda r:r['mtime'],reverse=True)
    if not any(r['sha']==active_sha and r['validated'] for r in releases):raise RuntimeError('La versión activa no está completa; no se limpiará.')
    # Retain current plus two previous complete releases.
    keep={active_sha}
    for r in releases:
        if r['validated'] and len(keep)<3:keep.add(r['sha'])
    candidates=[r for r in releases if r['validated'] and r['sha'] not in keep]
    emit('ACTIVE_RELEASE',active_sha);emit('KEEP_RELEASES',sorted(keep));emit('RELEASES',releases);emit('POTENTIAL_RECLAIM_KIB',sum(r['KiB'] for r in candidates))
    def fetch(url,limit=2097152):
        for attempt in range(3):
            try:
                with urllib.request.urlopen(urllib.request.Request(url,headers={'User-Agent':'CEC-maintenance/1.0','Cache-Control':'no-cache'}),timeout=25) as response:
                    data=response.read(limit+1)
                    if response.status!=200 or len(data)>limit:raise RuntimeError('No se pudo verificar la página pública.')
                    fetch.headers=response.headers
                    return data
            except (OSError,TimeoutError):
                if attempt==2:raise
                time.sleep(2)
    def verify_campus():
        fetch('https://campus.cecalicante.es/acceso?cec-maintenance='+str(time.time_ns()))
        if fetch.headers.get('X-CEC-Release')!=active_sha:raise RuntimeError('El campus público no coincide con la versión activa.')
        fetch('https://gestion.cecalicante.es/')
    if mode=='prune-releases':
        verify_campus()
        before=kb(root)
        for r in candidates:
            if ftp.readlink(path(root+'/current'))!=active:raise RuntimeError('La versión activa ha cambiado; limpieza detenida.')
            rel=root+'/releases/'+r['sha']
            # rm follows no storage/.env symlinks inside the immutable code release.
            if r['sha']==active_sha or not stat.S_ISDIR(ftp.lstat(path(rel)).st_mode):raise RuntimeError('Candidato no seguro.')
            run('rm -rf -- '+shlex.quote(posixpath.join(home,rel)))
            emit('REMOVED_RELEASE',{'sha':r['sha'],'KiB':r['KiB']})
        verify_campus()
        emit('RECLAIMED_KIB',max(0,before-kb(root)))
    elif mode=='archive-legacy-wordpress':
        verify_campus()
        page=fetch('https://cecalicante.es/')
        if re.search(rb'wp-content|wp-includes|CampusCEC',page,re.I):raise RuntimeError('La web pública referencia WordPress; se conservará.')
        public_match=False
        for candidate in ('public/index.html','public/index.php'):
            if exists(candidate):
                with ftp.open(path(candidate)) as f:raw=f.read(2097153)
                if raw==page:public_match=True
        if not public_match:raise RuntimeError('No se pudo comprobar la raíz de la web principal; se conservará WordPress.')
        if not exists('CampusCEC/wp-config.php') or not exists('CampusCEC/wp-includes/version.php') or not stat.S_ISDIR(ftp.lstat(path('CampusCEC')).st_mode):raise RuntimeError('No se reconoce el antiguo WordPress.')
        # Ensure the same historical location is absent from every maintained application's code.
        emit('PUBLIC_SITE_ROOT_VERIFIED','public')
        backups=root+'/shared/legacy-backups'
        if not exists(backups):ftp.mkdir(path(backups),0o700)
        if not stat.S_ISDIR(ftp.lstat(path(backups)).st_mode):raise RuntimeError('Directorio de copias no válido.')
        ftp.chmod(path(backups),0o700)
        stamp=datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')
        archive=backups+'/wordpress-CampusCEC-'+stamp+'.tar.gz'
        stage=backups+'/wordpress-staging-'+stamp
        if exists(archive) or exists(stage):raise RuntimeError('Ya existe la copia de esta ejecución.')
        before=kb('CampusCEC')
        run('umask 077; tar -czf '+shlex.quote(posixpath.join(home,archive))+' -C '+shlex.quote(home)+' -- CampusCEC',timeout=240)
        ftp.chmod(path(archive),0o600)
        # GNU tar compares bytes and metadata against the source before any removal.
        run('tar -dzf '+shlex.quote(posixpath.join(home,archive))+' -C '+shlex.quote(home),timeout=240)
        digest=run('sha256sum -- '+shlex.quote(posixpath.join(home,archive))).split()[0]
        backup_kb=kb(archive)
        if not re.fullmatch('[a-f0-9]{64}',digest):raise RuntimeError('No se pudo verificar la copia.')
        emit('LEGACY_BACKUP',{'path':archive,'sha256':digest,'KiB':backup_kb,'source_KiB':before})
        # A temporary, reversible move precedes health checks; only then remove the old copy.
        ftp.rename(path('CampusCEC'),path(stage))
        try:
            ftp.mkdir(path('CampusCEC'),0o755)
            with ftp.open(path('CampusCEC/CEC-ARCHIVADO.txt'),'w') as f:f.write('WordPress antiguo archivado en '+archive+'. La web principal sigue en /public y el campus en /campus-cec/public.\n')
            ftp.chmod(path('CampusCEC/CEC-ARCHIVADO.txt'),0o600)
            if fetch('https://cecalicante.es/')!=page:raise RuntimeError('La web pública ha cambiado durante la revisión.')
            verify_campus()
        except Exception:
            if exists('CampusCEC/CEC-ARCHIVADO.txt'):ftp.remove(path('CampusCEC/CEC-ARCHIVADO.txt'))
            ftp.rmdir(path('CampusCEC'))
            ftp.rename(path(stage),path('CampusCEC'))
            emit('LEGACY_RESTORED',True)
            raise
        run('rm -rf -- '+shlex.quote(posixpath.join(home,stage)))
        emit('LEGACY_RECLAIMED_KIB',max(0,before-backup_kb-kb('CampusCEC')))
        emit('PUBLIC_SITES_VERIFIED',True)
    else:emit('READ_ONLY',True)
finally:
    if transport:transport.close()
