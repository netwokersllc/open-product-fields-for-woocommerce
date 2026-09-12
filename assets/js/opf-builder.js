/**
 * OPF field builder. Dependency-free vanilla JS — no jQuery, no build step.
 * Edits a JSON model and persists through the REST API.
 */
( function () {
	'use strict';

	var mount = document.getElementById( 'opf-builder-app' );
	if ( ! mount ) {
		return;
	}

	var postId = parseInt( mount.dataset.postId, 10 ) || 0;
	var model = JSON.parse( mount.dataset.model || '{}' );
	var nonce = mount.dataset.nonce;
	var restUrl = mount.dataset.rest;
	var previewRest = mount.dataset.previewRest;

	model.fields = model.fields || [];
	model.rule_groups = model.rule_groups || [];

	var TYPES = [ 'text', 'textarea', 'url', 'number', 'select', 'radio', 'checkbox', 'swatch' ];
	var PRICING = [ 'none', 'fixed', 'percent', 'formula' ];

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				if ( 'class' === k ) {
					node.className = attrs[ k ];
				} else if ( 'text' === k ) {
					node.textContent = attrs[ k ];
				} else if ( 'html' === k ) {
					node.innerHTML = attrs[ k ];
				} else if ( 'value' === k ) {
					node.value = attrs[ k ];
				} else if ( 0 === k.indexOf( 'on' ) ) {
					node.addEventListener( k.slice( 2 ), attrs[ k ] );
				} else {
					node.setAttribute( k, attrs[ k ] );
				}
			} );
		}
		( children || [] ).forEach( function ( c ) {
			if ( c ) {
				node.appendChild( c );
			}
		} );
		return node;
	}

	function slugify( text ) {
		return String( text )
			.toLowerCase()
			.normalize( 'NFKD' )
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' )
			.slice( 0, 40 );
	}

	function uniqueId( base ) {
		var id = base || 'field';
		var n = 2;
		var taken = {};
		model.fields.forEach( function ( f ) {
			taken[ f.id ] = true;
		} );
		while ( taken[ id ] ) {
			id = base + '-' + n;
			n++;
		}
		return id;
	}

	function choiceRow( field, choice, index ) {
		var slugInput = el( 'input', { class: 'opf-b-input opf-b-slug', value: choice.slug, placeholder: 'slug', oninput: function ( e ) {
			choice.slug = e.target.value;
		} } );
		var labelInput = el( 'input', { class: 'opf-b-input', value: choice.label, oninput: function ( e ) {
			choice.label = e.target.value;
		} } );
		var typeSelect = el( 'select', { class: 'opf-b-input' },
			PRICING.map( function ( t ) {
				var o = el( 'option', { value: t, text: t } );
				if ( t === ( choice.pricing.type || 'none' ) ) {
					o.selected = true;
				}
				return o;
			} )
		);
		typeSelect.addEventListener( 'change', function () {
			choice.pricing.type = typeSelect.value;
			rerender();
		} );
		var amountInput = el( 'input', { class: 'opf-b-input', type: 'text', value: choice.pricing.amount || '', placeholder: 'amount' } );
		amountInput.addEventListener( 'input', function ( e ) {
			choice.pricing.amount = parseFloat( e.target.value ) || 0;
		} );
		var formulaInput = el( 'input', { class: 'opf-b-input', type: 'text', value: choice.pricing.formula || '', placeholder: '([price] + [addons]) * 0.2' } );
		formulaInput.addEventListener( 'input', function ( e ) {
			choice.pricing.formula = e.target.value;
		} );
		var selected = el( 'input', { type: 'checkbox', title: 'Preselected' } );
		selected.checked = !! choice.selected;
		selected.addEventListener( 'change', function () {
			choice.selected = selected.checked;
		} );
		var remove = el( 'button', { class: 'button-link opf-b-remove', text: '×', onclick: function () {
			field.choices.splice( index, 1 );
			rerender();
		} } );

		var row = el( 'div', { class: 'opf-b-choice' }, [
			selected, slugInput, labelInput, typeSelect,
			'formula' === choice.pricing.type ? formulaInput : amountInput,
			remove
		] );
		return row;
	}

	function fieldCard( field, index ) {
		var label = el( 'input', { class: 'opf-b-input opf-b-label', value: field.label, placeholder: 'Field label' } );
		label.addEventListener( 'input', function ( e ) {
			field.label = e.target.value;
		} );

		var typeSel = el( 'select', { class: 'opf-b-input' }, TYPES.map( function ( t ) {
			var o = el( 'option', { value: t, text: t } );
			if ( t === field.type ) {
				o.selected = true;
			}
			return o;
		} ) );
		typeSel.addEventListener( 'change', function () {
			field.type = typeSel.value;
			if ( in_array( field.type, [ 'swatch', 'select', 'radio', 'checkbox' ] ) && ! field.choices.length ) {
				field.choices = [ { slug: 'option-1', label: 'Option 1', selected: false, disabled: false, pricing: { type: 'none', amount: 0, formula: '' } } ];
			}
			rerender();
		} );

		var req = el( 'input', { type: 'checkbox', title: 'Required' } );
		req.checked = !! field.required;
		req.addEventListener( 'change', function () {
			field.required = req.checked;
		} );

		var desc = el( 'input', { class: 'opf-b-input', value: field.description || '', placeholder: 'Description (optional)' } );
		desc.addEventListener( 'input', function ( e ) {
			field.description = e.target.value;
		} );

		var remove = el( 'button', { class: 'button button-link-delete', text: 'Delete field', onclick: function () {
			model.fields.splice( index, 1 );
			rerender();
		} } );

		var head = el( 'div', { class: 'opf-b-field-head' }, [ label, typeSel, desc, req, remove ] );
		var card = el( 'div', { class: 'opf-b-field' }, [ head ] );

		if ( field.choices.length ) {
			var addChoice = el( 'button', { class: 'button', text: '+ Add choice', onclick: function () {
				var n = field.choices.length + 1;
				field.choices.push( { slug: 'option-' + n, label: 'Option ' + n, selected: false, disabled: false, pricing: { type: 'none', amount: 0, formula: '' } } );
				rerender();
			} } );
			var header = el( 'div', { class: 'opf-b-choices-header', html: '<strong>Choices</strong> <em>(slug · label · pricing)</em>' } );
			var list = el( 'div', { class: 'opf-b-choices' }, field.choices.map( function ( c, i ) {
				return choiceRow( field, c, i );
			} ) );
			card.appendChild( header );
			card.appendChild( list );
			card.appendChild( addChoice );
		}

		return card;
	}

	function in_array( needle, haystack ) {
		return haystack.indexOf( needle ) !== -1;
	}

	function rerender() {
		var app = document.getElementById( 'opf-builder-fields' );
		app.innerHTML = '';
		model.fields.forEach( function ( field, i ) {
			app.appendChild( fieldCard( field, i ) );
		} );
	}

	function save() {
		// Fold placement selects into the model.
		var cats = Array.prototype.slice.call( document.querySelectorAll( '#opf-placement-cats option:checked' ) ).map( function ( o ) {
			return o.value;
		} );
		var tags = Array.prototype.slice.call( document.querySelectorAll( '#opf-placement-tags option:checked' ) ).map( function ( o ) {
			return o.value;
		} );
		var rules = [];
		if ( cats.length ) {
			rules.push( { subject: 'product_cat', operator: 'in', terms: cats } );
		}
		if ( tags.length ) {
			rules.push( { subject: 'product_tag', operator: 'in', terms: tags } );
		}
		model.rule_groups = rules.length ? [ { rules: rules } ] : [];

		var status = document.getElementById( 'opf-b-status' );
		status.textContent = 'Saving…';
		window.fetch( restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce
			},
			body: JSON.stringify( { id: postId, title: document.getElementById( 'title' ) ? document.getElementById( 'title' ).value : '', data: model } )
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			if ( j.id ) {
				postId = j.id;
				if ( ! parseInt( mount.dataset.postId, 10 ) ) {
					mount.dataset.postId = j.id;
				}
				status.textContent = 'Saved.';
			} else {
				status.textContent = 'Save failed: ' + ( j.message || 'unknown error' );
			}
		} ).catch( function ( e ) {
			status.textContent = 'Save failed: ' + e;
		} );
	}

	function preview() {
		var frame = document.getElementById( 'opf-b-preview' );
		var status = document.getElementById( 'opf-b-status' );
		status.textContent = 'Loading preview…';
		window.fetch( previewRest, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
			body: JSON.stringify( { data: model, product_id: 0 } )
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			frame.innerHTML = j.html || ( j.message || 'No preview available.' );
			status.textContent = '';
		} ).catch( function ( e ) {
			status.textContent = 'Preview failed: ' + e;
		} );
	}

	var toolbar = el( 'div', { class: 'opf-b-toolbar' }, [
		el( 'button', { class: 'button button-primary', text: '+ Add field', onclick: function () {
			model.fields.push( { id: uniqueId( 'field' ), label: '', description: '', type: 'text', required: false, width: 100, choices: [], pricing: { type: 'none', amount: 0, formula: '' }, conditionals: [] } );
			rerender();
		} } ),
		el( 'button', { class: 'button', text: 'Save', onclick: save } ),
		el( 'button', { class: 'button', text: 'Refresh preview', onclick: preview } ),
		el( 'span', { id: 'opf-b-status', class: 'opf-b-status' } )
	] );

	var app = el( 'div', { id: 'opf-builder-fields', class: 'opf-b-fields' } );
	var frame = el( 'div', { id: 'opf-b-preview', class: 'opf-b-preview' } );

	mount.appendChild( toolbar );
	mount.appendChild( app );
	mount.appendChild( el( 'h4', { text: 'Preview' } ) );
	mount.appendChild( frame );

	rerender();
} )();
