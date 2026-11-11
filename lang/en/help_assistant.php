<?php
/**
 * The help assistant's window (includes/help_assistant_widget.php, assets/js/help-assistant.js).
 * phrase.<kind>.N: the canned lines, chosen by how good the match is - see
 * helpAssistantReplyKind(). Add or remove variants freely; they never claim an AI read anything.
 */
return [
    'title' => 'Help',
    'button' => 'Help assistant',
    'greeting' => 'Hi! Ask me how to do something in FreeITSM and I\'ll find the right part of the help.',
    'mode_search' => 'Searching the help pages - no AI is set up, so you get the right pages rather than a written answer.',
    'mode_meaning' => 'Searching the help by meaning - no chat AI is set up, so you get the right pages rather than a written answer.',
    'placeholder' => 'Ask how to do something…',
    'send' => 'Send',
    'reset' => 'Reset',
    'close' => 'Close',
    'open' => 'Open',
    'also' => 'Also worth a look',
    'searching' => 'Searching the help…',
    'error' => 'Something went wrong - please try again.',
    'phrase' => [
        'clear' => [
            1 => 'This looks like exactly what you need:',
            2 => 'Good news - the help covers this:',
            3 => 'I think this is the one:',
        ],
        'several' => [
            1 => 'Here\'s what I found in the help:',
            2 => 'These look like the closest matches:',
            3 => 'You might find these helpful:',
            4 => 'I had a look through the help and found these:',
        ],
        'module' => [
            1 => 'Here\'s what the help for this module says:',
            2 => 'From the help for the module you\'re in:',
        ],
        'weak' => [
            1 => 'I couldn\'t find an exact match, but these might be close:',
            2 => 'Not a perfect match, but worth a look:',
        ],
        'none' => [
            1 => 'I couldn\'t find anything for that - try different words, or name the module you\'re in?',
            2 => 'Nothing in the help matches that. Maybe try a shorter question?',
        ],
    ],
];
