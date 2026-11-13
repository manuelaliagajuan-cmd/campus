"""Deploy the tested app to its own IONOS directory. Never touch CampusCEC or domain settings."""
import base64, hashlib, hmac, io, json, os, posixpath, re, secrets, shlex, socket, stat, sys, tarfile, tempfile
import paramiko

FINGERPRINT = 'SHA256:1gx2w8Rtv3wCgi7Jh8myf/KVd72cRQbow03UP8P095Q'
REQUIRED = ['IONOS_HOST','IONOS_USER','IONOS_PASSWORD','IONOS_DB_HOST','IONOS_DB_NAME','IONOS_DB_USER','IONOS_DB_PASSWORD']
for name in REQUIRED:
    if not os.environ.get(name):
        raise SystemExit('Falta el secreto '+name)
sha = os.environ.get('GITHUB_SHA','')
if not re.fullmatch('[a-f0-9]{40}',sha):
    raise SystemExit('Identificador de versión no válido.')
class WebspaceSFTP:
    # IONOS can expose a chrooted SFTP root while SSH returns an absolute host path.
    def __init__(self, client, ssh_home):
        self.client = client
        self.ssh_home = ssh_home.rstrip('/')
        self.ftp_home = client.normalize('.').rstrip('/') or '/'

    def path(self, value):
        if value == self.ssh_home:
            return self.ftp_home
        if value.startswith(self.ssh_home + '/'):
            return posixpath.join(self.ftp_home, value[len(self.ssh_home) + 1:])
        raise RuntimeError('Se ha rechazado una ruta fuera del espacio web.')

    def stat(self, p): return self.client.stat(self.path(p))
    def lstat(self, p): return self.client.lstat(self.path(p))
    def open(self, p, *args): return self.client.open(self.path(p), *args)
    def mkdir(self, p, mode): return self.client.mkdir(self.path(p), mode)
    def chmod(self, p, mode): return self.client.chmod(self.path(p), mode)
    def remove(self, p): return self.client.remove(self.path(p))
    def readlink(self, p): return self.client.readlink(self.path(p))
    def symlink(self, source, destination):
        if source.startswith('/'):
            raise RuntimeError('Solo se permiten enlaces relativos dentro de la instalación.')
        return self.client.symlink(source, self.path(destination))
    def putfo(self, file, p): return self.client.putfo(file, self.path(p))
    def close(self): return self.client.close()

transport = None
sftp = None
sensitive = [os.environ[n] for n in REQUIRED]

def run(command, timeout=180, silent=False):
    channel = transport.open_session(timeout=20)
    channel.settimeout(timeout)
    channel.exec_command(command)
    out, err = bytearray(), bytearray()
    while True:
        if channel.recv_ready(): out.extend(channel.recv(16384))
        if channel.recv_stderr_ready(): err.extend(channel.recv_stderr(16384))
        if len(out)+len(err)>1048576:
            channel.close();raise RuntimeError('La respuesta remota superó el límite.')
        if channel.exit_status_ready() and not channel.recv_ready() and not channel.recv_stderr_ready():break
        import time
        time.sleep(.05)
    status = channel.recv_exit_status()
    text = out.decode('utf-8','replace')
    if status:
        # Print only a sanitized tail so a failed migration can be diagnosed.
        detail = (text+'\n'+err.decode('utf-8','replace'))[-5000:]
        for secret in sorted(sensitive,key=len,reverse=True):
            if secret:detail=detail.replace(secret,'[oculto]')
        print(detail)
        raise RuntimeError('El comando remoto terminó con error.')
    if not silent:
        for line in text.splitlines():
            if line.startswith('CEC_'):print(line)
    return text.strip()

def write(path, data, mode=0o600):
    with sftp.open(path,'w') as f:f.write(data)
    sftp.chmod(path,mode)

def exists(path):
    try:sftp.lstat(path);return True
    except FileNotFoundError:return False
    except OSError as e:
        if e.errno==2:return False
        raise

def dotenv(value):
    # Escape values for vlucas/phpdotenv. Prevent interpolation and physical newlines.
    return '"'+str(value).replace('\\','\\\\').replace('$','\\$').replace('"','\\"').replace('\n','\\n').replace('\r','\\r')+'"'

