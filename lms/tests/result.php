<?php
/**
 * LMS -> Tests -> one candidate's result: the score, by skill, their answers
 * against the frozen paper they sat, and the analyst's notes. Printable.
 */
require __DIR__ . '/_page.php';
$sittingId = (int)($_GET['id'] ?? 0);
ctHead(lt('result_heading', 'Candidate result'));
?>
<body data-ct-page="result" data-mobile-module="lms" data-mobile-page="lms-tests-result" data-sitting-id="<?php echo $sittingId; ?>">
<div class="ct-page">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div class="ct-scroll">
    <div class="ct-wrap">
        <a class="ct-back ct-noprint" href="./#candidates">← <?php echo htmlspecialchars(lt('tab_candidates', 'Candidates')); ?></a>
        <div class="ct-title-row">
            <h1 id="rName"></h1>
            <span class="ct-head-actions ct-noprint"><button class="btn btn-secondary" id="rPrint"><?php echo htmlspecialchars(lt('print', 'Print')); ?></button></span>
        </div>
        <div id="result"></div>
    </div>
    </div>
</div>
<?php ctFoot();
