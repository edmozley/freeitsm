<?php
/**
 * Forms help — the topic registry.
 *
 * 🔑 WHY FORMS HAS TOPIC PAGES AT ALL. `forms/help.php` is one page with eight
 * sections and it was already the longest help guide in the product. Six more
 * areas landed in 2.3.0 — layout, blocks, tables, drafts and who may request a
 * form — and adding them as six more sections would have made a page nobody
 * reads to the bottom of. Ed's words: "maybe it should have its own help pages
 * so the help screen doesn't turn into war and peace."
 *
 * So the main guide keeps the basics and links here; each topic that needs real
 * explaining gets a page of its own.
 *
 * The registry owns each topic's title, standfirst and section list. The
 * sections drive BOTH the page's own sidebar and the cards on forms/help.php,
 * so a topic cannot list a section it does not have, and the guide cannot link
 * to a topic that does not exist.
 *
 * ADDING A TOPIC: add the entry here, then create <slug>.php which sets
 * $helpTopic and requires _top.php / _bottom.php. Nothing else to wire up.
 *
 * ⚠️ ENGLISH-ONLY AND INLINE, exactly as system/help/ is. A help topic is prose
 * rather than chrome, and putting whole paragraphs through the translation
 * layer would add several hundred keys per locale for text that nobody has
 * asked to have translated. The module's chrome IS translated, as it always was.
 */

/**
 * Returns the Forms help topics - title, standfirst, card blurb and section list for each.
 *
 * @return array<string,array<string,mixed>> Help topics, keyed by slug.
 */
function formsHelpTopics(): array
{
    return [
        // GH #420: worked examples of whole jobs, end to end. A reference explains each
        // part; a recipe shows how the parts connect - which is where people get lost
        // (who a raised ticket is for, what an answer looks like in the next step).
        // ADDING A RECIPE: add a section here and a helpSection() of the same key in
        // recipes.php. Good ones come from real "how do I...?" questions.
        'recipes' => [
            'title' => 'Recipes: whole jobs, start to finish',
            'sub'   => 'Worked examples of the things people most often build a form for - and who each ticket ends up being for.',
            'blurb' => 'Step-by-step: raise a ticket for whoever asks, on behalf of a colleague, after approval, or send the answers elsewhere.',
            'sections' => [
                'requester' => 'Who is a raised ticket for?',
                'self'      => 'Raise a ticket for whoever sends the form',
                'behalf'    => 'Raise a ticket on behalf of someone else',
                'approval'  => 'Ask for approval, then raise the ticket',
                'elsewhere' => 'Send the answers somewhere else',
            ],
        ],
        'layout' => [
            'title' => 'Laying a form out',
            'sub'   => 'Putting two questions on one line, and moving a label beside its answer box.',
            'blurb' => 'Field widths, side-by-side questions, and where a label sits.',
            'sections' => [
                'widths'   => 'Field widths',
                'pairs'    => 'Putting two questions on one line',
                'labels'   => 'Labels above or beside',
                'phones'   => 'What happens on a phone',
            ],
        ],
        'blocks' => [
            'title' => 'Notes, warnings and images',
            'sub'   => 'The things on a form that are there to be read rather than answered.',
            'blurb' => 'Add a note, a warning or a picture to a form.',
            'sections' => [
                'notes'    => 'Notes and warnings',
                'styles'   => 'Choosing a style',
                'when'     => 'Showing a warning only when it matters',
                'images'   => 'Images',
            ],
        ],
        'tables' => [
            'title' => 'Questions answered with a table',
            'sub'   => 'One question, as many rows as the person filling it in needs.',
            'blurb' => 'Ask for a list of things — items, quantities, dates — in one question.',
            'sections' => [
                'what'     => 'What a table question is',
                'columns'  => 'Setting up the columns',
                'filling'  => 'Filling one in',
                'changing' => 'Changing a table later',
                'reading'  => 'Reading the answers back',
            ],
        ],
        'drafts' => [
            'title' => 'Saving a form as a draft',
            'sub'   => 'Starting a form now and finishing it when you have the rest of the information.',
            'blurb' => 'Save a half-finished form and come back to it.',
            'sections' => [
                'saving'   => 'Saving a draft',
                'coming'   => 'Coming back to it',
                'changed'  => 'When the form has changed',
                'who'      => 'Who can see a draft',
            ],
        ],
        'approvals' => [
            'title' => 'Approving catalogue requests',
            'sub'   => 'A request that waits for a yes - from one person, or from several members of a team.',
            'blurb' => 'Named approvers, team approvals with a quorum, steps one after another, chains, and what an administrator can do.',
            'sections' => [
                'setting'  => 'Asking for approval',
                'person'   => 'A person decides',
                'team'     => 'A team decides',
                'steps'    => 'More than one step',          // GH #160
                'nobody'   => 'If nobody can decide',
                'chains'   => 'Approval chains',
                'inbox'    => 'The approvals inbox',
                'admins'   => 'When an administrator steps in',
            ],
        ],
        'catalogue' => [
            'title' => 'A catalogue item\'s card',
            'sub'   => 'A picture, one line and a price on the item in the portal catalogue - and what the requester can do with a request.',
            'blurb' => 'The Catalogue tab: picture, short description and price; Your requests; cancelling a request.',
            'sections' => [
                'card'      => 'What the card shows',
                'picture'   => 'The picture',
                'price'     => 'The price',
                'questions' => 'An item with no questions',
                'requests'  => 'Your requests, and cancelling',
            ],
        ],
        'who-can-request' => [
            'title' => 'Who can request a form',
            'sub'   => 'Offering a form to some customers and not others.',
            'blurb' => 'Restrict a catalogue form to one or more groups of people.',
            'sections' => [
                'default'  => 'By default, everyone',
                'restrict' => 'Restricting a form',
                'groups'   => 'Where the groups come from',
                'effect'   => 'What a restriction actually does',
            ],
        ],
    ];
}
