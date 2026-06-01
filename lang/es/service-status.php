<?php
/**
 * Español (es) — Service Status module strings.
 * Missing keys fall back to lang/en/service-status.php per-key.
 */
return [
    'title' => 'Estado del servicio',

    'nav' => [
        'status'   => 'Estado',
        'settings' => 'Configuración',
        'help'     => 'Ayuda',
    ],

    'board' => [
        'services'        => 'Servicios',
        'service_count'   => '{count} servicios',
        'loading'         => 'Cargando...',
        'no_services'     => 'No hay servicios configurados. Ve a Configuración para añadir servicios.',
        'incidents'       => 'Incidencias',
        'new'             => 'Nueva',
        'col_title'       => 'Título',
        'col_status'      => 'Estado',
        'col_affected'    => 'Servicios afectados',
        'col_updated'     => 'Actualizado',
        'no_incidents'    => 'No hay incidencias que mostrar.',
        'none'            => 'Ninguno',
    ],

    'modal' => [
        'new_incident'        => 'Nueva incidencia',
        'edit_incident'       => 'Editar incidencia',
        'title'               => 'Título',
        'title_placeholder'   => 'Descripción breve de la incidencia',
        'status'              => 'Estado',
        'comment'             => 'Comentario',
        'comment_placeholder' => 'Detalles sobre la incidencia...',
        'affected_services'   => 'Servicios afectados',
        'add_service'         => '+ Añadir servicio',
        'delete'              => 'Eliminar',
        'cancel'              => 'Cancelar',
        'save'                => 'Guardar',
    ],

    'toast' => [
        'incident_saved'   => 'Incidencia guardada',
        'incident_deleted' => 'Incidencia eliminada',
        'save_failed'      => 'Error al guardar',
        'delete_failed'    => 'Error al eliminar',
        'save_incident_failed'   => 'Error al guardar la incidencia',
        'delete_incident_failed' => 'Error al eliminar la incidencia',
        'saved'            => 'Guardado',
        'deleted'          => 'Eliminado',
        'save_service_failed'    => 'Error al guardar el servicio',
        'delete_service_failed'  => 'Error al eliminar el servicio',
    ],

    'confirm' => [
        'delete_incident_title'   => 'Eliminar incidencia',
        'delete_incident_message' => '¿Eliminar esta incidencia?',
        'delete_title'            => 'Eliminar',
        'delete_message'          => '¿Eliminar "{name}"?',
        'delete_label'            => 'Eliminar',
    ],

    'settings' => [
        'tab_services'     => 'Servicios',
        'tab_statuses'     => 'Estados',
        'tab_impacts'      => 'Niveles de impacto',

        'services_heading' => 'Servicios',
        'statuses_heading' => 'Estados de incidencia',
        'impacts_heading'  => 'Niveles de impacto',
        'add'              => 'Añadir',
        'loading'          => 'Cargando...',
        'no_services'      => 'Aún no hay servicios. Haz clic en Añadir para crear uno.',
        'no_items'         => 'No se encontraron elementos',
        'load_failed'      => 'Error al cargar los datos',
        'error_prefix'     => 'Error: {message}',

        'statuses_intro_html' => 'Estados de flujo de trabajo para las incidencias de servicio. Los estados marcados como <em>resueltos</em> cierran la incidencia, registrando automáticamente <code>resolved_datetime</code> y retirando la incidencia del panel activo. Exactamente un estado es el predeterminado para las nuevas incidencias.',
        'impacts_intro_html'  => 'Bandas de gravedad mostradas como insignia en cada tarjeta de servicio. El <strong>orden de gravedad</strong> determina la ordenación por «peor impacto actual» en el panel: cuanto menor, peor (1 = interrupción grave, 5 = operativo). Dos filas pueden compartir el mismo orden.',

        'col_name'        => 'Nombre',
        'col_description' => 'Descripción',
        'col_order'       => 'Orden',
        'col_status'      => 'Estado',
        'col_actions'     => 'Acciones',
        'col_colour'      => 'Color',
        'col_resolved'    => 'Resuelto',
        'col_default'     => 'Predeterminado',
        'col_severity'    => 'Gravedad',

        'active'          => 'Activo',
        'inactive'        => 'Inactivo',
        'yes'             => 'Sí',
        'no'              => 'No',
        'edit'            => 'Editar',
        'delete'          => 'Eliminar',

        'kind_status'     => 'estado',
        'kind_impact'     => 'nivel de impacto',

        // Service modal
        'add_service'     => 'Añadir servicio',
        'edit_service'    => 'Editar servicio',
        'field_name'      => 'Nombre',
        'field_description' => 'Descripción',
        'field_order'     => 'Orden de visualización',
        'field_active'    => 'Activo',

        // Lookup modal (statuses + impact levels)
        'add_item'        => 'Añadir elemento',
        'add_kind'        => 'Añadir {kind}',
        'edit_kind'       => 'Editar {kind}',
        'field_colour'    => 'Color',
        'field_resolved'  => 'Cuenta como resuelto',
        'resolved_help_html' => 'Las incidencias en este estado registran automáticamente <code>resolved_datetime</code> y desaparecen del panel activo.',
        'field_severity'  => 'Orden de gravedad',
        'severity_help'   => '1 = peor (interrupción grave). Mayor = menos grave.',
        'field_default'   => 'Predeterminado',

        'cancel'          => 'Cancelar',
        'save'            => 'Guardar',
    ],

    'help' => [
        'page_title' => 'Guía de estado del servicio',
        'guide'      => 'Guía',

        'nav_overview'  => 'Resumen',
        'nav_dashboard' => 'El panel de estado',
        'nav_services'  => 'Gestión de servicios',
        'nav_history'   => 'Historial de incidencias',
        'nav_settings'  => 'Configuración',
        'nav_tips'      => 'Consejos rápidos',

        'hero_title' => 'Guía de estado del servicio',
        'hero_sub'   => 'Supervisa tus servicios de TI, comunica las incidencias y mantén informadas a las partes interesadas en tiempo real.',

        // Section 1: Overview
        'overview_heading' => 'Resumen',
        'overview_intro'   => 'El módulo de Estado del servicio te ofrece una vista centralizada del estado de cada servicio de TI del que depende tu organización. Cuando algo falla, puedes registrar incidencias, actualizar los servicios afectados y mantener informados a los usuarios durante todo el proceso de resolución.',
        'feature_dashboard_title' => 'Panel de estado',
        'feature_dashboard_desc'  => 'Consulta de un vistazo el estado actual de cada servicio. Las insignias con código de color muestran si cada servicio está operativo, degradado, en mantenimiento o sufriendo una interrupción.',
        'feature_incident_title'  => 'Seguimiento de incidencias',
        'feature_incident_desc'   => 'Registra incidencias con títulos, actualizaciones de estado y comentarios. Vincula los servicios afectados a cada incidencia para que todos sepan exactamente qué está afectado y por qué.',
        'feature_management_title' => 'Gestión de servicios',
        'feature_management_desc'  => 'Configura tu catálogo de servicios en la configuración. Añade servicios con nombres, descripciones y orden de visualización. Activa o desactiva servicios a medida que evoluciona tu infraestructura.',
        'feature_comms_title' => 'Comunicación',
        'feature_comms_desc'  => 'Mantén informadas a las partes interesadas con actualizaciones de estado en tiempo real. Cada incidencia lleva un estado y un historial de comentarios para que los usuarios puedan seguir el progreso de la resolución sin tener que perseguir al servicio de soporte.',

        // Section 2: Dashboard
        'dashboard_heading' => 'El panel de estado',
        'dashboard_p1'      => 'El panel es lo primero que ves al abrir el módulo de Estado del servicio. Muestra una cuadrícula de tarjetas de servicio, cada una con el nombre del servicio, una breve descripción y una insignia de impacto con código de color que refleja su peor estado actual. Debajo de la cuadrícula se encuentra la tabla de incidencias que enumera todas las incidencias recientes y activas.',
        'dashboard_p2_html' => 'Cada tarjeta de servicio refleja automáticamente el nivel de impacto más grave que se le ha asignado desde cualquier incidencia activa (sin resolver). Cuando todas las incidencias que afectan a un servicio se resuelven, este vuelve a <strong>Operativo</strong>.',
        'status_levels'     => 'Niveles de estado',
        'level_operational_name' => 'Operativo',
        'level_operational_desc' => 'El servicio funciona con normalidad y sin problemas conocidos. Este es el estado predeterminado para todos los servicios en buen estado.',
        'level_degraded_name'    => 'Rendimiento degradado',
        'level_degraded_desc'    => 'El servicio está disponible pero funciona más lento de lo esperado o con funcionalidad reducida. Los usuarios pueden notar retrasos.',
        'level_maintenance_name' => 'En mantenimiento',
        'level_maintenance_desc' => 'Tiempo de inactividad o ventana de mantenimiento planificados. El servicio puede no estar disponible temporalmente mientras se realizan los trabajos.',
        'level_outage_name'      => 'Interrupción grave',
        'level_outage_desc'      => 'El servicio no está disponible en absoluto. Este es el estado más grave y debería desencadenar una investigación inmediata.',
        'dashboard_tip'     => 'Los niveles de impacto son jerárquicos. Si un servicio está vinculado a varias incidencias activas, el panel muestra el peor impacto. Por ejemplo, una incidencia que marca un servicio como Degradado y otra que lo marca como Interrupción grave dará como resultado que se muestre Interrupción grave.',

        // Section 3: Managing services
        'services_heading_html' => 'Gestión de servicios y registro de incidencias',
        'services_intro'        => 'Los servicios son los componentes básicos de tu página de estado. Cada uno representa un servicio de TI, sistema o componente de infraestructura del que dependen tus usuarios. Cuando algo falla, creas una incidencia y la vinculas a los servicios afectados.',
        'add_incident_heading'  => 'Añadir una nueva incidencia',
        'add_incident_step1_html' => '<strong>Haz clic en «Nueva»</strong> en el panel para abrir el formulario de incidencia.',
        'add_incident_step2_html' => '<strong>Introduce un título</strong> &mdash; una descripción breve y clara del problema. Por ejemplo: «Retrasos en la entrega de correo» o «Pasarela VPN inaccesible».',
        'add_incident_step3_html' => '<strong>Establece el estado</strong> &mdash; elige Investigando, Identificado, Terceros, Supervisando o Resuelto. Empieza con Investigando y actualiza a medida que sepas más.',
        'add_incident_step4_html' => '<strong>Añade un comentario</strong> &mdash; describe lo que se sabe hasta ahora, qué acciones se están tomando y cualquier solución alternativa disponible para los usuarios.',
        'add_incident_step5_html' => '<strong>Vincula los servicios afectados</strong> &mdash; añade uno o más servicios y elige el nivel de impacto para cada uno (Interrupción grave, Interrupción parcial, Degradado, Mantenimiento, Operativo o Sin interrupción).',
        'add_incident_step6_html' => '<strong>Guarda</strong> &mdash; la incidencia aparece en la tabla y las tarjetas de los servicios afectados se actualizan de inmediato en el panel.',
        'workflow_heading'  => 'Flujo de trabajo del estado de la incidencia',
        'workflow_investigating' => 'Investigando',
        'workflow_identified'    => 'Identificado',
        'workflow_monitoring'    => 'Supervisando',
        'workflow_resolved'      => 'Resuelto',
        'workflow_note_html'     => 'Usa <strong>Terceros</strong> cuando la causa raíz reside en un proveedor o suministrador externo.',
        'services_tip'      => 'Puedes editar cualquier incidencia haciendo clic en su título en la tabla. Actualiza el estado, añade nuevos comentarios o cambia los servicios afectados a medida que evoluciona la situación. Mantener las incidencias actualizadas es clave para una comunicación transparente.',

        // Section 4: Incident history
        'history_heading' => 'Historial de incidencias',
        'history_p1'      => 'La tabla de incidencias del panel muestra tanto las incidencias activas como las resueltas, ofreciéndote una cronología completa del estado del servicio. Cada fila muestra el título de la incidencia, el estado actual, los servicios afectados con sus niveles de impacto y la marca de tiempo de la última actualización.',
        'history_field_title_html'    => '<strong>Título</strong> &mdash; un enlace clicable que abre la incidencia para editarla. Usa títulos claros y descriptivos para que el historial sea fácil de revisar.',
        'history_field_status_html'   => '<strong>Estado</strong> &mdash; insignia con código de color que muestra la fase actual de investigación (Investigando, Identificado, Terceros, Supervisando o Resuelto).',
        'history_field_affected_html' => '<strong>Servicios afectados</strong> &mdash; insignias etiquetadas que muestran cada servicio vinculado con el color de su nivel de impacto. De un vistazo puedes ver qué está afectado y con qué gravedad.',
        'history_field_updated_html'  => '<strong>Actualizado</strong> &mdash; la marca de tiempo del cambio más reciente. Las incidencias resueltas se muestran con texto atenuado para que las incidencias activas destaquen visualmente.',
        'history_p2'      => 'Las incidencias resueltas permanecen visibles en la tabla como registro histórico. Esto facilita detectar problemas recurrentes, revisar cómo se gestionaron incidencias pasadas e identificar patrones que podrían apuntar a problemas subyacentes.',
        'history_tip'     => 'Revisar regularmente tu historial de incidencias te ayuda a identificar los servicios que se interrumpen con frecuencia. Si el mismo servicio aparece en varias incidencias, quizá sea el momento de investigar la causa raíz más a fondo o planificar una mejora de la infraestructura.',

        // Section 5: Settings
        'settings_heading' => 'Configuración',
        'settings_p1'      => 'La página de Configuración es donde creas y mantienes tu catálogo de servicios. Cada servicio que aparece en el panel de estado debe configurarse aquí primero.',
        'settings_step1_html' => '<strong>Añade un servicio</strong> &mdash; haz clic en «Añadir» y proporciona un nombre (p. ej. «Correo», «VPN», «Sistema ERP») y una descripción opcional que explique qué hace el servicio.',
        'settings_step2_html' => '<strong>Establece el orden de visualización</strong> &mdash; el número de orden controla dónde aparece el servicio en la cuadrícula del panel. Los números más bajos aparecen primero, así que coloca tus servicios más críticos en la parte superior.',
        'settings_step3_html' => '<strong>Alterna activo/inactivo</strong> &mdash; desactivar un servicio lo retira del panel sin eliminarlo. Esto resulta útil para servicios dados de baja o sistemas estacionales.',
        'settings_step4_html' => '<strong>Edita o elimina</strong> &mdash; usa los botones de acción de cada fila para actualizar los detalles de un servicio o eliminarlo por completo. Siempre es preferible editar antes que eliminar, para que los vínculos históricos de incidencias permanezcan intactos.',
        'settings_tip'     => 'Piensa en tu catálogo de servicios como la base de tu página de estado. Dedica tiempo a definir bien los nombres y las descripciones &mdash; es lo que verán tus usuarios y partes interesadas cuando comprueben el estado de tu entorno de TI.',

        // Section 6: Quick tips
        'tips_heading' => 'Consejos rápidos',
        'tip_communicate_title' => 'Comunica pronto',
        'tip_communicate_desc'  => 'Publica una incidencia en cuanto sepas que algo va mal, aunque aún no tengas todos los detalles. Reconocer un problema con rapidez genera confianza entre tus usuarios.',
        'tip_update_title' => 'Actualiza con frecuencia',
        'tip_update_desc'  => 'Las actualizaciones de estado periódicas &mdash; aunque nada haya cambiado &mdash; muestran a los usuarios que se está trabajando activamente en el problema. El silencio genera frustración y tickets de soporte.',
        'tip_review_title' => 'Revisa los patrones',
        'tip_review_desc'  => 'Comprueba tu historial de incidencias con regularidad. Si el mismo servicio sigue apareciendo, podría apuntar a un problema de infraestructura más profundo que vale la pena abordar de forma proactiva.',
        'tip_maintenance_title' => 'Planifica el mantenimiento',
        'tip_maintenance_desc'  => 'Usa el nivel de impacto Mantenimiento para los trabajos planificados. Crear una incidencia con antelación permite que los usuarios conozcan el tiempo de inactividad programado antes de que ocurra.',
    ],
];
