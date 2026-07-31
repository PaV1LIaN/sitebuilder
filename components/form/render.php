<?php
require_once dirname(__DIR__, 2) . '/lib/FormSubmissionService.php';
$fields = FormSubmissionService::normalizeFields($content['fields'] ?? []);
$title = trim((string)($content['title'] ?? ''));
$description = trim((string)($content['description'] ?? ''));
$style = sb_public_safe_choice($props['style'] ?? 'card', ['card','minimal','accent'], 'card');
$siteId = (int)($context['siteId'] ?? 0);
$pageId = (int)($block['pageId'] ?? 0);
$blockId = (int)($block['id'] ?? 0);
?>
<section class="sb-form-block sb-form-block--<?= sb_public_h($style) ?>" data-sb-form-wrapper>
    <?php if ($title !== ''): ?><h2><?= sb_public_h($title) ?></h2><?php endif; ?>
    <?php if ($description !== ''): ?><p class="sb-form-block__description"><?= sb_public_h($description) ?></p><?php endif; ?>
    <form class="sb-public-form" method="post" action="<?= sb_public_h((string)($context['basePath'] ?? '')) ?>/form_submit.php" data-sb-public-form>
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="siteId" value="<?= $siteId ?>"><input type="hidden" name="pageId" value="<?= $pageId ?>"><input type="hidden" name="blockId" value="<?= $blockId ?>">
        <div class="sb-form-block__honeypot" aria-hidden="true"><label>Компания<input type="text" name="_company" tabindex="-1" autocomplete="off"></label></div>
        <div class="sb-form-block__grid">
        <?php foreach ($fields as $field): $key=$field['key']; $id='sb-form-'.$blockId.'-'.$key; ?>
            <div class="sb-form-field <?= $field['width']==='half'?'is-half':'is-full' ?>" data-form-field="<?= sb_public_h($key) ?>">
                <?php if ($field['type'] === 'checkbox'): ?>
                    <label class="sb-form-checkbox"><input type="checkbox" id="<?= sb_public_h($id) ?>" name="<?= sb_public_h($key) ?>" value="1" <?= $field['required']?'required':'' ?>><span><?= sb_public_h($field['label']) ?><?= $field['required']?' *':'' ?></span></label>
                <?php else: ?>
                    <label for="<?= sb_public_h($id) ?>"><?= sb_public_h($field['label']) ?><?= $field['required']?' *':'' ?></label>
                    <?php if ($field['type'] === 'textarea'): ?><textarea id="<?= sb_public_h($id) ?>" name="<?= sb_public_h($key) ?>" placeholder="<?= sb_public_h($field['placeholder']) ?>" <?= $field['required']?'required':'' ?>></textarea>
                    <?php elseif ($field['type'] === 'select'): ?><select id="<?= sb_public_h($id) ?>" name="<?= sb_public_h($key) ?>" <?= $field['required']?'required':'' ?>><option value="">Выберите...</option><?php foreach ($field['options'] as $option): ?><option value="<?= sb_public_h($option) ?>"><?= sb_public_h($option) ?></option><?php endforeach; ?></select>
                    <?php else: ?><input type="<?= $field['type']==='email'?'email':($field['type']==='phone'?'tel':'text') ?>" id="<?= sb_public_h($id) ?>" name="<?= sb_public_h($key) ?>" placeholder="<?= sb_public_h($field['placeholder']) ?>" <?= $field['required']?'required':'' ?>><?php endif; ?>
                <?php endif; ?>
                <span class="sb-form-field__error" data-form-error></span>
            </div>
        <?php endforeach; ?>
        </div>
        <button class="sb-btn-public" type="submit"><?= sb_public_h((string)($content['submitLabel'] ?? 'Отправить')) ?></button>
        <?php if (!empty($content['privacyText'])): ?><small class="sb-form-block__privacy"><?= sb_public_h(mb_substr((string)$content['privacyText'],0,1000)) ?></small><?php endif; ?>
        <div class="sb-form-block__message" data-form-message hidden data-success-text="<?= sb_public_h((string)($content['successText'] ?? 'Спасибо! Заявка отправлена.')) ?>"></div>
    </form>
</section>
