(() => {
  'use strict';
  const review = document.querySelector('[data-public-review]');
  if (!review) return;
  const fields = [['name','Nome não informado'], ['specialty','Área de atuação não informada'], ['bio','Apresentação profissional ainda não confirmada. Consulte o profissional para conhecer sua atuação.']];
  fields.forEach(([name, empty]) => {
    const input = document.getElementById('profile-' + name);
    const preview = review.querySelector('[data-public-preview-' + name + ']');
    if (!input || !preview) return;
    input.addEventListener('input', () => {
      preview.textContent = input.value.trim() || empty;
      review.querySelector('[data-public-confirm]').checked = false;
    });
  });
})();