try:
    transport=paramiko.Transport(socket.create_connection((os.environ['IONOS_HOST'].strip(),22),timeout=20))
    transport.start_client(timeout=20)
    key=transport.get_remote_server_key()
    actual='SHA256:'+base64.b64encode(hashlib.sha256(key.asbytes()).digest()).decode().rstrip('=')
    if not hmac.compare_digest(actual,FINGERPRINT):raise RuntimeError('La identidad SSH ha cambiado; no se ha enviado la contraseña.')
    transport.auth_password(os.environ['IONOS_USER'].strip(),os.environ['IONOS_PASSWORD'],fallback=False)
    raw_sftp=paramiko.SFTPClient.from_transport(transport)
    home=run('pwd -P',silent=True)
    if not home.startswith('/') or '\n' in home:raise RuntimeError('No se ha podido resolver el directorio remoto.')
    # Resolve both namespaces before writing anything.
    sftp=WebspaceSFTP(raw_sftp,home)
    sftp.stat(posixpath.join(home,'CampusCEC'))
    root=posixpath.join(home,'campus-cec');marker=posixpath.join(root,'.cec-managed')
    if exists(root) and not exists(marker):raise RuntimeError('La carpeta campus-cec ya existe y no pertenece a este despliegue.')
    if not exists(root):
        sftp.mkdir(root,0o755);write(marker,'manuelaliagajuan-cmd/campus\n')
    with sftp.open(marker) as f:
        if f.read().decode().strip()!='manuelaliagajuan-cmd/campus':raise RuntimeError('Identificador de instalación no válido.')
    for rel,mode in [('releases',0o755),('shared',0o700),('shared/storage',0o700),('shared/storage/app',0o700),('shared/storage/app/private',0o700),('shared/storage/framework',0o700),('shared/storage/framework/cache',0o700),('shared/storage/framework/cache/data',0o700),('shared/storage/framework/sessions',0o700),('shared/storage/framework/views',0o700),('shared/storage/logs',0o700)]:
        path=posixpath.join(root,rel)
        if not exists(path):sftp.mkdir(path,mode)
    envpath=posixpath.join(root,'shared/.env')
    appkey=None
    if exists(envpath):
        with sftp.open(envpath) as f:
            old=f.read().decode()
        match=re.search(r'^APP_KEY="?(base64:[A-Za-z0-9+/=]+)"?$',old,re.M)
        if not match:raise RuntimeError('La configuración existente no contiene una clave de aplicación válida.')
        appkey=match.group(1)
    if not appkey:appkey='base64:'+base64.b64encode(secrets.token_bytes(32)).decode()
    sensitive.append(appkey)
    targethash=hashlib.sha256((os.environ['IONOS_DB_HOST'].strip()+'\0'+os.environ['IONOS_DB_NAME'].strip()).encode()).hexdigest()
    dbmarker=posixpath.join(root,'shared/database-target.sha256')
    first=not exists(dbmarker)
    if not first:
        with sftp.open(dbmarker) as f:
            if not hmac.compare_digest(f.read().decode().strip(),targethash):raise RuntimeError('Los secretos apuntan a otra base de datos; se ha detenido el despliegue.')
    config={'APP_NAME':'Campus CEC','APP_ENV':'production','APP_KEY':appkey,'APP_DEBUG':'false','APP_URL':'https://campus.cecalicante.es','APP_LOCALE':'es','APP_FALLBACK_LOCALE':'es','DB_CONNECTION':'mysql','DB_HOST':os.environ['IONOS_DB_HOST'].strip(),'DB_PORT':'3306','DB_DATABASE':os.environ['IONOS_DB_NAME'].strip(),'DB_USERNAME':os.environ['IONOS_DB_USER'].strip(),'DB_PASSWORD':os.environ['IONOS_DB_PASSWORD'],'SESSION_DRIVER':'database','SESSION_LIFETIME':'120','SESSION_ENCRYPT':'true','SESSION_SECURE_COOKIE':'true','SESSION_HTTP_ONLY':'true','SESSION_SAME_SITE':'lax','SESSION_COOKIE':'cec_campus_session','CACHE_STORE':'file','QUEUE_CONNECTION':'sync','LOG_CHANNEL':'single','LOG_LEVEL':'warning','MAIL_MAILER':'log','MAIL_FROM_ADDRESS':'campus@cecalicante.es','MAIL_FROM_NAME':'Campus CEC'}
    if all(os.environ.get(n) for n in ['CEC_MAIL_HOST','CEC_MAIL_USER','CEC_MAIL_PASSWORD']):
        config.update({'MAIL_MAILER':'smtp','MAIL_HOST':os.environ['CEC_MAIL_HOST'],'MAIL_PORT':os.environ.get('CEC_MAIL_PORT') or '587','MAIL_USERNAME':os.environ['CEC_MAIL_USER'],'MAIL_PASSWORD':os.environ['CEC_MAIL_PASSWORD'],'MAIL_SCHEME':'smtps' if os.environ.get('CEC_MAIL_PORT')=='465' else 'smtp','MAIL_FROM_ADDRESS':os.environ.get('CEC_MAIL_FROM') or os.environ['CEC_MAIL_USER']})
        sensitive.append(os.environ['CEC_MAIL_PASSWORD'])
    # Existing env is backed up without printing its contents.
    if exists(envpath):
        with sftp.open(envpath) as f:prior_env=f.read()
    else:prior_env=None
    write(envpath,'\n'.join(k+'='+dotenv(v) for k,v in config.items())+'\n')
    release=posixpath.join(root,'releases',sha)
    if exists(release):
        # Re-running the same immutable source is safe; migrations are idempotent.
        if not exists(posixpath.join(release,'artisan')):raise RuntimeError('Existe una versión incompleta. Revisa la carpeta antes de reintentar.')
    else:
        sftp.mkdir(release,0o755)
        remote_archive=posixpath.join(root,'releases',sha+'.tar.gz')
        with tempfile.TemporaryFile() as archive:
            with tarfile.open(fileobj=archive,mode='w:gz') as tar:
                for name in ['app','artisan','bootstrap','composer.json','composer.lock','config','database','public','resources','routes','vendor']:
                    def include(info):
                        if info.name.startswith('bootstrap/cache/') and not info.name.endswith('.gitignore'):return None
                        if info.name.startswith('database/') and info.name.endswith('.sqlite'):return None
                        return info
                    tar.add(name,arcname=name,filter=include)
            archive.seek(0);sftp.putfo(archive,remote_archive)
        run('tar -xzf '+shlex.quote(remote_archive)+' -C '+shlex.quote(release)+' && rm -- '+shlex.quote(remote_archive),silent=True)
        sftp.symlink('../../shared/.env',posixpath.join(release,'.env'))
        sftp.symlink('../../shared/storage',posixpath.join(release,'storage'))
    prefix='cd '+shlex.quote(release)+' && '
    # The first migration must only run against an empty database.
    if first:
        run(prefix+'php artisan cec:empty-database --no-interaction')
        write(dbmarker,targethash+'\n')
    try:
        run(prefix+'php artisan migrate --force --no-interaction && php artisan config:cache && php artisan view:cache && php artisan cec:health --no-interaction',timeout=240)
    except Exception:
        if prior_env is not None:write(envpath,prior_env)
        raise
    # Publish only after migrations, compiled views and the database health check pass.
    current=posixpath.join(root,'current');nextlink=posixpath.join(root,'current.next')
    if exists(current) and not stat.S_ISLNK(sftp.lstat(current).st_mode):raise RuntimeError('current existe y no es un enlace gestionado.')
    if exists(nextlink):sftp.remove(nextlink)
    sftp.symlink('releases/'+sha,nextlink)
    run('mv -Tf -- '+shlex.quote(nextlink)+' '+shlex.quote(current),silent=True)
    public=posixpath.join(root,'public')
    if exists(public):
        if not stat.S_ISLNK(sftp.lstat(public).st_mode) or sftp.readlink(public)!='current/public':raise RuntimeError('El destino public existente no es el esperado.')
    else:sftp.symlink('current/public',public)
    print('CEC_INSTALACION_CORRECTA')
    print('CEC_DESTINO_IONOS=/campus-cec/public')
    print('CEC_WORDPRESS_CONSERVADO=/CampusCEC')
    with open(os.environ['GITHUB_STEP_SUMMARY'],'a') as summary:
        summary.write('## Campus CEC instalado en carpeta independiente\n\n- Versión: '+sha+'\n- Destino que deberá seleccionar el propietario en IONOS: **/campus-cec/public**.\n- WordPress de /CampusCEC conservado. No se han cambiado dominios.\n- Base de datos migrada y comprobada. No existen cuentas ni contraseñas por defecto.\n- Después de cambiar el destino, registra tu cuenta y ejecuta el flujo Habilitar administrador con tu correo.\n- Correo: '+('SMTP configurado; pendiente de prueba de entrega real.' if config['MAIL_MAILER']=='smtp' else 'pendiente de configurar SMTP; recuperación por correo deshabilitada.')+'\n')
except Exception as e:
    message=str(e)
    for secret in sorted(sensitive,key=len,reverse=True):
        if secret:message=message.replace(secret,'[oculto]')
    raise SystemExit(message)
finally:
    if sftp:sftp.close()
    if transport:transport.close()
