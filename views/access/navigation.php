<?php if (!defined('SB_ACCESS_CONSOLE_VIEW')) { http_response_code(404); exit; } ?>
<a class="sbg-brand" href="<?= $h($base) ?>/access.php"><span class="sbg-logo">S</span> Sitebuilder <span class="sbg-brand-note">ACCESS</span></a>
<nav class="sbg-nav" aria-label="Управление доступом">
<?php foreach (['objects'=>'Объекты','groups'=>'Группы','employees'=>'Сотрудники'] as $key=>$label): ?>
    <a href="<?= $h($base) ?>/access.php?view=<?= $h($key) ?>" class="<?= $view===$key?'sbg-current':'' ?>"<?= $view===$key?' aria-current="page"':'' ?>><?= $h($label) ?></a>
<?php endforeach; ?>
    <a href="<?= $h($base) ?>/index.php">← Все сайты</a>
</nav>
