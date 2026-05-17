<?php
/**
 * Русский (ru) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    'save'         => 'Сохранить',
    'cancel'       => 'Отменить',
    'delete'       => 'Удалить',
    'add'          => 'Добавить',
    'edit'         => 'Изменить',
    'close'        => 'Закрыть',
    'copy'         => 'Копировать',
    'copied'       => 'Скопировано',
    'retry'        => 'Повторить',
    'export'       => 'Экспорт',
    'open'         => 'Открыть',
    'apply'        => 'Применить',

    'yes'          => 'Да',
    'no'           => 'Нет',
    'ok'           => 'OK',
    'loading'      => 'Загрузка…',
    'saving'       => 'Сохранение…',
    'saved'        => 'Сохранено',
    'unsaved'      => 'Не сохранено',
    'unsaved_changes' => 'Несохранённые изменения',
    'failed'       => 'Ошибка',

    'just_now'     => 'только что',
    'today'        => 'Сегодня',
    'yesterday'    => 'Вчера',

    'required'     => 'Обязательно',
    'optional'     => 'Необязательно',
    'select_one'   => 'Выбрать…',
    'search'       => 'Поиск',

    'error_generic'       => 'Что-то пошло не так.',
    'error_network'       => 'Ошибка сети',
    'error_not_logged_in' => 'Необходимо войти в систему.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'Служба поддержки',
        'browser_title'    => 'Служба поддержки - ITSM',
        'welcome_heading'  => 'Что вы хотите сделать?',
        'welcome_subtitle' => 'Выберите модуль, чтобы начать',
        'footer'           => 'Служба поддержки ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'Модули ITSM',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'Дозор',         'description' => 'Единая панель уведомлений по всем модулям'],
        'tickets'        => ['name' => 'Заявки',        'description' => 'Управление запросами в поддержку, письмами и проблемами пользователей'],
        'assets'         => ['name' => 'Активы',        'description' => 'Учёт ИТ-активов и назначений пользователям'],
        'knowledge'      => ['name' => 'База знаний',   'description' => 'Создание и просмотр статей базы знаний'],
        'changes'        => ['name' => 'Изменения',     'description' => 'Планирование, отслеживание и управление ИТ-изменениями'],
        'calendar'       => ['name' => 'Календарь',     'description' => 'Отслеживание событий, сроков и расписаний'],
        'morning-checks' => ['name' => 'Проверки',      'description' => 'Запись ежедневных проверок инфраструктуры'],
        'reporting'      => ['name' => 'Отчёты',        'description' => 'Просмотр системных журналов и аналитики'],
        'software'       => ['name' => 'ПО',            'description' => 'Просмотр инвентаря ПО и лицензий'],
        'forms'          => ['name' => 'Формы',         'description' => 'Создание форм и просмотр отправок'],
        'contracts'      => ['name' => 'Контракты',     'description' => 'Управление поставщиками, контактами и контрактами'],
        'service-status' => ['name' => 'Статус',        'description' => 'Мониторинг состояния сервисов и инцидентов'],
        'wiki'           => ['name' => 'Wiki',          'description' => 'Просмотр автоматически генерируемой документации кода'],
        'lms'            => ['name' => 'LMS',           'description' => 'Система управления обучением с проигрывателем SCORM'],
        'process-mapper' => ['name' => 'Процессы',      'description' => 'Визуальный инструмент построения блок-схем и процессов'],
        'tasks'          => ['name' => 'Задачи',        'description' => 'Канбан-доска и список для отслеживания задач'],
        'cmdb'           => ['name' => 'CMDB',          'description' => 'База данных управления конфигурациями'],
        'network-mapper' => ['name' => 'Сеть',          'description' => 'Проектирование и документирование сетевых схем'],
        'system'         => ['name' => 'Система',       'description' => 'Системное администрирование и настройка'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'Проверить новые письма',
        'change_password' => 'Сменить пароль',
        'mfa'             => 'Многофакторная аутентификация',
        'trusted_device'  => 'Доверенное устройство',
        'logout'          => 'Выйти',
        'logout_confirm'  => 'Вы уверены, что хотите выйти?',
        'badge_off'       => 'Выкл.',
        'badge_on'        => 'Вкл.',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'Сменить пароль',
        'current_password' => 'Текущий пароль',
        'new_password'     => 'Новый пароль',
        'confirm_password' => 'Подтвердите новый пароль',
        'submit'           => 'Сменить пароль',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'Многофакторная аутентификация',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'Назад',
        'next'     => 'Вперёд',
        'today'    => 'Сегодня',

        'months' => [
            'january'   => 'январь',
            'february'  => 'февраль',
            'march'     => 'март',
            'april'     => 'апрель',
            'may'       => 'май',
            'june'      => 'июнь',
            'july'      => 'июль',
            'august'    => 'август',
            'september' => 'сентябрь',
            'october'   => 'октябрь',
            'november'  => 'ноябрь',
            'december'  => 'декабрь',
        ],

        'weekdays' => [
            'monday'    => 'понедельник',
            'tuesday'   => 'вторник',
            'wednesday' => 'среда',
            'thursday'  => 'четверг',
            'friday'    => 'пятница',
            'saturday'  => 'суббота',
            'sunday'    => 'воскресенье',
        ],
    ],
];
