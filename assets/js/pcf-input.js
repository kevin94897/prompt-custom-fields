/* Prompt Custom Fields — formularios de campos. */
(function ($) {
	'use strict';

	var i18n = (window.pcfInput && pcfInput.i18n) || {};
	var uid = 0;
	var newKey = function () {
		uid += 1;
		return 'row-n' + Date.now().toString(36) + uid;
	};

	/* ------------------------------------------------------------------
	 * Inicialización de campos dentro de un contenedor
	 * --------------------------------------------------------------- */

	function init($scope) {
		// Selector de color.
		if ($.fn.wpColorPicker) {
			$scope.find('input.pcf-color').each(function () {
				var $i = $(this);
				if ($i.closest('.wp-picker-container').length) return;
				$i.wpColorPicker({ change: function () { setTimeout(function () { $i.trigger('pcf:change'); }); } });
			});
		}

		// Editores WYSIWYG.
		$scope.find('textarea.pcf-wysiwyg').each(function () { initEditor(this); });

		// Vista previa de los campos de enlace (incluye filas nuevas de repetidor).
		$scope.find('[data-pcf-link]').each(function () {
			// La clase la pone el JS, no el servidor: sin script el campo se
			// queda con sus tres inputs visibles y sigue siendo editable.
			$(this).addClass('is-enhanced');
			linkPintar($(this));
		});

		// Ordenables.
		if ($.fn.sortable) {
			$scope.find('.pcf-rows').each(function () {
				var $rows = $(this);
				if ($rows.data('pcfSortable')) return;
				$rows.data('pcfSortable', true).sortable({
					items: '> .pcf-row',
					handle: '> .pcf-row-handle, > .pcf-fc-title',
					axis: 'y',
					tolerance: 'pointer',
					start: function (e, ui) { removeEditors(ui.item); },
					stop: function (e, ui) {
						ui.item.find('textarea.pcf-wysiwyg').each(function () { initEditor(this); });
						renumber($rows.parent());
					}
				});
			});
			$scope.find('.pcf-gallery-items, .pcf-rel-values').each(function () {
				var $l = $(this);
				if (!$l.data('pcfSortable')) $l.data('pcfSortable', true).sortable({ tolerance: 'pointer' });
			});
		}

		$scope.find('.pcf-repeater, .pcf-flexible').each(function () { renumber($(this)); });
		$scope.find('.pcf-range input[type=range]').trigger('input');
		layoutGroups($scope);
		conditions($scope);
	}

	/* ------------------------------------------------------------------
	 * WYSIWYG
	 * --------------------------------------------------------------- */

	function initEditor(el) {
		if (!window.wp || !wp.editor || !wp.editor.initialize || !el.id) return;
		if (window.tinymce && tinymce.get(el.id)) return;
		var $el = $(el);
		var tabs = $el.data('tabs') || 'all';
		var basic = $el.data('toolbar') === 'basic';
		var settings = {
			quicktags: tabs !== 'visual',
			mediaButtons: !!$el.data('media')
		};
		if (tabs !== 'text') {
			settings.tinymce = {
				wpautop: true,
				toolbar1: basic ? 'bold,italic,underline,blockquote,strikethrough,bullist,numlist,alignleft,aligncenter,alignright,undo,redo,link'
					: 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,wp_more,spellchecker,fullscreen,wp_adv',
				toolbar2: basic ? '' : 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help',
				setup: function (ed) {
					ed.on('change keyup NodeChange', function () { ed.save(); $el.trigger('pcf:change'); });
				}
			};
		}
		wp.editor.initialize(el.id, settings);
	}

	function removeEditors($scope) {
		if (!window.wp || !wp.editor || !wp.editor.remove) return;
		$scope.find('textarea.pcf-wysiwyg').each(function () {
			if (window.tinymce && tinymce.get(this.id)) tinymce.get(this.id).save();
			wp.editor.remove(this.id);
		});
	}

	/* ------------------------------------------------------------------
	 * Repeater / Flexible content
	 * --------------------------------------------------------------- */

	function directRows($container) {
		return $container.children('.pcf-rows').children('.pcf-row');
	}

	function renumber($container) {
		directRows($container).each(function (i) {
			$(this).children('.pcf-row-handle, .pcf-fc-title').find('.pcf-row-num').first().text(i + 1);
		});
		var max = parseInt($container.data('max'), 10) || 0;
		var count = directRows($container).length;
		$container.toggleClass('is-full', max > 0 && count >= max);
		$container.toggleClass('is-empty', count === 0);
	}

	function canAdd($container) {
		var max = parseInt($container.data('max'), 10) || 0;
		if (max > 0 && directRows($container).length >= max) {
			window.alert(i18n.maxRows || 'Máximo alcanzado');
			return false;
		}
		return true;
	}

	function insertRow($container, html, $after) {
		var $row = $(html.split($container.data('placeholder')).join(newKey()));
		if ($after && $after.length) $row.insertAfter($after);
		else $container.children('.pcf-rows').append($row);
		init($row);
		renumber($container);
		$row.find(':input:visible').first().trigger('focus');
		return $row;
	}

	$(document).on('click', '.pcf-add-row', function () {
		var $rep = $(this).closest('.pcf-repeater');
		if (!canAdd($rep)) return;
		insertRow($rep, $rep.children('template.pcf-row-template').html());
	});

	$(document).on('click', '.pcf-fc-open', function (e) {
		e.stopPropagation();
		var $menu = $(this).siblings('.pcf-fc-menu');
		$('.pcf-fc-menu').not($menu).prop('hidden', true);
		$menu.prop('hidden', !$menu.prop('hidden'));
	});
	$(document).on('click', function () { $('.pcf-fc-menu').prop('hidden', true); });

	$(document).on('click', '.pcf-add-layout', function () {
		var $fc = $(this).closest('.pcf-flexible');
		$(this).closest('.pcf-fc-menu').prop('hidden', true);
		if (!canAdd($fc)) return;
		var name = $(this).data('layout');
		var $tpl = $fc.children('template.pcf-layout-template').filter(function () { return $(this).data('layout') === name; });
		insertRow($fc, $tpl.html());
	});

	$(document).on('click', '.pcf-row-remove', function () {
		var $row = $(this).closest('.pcf-row');
		var $container = $row.closest('.pcf-repeater, .pcf-flexible');
		var min = parseInt($container.data('min'), 10) || 0;
		if (min && directRows($container).length <= min) {
			window.alert(i18n.minRows || 'Mínimo alcanzado');
			return;
		}
		removeEditors($row);
		$row.remove();
		renumber($container);
		conditions($container);
	});

	// Contraer: solo el contenido flexible, donde cada fila puede ser una
	// sección entera. El repetidor ya no imprime este botón.
	$(document).on('click', '.pcf-row-toggle', function () {
		$(this).closest('.pcf-row').toggleClass('is-collapsed');
	});

	$(document).on('click', '.pcf-row-duplicate', function () {
		var $row = $(this).closest('.pcf-row');
		var $container = $row.closest('.pcf-repeater, .pcf-flexible');
		if (!canAdd($container)) return;
		if (window.tinymce) $row.find('textarea.pcf-wysiwyg').each(function () { if (tinymce.get(this.id)) tinymce.get(this.id).save(); });

		var base = $container.children('input[type=hidden]').first().attr('name');
		var first = $row.find(':input[name^="' + base + '["]').first().attr('name') || '';
		var m = first.slice(base.length).match(/^\[([^\]]+)\]/);
		if (!m) return;
		var oldKey = m[1];
		var key = newKey();

		var $clone = $row.clone(false, false);
		// Limpiar widgets inicializados en el clon.
		$clone.find('.wp-picker-container').each(function () {
			var $input = $(this).find('input.pcf-color').detach();
			$(this).replaceWith($input.removeClass('wp-color-picker').show());
		});
		$clone.find('.wp-editor-wrap').each(function () {
			var $ta = $(this).find('textarea.pcf-wysiwyg').detach();
			$(this).replaceWith($ta.show().css('visibility', ''));
		});
		$clone.find('.ui-sortable').removeClass('ui-sortable').removeData('pcfSortable');

		var oldName = base + '[' + oldKey + ']';
		var newName = base + '[' + key + ']';
		var idify = function (s) { return 'pcf-' + s.replace(/[^a-z0-9_-]+/gi, '-').replace(/^-+|-+$/g, ''); };
		var oldId = idify(oldName);
		var newId = idify(newName);
		$clone.find('[name]').each(function () {
			this.name = this.name.split(oldName).join(newName);
		});
		$clone.find('[id]').each(function () { this.id = this.id.split(oldId).join(newId); });
		$clone.find('label[for]').each(function () { this.htmlFor = this.htmlFor.split(oldId).join(newId); });
		$clone.find('template').each(function () {
			this.innerHTML = this.innerHTML.split(oldName).join(newName).split(oldId).join(newId);
		});
		// Conservar selección de selects y radios (clone no copia el estado actual).
		var $src = $row.find('select');
		$clone.find('select').each(function (i) { $(this).val($src.eq(i).val()); });
		$clone.insertAfter($row);
		init($clone);
		renumber($container);
	});

	/* ------------------------------------------------------------------
	 * Medios
	 * --------------------------------------------------------------- */

	function libraryArgs($el, type) {
		var args = {};
		if (type === 'image') args.type = 'image';
		if ($el.data('library') === 'uploadedTo' && window.wp && wp.media.view.settings.post.id) {
			args.uploadedTo = wp.media.view.settings.post.id;
		}
		var mime = String($el.data('mime') || '').trim();
		if (mime && type !== 'image') {
			args.type = mime.split(',').map(function (x) { return x.trim(); });
		}
		return args;
	}

	$(document).on('click', '.pcf-media-select', function () {
		if (!window.wp || !wp.media) return;
		var $wrap = $(this).closest('.pcf-media');
		var type = $wrap.data('type');
		var frame = wp.media({
			title: type === 'image' ? i18n.selectImage : i18n.selectFile,
			button: { text: i18n.use || 'Usar' },
			library: libraryArgs($wrap, type),
			multiple: false
		});
		frame.on('select', function () {
			var a = frame.state().get('selection').first().toJSON();
			$wrap.children('input[type=hidden]').val(a.id).trigger('change');
			var html;
			if (type === 'image') {
				var src = (a.sizes && (a.sizes.medium || a.sizes.thumbnail || a.sizes.full) || a).url;
				html = $('<img>').attr({ src: src, alt: a.alt || '' });
			} else {
				html = $('<span class="pcf-file-name">').text(a.filename || a.title);
			}
			$wrap.find('.pcf-media-preview').empty().append(html);
			$wrap.addClass('has-value');
		});
		frame.open();
	});

	$(document).on('click', '.pcf-media-remove', function () {
		var $wrap = $(this).closest('.pcf-media');
		$wrap.children('input[type=hidden]').val('').trigger('change');
		$wrap.find('.pcf-media-preview').empty();
		$wrap.removeClass('has-value');
	});

	$(document).on('click', '.pcf-gallery-add', function () {
		if (!window.wp || !wp.media) return;
		var $g = $(this).closest('.pcf-gallery');
		var name = $g.data('name');
		var max = parseInt($g.data('max'), 10) || 0;
		var frame = wp.media({ title: i18n.selectImage, button: { text: i18n.use || 'Usar' }, library: libraryArgs($g, 'image'), multiple: 'add' });
		frame.on('select', function () {
			var $list = $g.find('.pcf-gallery-items');
			frame.state().get('selection').each(function (m) {
				var a = m.toJSON();
				if ($list.children('[data-id="' + a.id + '"]').length) return;
				if (max && $list.children().length >= max) return;
				var src = (a.sizes && (a.sizes.thumbnail || a.sizes.full) || a).url;
				var $li = $('<li>').attr('data-id', a.id)
					.append($('<input type="hidden">').attr('name', name + '[]').val(a.id))
					.append($('<img>').attr({ src: src, alt: '' }))
					.append('<button type="button" class="pcf-gallery-remove" aria-label="Quitar">&times;</button>');
				if ($g.data('insert') === 'prepend') $list.prepend($li); else $list.append($li);
			});
		});
		frame.open();
	});

	$(document).on('click', '.pcf-gallery-remove', function () {
		$(this).closest('li').remove();
	});

	/* ------------------------------------------------------------------
	 * Relación
	 * --------------------------------------------------------------- */

	$(document).on('input', '.pcf-rel-search', function () {
		var q = $(this).val().toLowerCase();
		$(this).siblings('.pcf-rel-choices').children().each(function () {
			$(this).toggle($(this).text().toLowerCase().indexOf(q) !== -1);
		});
	});

	$(document).on('click', '.pcf-rel-choices > li:not(.is-disabled)', function () {
		var $rel = $(this).closest('.pcf-relationship');
		var $values = $rel.find('.pcf-rel-values');
		var max = parseInt($rel.data('max'), 10) || 0;
		if (max && $values.children().length >= max) {
			window.alert(i18n.maxRows || 'Máximo alcanzado');
			return;
		}
		var id = $(this).data('id');
		$values.append(
			$('<li>').attr('data-id', id)
				.append($('<input type="hidden">').attr('name', $rel.data('name') + '[]').val(id))
				.append($('<span>').text($(this).children('span').text()))
				.append(' <button type="button" class="pcf-rel-remove" aria-label="Quitar">&times;</button>')
		);
		$(this).addClass('is-disabled');
	});

	$(document).on('click', '.pcf-rel-remove', function () {
		var $li = $(this).closest('li');
		$li.closest('.pcf-relationship').find('.pcf-rel-choices > li[data-id="' + $li.data('id') + '"]').removeClass('is-disabled');
		$li.remove();
	});

	/* ------------------------------------------------------------------
	 * Pequeños comportamientos
	 * --------------------------------------------------------------- */

	$(document).on('input', '.pcf-range input[type=range]', function () {
		$(this).closest('.pcf-range').find('output').text(this.value);
	});

	$(document).on('change', '.pcf-toggle-all', function () {
		$(this).closest('.pcf-choice-list').find('input[type=checkbox]').not(this).prop('checked', this.checked).trigger('change');
	});

	$(document).on('click', '.pcf-add-custom', function () {
		var $b = $(this);
		var $li = $('<li><label><input type="' + $b.data('type') + '" checked> <input type="text" class="pcf-custom-value"></label></li>');
		$li.find('input').first().attr('name', $b.data('name'));
		$b.closest('.pcf-input').find('.pcf-choice-list').append($li);
		$li.find('.pcf-custom-value').trigger('focus');
	});

	$(document).on('input', '.pcf-custom-value', function () {
		$(this).siblings('input').first().val(this.value);
	});

	/* ------------------------------------------------------------------
	 * Pestañas y acordeones
	 * --------------------------------------------------------------- */

	function layoutGroups($scope) {
		$scope.find('.pcf-fields, .pcf-row-fields, .pcf-group').addBack('.pcf-fields, .pcf-row-fields, .pcf-group').each(function () {
			var $c = $(this);
			if ($c.data('pcfGrouped')) return;
			if ($c.closest('template').length) return;
			var $markers = $c.children('.pcf-field-tab, .pcf-field-accordion');
			// La clase la lee el CSS para no enseñar todas las pestañas a la vez
			// mientras este script todavía no ha corrido (ver pcf-input.css).
			$c.data('pcfGrouped', true).addClass('pcf-grouped');
			if (!$markers.length) return;
			buildTabs($c);
			buildAccordions($c);
		});
	}

	function buildTabs($c) {
		var $children = $c.children();
		var groups = [];
		var current = null;
		$children.each(function () {
			var $el = $(this);
			if ($el.hasClass('pcf-field-tab')) {
				if (!current || $el.data('endpoint')) {
					current = { $first: $el, tabs: [], left: $el.data('placement') === 'left' };
					groups.push(current);
				}
				current.tabs.push({ $marker: $el, $fields: $() });
				return;
			}
			if (current && current.tabs.length && !$el.hasClass('pcf-tab-wrap')) {
				current.tabs[current.tabs.length - 1].$fields = current.tabs[current.tabs.length - 1].$fields.add($el);
			}
		});
		groups.forEach(function (g) {
			var $wrap = $('<div class="pcf-tab-wrap">').toggleClass('is-left', g.left);
			var $nav = $('<div class="pcf-tab-nav" role="tablist">');
			$wrap.append($nav);
			g.$first.before($wrap);
			var selected = 0;
			g.tabs.forEach(function (t, i) { if (t.$marker.data('open')) selected = i; });
			g.tabs.forEach(function (t) {
				var $pane = $('<div class="pcf-tab-pane" role="tabpanel">').append(t.$fields);
				var $btn = $('<button type="button" role="tab" class="pcf-tab-btn">').text(t.$marker.find('.pcf-marker-label').text());
				$btn.on('click', function () {
					$nav.children().removeClass('is-active').attr('aria-selected', 'false');
					$btn.addClass('is-active').attr('aria-selected', 'true');
					$wrap.children('.pcf-tab-pane').prop('hidden', true);
					$pane.prop('hidden', false);
				});
				$nav.append($btn);
				$wrap.append($pane);
				t.$marker.addClass('pcf-marker-used').hide().prependTo($pane);
			});
			// La pestaña activa se marca al final, no dentro del bucle: el
			// manejador oculta las hermanas que ya estén montadas, así que
			// hacerlo en la primera vuelta dejaba visibles las que faltaban
			// por crear (se veían todas apiladas hasta el primer clic).
			$nav.children().eq(selected).trigger('click');
			// Si la pestaña queda oculta por lógica condicional, ocultar también el botón.
			g.tabs.forEach(function (t, i) {
				t.$marker.on('pcf:visibility', function (e, visible) {
					$nav.children().eq(i).toggle(visible);
				});
			});
		});
	}

	function buildAccordions($c) {
		var $children = $c.children();
		var current = null;
		$children.each(function () {
			var $el = $(this);
			if ($el.hasClass('pcf-field-accordion')) {
				if ($el.data('endpoint')) { current = null; $el.hide(); return; }
				var $acc = $('<div class="pcf-accordion">');
				var $btn = $('<button type="button" class="pcf-accordion-title" aria-expanded="false">').text($el.find('.pcf-marker-label').text());
				var $body = $('<div class="pcf-accordion-body" hidden>');
				$acc.append($btn, $body);
				$el.before($acc);
				$el.addClass('pcf-marker-used').hide().prependTo($body);
				var multi = !!$el.data('multi');
				$btn.on('click', function () {
					var open = $body.prop('hidden');
					if (open && !multi) {
						$acc.siblings('.pcf-accordion').each(function () {
							$(this).children('.pcf-accordion-body').prop('hidden', true);
							$(this).children('.pcf-accordion-title').attr('aria-expanded', 'false');
						});
					}
					$body.prop('hidden', !open);
					$btn.attr('aria-expanded', open ? 'true' : 'false');
				});
				if ($el.data('open')) $btn.trigger('click');
				current = $body;
				return;
			}
			if (current && !$el.is('.pcf-accordion, .pcf-tab-wrap')) current.append($el);
		});
	}

	/* ------------------------------------------------------------------
	 * Lógica condicional
	 * --------------------------------------------------------------- */

	function ownInputs($field) {
		return $field.find(':input').filter(function () {
			return $(this).closest('.pcf-field')[0] === $field[0] && !$(this).closest('template').length;
		});
	}

	function fieldValue($field) {
		var type = $field.data('type');
		var $inputs = ownInputs($field);
		if (type === 'true_false') return $inputs.filter(':checkbox').is(':checked') ? '1' : '0';
		if (type === 'checkbox' || type === 'radio' || type === 'button_group' || type === 'taxonomy') {
			var vals = $inputs.filter(':checked').map(function () { return this.value; }).get();
			if ($inputs.filter('select').length) vals = [].concat($inputs.filter('select').val() || []);
			return vals;
		}
		var $sel = $inputs.filter('select');
		if ($sel.length) return $sel.val();
		var $vis = $inputs.not('[type=hidden]');
		if ($vis.length) return $vis.first().val();
		return $inputs.last().val();
	}

	function findTarget($field, key) {
		var $scope = $field.parent();
		while ($scope.length) {
			var $t = $scope.find('.pcf-field[data-key="' + key + '"]').filter(function () { return !$(this).closest('template').length; }).first();
			if ($t.length) return $t;
			if ($scope.is('form, body')) break;
			$scope = $scope.parent();
		}
		return $();
	}

	function matchRule(value, rule) {
		var list = Array.isArray(value) ? value.map(String) : (value === null || value === undefined ? [] : [String(value)]);
		var str = list.join(',');
		var empty = !list.length || (list.length === 1 && list[0] === '');
		var v = String(rule.value);
		switch (rule.operator) {
			case '==': return list.indexOf(v) !== -1 || (v === '' && empty);
			case '!=': return list.indexOf(v) === -1 && !(v === '' && empty);
			case '==empty': return empty;
			case '!=empty': return !empty;
			case '==contains': return str.indexOf(v) !== -1;
			case '!=contains': return str.indexOf(v) === -1;
			case '==pattern': try { return new RegExp(v).test(str); } catch (e) { return false; }
			case '>': return parseFloat(str) > parseFloat(v);
			case '<': return parseFloat(str) < parseFloat(v);
			default: return false;
		}
	}

	function conditions($scope) {
		var $root = $scope.closest('form').length ? $scope.closest('form') : $(document.body);
		$root.find('.pcf-field[data-conditions]').filter(function () { return !$(this).closest('template').length; }).each(function () {
			var $f = $(this);
			var groups = $f.data('conditions');
			if (typeof groups === 'string') { try { groups = JSON.parse(groups); } catch (e) { return; } }
			if (!Array.isArray(groups)) return;
			var visible = groups.some(function (and) {
				return and.every(function (rule) {
					var $t = findTarget($f, rule.field);
					if (!$t.length) return false;
					if ($t.hasClass('pcf-hidden')) return false;
					return matchRule(fieldValue($t), rule);
				});
			});
			if (visible === !$f.hasClass('pcf-hidden')) return;
			$f.toggleClass('pcf-hidden', !visible);
			$f.find(':input').not(function () { return $(this).closest('template').length; }).prop('disabled', !visible);
			if (visible) $f.find('.pcf-hidden :input').prop('disabled', true);
			$f.trigger('pcf:visibility', [visible]);
		});
	}

	var timer = null;
	$(document).on('change input pcf:change', '.pcf-field :input, .pcf-field', function (e) {
		if (e.type === 'input' && !$(e.target).is('input[type=text], input[type=number], input[type=range], input[type=email], input[type=url], textarea')) return;
		clearTimeout(timer);
		var $t = $(this);
		timer = setTimeout(function () { conditions($t); }, 60);
	});

	/* ------------------------------------------------------------------
	 * Enlace (campo link) — modal nativo de WordPress
	 *
	 * Reutiliza wpLink, el mismo "Insertar/editar enlace" del editor: trae
	 * gratis el buscador de contenido publicado, la lista de recientes y la
	 * casilla de pestaña nueva. Los valores siguen viviendo en los inputs del
	 * campo; el modal solo los rellena.
	 * --------------------------------------------------------------- */

	var linkActivo = null;

	function linkPartes($field) {
		return {
			url: $field.find('[data-pcf-link-url]'),
			title: $field.find('[data-pcf-link-title]'),
			target: $field.find('[data-pcf-link-target]')
		};
	}

	// Refresca la vista previa a partir de los inputs (la fuente de verdad).
	function linkPintar($field) {
		var p = linkPartes($field);
		var url = $.trim(p.url.val() || '');
		var titulo = $.trim(p.title.val() || '');
		var blank = p.target.is(':checked');

		$field.toggleClass('has-value', url !== '');
		$field.find('[data-pcf-link-label]').text(titulo || url);
		$field.find('[data-pcf-link-href]').text(url);
		$field.find('[data-pcf-link-blank]').prop('hidden', !blank);
	}

	// wpLink necesita un textarea donde "insertar" el enlace; le damos uno
	// oculto y descartamos lo que escriba: nosotros leemos el formulario.
	function linkTextarea() {
		if (!$('#pcf-link-textarea').length) {
			$('<textarea id="pcf-link-textarea" style="display:none" aria-hidden="true"></textarea>').appendTo('body');
		}
		return 'pcf-link-textarea';
	}

	// Se envuelve wpLink.update una sola vez: cuando el modal lo abrió un
	// campo PCF guardamos nosotros, y si lo abrió un WYSIWYG se delega en el
	// comportamiento original.
	function linkEngancharUpdate() {
		if (!window.wpLink || wpLink.__pcfPatched) return;
		var original = wpLink.update;
		wpLink.update = function () {
			if (!linkActivo) return original.apply(this, arguments);

			var $field = linkActivo;
			var p = linkPartes($field);
			p.url.val($.trim($('#wp-link-url').val() || ''));
			p.title.val($.trim($('#wp-link-text').val() || ''));
			p.target.prop('checked', $('#wp-link-target').is(':checked'));

			linkPintar($field);
			p.url.trigger('change');
			wpLink.close();
		};
		wpLink.__pcfPatched = true;
	}

	$(document).on('click', '[data-pcf-link-edit]', function (e) {
		e.preventDefault();
		if (!window.wpLink) return;

		var $field = $(this).closest('[data-pcf-link]');
		var p = linkPartes($field);

		linkEngancharUpdate();
		linkActivo = $field;

		window.wpActiveEditor = linkTextarea();
		wpLink.open(window.wpActiveEditor, p.url.val() || '', p.title.val() || '');

		// wpLink rellena según su propia heurística: se imponen los valores
		// del campo después de abrir.
		$('#wp-link-url').val(p.url.val() || '');
		$('#wp-link-text').val(p.title.val() || '');
		$('#wp-link-target').prop('checked', p.target.is(':checked'));
	});

	$(document).on('wplink-close', function () { linkActivo = null; });

	$(document).on('click', '[data-pcf-link-clear]', function (e) {
		e.preventDefault();
		var $field = $(this).closest('[data-pcf-link]');
		var p = linkPartes($field);
		p.url.val('');
		p.title.val('');
		p.target.prop('checked', false);
		linkPintar($field);
		p.url.trigger('change');
	});

	// Sin JS del modal (o editando los inputs a mano) la vista previa sigue
	// al día.
	$(document).on('input change', '[data-pcf-link-url], [data-pcf-link-title], [data-pcf-link-target]', function () {
		linkPintar($(this).closest('[data-pcf-link]'));
	});

	/* ------------------------------------------------------------------
	 * Arranque
	 * --------------------------------------------------------------- */

	window.pcf = window.pcf || {};
	window.pcf.init = init;

	$(function () {
		$('.pcf-root').each(function () { init($(this)); });
		// Sincronizar TinyMCE antes de guardar (editor clásico, opciones, términos).
		$(document).on('submit', 'form', function () { if (window.tinymce) tinymce.triggerSave(); });
		// Editor de bloques: meta boxes se guardan por AJAX.
		if (window.wp && wp.data && wp.data.subscribe && wp.data.select('core/edit-post')) {
			var wasSaving = false;
			wp.data.subscribe(function () {
				var ed = wp.data.select('core/edit-post');
				var saving = ed && ed.isSavingMetaBoxes && ed.isSavingMetaBoxes();
				if (saving && !wasSaving && window.tinymce) tinymce.triggerSave();
				wasSaving = saving;
			});
		}
	});
})(jQuery);
