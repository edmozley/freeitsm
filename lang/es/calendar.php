<?php
/**
 * Español (es) — Calendar module strings.
 * Missing keys fall back to lang/en/calendar.php per-key.
 */
return [
    'title' => 'Calendario',

    'nav' => [
        'calendar' => 'Calendario',
        'table'    => 'Tabla',
        'settings' => 'Ajustes',
        'help'     => 'Ayuda',
    ],

    'sidebar' => [
        'new_event'   => 'Nuevo evento',
        'categories'  => 'Categorías',
        'none'        => 'No se encontraron categorías',
    ],

    'event' => [
        'modal_new'      => 'Nuevo evento',
        'modal_edit'     => 'Editar evento',
        'title'          => 'Título',
        'title_ph'       => 'Título del evento...',
        'category'       => 'Categoría',
        'category_none'  => '-- Seleccionar categoría --',
        'start_date'     => 'Fecha de inicio',
        'start_time'     => 'Hora de inicio',
        'end_date'       => 'Fecha de fin',
        'end_time'       => 'Hora de fin',
        'all_day'        => 'Evento de todo el día',
        'location'       => 'Ubicación',
        'location_ph'    => 'Ubicación (opcional)',
        'description'    => 'Descripción',
        'description_ph' => 'Descripción (opcional)',
        'delete'         => 'Eliminar',
        'cancel'         => 'Cancelar',
        'save'           => 'Guardar',
        'edit'           => 'Editar',
        'delete_confirm' => '¿Seguro que quieres eliminar este evento?',
        'title_required' => 'Introduce un título para el evento',
        'start_required' => 'Selecciona una fecha de inicio',
    ],

    'table' => [
        'start_required' => 'La fecha y hora de inicio son obligatorias',
        'save_failed'    => 'No se pudo guardar',
        'col_title'       => 'Título',
        'col_category'    => 'Categoría',
        'col_start'       => 'Inicio',
        'col_end'         => 'Fin',
        'col_all_day'     => 'Todo el día',
        'col_location'    => 'Ubicación',
        'col_description' => 'Descripción',
        'col_created_by'  => 'Creado por',
        'col_created'     => 'Creado',
    ],

    'settings' => [
        'title'           => 'Ajustes del calendario',
        'tab_categories'  => 'Categorías',
        'heading'         => 'Categorías de eventos',
        'add'             => 'Añadir',
        'intro'           => 'Gestiona las categorías que se usan para organizar los eventos del calendario. Cada categoría puede tener un color personalizado para identificarla fácilmente.',
        'col_name'        => 'Nombre',
        'col_description' => 'Descripción',
        'col_status'      => 'Estado',
        'active'          => 'Activa',
        'inactive'        => 'Inactiva',
        'edit'            => 'Editar',
        'delete'          => 'Eliminar',
        'empty'           => 'Aún no hay categorías. Haz clic en <strong>Añadir</strong> para crear una.',
        'load_error'      => 'Error al cargar las categorías',

        'modal_add'       => 'Añadir categoría',
        'modal_edit'      => 'Editar categoría',
        'modal_name'      => 'Nombre',
        'modal_name_ph'   => 'p. ej. Caducidad de certificado',
        'modal_description'    => 'Descripción',
        'modal_description_ph' => 'Descripción opcional...',
        'modal_colour'    => 'Color',
        'modal_active'    => 'Activa',
        'cancel'          => 'Cancelar',
        'save'            => 'Guardar',
        'name_required'   => 'Introduce un nombre para la categoría',

        'delete_title'    => 'Eliminar categoría',
        'delete_confirm'  => '¿Seguro que quieres eliminar "{name}"? Esta acción no se puede deshacer.',
        'delete_this'     => 'esta categoría',
    ],

    'toast' => [
        'saved'         => 'Guardado',
        'deleted'       => 'Eliminado',
        'save_failed'   => 'No se pudo guardar',
        'delete_failed' => 'No se pudo eliminar',
    ],

    'help' => [
        'page_title'  => 'Guía del calendario',
        'guide'       => 'Guía',
        'hero_title'  => 'Guía del calendario',
        'hero_sub'    => 'Controla certificados, contratos, ventanas de mantenimiento y eventos recurrentes &mdash; todo en un solo lugar.',

        'nav_overview'  => 'Resumen',
        'nav_views'     => 'Vistas del calendario',
        'nav_creating'  => 'Crear eventos',
        'nav_categories'=> 'Categorías de eventos',
        'nav_settings'  => 'Ajustes',
        'nav_tips'      => 'Consejos rápidos',

        // Section 1 — Overview
        'overview_heading' => 'Resumen',
        'overview_intro'   => 'El módulo de Calendario ofrece a tu equipo de TI una línea de tiempo compartida para todo lo que importa. En lugar de depender de hojas de cálculo o recordatorios personales, puedes controlar las fechas de caducidad de los certificados, las renovaciones de contratos, las ventanas de mantenimiento programado y los eventos del equipo en un único calendario codificado por colores que todo el centro de servicio puede ver.',
        'feature_tracking_title' => 'Seguimiento de eventos',
        'feature_tracking_desc'  => 'Crea eventos con títulos, fechas, horas, ubicaciones y descripciones. Cada evento es visible para el equipo, de modo que nada se pasa por alto.',
        'feature_views_title'    => 'Múltiples vistas',
        'feature_views_desc'     => 'Cambia entre las vistas de mes, semana y día para obtener el nivel de detalle que necesites. La vista de mes ofrece una visión general; las vistas de semana y día muestran franjas horarias precisas.',
        'feature_categories_title' => 'Categorías',
        'feature_categories_desc'  => 'Organiza los eventos en categorías codificadas por colores, como certificados, contratos, mantenimiento y reuniones. Filtra el calendario para mostrar solo lo que te interesa.',
        'feature_scheduling_title' => 'Programación',
        'feature_scheduling_desc'  => 'Planifica ventanas de mantenimiento, crea eventos de todo el día para los plazos límite y programa trabajos recurrentes. El calendario ayuda a tu equipo a coordinarse y evitar conflictos.',

        // Section 2 — Views
        'views_heading' => 'Vistas del calendario',
        'views_intro'   => 'El calendario ofrece tres vistas para que puedas acercar o alejar según lo que necesites. Cambia entre ellas con los botones de alternancia situados en la esquina superior derecha de la cabecera del calendario.',
        'views_month_title' => 'Vista de mes',
        'views_month_desc'  => 'La vista predeterminada. Muestra una cuadrícula de un mes completo con los eventos representados como barras de colores en cada día. Ideal para obtener una visión general de lo que se avecina en todo el equipo.',
        'views_week_title'  => 'Vista de semana',
        'views_week_desc'   => 'Muestra siete días con franjas horarias por hora. Los eventos se sitúan según sus horas de inicio y fin, lo que facilita detectar conflictos de programación.',
        'views_day_title'   => 'Vista de día',
        'views_day_desc'    => 'Se centra en un único día con un desglose detallado por horas. Úsala cuando necesites ver exactamente qué ocurre hora a hora durante un día ajetreado.',
        'views_nav'         => 'Usa las flechas de navegación junto al título de mes/semana/día para avanzar y retroceder en el tiempo. El botón <strong>Hoy</strong> te lleva directamente de vuelta a la fecha actual, sin importar cuánto hayas navegado.',
        'views_flow_today'  => 'Botón Hoy',
        'views_flow_nav'    => 'Navegar ant./sig.',
        'views_flow_choose' => 'Elegir vista',
        'views_flow_click'  => 'Hacer clic en el evento',
        'views_tip'         => 'Haz clic en cualquier evento del calendario para abrir una ventana de vista rápida que muestra el título, la hora, la ubicación y la descripción. Desde ahí puedes abrir el formulario de edición completo.',

        // Section 3 — Creating events
        'creating_heading' => 'Crear eventos',
        'creating_intro'   => 'Añadir eventos al calendario es sencillo. Haz clic en el botón <strong>+ Nuevo evento</strong> de la barra lateral para abrir el formulario de evento. Rellena los detalles y guarda &mdash; el evento aparece en el calendario al instante.',
        'creating_step1'   => '<strong>Haz clic en + Nuevo evento</strong> &mdash; el botón está en la barra lateral del calendario, a la izquierda. Esto abre la ventana de creación de eventos.',
        'creating_step2'   => '<strong>Introduce un título</strong> &mdash; da al evento un nombre claro y descriptivo. Por ejemplo: "Renovación del certificado SSL &mdash; webserver01" o "Ventana de parcheo mensual".',
        'creating_step3'   => '<strong>Elige una categoría</strong> &mdash; selecciónala en el desplegable para codificar el evento por color. Las categorías se configuran en Ajustes y te ayudan a filtrar el calendario más adelante.',
        'creating_step4'   => '<strong>Establece las fechas y horas</strong> &mdash; elige una fecha de inicio y, opcionalmente, una fecha de fin. Añade horas de inicio y fin para los eventos con horario, o marca "Evento de todo el día" para plazos límite y entradas de día completo.',
        'creating_step5'   => '<strong>Añade ubicación y descripción</strong> &mdash; opcionalmente indica dónde tiene lugar el evento y añade notas. Estos detalles se muestran en la ventana de vista rápida cuando alguien hace clic en el evento.',
        'creating_step6'   => '<strong>Guarda</strong> &mdash; haz clic en Guardar y el evento se crea. Aparece en el calendario de inmediato, codificado por el color de su categoría.',
        'creating_tip'     => 'Para editar un evento existente, haz clic en él en el calendario para abrir la ventana emergente y luego haz clic en <strong>Editar</strong>. Se abre el mismo formulario con los detalles actuales del evento ya rellenados. También puedes eliminar eventos desde el formulario de edición.',

        // Section 4 — Categories
        'categories_heading' => 'Categorías de eventos',
        'categories_intro'   => 'Las categorías son la columna vertebral de la organización del calendario. Cada categoría tiene un nombre y un color, de modo que los eventos se reconocen al instante de un vistazo. La barra lateral muestra todas las categorías disponibles con casillas de verificación &mdash; desmarca una categoría para ocultar esos eventos del calendario.',
        'categories_certificates' => '<strong>Certificados</strong> &mdash; controla las fechas de caducidad de los certificados SSL/TLS, los certificados de firma de código y otras credenciales que requieren renovación periódica',
        'categories_contracts'    => '<strong>Contratos</strong> &mdash; registra las fechas de renovación de contratos con proveedores, la caducidad de licencias y los hitos de revisión de SLA para que nada caduque inesperadamente',
        'categories_maintenance'  => '<strong>Mantenimiento</strong> &mdash; programa ventanas de mantenimiento planificado para servidores, equipos de red e infraestructura. Tu equipo y las partes interesadas pueden ver exactamente cuándo se espera una interrupción',
        'categories_meetings'     => '<strong>Reuniones</strong> &mdash; registra las reuniones diarias del equipo, las reuniones del CAB, las llamadas con proveedores y otras citas recurrentes relevantes para las operaciones de TI',
        'categories_custom'       => '<strong>Categorías personalizadas</strong> &mdash; añade tus propias categorías en Ajustes para adaptarlas al flujo de trabajo de tu equipo. Añadidos habituales son "Despliegues", "Auditorías" y "Formación"',
        'categories_filtering'    => 'El filtrado se aplica en tiempo real. Cuando desmarcas una categoría en la barra lateral, los eventos de esa categoría se ocultan de inmediato sin recargar la página. Vuelve a marcarla para mostrarlos de nuevo.',
        'categories_tip'          => 'La codificación por colores funciona en las tres vistas. En la vista de mes, los eventos se muestran como barras de colores. En las vistas de semana y día, los eventos se muestran como bloques de colores situados en la hora correcta.',

        // Section 5 — Settings
        'settings_heading' => 'Ajustes',
        'settings_intro'   => 'La página de Ajustes te permite configurar cómo funciona el calendario para tu equipo. Accede a ella haciendo clic en <strong>Ajustes</strong> en la barra de navegación de la parte superior del módulo de calendario.',
        'settings_step1'   => '<strong>Gestiona las categorías</strong> &mdash; añade, edita o elimina categorías de eventos. Cada categoría tiene un nombre y un color. Los cambios surten efecto de inmediato en todo el calendario para todos los usuarios.',
        'settings_step2'   => '<strong>Establece los colores</strong> &mdash; elige un color para cada categoría con el selector de color. Elige colores distintos para que los eventos sean fáciles de diferenciar en un calendario ajetreado.',
        'settings_step3'   => '<strong>Renombra las categorías</strong> &mdash; haz clic en el nombre de una categoría para editarlo. Los eventos existentes asignados a esa categoría se actualizan automáticamente.',
        'settings_step4'   => '<strong>Elimina categorías</strong> &mdash; elimina las categorías que ya no necesites. Los eventos de una categoría eliminada no se borran &mdash; permanecen en el calendario sin una categoría asignada.',
        'settings_tip'     => 'Mantén tu lista de categorías centrada. Tener demasiadas categorías puede saturar la barra lateral y dificultar la lectura de la codificación por colores. Procura tener entre 5 y 10 categorías bien definidas que cubran las necesidades de tu equipo.',

        // Section 6 — Quick tips
        'tips_heading'        => 'Consejos rápidos',
        'tips_maintenance_title' => 'Ventanas de mantenimiento',
        'tips_maintenance_desc'  => 'Crea eventos de todo el día o bloques con horario para el mantenimiento planificado. Incluye los sistemas afectados en la descripción para que los analistas puedan comprobar rápidamente si se espera una interrupción.',
        'tips_certificates_title' => 'Renovaciones de certificados',
        'tips_certificates_desc'  => 'Añade eventos 30 días antes de que caduque cada certificado. Esto da a tu equipo suficiente margen para renovarlo sin arriesgarse a una interrupción por un certificado caducado.',
        'tips_contracts_title'   => 'Seguimiento de contratos',
        'tips_contracts_desc'    => 'Registra las fechas de renovación de contratos como eventos de todo el día. Añade el nombre del proveedor y el valor del contrato en la descripción para tener la información a mano cuando llegue el momento de negociar.',
        'tips_filters_title'     => 'Usa los filtros de categoría',
        'tips_filters_desc'      => 'Cuando el calendario se llena, desmarca las categorías que no necesites. Por ejemplo, oculta las reuniones cuando solo te interesen las próximas ventanas de mantenimiento.',
    ],
];
