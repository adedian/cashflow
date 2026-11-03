<?php /** @var array $months @var array $chain @var string $startBalance @var ?string $asOf @var bool $isAdmin */ ?>
<div id="fcResults" data-query="<?= !empty($history) ? 'riwayat=1' : '' ?>">
  <?= \App\Core\View::partial('forecast/_list', get_defined_vars()) ?>
</div>
