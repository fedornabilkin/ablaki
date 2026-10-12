(function ($) {
  'use strict';
  function enhance(root) {
    $(root).find('select.entity-search').each(function () {
      var field = $(this);
      if (field.hasClass('select2-hidden-accessible')) return;
      var config = { width: '100%', language: 'ru', placeholder: 'Выберите…', allowClear: !field.prop('required') && !field.prop('multiple'), dropdownParent: field.closest('.modal').length ? field.closest('.modal') : $(document.body) };
      if (field.data('lookup')) config.ajax = { url: field.data('lookup'), dataType: 'json', delay: 250, data: function (params) { return { q: params.term || '', page: params.page || 1 }; } };
      field.select2(config);
    });
    $(root).find('.content form').filter(function () { return this.method.toLowerCase() === 'post' && $(this).find('.form-group').length > 2 && !/login|register|security/i.test(this.id); }).addClass('admin-form-grid');
    $(root).find('.form-group').filter(function () { return $(this).find('textarea').length > 0; }).addClass('admin-wide');
    $(root).find('[role="radiogroup"]').addClass('admin-radio-list');
  }
  $(function () {
    enhance(document);
    try { if (window.innerWidth >= 768 && localStorage.getItem('ablaki.admin.sidebarCollapsed') === '1') $('body').addClass('sidebar-collapse'); } catch (_) {}
    $(document).on('collapsed.pushMenu expanded.pushMenu', function (event) {
      if (window.innerWidth < 768) return;
      try { localStorage.setItem('ablaki.admin.sidebarCollapsed', event.type === 'collapsed' ? '1' : '0'); } catch (_) {}
    });
  });
  $(document).on('pjax:end', function () { enhance(document); });
  window.ablakiAdminEnhance = enhance;
})(jQuery);
