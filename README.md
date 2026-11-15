# Campus CEC

LMS propio del Centro de Estudios Campoamor (Alicante), sin WordPress, TutorLMS ni Elementor. Laravel 12, PHP 8.3+ y MySQL/MariaDB; interfaz propia con recursos locales y sin compilación de Node en producción.

## Funciones implementadas

- Registro de estudiantes, acceso con contraseña, cierre de sesión y permisos de estudiante, profesor y administrador comprobados en servidor.
- Administración de roles: el registro público nunca concede privilegios docentes. No existen cuentas ni contraseñas por defecto.
- Cursos en borrador o publicados, lecciones por unidades, contenido y vídeos incrustados de YouTube mediante URL.
- Matrícula por correo de una cuenta registrada, revocación del acceso y seguimiento de lecciones completadas.
- Biblioteca privada por curso: PDF, imágenes, Office, audio y vídeo; subida, consulta, descarga y eliminación por el profesor autorizado. Límite de aplicación 20 MB, sujeto al límite inferior de PHP/IONOS. Office se descarga; los formatos compatibles se visualizan en el navegador.
- Catálogo visual y constructor propio de selección múltiple, verdadero/falso, huecos, emparejamiento y ordenación con arrastre y alternativa por botones.
- Actividades con varias preguntas, banco reutilizable en distintas lecciones del mismo curso, vista previa, publicación, número de intentos, objetivo mínimo y explicación de correcciones.
- Corrección en servidor, resultados persistentes y consulta docente. No se entregan soluciones al alumno antes de responder. Los intentos conservan las preguntas y el objetivo con los que comenzaron, aunque el profesor edite después la actividad.
- Interfaz adaptable a móvil con azul Campoamor `#212A5E`, blanco, negro y recursos gráficos del manual. Se usa Arial como sustitución de Nexa; falta incorporar el archivo original del logotipo y, si se desea, la fuente con licencia web.

Los vídeos de YouTube se sirven desde YouTube y no ocupan espacio de vídeo en IONOS. «No listado» no evita compartir el enlace. Esta versión no incluye preguntas superpuestas durante el vídeo, pagos, videoconferencias, SCORM ni integración H5P. Las actividades y evaluaciones aquí descritas son propias de este LMS.

## Desarrollo local

```sh
composer install
cp .env.example .env
php artisan key:generate
# En desarrollo se puede utilizar SQLite.
touch database/database.sqlite
php artisan migrate
php artisan serve
```

La carpeta pública del servidor debe apuntar únicamente a `public/`. No publicar `.env`, `storage/`, `vendor/` ni la raíz de la aplicación como directorio del dominio. No ejecutar `storage:link` para exponer los materiales privados.

```sh
php artisan test
php artisan view:cache
```

Pruebas locales: 25 pruebas de aplicación con 136 aserciones; flujo en Chromium de acceso, catálogo, creación de dos tipos de pregunta, vista previa, guardado, respuesta y nota 100%, menú móvil y ausencia de desbordamiento horizontal a 390 px. Las pruebas automatizadas usan SQLite; las migraciones y el chequeo de conexión del despliegue se ejecutan sobre MariaDB de IONOS. No se ha realizado una prueba de carga con alumnos simultáneos.

## GitHub e IONOS

El flujo **Comprobar e instalar Campus CEC** se ejecuta al subir a `main`, en las pull requests (solo pruebas) y manualmente. Primero instala las dependencias fijadas en `composer.lock` y ejecuta las pruebas. Si pasan en `main`, instala el código en una versión separada de `campus-cec/`, sin tocar `CampusCEC/` ni la configuración del dominio.

Secretos necesarios, ya solicitados en el panel de GitHub Actions:

- `IONOS_HOST`, `IONOS_USER`, `IONOS_PASSWORD`.
- `IONOS_DB_HOST`, `IONOS_DB_NAME`, `IONOS_DB_USER`, `IONOS_DB_PASSWORD`.

La huella SSH aceptada de esta instalación se verifica antes de enviar credenciales. Si cambia, el despliegue se detiene. Las contraseñas no se guardan en el repositorio. La configuración privada `.env`, las sesiones y los archivos permanecen en `campus-cec/shared/`; la clave de cifrado se genera en la primera instalación y se conserva.

