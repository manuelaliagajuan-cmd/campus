"""Inspect webspace usage and prune only inactive, validated campus code releases."""
import base64, hashlib, hmac, json, os, posixpath, re, shlex, socket, stat, time
import paramiko

mode=os.environ.get('MAINTENANCE_MODE','audit')
if mode not in ('audit','prune-releases'):raise SystemExit('Modo no válido.')
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
    def emit(key,value):print('CEC_'+key+'='+json.dumps(value,ensure_ascii=False))
    inventory=[]
    for entry in ftp.listdir_attr(base):
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
        valid=exists(rel+'/artisan') and exists(rel+'/composer.lock')
        for name,target in [('.env','../../shared/.env'),('storage','../../shared/storage')]:
            if not exists(rel+'/'+name) or not stat.S_ISLNK(ftp.lstat(path(rel+'/'+name)).st_mode) or ftp.readlink(path(rel+'/'+name))!=target:valid=False
        releases.append({'sha':e.filename,'mtime':e.st_mtime,'KiB':kb(rel),'validated':valid})
    releases.sort(key=lambda r:r['mtime'],reverse=True)
    keep={active_sha}
    keep.update(r['sha'] for r in releases if r['sha']!=active_sha and r['validated'] and len(keep)<3)
    # Explicit loop: retain two previous complete releases even when current is newest.
    keep={active_sha}
    for r in releases:
        if r['validated'] and len(keep)<3:keep.add(r['sha'])
    candidates=[r for r in releases if r['validated'] and r['sha'] not in keep]
    emit('ACTIVE_RELEASE',active_sha);emit('KEEP_RELEASES',sorted(keep));emit('RELEASES',releases);emit('POTENTIAL_RECLAIM_KIB',sum(r['KiB'] for r in candidates))
    if mode=='prune-releases':
        before=kb(root)
        for r in candidates:
            if ftp.readlink(path(root+'/current'))!=active:raise RuntimeError('La versión activa ha cambiado; limpieza detenida.')
            rel=root+'/releases/'+r['sha']
            # rm follows no storage/.env symlinks inside the immutable code release.
            if r['sha']==active_sha or not stat.S_ISDIR(ftp.lstat(path(rel)).st_mode):raise RuntimeError('Candidato no seguro.')
            run('rm -rf -- '+shlex.quote(posixpath.join(home,rel)))
            emit('REMOVED_RELEASE',{'sha':r['sha'],'KiB':r['KiB']})
        emit('RECLAIMED_KIB',max(0,before-kb(root)))
    else:emit('READ_ONLY',True)
finally:
    if transport:transport.close()
