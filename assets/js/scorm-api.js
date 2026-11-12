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

    /* Which front door this runtime is playing in. The analyst app and the
       portal share one PHP session, so an administrator signed into both has two
       identities and the server cannot tell from the session which is acting —
       every call says. Without it, an administrator taking a SCORM course in the
       portal has the attempt written onto their ANALYST record. */
    const AS_Q     = window.LMS_AS ? '&as=' + encodeURIComponent(window.LMS_AS) : '';
    const AS_FIRST = window.LMS_AS ? '?as=' + encodeURIComponent(window.LMS_AS) : '';
    let cmiData = {};
    let dirtyElements = [];
    let initialized = false;
    let finished = false;
    let lastError = '0';

    // --- Network helpers (synchronous — SCORM requires sync returns) ---

    /**
     * Loads the learner's stored CMI values for the course from the API endpoint, synchronously
     * because SCORM calls must return a value at once.
     */
    function loadCmiData() {
        try {
            const xhr = new XMLHttpRequest();
            xhr.open('GET', config.apiEndpoint + '?course_id=' + config.courseId + AS_Q, false);
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

    /**
     * Posts the CMI elements changed since the last save to the API endpoint, synchronously; returns
     * true when nothing needed saving or the server answered 200.
     */
    function saveCmiData() {
        if (dirtyElements.length === 0) return true;

        const elements = dirtyElements.map(el => ({ element: el, value: cmiData[el] || '' }));
        dirtyElements = [];

        try {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', config.apiEndpoint + AS_FIRST, false);
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

    /** Starts a SCORM session - loads the stored CMI data; error 101 when already initialised. */
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

    /**
     * Ends the SCORM session, saving any changed values; errors 301 when not initialised and 111 when
     * already finished.
     */
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

    /**
     * Returns a CMI element's value as a string, working out ._count from the stored array keys and
     * answering ._children from a fixed list.
     */
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

    /**
     * Stores a CMI element value and marks it for the next save.
     *
     * TRAP: the two standards number "read only" differently - 403 in SCORM 1.2, 404 in SCORM
     * 2004 (where 403 means "value not initialized"). Until 5.1.0 both APIs answered 404, so a
     * SCORM 1.2 course was told "Element Is Write Only" for an element it may only read.
     *
     * @param {string} version  '1.2' (window.API) or '2004' (window.API_1484_11)
     */
    function doSetValue(element, value, version) {
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
            lastError = version === '2004' ? '404' : '403';
            return 'false';
        }

        cmiData[element] = String(value);
        if (!dirtyElements.includes(element)) {
            dirtyElements.push(element);
        }
        lastError = '0';
        return 'true';
    }

    /** Saves the changed CMI values now (LMSCommit / Commit). */
    function doCommit() {
        if (!initialized) {
            lastError = '301';
            return 'false';
        }
        saveCmiData();
        lastError = '0';
        return 'true';
    }

    /** Returns the error code of the last API call, "0" for none. */
    function doGetLastError() {
        return lastError;
    }

    /**
     * i18n-exempt: these strings are defined by the SCORM 2004 run-time
     * specification, not by FreeITSM. They are returned to the CONTENT PACKAGE
     * through GetErrorString(), which compares them and logs them; a course
     * built to the spec expects "Not Initialized" in English whatever language
     * the learner reads. Translating them would break conformance, and no
     * learner ever sees them. The header block above says the same for
     * Content-Type, which is an HTTP header name.
     */
    function doGetErrorString(code, version) {
        // SCORM 2004's own wording for its read-only code; everything else below is the shared table.
        if (version === '2004' && String(code) === '404') return 'Data Model Element Is Read Only';
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

    /** Returns the diagnostic text for an error code - the same as its error string. */
    function doGetDiagnostic(code, version) {
        return doGetErrorString(code, version);
    }

    // --- Helper: read-only elements ---

    /**
     * Whether a CMI element is read-only in SCORM 1.2 or 2004, including every ._count and ._children
     * element.
     */
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

    /** Returns the comma-separated child list for a ._children element, or empty for one it does not know. */
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
        /** SCORM 1.2: starts the session. */
        LMSInitialize:    function(p) { return doInitialize(); },
        /** SCORM 1.2: ends the session and saves. */
        LMSFinish:        function(p) { return doFinish(); },
        /** SCORM 1.2: reads a CMI element. */
        LMSGetValue:      function(el) { return doGetValue(el); },
        /** SCORM 1.2: writes a CMI element. */
        LMSSetValue:      function(el, val) { return doSetValue(el, val, '1.2'); },
        /** SCORM 1.2: saves changed values now. */
        LMSCommit:        function(p) { return doCommit(); },
        /** SCORM 1.2: the last error code. */
        LMSGetLastError:  function() { return doGetLastError(); },
        /** SCORM 1.2: the text for an error code. */
        LMSGetErrorString: function(c) { return doGetErrorString(c, '1.2'); },
        /** SCORM 1.2: the diagnostic for an error code. */
        LMSGetDiagnostic: function(c) { return doGetDiagnostic(c, '1.2'); }
    };

    // --- SCORM 2004 API ---

    window.API_1484_11 = {
        /** SCORM 2004: starts the session. */
        Initialize:      function(p) { return doInitialize(); },
        /** SCORM 2004: ends the session and saves. */
        Terminate:        function(p) { return doFinish(); },
        /** SCORM 2004: reads a CMI element. */
        GetValue:         function(el) { return doGetValue(el); },
        /** SCORM 2004: writes a CMI element. */
        SetValue:         function(el, val) { return doSetValue(el, val, '2004'); },
        /** SCORM 2004: saves changed values now. */
        Commit:           function(p) { return doCommit(); },
        /** SCORM 2004: the last error code. */
        GetLastError:     function() { return doGetLastError(); },
        /** SCORM 2004: the text for an error code. */
        GetErrorString:   function(c) { return doGetErrorString(c, '2004'); },
        /** SCORM 2004: the diagnostic for an error code. */
        GetDiagnostic:    function(c) { return doGetDiagnostic(c, '2004'); }
    };

})();
