<fieldset class="form-group" style="margin-top: var(--space-4);">
<legend class="form-label">Registro de consentimento</legend>
<?php if ($term): ?>
<details><summary>Consultar termo v<?= htmlspecialchars($term['version']) ?></summary><p><?= nl2br(htmlspecialchars($term['term_text'])) ?></p></details>
<input type="hidden" name="consent_term_id" value="<?= (int)$term['id'] ?>">
<label for="consent-choice" class="form-label">Manifestação do paciente para este termo</label>
<select id="consent-choice" name="consent_choice" class="form-control">
<option value="not_collected">Não coletado — não registrar aceite</option>
<option value="granted">Paciente manifestou aceite deste termo</option>
<option value="declined">Paciente recusou este termo</option>
</select>
<p>Registre somente a manifestação efetivamente coletada do paciente. A escolha fica vinculada à versão do termo e ao profissional responsável.</p>
<?php else: ?>
<p>Nenhum termo de atendimento está ativo. Este cadastro não registra consentimento. Consulte o responsável por privacidade para definir o termo e a base aplicável.</p>
<?php endif; ?>
</fieldset>