El primer despliegue exige una base de datos vacía y registra qué base pertenece a la instalación. Se ejecutan migraciones, compilación de vistas y comprobación de tablas antes de cambiar el enlace `current`. No se ejecutan `migrate:fresh`, borrados de tablas ni borrados de WordPress.

### Publicar en el subdominio

1. Esperar a que los trabajos `test` y `deploy` del flujo estén en verde.
2. En IONOS, cambiar **solo** el destino de `campus.cecalicante.es` de `/CampusCEC` a **`/campus-cec/public`**. El despliegue mantiene esta carpeta pública estable y su cargador selecciona la versión instalada.
3. Abrir el campus con HTTPS y registrar tu cuenta personal. No hay un administrador predefinido.
4. Ejecutar **Habilitar administrador del campus** desde GitHub Actions, introduciendo el correo exacto de esa cuenta. Este flujo solo lo puede iniciar quien tenga permisos de ejecución en el repositorio; no es una operación disponible para visitantes del campus.
5. Cerrar sesión y volver a entrar. Aparecerán gestión de usuarios, creación de cursos y catálogo docente.
6. Crear un curso, una lección, una actividad y una cuenta de estudiante de prueba; matricularla y comprobar permisos, vídeo, archivos, respuestas y progreso en el alojamiento real.

Ni el código ni los flujos cambian automáticamente el destino del subdominio. El sitio anterior permanece en `/CampusCEC`, pero la base de datos nueva no importa ni migra usuarios, cursos o matrículas de WordPress.

### Correo y recuperación de contraseña

La recuperación usa el broker de Laravel y está probada con notificaciones simuladas. Para el envío real, añadir los secretos `CEC_MAIL_HOST`, `CEC_MAIL_USER`, `CEC_MAIL_PASSWORD`, `CEC_MAIL_PORT` (normalmente 587 o 465) y `CEC_MAIL_FROM` (remitente autorizado). Reejecutar el despliegue y probar la recepción real de un correo.

Sin SMTP configurado, la aplicación indica al usuario que contacte con el centro para recuperar el acceso; no afirma haber enviado un correo. No hay un servicio de correo externo incluido en el proyecto.

### Copias y reversión

Conservar copias de la base de datos nueva y de `campus-cec/shared/` (incluida su clave privada `.env`), además del WordPress y su base de datos independientes. Los historiales de código de GitHub no sustituyen esas copias.

Para volver al sitio anterior, restaurar el destino de `campus.cecalicante.es` a `/CampusCEC`. No elimina el LMS ni sus datos. Las versiones anteriores del código quedan en `campus-cec/releases/`; no se eliminan automáticamente. Revisar compatibilidad de migraciones antes de revertir `current`; no ejecutar retrocesos de base de datos en producción sin copia y revisión.

## Alcance pendiente de verificación operativa

El despliegue automatizado verifica PHP, migraciones, vistas y conexión. Tras cambiar el destino hacen falta pruebas HTTP reales de login, subida y descarga (incluida la configuración de límites de IONOS), correo SMTP y vídeo de YouTube. La velocidad depende del plan y la concurrencia; no se han prometido tiempos ni capacidad simultánea sin medirlos.

## Experiencia de aprendizaje y certificados

La entrada de cada actividad muestra preguntas, intentos disponibles y nota objetivo antes de comenzar. Visitar esta página no consume intentos; «Empezar» crea uno y «Continuar» reanuda el que está abierto. La ordenación usa SortableJS 1.15.6 (MIT, distribuido localmente con su licencia), con tarjeta flotante, hueco de destino, desplazamiento automático y alternativa accesible por flechas. La corrección distingue verde/acierto, rojo/error y ámbar/solución, además de texto e iconos.

El progreso del curso suma lecciones completadas y tests evaluables publicados superados. Las prácticas no bloquean el certificado. «Continuar» lleva a la siguiente lección pendiente o al test pendiente. Los filtros distinguen cursos en curso y completados, y existe una sección de certificados obtenidos.

