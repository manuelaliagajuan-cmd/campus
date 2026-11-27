"""Obtain only short-lived test ICE credentials from the live Speakyu configuration, on the same host. Never log credentials."""
import base64, hashlib, hmac, json, os, socket, time
from pathlib import Path
import paramiko

transport = None
try:
    transport = paramiko.Transport(socket.create_connection((os.environ['IONOS_HOST'].strip(),22),timeout=20))
    transport.start_client(timeout=20)
    fingerprint='SHA256:'+base64.b64encode(hashlib.sha256(transport.get_remote_server_key().asbytes()).digest()).decode().rstrip('=')
    if not hmac.compare_digest(fingerprint,'SHA256:1gx2w8Rtv3wCgi7Jh8myf/KVd72cRQbow03UP8P095Q'):
        raise RuntimeError('La identidad SSH no coincide.')
    transport.auth_password(os.environ['IONOS_USER'].strip(),os.environ['IONOS_PASSWORD'],fallback=False)
    channel=transport.open_session(timeout=20)
    channel.settimeout(40)
    channel.exec_command('cd campus-cec/current && php')
    channel.sendall(Path(__file__).with_name('check-speakyu-relay.php').read_bytes())
    channel.shutdown_write()
    output=bytearray();error=bytearray();started=time.monotonic()
    while True:
        if channel.recv_ready(): output.extend(channel.recv(8192))
        if channel.recv_stderr_ready(): error.extend(channel.recv_stderr(8192))
        if len(output)+len(error)>65536 or time.monotonic()-started>40:
            raise RuntimeError('La comprobación remota ha superado su límite.')
        if channel.exit_status_ready() and not channel.recv_ready() and not channel.recv_stderr_ready():break
        time.sleep(.05)
    if channel.recv_exit_status()!=0:raise RuntimeError('No se pudo comprobar la configuración del campus.')
    try:data=json.loads(output)
    except Exception:raise RuntimeError('La respuesta de comprobación no es válida.') from None
    print('SPEAKYU_TURN_GUARDADO='+str(bool(data.get('configured'))).lower())
    print('SPEAKYU_TURN_CREDENCIALES_VALIDAS='+str(bool(data.get('relay'))).lower())
    if not data.get('relay'):raise RuntimeError('No hay credenciales TURN verificadas. Comprueba la configuración privada de Speakyu.')
    fd=os.open(os.environ['CEC_CONNECTIVITY_FIXTURE'],os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    with os.fdopen(fd,'w') as file:json.dump({'iceServers':data['iceServers']},file)
finally:
    if transport:transport.close()
