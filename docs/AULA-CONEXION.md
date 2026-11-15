# Conectividad del aula

El aula necesita TURN para redes que no permiten una conexión WebRTC directa (NAT restrictivo, algunas conexiones móviles y cortafuegos). STUN por sí solo no cubre esos casos. No se puede prometer ausencia absoluta de interrupciones en conexiones de Internet ajenas al campus.

## Activación por administración

1. Crear una cuenta propia en Cloudflare. Revisar precios, facturación y consumo en https://developers.cloudflare.com/realtime/turn/faq/ .
2. Realtime → TURN Server → Create. Crear una clave TURN (por ejemplo, Campus CEC).
3. Copiar TURN Key ID y su API Token en Campus → menú de perfil → Conexión del aula (`/administracion/conexion-aula`). No usar un token general de cuenta ni enviar las claves por chat.
4. «Verificar y guardar» comprueba la API del proveedor antes de sustituir la configuración. Un error conserva la anterior.
5. «Comprobar ruta de vídeo» verifica una asignación TURN real desde ese navegador, con `iceTransportPolicy: relay`.
6. Volver a entrar al aula desde ambos dispositivos, preferiblemente uno por wifi y otro por datos móviles. Verificar vídeo y sonido en ambos sentidos.

Las claves maestras se guardan cifradas con APP_KEY en almacenamiento privado compartido entre despliegues; no se imprimen, no se publican ni se devuelven al navegador. El servidor genera credenciales temporales y las cachea diez minutos por usuario/sesión. La configuración antigua `CEC_RTC_ICE_SERVERS` sigue siendo compatible. Sin configurar el proveedor, la aplicación conserva las conexiones directas; no debe presentarse como resuelta la cobertura entre todas las redes.

El token API no debe otorgar permisos ajenos a la clave TURN. Documentación del proveedor: https://developers.cloudflare.com/realtime/turn/generate-credentials/ . Los costes corresponden a la cuenta del titular; no se activa ni contrata ningún servicio desde el campus.

## Recuperación y pruebas

Se limita el tiempo de espera HTTP, se reintentan señales transitorias, se hace idempotente la entrada mediante UUID de conexión y se renegocia ICE tras una interrupción o al volver a la red. Los participantes no se duplican cuando se pierde la respuesta de entrada. «Reconectar» permite forzar la recuperación sin recargar ni perder chat/apuntes. Después de fallos repetidos se prioriza TURN cuando está disponible.

La prueba de dos navegadores incluye pérdida de una respuesta de entrada y de una señal ICE, corte de red y recuperación con nuevo ICE ufrag, audio y vídeo reales, pantalla compartida y controles de dispositivos. Se ejecuta en conexión directa y obligando a utilizar un coturn TCP local de pruebas. El coturn de CI solo escucha en loopback con credenciales ficticias; no se instala ni expone en producción.
