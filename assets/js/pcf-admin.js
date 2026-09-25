/* Prompt Custom Fields — administración. */
(function ($) {
	'use strict';

	$(function () {
		// Editor de código para el JSON.
		var $json = $('#pcf-json');
		if ($json.length && window.pcfAdmin && pcfAdmin.codeEditor && wp.codeEditor) {
			var editor = wp.codeEditor.initialize($json[0], pcfAdmin.codeEditor);
			$json.closest('form').on('submit', function () {
				editor.codemirror.save();
			});
		}

		// Confirmación de acciones destructivas.
		$(document).on('click', '.pcf-confirm', function (e) {
			if (!window.confirm($(this).data('confirm'))) {
				e.preventDefault();
			}
		});

		// Copiar al portapapeles el bloque <pre> anterior.
		$(document).on('click', '.pcf-copy', function () {
			var $btn = $(this);
			var text = $btn.prevAll('pre').first().text();
			var done = function () {
				var label = $btn.text();
				$btn.addClass('is-copied').text('Copiado');
				setTimeout(function () { $btn.removeClass('is-copied').text(label); }, 1500);
			};
			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(text).then(done);
			} else {
				var $t = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
				$t[0].select();
				document.execCommand('copy');
				$t.remove();
				done();
			}
		});
	});
})(jQuery);
