<?php $rxPatient=(int)$data['patient']['id']; ?>
<section class="card" data-testid="prescriptions-unavailable">
  <h1>Recurso ainda não disponível neste consultório</h1>
  <p>Você pode registrar as orientações para o paciente no prontuário.</p>
  <p><a class="btn btn-secondary" href="/pacientes/<?= $rxPatient ?>/documentos">Voltar a Documentos</a></p>
  <p><a class="btn btn-outline" href="/pacientes/<?= $rxPatient ?>/prontuario">Registrar orientação em texto no prontuário</a></p>
</section>
