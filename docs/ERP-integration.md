# Gestión CEC y Campus CEC

Gestión es el origen de cursos, matrículas y bonos de sesiones. El campus conserva sus lecciones, vídeos, actividades, archivos, progreso y certificados. No se comparten contraseñas ni sesiones de acceso entre aplicaciones.

## Puesta en marcha

1. Abrir Gestión como administrador desde el navegador que contiene los datos actuales. La primera visita publica automáticamente esa copia en almacenamiento privado del servidor, sin borrar la copia local. Esperar a «Guardado · conectado con campus».
2. Comprobar que la ficha de cada alumno tiene su correo propio y que coincide con el de su cuenta del campus. El cotejo ignora espacios exteriores y mayúsculas. Los correos compartidos por varias fichas quedan pendientes de revisión.
3. Confirmar el correo una vez. Con SMTP configurado, el alumno solicita y abre un enlace firmado desde «Mis cursos y bonos». Sin SMTP, administración puede confirmar la titularidad en «Usuarios y permisos», marcando que la ha comprobado. Se conserva el administrador que confirmó la cuenta.
4. El alumno entra o recarga el campus: aparecen sus cursos activos y sus bonos. Los cursos nuevos están disponibles para añadir sus contenidos; el administrador puede asignar un profesor desde la gestión del curso.

No hay que volver a matricular manualmente a los alumnos del ERP. Si el alumno se registra después de su matrícula, se vincula al confirmar su correo. Los IDs del ERP mantienen la identidad al cambiar títulos; no se fusionan cursos existentes por coincidencia de nombre. Una cuenta ya vinculada no se transfiere automáticamente a otra ficha al cambiar correos. Para esa situación, hace falta revisión de administración.

## Guardado y sincronización

Gestión guarda los datos académicos completos en `../.cec-gestion-private/academic.php` mediante bloqueo, revisión incremental y sustitución atómica. Conserva cinco revisiones anteriores como copias rotatorias. Tras conectar, la copia del servidor es la referencia al abrir cualquier navegador; las copias locales anteriores y los cambios pendientes pueden descargarse en Configuración. Una pestaña antigua recibe un conflicto y no sobrescribe cambios más recientes. Un fallo de red se muestra como guardado sin confirmar; bloquea nuevas modificaciones hasta revisar y recargar, conservando la copia pendiente.

La primera copia debe proceder del navegador correcto. Si se han mantenido datos distintos en varios navegadores, no se fusionan automáticamente: conservar y revisar esas copias antes de incorporarlas al origen común. El límite de la solicitud es 32 MB, sujeto también a los límites PHP del alojamiento.

Gestión publica `campus.php` en la misma carpeta privada. Solo contiene IDs de cursos y alumnos, correo de vinculación, estado y matrículas activas y datos mínimos de los bonos. No incluye IBAN, recibos, documentos firmados, notas internas ni credenciales. Las dos aplicaciones del mismo espacio IONOS comparten este archivo privado; no necesitan una API pública ni una contraseña de integración. La proyección se reconstruye del origen si una publicación se interrumpe.

El despliegue del campus configura `CEC_ERP_PROJECTION_PATH` con la ruta absoluta del archivo. La lectura coordina el mismo bloqueo con Gestión. En cada petición autenticada, el campus aplica novedades y reconcilia las matrículas de esa cuenta. Para ver cambios en una página ya abierta hay que recargarla; no se interrumpe un test en curso.

## Accesos y saldos

Los cursos activos del ERP crean cursos del campus asociados por ID estable. Nombre y disponibilidad se gestionan en Gestión. El campus no borra contenidos al dar de baja una matrícula o archivar un curso. Las matrículas manuales independientes se conservan y pueden dar acceso aunque desaparezca una asignación del ERP. Una matrícula proveniente del ERP se modifica allí.

La vinculación automática requiere un único correo coincidente y confirmado y una ficha activa. Un duplicado, un origen inaccesible o una ficha de baja no muestra bonos ni mantiene permisos provenientes del ERP. No elimina el progreso ni los certificados obtenidos.

Cada bono emitido conserva sus propias condiciones. El saldo es sesiones iniciales menos consumos no anulados, con mínimo cero. Las reservas pendientes no descuentan clases. El estado distingue agotado, pendiente de inicio, caducado y activo, con fechas inclusivas y zona Europe/Madrid. Cambiar el catálogo no altera los bonos emitidos. Un bono caducado puede conservar saldo numérico, pero se identifica como caducado.

## Operación y pruebas

Incluir `academic.php`, las copias rotatorias y las cuentas privadas de Gestión en las copias de seguridad, además de la base de datos y el almacenamiento del campus. Los despliegues no sobrescriben esos archivos. Revisar restauraciones conjuntamente: una revisión del ERP inferior a la ya aplicada requiere revisión antes de reconectar.

Pruebas: la suite Laravel cubre vinculación, correo confirmado, duplicados, aislamiento, cursos nuevos, bajas, origen ausente, matrículas manuales, caducidades y confirmación de correo. Gestión prueba persistencia, autenticación, CSRF, concurrencia, proyección mínima, consumos anulados y recuperación. Su prueba de navegador comprueba la migración inicial, un guardado real de curso, conflictos entre pestañas y que una copia local antigua no sustituya el servidor.

## Revisión de cuentas desde Gestión

«Nuevos accesos» permite a administración de Gestión revisar las cuentas de estudiante y elegir la ficha que les corresponde. La lista se publica privadamente en `campus-accounts.php` al registrar una cuenta y se actualiza al acceder, confirmar el correo o cambiar su rol. `cec:publish-accesses` publica también las cuentas existentes al desplegar. Solo se incluyen ID, nombre, correo, alta, confirmación y vínculo; nunca contraseñas o tokens.

Gestión escribe la aprobación explícita en `campus-links.php` bajo el mismo bloqueo académico. Se conserva quién revisó la identidad, cuándo, el correo revisado y un ID de revisión. El campus consume esa aprobación al entrar o recargar, vincula la ficha y permite consultar sus cursos y bonos. Una revisión expresa permite resolver correos compartidos o distintos sin modificar las fichas originales. No permite ocupar la ficha de otra cuenta ni sustituir un vínculo existente sin revisión adicional. Los cambios de correo invalidan aprobaciones anteriores. Estas revisiones y su auditoría deben incluirse en las copias de seguridad.

`cec:erp-diagnostics` emite solo indicadores y recuentos de conexión y confirmación, sin nombres, correos ni saldos, para diagnosticar un acceso pendiente sin publicar datos personales.
