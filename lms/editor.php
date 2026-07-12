<?php
/**
 * LMS — the course editor (native courses only).
 *
 * Left: the lessons, in order, draggable. Right: the selected lesson's body in
 * TinyMCE, and the questions that follow it. A SCORM course has no lessons to
 * edit, so it is bounced back to the list rather than shown an editor that
 * cannot do anything.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('lms');

$courseId = (int)($_GET['course_id'] ?? 0);
$conn     = connectToDatabase();

$stmt = $conn->prepare("SELECT * FROM lms_courses WHERE id = ? AND is_active = 1");
$stmt->execute([$courseId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    header('Location: index.php');
    exit;
}
if (($course['content_type'] ?? 'scorm') !== 'native') {
    // An uploaded package is authored in the tool that made it, not here.
    header('Location: index.php?error=not_authored');
    exit;
}

$path_prefix = '../';
$translationNamespaces = ['common', 'lms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($course['title']); ?> — <?php echo htmlspecialchars(t('lms.editor.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/lms.css?v=4">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <script src="../assets/js/tinymce/tinymce.min.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="lms-editor">
        <!-- Toolbar -->
        <div class="lms-editor-bar">
            <a href="index.php" class="lms-editor-back">&larr; <?php echo htmlspecialchars(t('lms.editor.back')); ?></a>
            <div class="lms-editor-bar-title">
                <h1 id="courseTitle"><?php echo htmlspecialchars($course['title']); ?></h1>
                <span class="lms-editor-passmark" id="passMarkChip"></span>
            </div>
            <div class="lms-editor-bar-actions">
                <button class="btn btn-secondary" onclick="LMSEditor.openCourseModal()"><?php echo htmlspecialchars(t('lms.editor.course_settings')); ?></button>
                <a class="btn btn-primary" href="player.php?course_id=<?php echo $courseId; ?>"><?php echo htmlspecialchars(t('lms.editor.preview')); ?></a>
            </div>
        </div>

        <div class="lms-editor-body">
            <!-- Lessons -->
            <aside class="lms-editor-side">
                <div class="lms-editor-side-head">
                    <h2><?php echo htmlspecialchars(t('lms.editor.lessons')); ?></h2>
                </div>
                <div id="lessonList" class="lms-lesson-list"></div>
                <div class="lms-editor-side-actions">
                    <button class="btn btn-secondary btn-block" onclick="LMSEditor.newLesson()">+ <?php echo htmlspecialchars(t('lms.editor.add_lesson')); ?></button>
                    <button class="btn btn-ai btn-block" onclick="LMSEditor.openOutlineModal()">
                        <?php echo htmlspecialchars(t('lms.editor.ai_outline')); ?>
                    </button>
                </div>
            </aside>

            <!-- The selected lesson -->
            <main class="lms-editor-main">
                <div id="noLesson" class="lms-editor-empty">
                    <h3><?php echo htmlspecialchars(t('lms.editor.empty_title')); ?></h3>
                    <p><?php echo htmlspecialchars(t('lms.editor.empty_body')); ?></p>
                </div>

                <div id="lessonPane" style="display:none;">
                    <div class="lms-form-row">
                        <label for="lessonTitle"><?php echo htmlspecialchars(t('lms.editor.lesson_title')); ?></label>
                        <input type="text" id="lessonTitle" class="lms-input-lg">
                    </div>

                    <div class="lms-editor-tools">
                        <button class="btn btn-ai" onclick="LMSEditor.openLessonAiModal()"><?php echo htmlspecialchars(t('lms.editor.ai_lesson')); ?></button>
                        <span class="lms-editor-hint"><?php echo htmlspecialchars(t('lms.editor.ai_lesson_hint')); ?></span>
                    </div>

                    <textarea id="lessonBody"></textarea>

                    <div class="lms-editor-save">
                        <button class="btn btn-primary" onclick="LMSEditor.saveLesson()"><?php echo htmlspecialchars(t('lms.editor.save')); ?></button>
                        <span id="saveState" class="lms-editor-hint"></span>
                    </div>

                    <!-- Questions -->
                    <section class="lms-questions">
                        <div class="lms-questions-head">
                            <h3><?php echo htmlspecialchars(t('lms.editor.questions')); ?></h3>
                            <div>
                                <button class="btn btn-ai" onclick="LMSEditor.generateQuiz()"><?php echo htmlspecialchars(t('lms.editor.ai_quiz')); ?></button>
                                <button class="btn btn-secondary" onclick="LMSEditor.openQuestionModal()">+ <?php echo htmlspecialchars(t('lms.editor.add_question')); ?></button>
                            </div>
                        </div>
                        <p class="lms-editor-hint"><?php echo htmlspecialchars(t('lms.editor.questions_hint')); ?></p>
                        <div id="questionList"></div>
                    </section>
                </div>
            </main>
        </div>
    </div>

    <!-- Course settings -->
    <div class="modal" id="courseModal">
        <div class="modal-content" style="max-width: 560px;">
            <div class="modal-header"><?php echo htmlspecialchars(t('lms.editor.course_settings')); ?></div>
            <form id="courseForm" style="padding: 20px 24px;">
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.editor.course_title')); ?> *</label>
                    <input type="text" id="cTitle" required>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.editor.course_description')); ?></label>
                    <textarea id="cDescription" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.editor.pass_mark')); ?></label>
                    <input type="number" id="cPassMark" min="0" max="100" placeholder="<?php echo htmlspecialchars(t('lms.editor.pass_mark_placeholder')); ?>">
                    <small><?php echo htmlspecialchars(t('lms.editor.pass_mark_help')); ?></small>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="LMSEditor.closeModal('courseModal')"><?php echo htmlspecialchars(t('lms.editor.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('lms.editor.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Question -->
    <div class="modal" id="questionModal">
        <div class="modal-content" style="max-width: 640px;">
            <div class="modal-header" id="questionModalTitle"><?php echo htmlspecialchars(t('lms.editor.add_question')); ?></div>
            <form id="questionForm" style="padding: 20px 24px;">
                <input type="hidden" id="qId">
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.editor.question_text')); ?> *</label>
                    <textarea id="qText" rows="2" required></textarea>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.editor.question_type')); ?></label>
                    <select id="qType" onchange="LMSEditor.onTypeChange()">
                        <option value="single"><?php echo htmlspecialchars(t('lms.editor.type_single')); ?></option>
                        <option value="multiple"><?php echo htmlspecialchars(t('lms.editor.type_multiple')); ?></option>
                        <option value="truefalse"><?php echo htmlspecialchars(t('lms.editor.type_truefalse')); ?></option>
                    </select>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.editor.answers')); ?> *</label>
                    <div id="answerRows" class="lms-answer-rows"></div>
                    <button type="button" class="btn btn-secondary btn-sm" id="addAnswerBtn" onclick="LMSEditor.addAnswerRow()">+ <?php echo htmlspecialchars(t('lms.editor.add_answer')); ?></button>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.editor.explanation')); ?></label>
                    <textarea id="qExplanation" rows="2" placeholder="<?php echo htmlspecialchars(t('lms.editor.explanation_placeholder')); ?>"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="LMSEditor.closeModal('questionModal')"><?php echo htmlspecialchars(t('lms.editor.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('lms.editor.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- AI: course outline -->
    <div class="modal" id="outlineModal">
        <div class="modal-content" style="max-width: 560px;">
            <div class="modal-header"><?php echo htmlspecialchars(t('lms.editor.ai_outline')); ?></div>
            <form id="outlineForm" style="padding: 20px 24px;">
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.editor.outline_topic')); ?> *</label>
                    <input type="text" id="oTopic" required placeholder="<?php echo htmlspecialchars(t('lms.editor.outline_topic_placeholder')); ?>">
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.editor.outline_count')); ?></label>
                    <input type="number" id="oCount" min="2" max="12" value="5">
                </div>
                <p class="lms-editor-hint"><?php echo htmlspecialchars(t('lms.editor.outline_help')); ?></p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="LMSEditor.closeModal('outlineModal')"><?php echo htmlspecialchars(t('lms.editor.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary" id="outlineBtn"><?php echo htmlspecialchars(t('lms.editor.generate')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- AI: lesson from a knowledge article -->
    <div class="modal" id="lessonAiModal">
        <div class="modal-content" style="max-width: 560px;">
            <div class="modal-header"><?php echo htmlspecialchars(t('lms.editor.ai_lesson')); ?></div>
            <form id="lessonAiForm" style="padding: 20px 24px;">
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('lms.editor.from_article')); ?></label>
                    <select id="aiArticle">
                        <option value=""><?php echo htmlspecialchars(t('lms.editor.from_article_none')); ?></option>
                    </select>
                    <small><?php echo htmlspecialchars(t('lms.editor.from_article_help')); ?></small>
                </div>
                <div class="syshelp-callout" style="display:none;"></div>
                <p class="lms-editor-hint"><?php echo htmlspecialchars(t('lms.editor.ai_lesson_warning')); ?></p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="LMSEditor.closeModal('lessonAiModal')"><?php echo htmlspecialchars(t('lms.editor.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary" id="lessonAiBtn"><?php echo htmlspecialchars(t('lms.editor.generate')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        window.API_BASE  = '../api/lms/';
        window.COURSE_ID = <?php echo $courseId; ?>;
        window.COURSE    = <?php echo json_encode([
            'title'       => $course['title'],
            'description' => $course['description'],
            'pass_mark'   => $course['pass_mark'] === null ? null : (int)$course['pass_mark'],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="../assets/js/lms-editor.js?v=1"></script>
</body>
</html>
