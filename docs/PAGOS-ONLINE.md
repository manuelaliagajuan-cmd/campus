# Activar compras online del Campus CEC

La integración usa **Stripe Checkout alojado**. Las tarjetas no pasan por los servidores del campus. Tarjeta, Apple Pay, Google Pay, PayPal y Bizum se ofrecen según los métodos activados y aprobados en la cuenta de Stripe y el dispositivo del comprador. El campus utiliza los métodos dinámicos de Checkout; no anuncia métodos como operativos si la cuenta de pagos aún no está configurada.

## Configuración de producción

1. Completa el alta y verificación de la cuenta de empresa de Stripe en España.
2. Activa los medios deseados en **Configuración → Métodos de pago** de Stripe. PayPal puede requerir vincular su cuenta y Bizum su habilitación; Apple Pay y Google Pay dependen también del dispositivo/cartera del comprador.
3. En GitHub, repositorio `campus`, **Settings → Secrets and variables → Actions**, crea **STRIPE_SECRET_KEY** con la clave secreta de producción (`sk_live_…`). No la pegues en chats, archivos del repositorio o capturas.
4. En Stripe Workbench/Webhooks añade el destino `https://campus.cecalicante.es/pagos/stripe/webhook` con los eventos `checkout.session.completed` y `checkout.session.async_payment_succeeded`. Copia el secreto de firma del destino a GitHub como **STRIPE_WEBHOOK_SECRET** (`whsec_…`). No es la clave de API.
5. Ejecuta «Comprobar e instalar Campus CEC» en GitHub Actions para cargar los secretos en el entorno privado de IONOS. Gestión debe tener desplegado `online-purchases-core.php`; el campus lo encuentra en el directorio `gestion` del alojamiento compartido.
6. Verifica los métodos realmente ofrecidos por Checkout. Un pago confirmado debe aparecer en **Gestión → Compras online**, crear un único recibo cobrado y asignar el producto al alumno correcto. Una compra de curso digital no crea mensualidades ni remesas.

Antes de completar esta configuración, el catálogo es consultable y los botones de pago permanecen deshabilitados. No se aceptan pagos de prueba como cobros reales ni se asignan bonos a partir de una URL de éxito.

## Productos y compradores

- **Bonos:** precios, sesiones, validez, modalidad y disponibilidad proceden del catálogo activo de Gestión; al comprar se conserva una instantánea de las condiciones pagadas. La validez comienza el día de la compra en Europe/Madrid.
- **Cursos digitales:** el profesor responsable o administración fija un precio de pago único y marca «Ofrecer en Comprar clases». El curso debe estar publicado. Su contenido y precio se siguen gestionando en el campus.
- El alumno compra desde su cuenta vinculada a una ficha del centro. Las cuentas nuevas pendientes de revisión no pueden iniciar un cobro hasta que el centro confirme su acceso, evitando asignar pagos a fichas ajenas por coincidencia de correo.
- Gestión distingue **comprador de la cuenta**, **pagador comunicado por Stripe** y **alumno destinatario**. Conserva método, importe, pedido, referencia del pago y recibo. Los datos personales del pagador no se exportan al catálogo ni a otras cuentas del campus.

## Confirmación y recuperación

El servidor calcula los importes, verifica la firma del webhook, el importe/currency, la referencia del pedido y el pago real. La confirmación es idempotente tanto en la base del campus como en los archivos privados de Gestión. Una repetición no duplica bonos ni recibos. La vuelta del navegador consulta Stripe; no toma el parámetro de la URL como prueba de pago.

Si un pago está confirmado pero su activación falla, el pedido queda como «Pago recibido. Activación pendiente», conserva la referencia y puede reintentarse por el webhook o al abrir el pedido. Administración ve todos los pedidos en Comprar clases y puede revisar los pendientes sin pedir al alumno que vuelva a pagar. Si el alumno cambió de ficha o el curso dejó de estar disponible, hace falta una revisión del centro antes de activar el acceso.

Los reembolsos se tramitan en Stripe; registrar una devolución contable en Gestión no ejecuta un reembolso en la pasarela. No se borran pagos ni recibos al retirar un acceso. Las comisiones de la pasarela no se descuentan automáticamente del importe bruto del recibo.

Referencia de métodos: https://stripe.com/es/payment-method/bizum y https://stripe.com/payment-method/paypal .
