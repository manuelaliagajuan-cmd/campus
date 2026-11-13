# Campus CEC

LMS propio del Centro de Estudios Campoamor (Alicante), sin WordPress ni TutorLMS.

## Estado

Repositorio inicial de desarrollo. Este documento registra los requisitos; todavía no hay una aplicación ejecutable ni un campus desplegado. El dominio previsto es https://campus.cecalicante.es. No modificar la instalación existente ni sus datos hasta disponer de una copia de seguridad y un despliegue de sustitución validado.

## Base técnica propuesta

Laravel 12, PHP 8.2 o superior y MySQL. Interfaz propia con renderizado de servidor y JavaScript progresivo, sin Elementor. Confirmar compatibilidad del plan de IONOS antes de fijar la instalación y el procedimiento de despliegue.

El código de la aplicación, migraciones y pruebas se conservará en este repositorio. La base de datos, credenciales y archivos privados de alumnos no se guardarán en GitHub.

## Funciones del campus

- Administración de usuarios, roles, cursos y matrículas.
- Registro de estudiantes; las cuentas docentes las crea o autoriza administración.
- Inicio de sesión, recuperación de contraseña y permisos comprobados en servidor.
- Cursos, unidades y lecciones con progreso individual persistente.
- Biblioteca por curso con subida de archivos y visualización o descarga autorizada por matrícula. Almacenamiento fuera de la carpeta pública.
- Vídeos de YouTube incrustados mediante URL; el alojamiento del campus no almacena ni transmite esos vídeos. Un vídeo no listado de YouTube permite compartir su enlace y no constituye una protección por matrícula.
- Constructor web de actividades: catálogo visual, formulario por tipo, previsualización, guardado y asociación a una lección, sin escribir código.
- Tipos iniciales: selección múltiple, verdadero/falso, completar huecos, emparejamiento y ordenación.
- Tests con intentos, corrección en servidor, resultados y seguimiento docente. Las respuestas correctas no se entregan al navegador antes de enviar el intento.

## Identidad visual

Azul Campoamor #212A5E, blanco y negro. Titulares en mayúsculas, cortes a 45 grados y tramas discretas según el manual facilitado. Arial como sustitución mientras no estén disponibles Nexa y su licencia web. Incorporar el logotipo desde los archivos originales; no reconstruirlo a partir del PDF.

## Primera entrega funcional prevista

Registro e inicio de sesión, roles y autorizaciones, creación de cursos y lecciones, vídeo de YouTube, matrícula, un ejercicio de selección múltiple con corrección y persistencia, y seguimiento del intento. Ampliar sobre esa base con biblioteca y resto del catálogo. No considerar operativo un botón sin una acción persistente comprobada.

## Verificación antes de producción

Probar registro y recuperación por correo real; aislamiento de datos entre alumnos; acceso docente solo a cursos autorizados; matrícula y revocación; archivos privados; intentos y calificaciones; interfaz móvil; copias y restauración. Medir rendimiento y concurrencia en el plan real de IONOS antes de prometer tiempos de carga.

## Datos pendientes del alojamiento

- Nombre exacto del plan IONOS y versiones disponibles de PHP y MySQL.
- Disponibilidad de SSH o solo SFTP; posibilidad de usar Composer, tareas programadas y cambiar la raíz pública del dominio a `public/`.
- Acceso a SMTP mediante un canal de secretos, nunca en chat ni en commits.

El despliegue se preparará cuando se confirme el entorno. No se ha configurado GitHub Actions ni se han conectado credenciales de IONOS.
