(function ($) {
  'use strict';
  $(function () {
    var element = document.getElementById('admin-template-data');
    if (element) {
      var data = JSON.parse(element.textContent), form = $(element).closest('form'), body = form.find('.admin-json-table tbody');
      var rowSequence = 0;
      function sync() {
        var materials = Object.create(null), defaults = Object.assign(Object.create(null), data.defaults), tools = Object.create(null), stations = Object.create(null);
        body.find('tr').each(function () {
          var row = $(this), role = row.find('.requirement-role:checked').val(), code = row.find('.requirement-item').val(), quantity = Number(row.find('.requirement-quantity').val());
          if (!code) return;
          var target = role === 'material' ? materials : role === 'tool' ? tools : stations;
          target[code] = (target[code] || 0) + quantity;
        });
        form.find('[data-setting]').each(function () {
          var setting = $(this), inputs = setting.find('input'), value = inputs.first().attr('type') === 'radio' ? inputs.filter(':checked').val() : inputs.val();
          var key = setting.data('setting');
          if (value === '' || value === undefined) delete defaults[key];
          else defaults[key] = setting.data('type') === 'number' ? Number(value) : setting.data('type') === 'boolean' ? value === 'true' : value;
        });
        if (Object.keys(tools).length) defaults.build_tools = tools; else delete defaults.build_tools;
        if (Object.keys(stations).length) defaults.build_stations = stations; else delete defaults.build_stations;
        form.find('[name="NodeTemplate[materials_json]"]').val(JSON.stringify(materials, null, 2));
        form.find('[name="NodeTemplate[defaults_json]"]').val(JSON.stringify(defaults, null, 2));
      }
      function add(role, code, quantity) {
        var row = $('<tr>'), roles = $('<div class="admin-radio-list">'), roleName = 'requirement-role-' + (++rowSequence);
        Object.entries({ material: 'Материал', tool: 'Инструмент', station: 'Станция' }).forEach(function (entry) { roles.append($('<label>').append($('<input type="radio" class="requirement-role">').attr('name', roleName).val(entry[0]).prop('checked', entry[0] === role), document.createTextNode(' ' + entry[1]))); });
        var items = $('<select class="form-control entity-search requirement-item">'), count = $('<input class="form-control requirement-quantity" type="number" min="1" max="10000" required>').val(quantity || 1);
        var remove = $('<button type="button" class="btn btn-default" aria-label="Удалить строку">').text('×').on('click', function () { row.remove(); sync(); });
        row.append($('<td>').append(roles), $('<td>').append(items), $('<td>').append(count), $('<td>').append(remove)); body.append(row);
        function choices(selected) {
          if (items.hasClass('select2-hidden-accessible')) items.select2('destroy');
          items.empty().append($('<option value="">').text('Выберите предмет'));
          var selectedRole = roles.find(':checked').val();
          data.catalog.filter(function (item) { return item.kind === selectedRole; }).forEach(function (item) { items.append($('<option>').val(item.code).text(item.name)); });
          if (selected && !items.find('option').toArray().some(function (option) { return option.value === selected; })) items.append($('<option>').val(selected).text(selected + ' (недоступен в каталоге)'));
          items.val(selected || ''); count.attr('max', selectedRole === 'material' ? 10000 : 100); window.ablakiAdminEnhance(row);
        }
        roles.on('change', function () { choices(''); sync(); }); items.on('change', sync); count.on('input', sync); choices(code);
      }
      var maps = { material: data.materials || {}, tool: data.defaults.build_tools || {}, station: data.defaults.build_stations || {} };
      Object.keys(maps).forEach(function (role) { Object.keys(maps[role]).forEach(function (code) { add(role, code, maps[role][code]); }); });
      form.find('.admin-requirement-add').on('click', function () { add('material', '', 1); });
      form.find('[data-setting] input').on('input change', sync); form.on('submit beforeSubmit', sync);
      var parents = form.find('.admin-parent-tree input[type="checkbox"]'), kinds = form.find('[name="NodeTemplate[kind]"]'), level = form.find('[name="NodeTemplate[hierarchy_level]"]');
      function hierarchy() {
        var kind = kinds.filter(':checked').val(), allowed = kind === 'WORLD' ? [] : [1, 2, 3, 4, 5, 6];
        parents.filter(':checked').each(function () { if (allowed.indexOf(Number($(this).data('level'))) < 0) $(this).prop('checked', false); });
        var chosen = parents.filter(':checked').first(), chosenLevel = chosen.length ? Number(chosen.data('level')) : null;
        parents.each(function () { var field = $(this), disabled = allowed.indexOf(Number(field.data('level'))) < 0 || (chosenLevel !== null && Number(field.data('level')) !== chosenLevel); field.prop('disabled', disabled).closest('label').toggleClass('disabled', disabled); });
        level.val(kind === 'WORLD' ? 1 : chosenLevel === null ? '' : chosenLevel + 1);
      }
      parents.on('change', hierarchy); kinds.on('change', hierarchy); hierarchy(); sync();
    }
    $('.admin-node-cells').each(function () {
      var box = $(this), form = box.closest('form'), parent = form.find('[name="Node[parent_id]"]'), selected = String(box.data('selected')), grid = box.find('.admin-map-cells'), status = box.find('.admin-cells-status'), more = box.find('.admin-cells-more'), choice = form.find('[name="Node[cellChoice]"]');
      var epoch = 0, page = 1, loading = false;
      function load(append) {
        var id = parent.val(), current = ++epoch; loading = true; more.prop('hidden', true);
        if (!append) { page = 1; grid.empty(); }
        if (!id) { loading = false; status.text('Сначала выберите родителя.'); return; }
        status.text('Загрузка ячеек…');
        $.getJSON(box.data('cells-url'), { parent: id, except: box.data('except') || '', page: page }).done(function (result) {
          if (current !== epoch) return;
          result.cells.forEach(function (cell) {
            var radio = $('<input type="radio" name="admin-cell">').val(cell.key).prop('disabled', cell.occupied).prop('checked', !cell.occupied && cell.key === selected);
            var label = $('<label>').toggleClass('occupied', cell.occupied).attr('title', cell.occupied ? 'Занято: ' + cell.name : 'Свободная ячейка');
            label.append(radio, document.createTextNode(' ' + cell.x + ', ' + cell.y)); grid.append(label);
            if (radio.prop('checked')) { choice.val(cell.key); label[0].scrollIntoView({ block: 'nearest' }); }
            radio.on('change', function () { selected = cell.key; choice.val(cell.key).trigger('change'); });
          });
          more.prop('hidden', !result.more); status.text(result.cells.length ? '' : 'Нет ячеек для отображения.');
        }).fail(function () { if (current === epoch) status.text('Не удалось загрузить ячейки. Повторно выберите родителя.'); }).always(function () { if (current === epoch) loading = false; });
      }
      parent.on('change', function () { selected = ''; choice.val(''); load(false); });
      more.on('click', function () { if (!loading) { page++; load(true); } }); load(false);
    });
  });
})(jQuery);