Los profesores pueden habilitar o deshabilitar certificados y añadir una duración opcional al curso. El certificado de finalización de CEC se emite al alumno matriculado después de completar todas las lecciones y superar todos los tests evaluables publicados. No se emite en cursos vacíos; un curso compuesto solo por tests puede completarse al superarlos. Se genera un PDF real mediante Dompdf; guarda nombre, título, idioma, nivel, duración y nota (cuando correspondan) como una instantánea. Un certificado ya obtenido no cambia al editar después el curso y sigue disponible al titular si se retira su matrícula. No es una acreditación de Coursera ni de otra entidad. Descarga y vista previa requieren autorización; no hay una página pública con datos personales de alumnos.

La cabecera permite alternar la interfaz entre español e inglés y conserva la preferencia en la cuenta. Las lecciones, preguntas, respuestas y avisos escritos por docentes no se traducen ni se envían a servicios externos. El certificado institucional se emite en español.

Los avisos y recordatorios se publican desde la gestión del curso. Pueden aparecer inmediatamente o a partir de la fecha indicada. Son notificaciones dentro del campus con contador de no leídas, lectura individual o conjunta; no son correos ni notificaciones push del dispositivo. Solo se muestran a los usuarios autorizados de cada curso. Los avisos programados se incorporan al consultar la página o el contador al llegar su fecha; no requieren un cron. La campana actualiza su contador aproximadamente cada minuto mientras la pestaña está visible.

La migración `2026_11_14_000001_add_progress_certificates_and_notices` añade estas tablas y campos sin borrar cursos, usuarios, actividades ni intentos existentes. La raíz web es una carpeta estable; un cargador consulta el enlace de la versión para evitar que PHP sirva código anterior por la caché de rutas del alojamiento.

## Índice del curso y menú personal

Dentro del curso, las lecciones, actividades, intentos y materiales conservan un índice lateral por unidades. Identifica vídeo, lectura, práctica y test, resalta el elemento actual y marca las lecciones completadas y los tests superados. Puede ocultarse sin perder las respuestas; en móvil se abre como un panel. Las actividades reutilizadas conservan la unidad desde la que se accedió.

El menú del avatar enlaza a aprendizaje, cursos y bonos, materiales, certificados y avisos. «Mis cursos y bonos» consulta los cursos a los que la cuenta tiene acceso realmente. La conexión de bonos de sesiones y cursos con Gestión se describe en [conexión con el ERP](docs/ERP-integration.md).

## Conexión con Gestión CEC

Los cursos, las matrículas y los bonos de sesiones de Gestión se comparten con el campus mediante un origen privado en IONOS. Al entrar o recargar, la cuenta con correo único coincidente y confirmado recibe sus cursos activos y puede consultar clases iniciales, utilizadas, restantes y caducidad de sus bonos. Las lecciones, los archivos, los ejercicios y el progreso permanecen en el campus.

La primera conexión se realiza abriendo Gestión como administrador desde el navegador que conserva los datos actuales. Después se usa el servidor como referencia, con control de concurrencia y copias de seguridad. Sin SMTP, administración confirma la titularidad del correo desde Usuarios y permisos. Véanse [la configuración, permisos y pruebas de la integración](docs/ERP-integration.md).

## Materiales privados por bono y presentaciones

Gestión permite asignar un profesor del campus a cada bono. El profesor abre «Bonos a mi cargo» y publica archivos privados (hasta 20 MB), textos o vídeos de YouTube para el alumno. Puede guardar borradores, publicar, ocultar y eliminar. La lectura y descarga comprueban la asignación vigente y la identidad del alumno; cambiar el profesor retira el acceso al anterior. Los contenidos ya publicados se conservan para el alumno y el nuevo responsable. Se necesita una cuenta del campus con rol Profesorado para aparecer en el selector de Gestión.

El editor de lección ofrece «Texto y vídeo» o «Presentación interactiva». Cada presentación admite hasta 40 diapositivas y 15 bloques por diapositiva: texto, imagen JPG/PNG/WebP (hasta 5 MB), YouTube o una actividad publicada del mismo curso. Se pueden añadir, eliminar y reordenar diapositivas y bloques. Las imágenes se guardan como recursos privados del curso y siguen en su biblioteca si se retiran de la presentación.

El alumno navega con Anterior/Siguiente y una barra de progreso de la presentación. Las actividades se realizan dentro de la diapositiva, con los intentos, permisos y corrección habituales; abrir una diapositiva no consume intentos. Completar la lección conserva el funcionamiento manual existente y los tests evaluables siguen contando para completar el curso. Los vídeos permanecen alojados en YouTube.

