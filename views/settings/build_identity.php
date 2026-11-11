<?php
declare(strict_types=1);
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
?>
<section class="card" aria-labelledby="build-title">
  <h1 id="build-title">Versão instalada</h1>
  <?php if ($identity['integrity'] !== 'consistent'): ?>
    <p role="alert">Não foi possível confirmar a integridade desta instalação. Solicite a conferência do pacote antes de atualizar ou restaurar um backup.</p>
  <?php else: ?>
    <p>Os arquivos conferem com o manifesto desta instalação.</p>
    <dl style="overflow-wrap:anywhere">
      <?php foreach (['package_version'=>'Pacote','ui_version'=>'Versão visual','build_id'=>'Identificação do build','built_at_utc'=>'Manifesto gerado em (UTC)','content_sha256'=>'SHA-256 do conteúdo','file_count'=>'Arquivos verificados'] as $key=>$label): ?>
      <dt><?= $e($label) ?></dt><dd><?= $e($identity[$key]) ?></dd>
      <?php endforeach; ?>
    </dl>
  <?php endif; ?>
  <p>Esta conferência verifica arquivos de execução e dependências. Não valida configuração privada, banco de dados, pesquisa com usuários ou aprovação para produção. Confira também o SHA-256 do ZIP recebido por um canal confiável.</p>
  <p>Ao solicitar suporte, copie os dados acima. Não inclua informações de pacientes.</p>
</section>
