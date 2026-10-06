<?php
/**
 * LMS -> Tests: the competency tests, the question bank and the candidates.
 */
require __DIR__ . '/_page.php';
ctHead(lt('heading', 'Competency tests'));
?>
<body data-ct-page="index" data-mobile-module="lms" data-mobile-page="lms-tests">
<div class="ct-page">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div class="ct-scroll">
    <div class="ct-wrap">
        <div class="lms-tabs">
            <button class="lms-tab active" data-tab="tests"><?php echo htmlspecialchars(lt('tab_tests', 'Tests')); ?></button>
            <button class="lms-tab" data-tab="bank"><?php echo htmlspecialchars(lt('tab_bank', 'Question bank')); ?></button>
            <button class="lms-tab" data-tab="candidates"><?php echo htmlspecialchars(lt('tab_candidates', 'Candidates')); ?></button>
        </div>

        <div class="ct-panel" id="panel-tests">
            <div class="lms-panel-header">
                <h2><?php echo htmlspecialchars(lt('heading', 'Competency tests')); ?></h2>
                <a class="btn btn-primary" href="edit.php"><?php echo htmlspecialchars(lt('new', 'New')); ?></a>
            </div>
            <p class="ct-intro"><?php echo htmlspecialchars(lt('intro', 'Describe the role you are recruiting for, add the skills it needs with a difficulty and a number of questions, and the AI drafts the questions into a bank you check and reuse. Every candidate sent a test sits an identical, frozen paper through a private link.')); ?></p>
            <div id="testsList"></div>
        </div>

        <div class="ct-panel" id="panel-bank" hidden>
            <div class="lms-panel-header">
                <h2><?php echo htmlspecialchars(lt('tab_bank', 'Question bank')); ?></h2>
                <button class="btn btn-primary" id="bankNew"><?php echo htmlspecialchars(lt('new', 'New')); ?></button>
            </div>
            <p class="ct-intro"><?php echo htmlspecialchars(lt('bank_intro', 'Every question ever written for a test. Drafts are waiting to be checked; hidden ones are never offered again. Edit one here and future tests use the new wording - candidates already sent a test keep the paper they were sent.')); ?></p>
            <div id="bankFilters"></div>
            <div class="ct-bulk" id="bankBulk"></div>
            <div id="bankList"></div>
        </div>

        <div class="ct-panel" id="panel-candidates" hidden>
            <div class="lms-panel-header">
                <h2><?php echo htmlspecialchars(lt('tab_candidates', 'Candidates')); ?></h2>
            </div>
            <p class="ct-intro" id="candRetention"></p>
            <div id="candList"></div>
        </div>
    </div>
    </div>
</div>
<?php ctFoot();