La marca usa los SVG proporcionados por CEC, con el encuadre ajustado para el menú y las pantallas pequeñas. Los logos y favicons eligen la variante clara u oscura según `prefers-color-scheme` del navegador. No se ha activado una indexación pública de contenidos privados del campus.

## Eliminar y restaurar cursos y actividades

La gestión de cada curso incluye «Eliminar curso». El banco de actividades y su editor incluyen «Eliminar actividad». Las operaciones solicitan confirmación y llevan el elemento a «Papelera», accesible desde el menú personal, el campus docente y el banco. Profesorado solo puede eliminar y restaurar elementos de sus cursos; administración puede hacerlo con todos.

La eliminación retira el acceso, las actividades disponibles y los avisos correspondientes, conservando archivos, matrículas, lecciones, intentos y certificados para restaurar. Los resultados ya enviados de una actividad eliminada siguen disponibles mientras su curso siga activo; no se pueden continuar intentos abiertos de esa actividad hasta restaurarla. Los certificados obtenidos siguen disponibles para su titular aunque se elimine el curso. No hay un borrado definitivo ni una purga automática de datos.

Eliminar en el campus un curso procedente de Gestión no borra el curso ni sus datos económicos del ERP. La sincronización conserva el registro eliminado y no lo recrea. Al restaurarlo conserva su identidad y la disponibilidad actual del origen. Si se elimina también el curso de una actividad, primero se restaura el curso.

Las cuentas nuevas de alumno ven un estado de inscripción en revisión, sin instrucciones internas de Gestión ni un botón de correo que no envía nada. Los avisos operativos y la revisión de identidades permanecen en administración.

### Cabecera de las tarjetas de curso

En Crear/Gestionar curso, «Diseño de la cabecera» permite ajustar fondo, color y opacidad de la imagen, texto, etiqueta, tipografía, tamaño y alineación con vista previa. Las imágenes JPG, PNG o WebP se recortan en el navegador (arrastre, zoom o flechas) y se guardan al guardar el curso. El archivo recortado permanece privado y solo se sirve a usuarios con acceso al curso. El profesor responsable y administración pueden editar el diseño, también en cursos sincronizados con el ERP sin alterar sus matrículas.

### Audios y clases online

Lecciones y actividades incluyen un selector/subida de audio privado (MP3, WAV, OGG, M4A, AAC, WebM; 20 MB). Una presentación también admite bloques Audio, junto a imágenes y ejercicios. El audio seleccionado se conserva en el intento aunque después se edite la actividad; eliminar el archivo lo retira de todos sus usos.

La casilla «Habilitar clases online para este curso» activa una sala solo para sus matriculados y profesor responsable. En Gestión, los tipos de bono tienen modalidad online explícita; administración puede cambiarla para bonos emitidos desde «Profesor y clase online». Se exige bono vigente, alumno activo y sesiones restantes. Profesorado inicia/finaliza la sesión; los participantes pueden probar dispositivos, elegir cámara/micrófono (altavoces si el navegador lo permite), silenciar, apagar vídeo, consultar latencia, chatear y dibujar en una pizarra compartida. Tras finalizar, alumnos que se conectaron valoran 1–5 estrellas y dejan un comentario privado para el centro/profesor. No se graba audio/vídeo.

Arquitectura: WebRTC directo, señalización y chat/pizarra privados por HTTPS en Laravel, compatible con hosting PHP compartido sin proceso WebSocket. Máximo inicial **6 participantes por sala**, incluidas las cuentas docentes; sesiones expiran a las 6 horas. No es una solución para grupos grandes: para ampliar se necesita un servidor de medios SFU. Un cambio de matrícula, profesor, modalidad o estado del bono se comprueba también en cada petición de la sala. El chat y la pizarra viajan por sondeo (~1,5 s), no por la conexión de vídeo.

