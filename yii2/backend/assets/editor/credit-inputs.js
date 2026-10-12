// Formatting an unchanged field must not change its precise amount on submission.
(function () {
  'use strict';
  document.addEventListener('input', function (event) {
    if (event.target.matches('input[data-credit-original]')) event.target.dataset.creditEdited = 'true';
  });
  document.addEventListener('formdata', function (event) {
    event.target.querySelectorAll('input[data-credit-original]').forEach(function (input) {
      if (!input.disabled && input.name && !input.dataset.creditEdited && input.value === input.dataset.creditDisplay) {
        event.formData.set(input.name, input.dataset.creditOriginal);
      }
    });
  });
})();
