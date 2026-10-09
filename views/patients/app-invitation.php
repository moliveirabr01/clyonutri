<h1>Primeiro acesso ao app</h1>
<div class="card" style="padding:24px;max-width:720px;margin-top:24px">
<?php if ($error): ?>
<p role="alert"><?= htmlspecialchars($error) ?></p>
<?php else: ?>
<p>Entregue este código ao paciente por um canal privado. Ele deve abrir o app, escolher <strong>Primeiro acesso</strong> e conferir seus dados antes de criar a senha.</p>
<label for="invitation-code">Código do convite</label>
<input id="invitation-code" type="text" readonly value="<?= htmlspecialchars($invitation['invitation_code']) ?>" style="width:100%;margin:12px 0;font-family:monospace" onclick="this.select()">
<p>Válido por 48 horas, para um único uso. Gerar outro convite invalida o anterior.</p>
<p>Após a ativação, o paciente poderá acompanhar seu plano alimentar, consultas e diário no app.</p>
<?php endif; ?>
<a class="btn btn-secondary" href="/pacientes/<?= $patientId ?>">Voltar ao perfil do paciente</a>
</div>
