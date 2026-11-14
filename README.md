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
