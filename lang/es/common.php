<?php
/**
 * Español (es) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    'save'         => 'Guardar',
    'cancel'       => 'Cancelar',
    'delete'       => 'Eliminar',
    'add'          => 'Añadir',
    'edit'         => 'Editar',
    'close'        => 'Cerrar',
    'copy'         => 'Copiar',
    'copied'       => 'Copiado',
    'retry'        => 'Reintentar',
    'export'       => 'Exportar',
    'open'         => 'Abrir',
    'apply'        => 'Aplicar',

    'yes'          => 'Sí',
    'no'           => 'No',
    'ok'           => 'OK',
    'loading'      => 'Cargando…',
    'saving'       => 'Guardando…',
    'saved'        => 'Guardado',
    'unsaved'      => 'Sin guardar',
    'unsaved_changes' => 'Cambios sin guardar',
    'failed'       => 'Fallido',

    'just_now'     => 'ahora mismo',
    'today'        => 'Hoy',
    'yesterday'    => 'Ayer',

    'required'     => 'Obligatorio',
    'optional'     => 'Opcional',
    'select_one'   => 'Seleccionar…',
    'search'       => 'Buscar',

    'error_generic'       => 'Algo salió mal.',
    'error_network'       => 'Error de red',
    'error_not_logged_in' => 'Debe iniciar sesión.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'Mesa de servicio',
        'browser_title'    => 'Mesa de servicio - ITSM',
        'welcome_heading'  => '¿Qué desea hacer?',
        'welcome_subtitle' => 'Seleccione un módulo para empezar',
        'footer'           => 'Mesa de servicio ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'Módulos ITSM',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'Vigía',         'description' => 'Panel unificado de atención para todos los módulos'],
        'tickets'        => ['name' => 'Tickets',       'description' => 'Gestione solicitudes de soporte, correos e incidencias de usuarios'],
        'assets'         => ['name' => 'Activos',       'description' => 'Realice seguimiento de activos de TI y asignaciones a usuarios'],
        'knowledge'      => ['name' => 'Conocimiento',  'description' => 'Cree y consulte artículos de la base de conocimiento'],
        'changes'        => ['name' => 'Cambios',       'description' => 'Planifique, controle y gestione cambios de TI'],
        'calendar'       => ['name' => 'Calendario',    'description' => 'Realice seguimiento de eventos, plazos y agendas'],
        'morning-checks' => ['name' => 'Comprobaciones','description' => 'Registre comprobaciones diarias de infraestructura'],
        'reporting'      => ['name' => 'Informes',      'description' => 'Consulte registros del sistema y analíticas'],
        'software'       => ['name' => 'Software',      'description' => 'Consulte el inventario de software y licencias'],
        'forms'          => ['name' => 'Formularios',   'description' => 'Diseñe formularios personalizados y vea envíos'],
        'contracts'      => ['name' => 'Contratos',     'description' => 'Gestione proveedores, contactos y contratos'],
        'service-status' => ['name' => 'Estado',        'description' => 'Supervise la salud de los servicios y registre incidentes'],
        'wiki'           => ['name' => 'Wiki',          'description' => 'Consulte la documentación del código generada automáticamente'],
        'lms'            => ['name' => 'LMS',           'description' => 'Sistema de gestión de aprendizaje con reproductor SCORM'],
        'process-mapper' => ['name' => 'Procesos',      'description' => 'Herramienta visual de diagramas de flujo y mapeo de procesos'],
        'tasks'          => ['name' => 'Tareas',        'description' => 'Tablero Kanban y vista de lista para seguir tareas'],
        'cmdb'           => ['name' => 'CMDB',          'description' => 'Base de datos de gestión de configuración'],
        'network-mapper' => ['name' => 'Red',           'description' => 'Diseñe y documente diagramas de red'],
        'system'         => ['name' => 'Sistema',       'description' => 'Administración y configuración del sistema'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'Comprobar correos nuevos',
        'change_password' => 'Cambiar contraseña',
        'mfa'             => 'Autenticación multifactor',
        'trusted_device'  => 'Dispositivo de confianza',
        'logout'          => 'Cerrar sesión',
        'logout_confirm'  => '¿Seguro que desea cerrar sesión?',
        'badge_off'       => 'Desactivado',
        'badge_on'        => 'Activado',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'Cambiar contraseña',
        'current_password' => 'Contraseña actual',
        'new_password'     => 'Nueva contraseña',
        'confirm_password' => 'Confirmar nueva contraseña',
        'submit'           => 'Cambiar contraseña',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'Autenticación multifactor',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'Anterior',
        'next'     => 'Siguiente',
        'today'    => 'Hoy',

        'months' => [
            'january'   => 'enero',
            'february'  => 'febrero',
            'march'     => 'marzo',
            'april'     => 'abril',
            'may'       => 'mayo',
            'june'      => 'junio',
            'july'      => 'julio',
            'august'    => 'agosto',
            'september' => 'septiembre',
            'october'   => 'octubre',
            'november'  => 'noviembre',
            'december'  => 'diciembre',
        ],

        'weekdays' => [
            'monday'    => 'lunes',
            'tuesday'   => 'martes',
            'wednesday' => 'miércoles',
            'thursday'  => 'jueves',
            'friday'    => 'viernes',
            'saturday'  => 'sábado',
            'sunday'    => 'domingo',
        ],
    ],
];
