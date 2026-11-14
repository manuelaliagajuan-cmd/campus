# Conexión con Gestión CEC

El ERP es la fuente de los bonos de sesiones (por ejemplo 5, 8 o 12); no son suscripciones del campus. La revisión del código del ERP muestra que los datos académicos se conservan actualmente en el almacenamiento local del navegador. El acceso al repositorio no proporciona los saldos de los alumnos.

El catálogo de bonos define sesiones, vigencia y precio. Cada bono emitido conserva sus propias condiciones, alumno, fechas y registros de consumo. El saldo descuenta solo consumos no anulados. Las reservas pendientes no equivalen a sesiones consumidas. El estado distingue agotado, pendiente de inicio, caducado y activo.

Para mostrar saldos reales se necesita persistencia centralizada en el ERP y una API autenticada de consulta, o un mecanismo explícito de sincronización. Debe vincularse cada cuenta con el identificador del alumno en Gestión; el correo por sí solo no basta, pues puede pertenecer a un familiar o compartirse. La API debe limitar los datos al alumno autorizado y conservar fechas, anulaciones y vigencia.

Hasta que exista esa conexión, «Mis cursos y bonos» muestra cursos autorizados y materiales del campus e indica que el saldo no está disponible. No inventa bonos, importes ni saldos cero. Esta implementación no modifica el ERP ni copia sus datos de alumnos.
