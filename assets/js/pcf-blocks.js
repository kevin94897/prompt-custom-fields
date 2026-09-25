/* Prompt Custom Fields — bloques en el editor. Sin compilación: wp.element.createElement. */
(function (wp) {
	'use strict';
	if (!wp || !wp.blocks || !window.pcfBlocks) return;

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var be = wp.blockEditor || wp.editor;
	var c = wp.components;
	var __ = (wp.i18n && wp.i18n.__) || function (s) { return s; };
	var ServerSideRender = wp.serverSideRender;
	var SKIP = ['tab', 'accordion', 'message', 'separator'];

	/* ------------------------------------------------------------------
	 * Formato de datos (idéntico a ACF: nombre + _nombre)
	 * --------------------------------------------------------------- */

	function findLayout(field, name) {
		return (field.layouts || []).filter(function (l) { return l.name === name; })[0];
	}

	function flatten(fields, values, prefix, out) {
		values = values || {};
		fields.forEach(function (f) {
			if (SKIP.indexOf(f.type) !== -1 || !f.name) return;
			var name = prefix ? prefix + '_' + f.name : f.name;
			var v = values[f.name];
			out['_' + name] = f.key;
			if (f.type === 'repeater') {
				var rows = Array.isArray(v) ? v : [];
				out[name] = rows.length;
				rows.forEach(function (r, i) { flatten(f.sub_fields || [], r, name + '_' + i, out); });
			} else if (f.type === 'flexible_content') {
				var fr = Array.isArray(v) ? v : [];
				out[name] = fr.map(function (r) { return r.acf_fc_layout; });
				fr.forEach(function (r, i) {
					var l = findLayout(f, r.acf_fc_layout);
					if (l) flatten(l.sub_fields || [], r, name + '_' + i, out);
				});
			} else if (f.type === 'group') {
				out[name] = '';
				flatten(f.sub_fields || [], v, name, out);
			} else {
				out[name] = v === undefined ? '' : v;
			}
		});
		return out;
	}

	function unflatten(fields, data, prefix) {
		var obj = {};
		fields.forEach(function (f) {
			if (SKIP.indexOf(f.type) !== -1 || !f.name) return;
			var name = prefix ? prefix + '_' + f.name : f.name;
			var raw = data[name];
			if (f.type === 'repeater') {
				var n = Array.isArray(raw) ? raw.length : parseInt(raw, 10) || 0;
				obj[f.name] = [];
				for (var i = 0; i < n; i++) obj[f.name].push(unflatten(f.sub_fields || [], data, name + '_' + i));
			} else if (f.type === 'flexible_content') {
				obj[f.name] = (Array.isArray(raw) ? raw : []).map(function (ln, j) {
					var l = findLayout(f, ln);
					var row = l ? unflatten(l.sub_fields || [], data, name + '_' + j) : {};
					row.acf_fc_layout = ln;
					return row;
				});
			} else if (f.type === 'group') {
				obj[f.name] = unflatten(f.sub_fields || [], data, name);
			} else if (raw !== undefined) {
				obj[f.name] = raw;
			} else if (f.default_value !== undefined && f.default_value !== '') {
				obj[f.name] = f.default_value;
			}
		});
		return obj;
	}

	/* ------------------------------------------------------------------
	 * Controles
	 * --------------------------------------------------------------- */

	function options(choices, empty) {
		var list = empty ? [{ value: '', label: '—' }] : [];
		Object.keys(choices || {}).forEach(function (k) { list.push({ value: k, label: String(choices[k]) }); });
		return list;
	}

	function asArray(v) {
		if (Array.isArray(v)) return v.map(String);
		if (v === undefined || v === null || v === '') return [];
		return [String(v)];
	}

	function MediaField(props) {
		var f = props.field;
		var multiple = f.type === 'gallery';
		var ids = multiple ? asArray(props.value).map(Number) : (props.value ? [Number(props.value)] : []);
		var media = wp.data.useSelect(function (select) {
			return ids.map(function (id) { return select('core').getMedia(id); });
		}, [ids.join(',')]);
		var allowed = f.type === 'file' ? undefined : ['image'];
		return el('div', { className: 'pcf-block-media' },
			el('div', { className: 'pcf-block-media-list' },
				media.map(function (m, i) {
					if (!m) return el(c.Spinner, { key: i });
					var src = m.media_details && m.media_details.sizes && m.media_details.sizes.thumbnail ? m.media_details.sizes.thumbnail.source_url : m.source_url;
					return m.media_type === 'image'
						? el('img', { key: m.id, src: src, alt: m.alt_text || '' })
						: el('span', { key: m.id, className: 'pcf-block-file' }, m.title && m.title.rendered);
				})
			),
			el(be.MediaUploadCheck, null,
				el(be.MediaUpload, {
					allowedTypes: allowed,
					multiple: multiple ? 'add' : false,
					gallery: multiple,
					value: multiple ? ids : ids[0],
					onSelect: function (sel) {
						if (multiple) props.onChange(sel.map(function (s) { return String(s.id); }));
						else props.onChange(String(sel.id));
					},
					render: function (o) {
						return el(c.Button, { variant: 'secondary', onClick: o.open }, ids.length ? __('Cambiar') : __('Seleccionar'));
					}
				})
			),
			ids.length ? el(c.Button, { variant: 'link', isDestructive: true, onClick: function () { props.onChange(multiple ? [] : ''); } }, __('Quitar')) : null
		);
	}

	function Rows(props) {
		var f = props.field;
		var rows = Array.isArray(props.value) ? props.value : [];
		var isFlex = f.type === 'flexible_content';
		var max = parseInt(f.max, 10) || 0;
		var set = function (next) { props.onChange(next); };
		var move = function (i, d) {
			var next = rows.slice();
			var t = next[i + d];
			if (t === undefined) return;
			next[i + d] = next[i];
			next[i] = t;
			set(next);
		};
		var canAdd = !max || rows.length < max;

		return el('div', { className: 'pcf-block-rows' },
			rows.map(function (row, i) {
				var sub = isFlex ? ((findLayout(f, row.acf_fc_layout) || {}).sub_fields || []) : (f.sub_fields || []);
				var title = (i + 1) + (isFlex ? ' · ' + ((findLayout(f, row.acf_fc_layout) || {}).label || row.acf_fc_layout) : '');
				return el('div', { className: 'pcf-block-row', key: i },
					el('div', { className: 'pcf-block-row-head' },
						el('strong', null, title),
						el('span', null,
							el(c.Button, { icon: 'arrow-up-alt2', label: __('Subir'), size: 'small', disabled: i === 0, onClick: function () { move(i, -1); } }),
							el(c.Button, { icon: 'arrow-down-alt2', label: __('Bajar'), size: 'small', disabled: i === rows.length - 1, onClick: function () { move(i, 1); } }),
							el(c.Button, { icon: 'trash', label: __('Eliminar'), size: 'small', isDestructive: true, onClick: function () { set(rows.filter(function (_, j) { return j !== i; })); } })
						)
					),
					el(Fields, {
						fields: sub,
						values: row,
						onChange: function (vals) {
							var next = rows.slice();
							next[i] = Object.assign({}, vals, isFlex ? { acf_fc_layout: row.acf_fc_layout } : {});
							set(next);
						}
					})
				);
			}),
			canAdd ? (isFlex
				? el(c.DropdownMenu, {
					icon: 'plus',
					text: f.button_label || __('Añadir sección'),
					label: f.button_label || __('Añadir sección'),
					controls: (f.layouts || []).map(function (l) {
						return { title: l.label, onClick: function () { set(rows.concat([{ acf_fc_layout: l.name }])); } };
					})
				})
				: el(c.Button, { variant: 'secondary', onClick: function () { set(rows.concat([{}])); } }, f.button_label || __('Añadir fila'))
			) : null
		);
	}

	function Field(props) {
		var f = props.field;
		var v = props.value;
		var on = props.onChange;
		var label = f.label + (parseInt(f.required, 10) ? ' *' : '');
		var help = f.instructions || undefined;
		var multi = !!parseInt(f.multiple, 10);

		switch (f.type) {
			case 'tab':
			case 'accordion':
				return el('h3', { className: 'pcf-block-heading' }, f.label);
			case 'message':
				return el('p', { className: 'pcf-block-message' }, f.message);
			case 'separator':
				return el('hr');
			case 'textarea':
			case 'wysiwyg':
				return el(c.TextareaControl, { label: label, help: f.type === 'wysiwyg' ? (help || __('Admite HTML.')) : help, value: v || '', rows: f.type === 'wysiwyg' ? 6 : 4, onChange: on });
			case 'number':
				return el(c.TextControl, { type: 'number', label: label, help: help, value: v === undefined ? '' : v, min: f.min, max: f.max, step: f.step, onChange: on });
			case 'range':
				return el(c.RangeControl, { label: label, help: help, value: v === '' || v === undefined ? undefined : Number(v), min: f.min === '' ? 0 : Number(f.min), max: f.max === '' ? 100 : Number(f.max), step: f.step === '' ? 1 : Number(f.step), onChange: function (n) { on(n === undefined ? '' : String(n)); } });
			case 'true_false':
				return el(c.ToggleControl, { label: label, help: help || f.message, checked: v === true || v === '1' || v === 1, onChange: function (b) { on(b ? '1' : '0'); } });
			case 'select':
			case 'post_object':
			case 'page_link':
			case 'user':
				return el(c.SelectControl, { label: label, help: help, multiple: multi, value: multi ? asArray(v) : (v || ''), options: options(f.choices, !multi), onChange: on });
			case 'relationship':
				return el(c.SelectControl, { label: label, help: help || __('Ctrl/Cmd para seleccionar varios.'), multiple: true, value: asArray(v), options: options(f.choices, false), onChange: on });
			case 'taxonomy':
				var tmulti = f.field_type === 'checkbox' || f.field_type === 'multi_select';
				return el(c.SelectControl, { label: label, help: help, multiple: tmulti, value: tmulti ? asArray(v) : (v || ''), options: options(f.choices, !tmulti), onChange: on });
			case 'radio':
			case 'button_group':
				return el(c.RadioControl, { label: label, help: help, selected: v || '', options: options(f.choices, false), onChange: on });
			case 'checkbox':
				var vals = asArray(v);
				return el(c.BaseControl, { label: label, help: help },
					options(f.choices, false).map(function (o) {
						return el(c.CheckboxControl, {
							key: o.value,
							label: o.label,
							checked: vals.indexOf(o.value) !== -1,
							onChange: function (b) {
								var next = vals.filter(function (x) { return x !== o.value; });
								if (b) next.push(o.value);
								on(next);
							}
						});
					})
				);
			case 'color_picker':
				return el(c.BaseControl, { label: label, help: help },
					el(c.ColorPalette, { value: v || undefined, colors: [], enableAlpha: !!parseInt(f.enable_opacity, 10), clearable: true, onChange: function (col) { on(col || ''); } })
				);
			case 'date_picker':
				var d = String(v || '');
				var shown = /^\d{8}$/.test(d) ? d.slice(0, 4) + '-' + d.slice(4, 6) + '-' + d.slice(6, 8) : d;
				return el(c.TextControl, { type: 'date', label: label, help: help, value: shown, onChange: function (x) { on(x ? x.replace(/-/g, '') : ''); } });
			case 'date_time_picker':
				return el(c.TextControl, { type: 'datetime-local', label: label, help: help, value: String(v || '').replace(' ', 'T'), onChange: function (x) { on(x ? x.replace('T', ' ') + (x.length === 16 ? ':00' : '') : ''); } });
			case 'time_picker':
				return el(c.TextControl, { type: 'time', step: 1, label: label, help: help, value: v || '', onChange: on });
			case 'image':
			case 'file':
			case 'gallery':
				return el(c.BaseControl, { label: label, help: help }, el(MediaField, { field: f, value: v, onChange: on }));
			case 'link':
				var lk = v && typeof v === 'object' ? v : { url: v || '', title: '', target: '' };
				var upd = function (k) { return function (x) { var n = Object.assign({ url: '', title: '', target: '' }, lk); n[k] = x; on(n.url ? n : ''); }; };
				return el(c.BaseControl, { label: label, help: help },
					el(c.TextControl, { label: 'URL', type: 'url', value: lk.url || '', onChange: upd('url') }),
					el(c.TextControl, { label: __('Texto'), value: lk.title || '', onChange: upd('title') }),
					el(c.ToggleControl, { label: __('Abrir en pestaña nueva'), checked: lk.target === '_blank', onChange: function (b) { upd('target')(b ? '_blank' : ''); } })
				);
			case 'google_map':
				var mp = v && typeof v === 'object' ? v : {};
				var setm = function (k) { return function (x) { var n = Object.assign({}, mp); n[k] = x; on(n); }; };
				return el(c.BaseControl, { label: label, help: help },
					el(c.TextControl, { label: __('Dirección'), value: mp.address || '', onChange: setm('address') }),
					el(c.TextControl, { label: 'Lat', value: mp.lat === undefined ? '' : String(mp.lat), onChange: setm('lat') }),
					el(c.TextControl, { label: 'Lng', value: mp.lng === undefined ? '' : String(mp.lng), onChange: setm('lng') })
				);
			case 'icon_picker':
				var ic = v && typeof v === 'object' ? v : { type: 'dashicons', value: v || '' };
				return el(c.TextControl, { label: label, help: help || 'dashicons-star-filled / URL', value: ic.value || '', onChange: function (x) { on(x ? { type: /^https?:/.test(x) ? 'url' : 'dashicons', value: x } : ''); } });
			case 'group':
				return el(c.BaseControl, { label: label, help: help, className: 'pcf-block-group' },
					el(Fields, { fields: f.sub_fields || [], values: v || {}, onChange: on })
				);
			case 'repeater':
			case 'flexible_content':
				return el(c.BaseControl, { label: label, help: help }, el(Rows, { field: f, value: v, onChange: on }));
			default:
				return el(c.TextControl, {
					label: label,
					help: help,
					type: f.type === 'email' ? 'email' : (f.type === 'url' || f.type === 'oembed' ? 'url' : (f.type === 'password' ? 'password' : 'text')),
					value: v === undefined || v === null ? '' : String(v),
					placeholder: f.placeholder || undefined,
					onChange: on
				});
		}
	}

	function visible(field, values) {
		var groups = field.conditional_logic;
		if (!Array.isArray(groups) || !groups.length) return true;
		return groups.some(function (and) {
			return and.every(function (rule) {
				var val = values.__keys && values.__keys[rule.field];
				var list = asArray(val);
				var empty = !list.length || (list.length === 1 && list[0] === '');
				switch (rule.operator) {
					case '==': return list.indexOf(String(rule.value)) !== -1;
					case '!=': return list.indexOf(String(rule.value)) === -1;
					case '==empty': return empty;
					case '!=empty': return !empty;
					case '==contains': return list.join(',').indexOf(rule.value) !== -1;
					case '!=contains': return list.join(',').indexOf(rule.value) === -1;
					default: return true;
				}
			});
		});
	}

	function Fields(props) {
		var fields = props.fields || [];
		var values = props.values || {};
		// Mapa key => valor para evaluar lógica condicional entre hermanos.
		var byKey = {};
		fields.forEach(function (f) { if (f.name) byKey[f.key] = values[f.name]; });
		var ctx = { __keys: byKey };
		return el('div', { className: 'pcf-block-fields' },
			fields.map(function (f) {
				if (!visible(f, ctx)) return null;
				return el('div', { className: 'pcf-block-field pcf-block-field-' + f.type, key: f.key },
					el(Field, {
						field: f,
						value: f.name ? values[f.name] : undefined,
						onChange: function (x) {
							var next = Object.assign({}, values);
							next[f.name] = x;
							props.onChange(next);
						}
					})
				);
			})
		);
	}

	/* ------------------------------------------------------------------
	 * Registro de bloques
	 * --------------------------------------------------------------- */

	Object.keys(window.pcfBlocks).forEach(function (name) {
		var conf = window.pcfBlocks[name];
		var fields = conf.fields || [];

		function Edit(props) {
			var a = props.attributes;
			var mode = a.mode || conf.mode || 'preview';
			var blockProps = be.useBlockProps ? be.useBlockProps() : { className: props.className };
			var values = unflatten(fields, a.data || {}, '');
			var setValues = function (vals) {
				props.setAttributes({ data: flatten(fields, vals, '', {}) });
			};
			var postId = wp.data.select('core/editor') ? wp.data.select('core/editor').getCurrentPostId() : 0;
			var form = fields.length
				? el(Fields, { fields: fields, values: values, onChange: setValues })
				: el('p', null, __('Este bloque no tiene campos. Crea un grupo con la ubicación block == ') + name);

			var body;
			if (mode === 'edit') {
				body = el('div', { className: 'pcf-block-edit-form' }, el('strong', { className: 'pcf-block-edit-title' }, conf.title), form);
			} else {
				body = el(ServerSideRender, {
					block: name,
					attributes: { data: a.data || {}, mode: 'preview', align: a.align || '', className: a.className || '', anchor: a.anchor || '' },
					urlQueryArgs: postId ? { post_id: postId } : {},
					EmptyResponsePlaceholder: function () {
						return el(c.Placeholder, { label: conf.title, instructions: __('Rellena los campos en la barra lateral.') });
					}
				});
			}

			return el(Fragment, null,
				el(be.BlockControls, null,
					el(c.ToolbarGroup, null,
						el(c.ToolbarButton, {
							icon: mode === 'edit' ? 'visibility' : 'edit',
							label: mode === 'edit' ? __('Vista previa') : __('Editar campos'),
							onClick: function () { props.setAttributes({ mode: mode === 'edit' ? 'preview' : 'edit' }); }
						})
					)
				),
				mode !== 'edit' ? el(be.InspectorControls, null, el(c.PanelBody, { title: __('Campos'), initialOpen: true }, form)) : null,
				el('div', blockProps,
					body,
					conf.jsx ? el('div', { className: 'pcf-block-inner' }, el(be.InnerBlocks)) : null
				)
			);
		}

		var settings = {
			edit: Edit,
			save: function () { return conf.jsx ? el(be.InnerBlocks.Content) : null; }
		};
		// Título, icono, categoría y supports llegan desde el servidor (block.json / PHP).
		if (!wp.blocks.getBlockType(name)) {
			wp.blocks.registerBlockType(name, settings);
		}
	});
})(window.wp);
