<?php
/**
 * LMS -> Tests -> a test: the role, its skills, its questions, and sending it.
 * New when there is no ?id=.
 */
require __DIR__ . '/_page.php';
$testId = (int)($_GET['id'] ?? 0);
$ctSettings = lmsCtSettings(connectToDatabase());
ctHead(lt('heading', 'Competency tests'));
?>
<body data-ct-page="edit" data-mobile-module="lms" data-mobile-page="lms-tests-edit" data-test-id="<?php echo $testId; ?>" data-default-limit="<?php echo (int)$ctSettings[LMS_CT_TIME_LIMIT]; ?>">
<div class="ct-page">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div class="ct-scroll">
    <div class="ct-wrap">
        <a class="ct-back" href="./">← <?php echo htmlspecialchars(lt('all_tests', 'All tests')); ?></a>

        <div class="ct-section">
            <h2><?php echo htmlspecialchars(lt('s_role', '1. The role')); ?></h2>
            <p class="ct-hint"><?php echo htmlspecialchars(lt('s_role_hint', 'What the job is and what the person will actually do. The AI reads this when it writes the questions, so concrete beats generic.')); ?></p>
            <div class="ct-grid2">
                <div class="form-group full"><label for="tTitle"><?php echo htmlspecialchars(lt('f_title', 'Test title')); ?></label>
                    <input id="tTitle" maxlength="200" placeholder="<?php echo htmlspecialchars(lt('title_ph', 'e.g. 2nd line support analyst')); ?>"></div>
                <div class="form-group full"><label for="tRole"><?php echo htmlspecialchars(lt('f_role', 'The role you are recruiting for')); ?></label>
                    <textarea id="tRole" class="ct-role" placeholder="<?php echo htmlspecialchars(lt('role_ph', 'e.g. A 2nd line analyst in a 40-person IT team supporting 900 users on Microsoft 365 and Intune: escalated tickets, user and device management, some scripting, on-call one week in six.')); ?>"></textarea></div>
                <div class="form-group"><label for="tLimit"><?php echo htmlspecialchars(lt('f_limit', 'Time limit (minutes)')); ?></label>
                    <input id="tLimit" type="number" min="1" max="600" placeholder="<?php echo htmlspecialchars(lt('untimed', 'Untimed')); ?>"></div>
                <div class="form-group"><label for="tPass"><?php echo htmlspecialchars(lt('f_pass', 'Pass mark (%)')); ?></label>
                    <input id="tPass" type="number" min="0" max="100" placeholder="<?php echo htmlspecialchars(lt('optional', 'Optional')); ?>"></div>
            </div>
        </div>

        <div class="ct-section">
            <h2><?php echo htmlspecialchars(lt('s_skills', '2. The skills')); ?></h2>
            <p class="ct-hint"><?php echo htmlspecialchars(lt('s_skills_hint', 'One row per skill. Multiple choice has one right answer; graded (the ITIL style) has four defensible answers worth different marks, and tests judgement rather than recall.')); ?></p>
            <div class="ct-skill-head"><span><?php echo htmlspecialchars(lt('f_skill', 'Skill')); ?></span><span><?php echo htmlspecialchars(lt('f_difficulty', 'Difficulty')); ?></span>
                <span><?php echo htmlspecialchars(lt('f_count', 'Questions')); ?></span><span><?php echo htmlspecialchars(lt('f_format', 'Format')); ?></span><span></span><span></span></div>
            <div class="ct-skills" id="skills"></div>
            <div class="ct-row-actions">
                <button class="btn btn-secondary" id="addSkill"><?php echo htmlspecialchars(lt('add_skill', 'Add')); ?></button>
                <button class="btn btn-secondary" id="saveTest"><?php echo htmlspecialchars(lt('save', 'Save')); ?></button>
                <button class="btn btn-primary" id="fillAll"><?php echo htmlspecialchars(lt('fill_all', 'Fill all')); ?></button>
                <label class="ct-check"><input type="checkbox" id="useBank" checked> <?php echo htmlspecialchars(lt('use_bank', 'Use approved questions from the bank first')); ?></label>
                <span class="ct-note" id="fillStatus"></span>
            </div>
            <p class="ct-note" id="aiWarn" hidden style="margin-top:10px"><?php echo htmlspecialchars(lt('ai_missing', 'LMS AI is not set up (LMS → Settings → LMS AI), so Fill can only use the bank. You can still write questions yourself.')); ?></p>
        </div>

        <div class="ct-section">
            <h2><?php echo htmlspecialchars(lt('s_questions', '3. The questions')); ?> <span class="ct-count" id="qCount"></span>
                <span class="ct-head-actions">
                    <button class="btn btn-sm btn-primary" id="approveAll" hidden><?php echo htmlspecialchars(lt('approve_all', 'Approve all')); ?></button>
                    <button class="btn btn-sm btn-secondary" id="fromBank"><?php echo htmlspecialchars(lt('bank', 'Bank')); ?></button>
                    <button class="btn btn-sm btn-secondary" id="writeQ"><?php echo htmlspecialchars(lt('write', 'Write')); ?></button>
                </span></h2>
            <p class="ct-hint"><?php echo htmlspecialchars(lt('s_questions_hint', 'Read every draft before approving it - the AI is usually right and occasionally confidently wrong. Edit fixes a question everywhere; Remove takes it off this test only; Hide retires it from the bank.')); ?></p>
            <div id="questions"></div>
        </div>

        <div class="ct-section">
            <h2><?php echo htmlspecialchars(lt('s_candidates', '4. Candidates')); ?>
                <span class="ct-head-actions"><button class="btn btn-sm btn-primary" id="sendBtn" disabled><?php echo htmlspecialchars(lt('send', 'Send')); ?></button></span></h2>
            <p class="ct-hint" id="sendHint"></p>
            <div id="sittings"></div>
        </div>
    </div>
    </div>
</div>
<?php ctFoot();
