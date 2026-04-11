/**
 * SCORM API Bridge
 *
 * Provides both SCORM 1.1/1.2 (window.API) and SCORM 2004 (window.API_1484_11)
 * objects. SCORM content running in the iframe walks up the frame tree to find
 * these objects and calls their methods to read/write learner data.
 *
 * Requires window.SCORM_CONFIG = { courseId, analystId, scormVersion, apiEndpoint }
 */
(function() {
    const config = window.SCORM_CONFIG || {};
    let cmiData = {};
    let dirtyElements = [];
    let initialized = false;
    let finished = false;
    let lastError = '0';

    // --- Network helpers (synchronous — SCORM requires sync returns) ---

    function loadCmiData() {
        try {
            const xhr = new XMLHttpRequest();
            xhr.open('GET', config.apiEndpoint + '?course_id=' + config.courseId, false);
            xhr.send();
            if (xhr.status === 200) {
                const resp = JSON.parse(xhr.responseText);
                if (resp.success && resp.data) {
                    cmiData = resp.data;
                }
            }
        } catch (e) {
            console.error('SCORM bridge: failed to load CMI data', e);
        }
    }

    function saveCmiData() {
        if (dirtyElements.length === 0) return true;

        const elements = dirtyElements.map(el => ({ element: el, value: cmiData[el] || '' }));
        dirtyElements = [];

        try {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', config.apiEndpoint, false);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.send(JSON.stringify({
                course_id: config.courseId,
                elements: elements
            }));
            return xhr.status === 200;
        } catch (e) {
            console.error('SCORM bridge: failed to save CMI data', e);
            return false;
        }
    }

    // --- Shared implementation ---

    function doInitialize() {
        if (initialized) {
            lastError = '101'; // Already initialized
            return 'false';
        }
        loadCmiData();
        initialized = true;
        finished = false;
        lastError = '0';
        return 'true';
    }

    function doFinish() {
        if (!initialized) {
            lastError = '301'; // Not initialized
            return 'false';
        }
        if (finished) {
            lastError = '111'; // Already terminated
            return 'false';
        }
        saveCmiData();
        finished = true;
        initialized = false;
        lastError = '0';
        return 'true';
    }

    function doGetValue(element) {
        if (!initialized) {
            lastError = '301';
            return '';
        }
        lastError = '0';

        // Handle _count elements for arrays (interactions, objectives, etc.)
        if (element.endsWith('._count')) {
            const prefix = element.replace('._count', '.');
            let count = 0;
            for (const key in cmiData) {
                if (key.startsWith(prefix)) {
                    const idx = parseInt(key.substring(prefix.length));
                    if (!isNaN(idx) && idx >= count) count = idx + 1;
                }
            }
            return String(count);
        }

        // Handle _children elements
        if (element.endsWith('._children')) {
            return getChildrenFor(element);
        }

        return cmiData[element] !== undefined ? String(cmiData[element]) : '';
    }

    function doSetValue(element, value) {
        if (!initialized) {
            lastError = '301';
            return 'false';
        }
        if (finished) {
            lastError = '111';
            return 'false';
        }

        // Block read-only elements
        if (isReadOnly(element)) {
            lastError = '404';
            return 'false';
        }

        cmiData[element] = String(value);
        if (!dirtyElements.includes(element)) {
            dirtyElements.push(element);
        }
        lastError = '0';
        return 'true';
    }

    function doCommit() {
        if (!initialized) {
            lastError = '301';
            return 'false';
        }
        saveCmiData();
        lastError = '0';
        return 'true';
    }

    function doGetLastError() {
        return lastError;
    }

    function doGetErrorString(code) {
        const errors = {
            '0': 'No Error',
            '101': 'General Exception',
            '111': 'Already Terminated',
            '201': 'Invalid Argument',
            '301': 'Not Initialized',
            '401': 'Not Implemented',
            '402': 'Invalid Set Value',
            '403': 'Element Is Read Only',
            '404': 'Element Is Write Only'
        };
        return errors[String(code)] || 'Unknown Error';
    }

    function doGetDiagnostic(code) {
        return doGetErrorString(code);
    }

    // --- Helper: read-only elements ---

    function isReadOnly(element) {
        const readOnly12 = [
            'cmi.core._children', 'cmi.core.student_id', 'cmi.core.student_name',
            'cmi.core.credit', 'cmi.core.entry', 'cmi.launch_data',
            'cmi.core.total_time'
        ];
        const readOnly2004 = [
            'cmi._version', 'cmi.learner_id', 'cmi.learner_name',
            'cmi.credit', 'cmi.entry', 'cmi.launch_data',
            'cmi.total_time', 'cmi.mode'
        ];

        if (element.endsWith('._count') || element.endsWith('._children')) return true;
        return readOnly12.includes(element) || readOnly2004.includes(element);
    }

    // --- Helper: _children responses ---

    function getChildrenFor(element) {
        const childrenMap = {
            'cmi.core._children': 'student_id,student_name,lesson_location,credit,lesson_status,entry,score,total_time,lesson_mode,exit,session_time',
            'cmi.core.score._children': 'raw,min,max',
            'cmi._children': 'completion_status,credit,entry,exit,interactions,launch_data,learner_id,learner_name,location,max_time_allowed,mode,objectives,progress_measure,scaled_passing_score,score,session_time,success_status,suspend_data,time_limit_action,total_time'
        };
        return childrenMap[element] || '';
    }

    // --- SCORM 1.1 / 1.2 API ---

    window.API = {
        LMSInitialize:    function(p) { return doInitialize(); },
        LMSFinish:        function(p) { return doFinish(); },
        LMSGetValue:      function(el) { return doGetValue(el); },
        LMSSetValue:      function(el, val) { return doSetValue(el, val); },
        LMSCommit:        function(p) { return doCommit(); },
        LMSGetLastError:  function() { return doGetLastError(); },
        LMSGetErrorString: function(c) { return doGetErrorString(c); },
        LMSGetDiagnostic: function(c) { return doGetDiagnostic(c); }
    };

    // --- SCORM 2004 API ---

    window.API_1484_11 = {
        Initialize:      function(p) { return doInitialize(); },
        Terminate:        function(p) { return doFinish(); },
        GetValue:         function(el) { return doGetValue(el); },
        SetValue:         function(el, val) { return doSetValue(el, val); },
        Commit:           function(p) { return doCommit(); },
        GetLastError:     function() { return doGetLastError(); },
        GetErrorString:   function(c) { return doGetErrorString(c); },
        GetDiagnostic:    function(c) { return doGetDiagnostic(c); }
    };

})();