**Redes restringidas:** el STUN predeterminado permite intentar una conexión directa, pero no garantiza que atraviese todos los cortafuegos/NAT. Para uso fiable entre redes distintas se debe contratar/configurar TURN; este repositorio no incluye ni contrata ese servicio. El secreto opcional de GitHub `CEC_RTC_ICE_SERVERS` acepta el array JSON estándar RTCIceServer (STUN/TURN, urls, username, credential) y se escribe en el entorno privado de IONOS durante el despliegue. Usa credenciales limitadas del proveedor TURN; los parámetros ICE se entregan exclusivamente a participantes autorizados. Sin TURN, la sala muestra el fallo de vídeo y mantiene el chat y la pizarra disponibles. «Comprobar conexión» mide respuesta HTTP del campus y RTT WebRTC cuando existe; no mide la contraseña, señal o velocidad del Wi-Fi.

Validación de esta versión: pruebas PHP de autorización, aislamiento de señales, curso/bono online, revocación, caducidad, aforo, audio privado y valoración; navegador con dos cuentas para señalización offer/answer, chat, pizarra, controles locales, cierre y estrellas; audio WAV reproducible y diapositivas. El entorno local bloquea ICE directo por la política Chromium `WebRtcIPHandling=disable_non_proxied_udp`. La prueba de CI en GitHub exige vídeo remoto reproducible y recepción efectiva de paquetes de audio entre dos navegadores, además del chat, pizarra y valoraciones. Esto no sustituye la prueba desde redes reales ni la configuración TURN para redes restrictivas.

El aula da prioridad a cámaras 4:3 con encuadre de cámara a 1280×960 ideal, elimina la navegación lateral durante la clase y recoge la preparación al entrar. Ajustes permite cambiar dispositivos sin abandonar la sala. Compartir pantalla utiliza el selector nativo de `getDisplayMedia` (pestaña, ventana o escritorio, según navegador) y una pista WebRTC independiente para mantener la cámara activa. Se comparte la imagen de la pantalla; el sonido sigue procediendo del micrófono. En móviles/navegadores sin captura de pantalla, el botón queda deshabilitado. Las pestañas separan pizarra (paleta y ampliación), temario compartido y chat. Los apuntes se guardan con versión y bloqueo de fila para evitar sobrescrituras, y se conservan al finalizar la sesión.

Las reservas online de bonos se programan en Gestión, Horarios o «Programar clases» del bono. Se exportan sin notas privadas, con actualización de la previsualización cada 15 segundos y al recuperar foco. La cuenta atrás usa Europe/Madrid y el servidor valida la entrada desde 15 minutos antes hasta 15 minutos después del final. Cambiar/cancelar una reserva invalida sus salas activas anteriores. Los cursos del campus sin reserva ERP conservan su acceso docente habitual. El flujo del aula separa la previsualización de próxima clase, la comprobación oscura de dispositivos y el aula inmersiva con vídeo remoto principal, vídeo propio pequeño y herramientas bajo demanda.

### Comprar clases

La cabecera enlaza un catálogo con bonos activos de Gestión y cursos propios del campus publicados para venta, con precios en céntimos y pago único. El catálogo se refresca cada 15 segundos/al recuperar foco y se recalcula en el servidor al iniciar el pago. Stripe Checkout muestra los métodos activados por la cuenta del centro. La verificación del cobro y el registro idempotente de producto/recibo en Gestión se ejecutan exclusivamente en el servidor. Las compras requieren una cuenta de alumno vinculada; administración puede consultar y reintentar pedidos pagados pendientes de activación.

**Los cobros reales permanecen deshabilitados hasta configurar las credenciales y métodos de Stripe.** Guía completa de activación, webhooks, condiciones y recuperación: [docs/PAGOS-ONLINE.md](docs/PAGOS-ONLINE.md).

Los bonos de Comprar clases incluyen una consulta mensual de disponibilidad por materia y profesor. Administración configura las horas semanales, materias y modalidad en Gestión → Horarios → Disponibilidad del profesorado, añade bloqueos por fecha y vincula los horarios de grupo ocupados. Las reservas privadas también se descuentan automáticamente. El campus muestra únicamente franjas libres donde cabe una sesión de la duración del bono, dentro de su vigencia desde la compra, sin exponer datos de otros alumnos. La consulta se actualiza cada 15 segundos y no reserva una plaza al pagar: las fechas se confirman desde Gestión según el acuerdo con el alumno. Los tipos de bono pueden limitar materias y configurar la duración de cada sesión.
