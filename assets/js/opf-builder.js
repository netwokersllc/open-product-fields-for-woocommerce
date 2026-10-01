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

	var TYPES = [ 'text', 'textarea', 'email', 'url', 'number', 'date', 'toggle', 'select', 'radio', 'checkbox', 'swatch', 'paragraph' ];
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

	function labeledControl( label, control ) {
		return el( 'label', { class: 'opf-b-conditional-control' }, [
			document.createTextNode( label ),
			control,
		] );
	}

	function conditionalRuleRow( field, conditional, rule ) {
		var sources = model.fields.filter( function ( candidate ) { return candidate.id !== field.id; } );
		var fieldOptions = sources.map( function ( candidate ) {
			return el( 'option', { value: candidate.id, text: ( candidate.label || candidate.id ) + ' (' + candidate.id + ')' } );
		} );
		if ( rule.field && ! sources.some( function ( candidate ) { return candidate.id === rule.field; } ) ) {
			fieldOptions.unshift( el( 'option', { value: rule.field, text: 'Unavailable field: ' + rule.field } ) );
		}
		if ( ! fieldOptions.length ) {
			fieldOptions.push( el( 'option', { value: '', text: 'Add another field first' } ) );
		}
		var fieldSelect = el( 'select', { class: 'opf-b-input', 'aria-label': 'Condition field' }, fieldOptions );
		fieldSelect.value = rule.field || '';
		fieldSelect.disabled = ! sources.length;
		fieldSelect.addEventListener( 'change', function () {
			rule.field = fieldSelect.value;
			rule.value = '';
			rerender();
		} );

		var operatorLabels = [
			[ 'is', 'Is' ], [ 'is_not', 'Is not' ], [ 'contains', 'Contains' ], [ 'not_contains', 'Does not contain' ],
			[ 'greater', 'Is greater than' ], [ 'less', 'Is less than' ], [ 'empty', 'Is empty' ], [ 'not_empty', 'Is not empty' ],
		];
		var operatorSelect = el( 'select', { class: 'opf-b-input', 'aria-label': 'Condition operator' }, operatorLabels.map( function ( item ) {
			var option = el( 'option', { value: item[ 0 ], text: item[ 1 ] } );
			option.selected = item[ 0 ] === rule.operator;
			return option;
		} ) );
		operatorSelect.addEventListener( 'change', function () {
			rule.operator = operatorSelect.value;
			if ( in_array( rule.operator, [ 'empty', 'not_empty' ], true ) ) rule.value = '';
			rerender();
		} );

		var source = sources.find( function ( candidate ) { return candidate.id === rule.field; } );
		var valueControl = null;
		if ( ! in_array( rule.operator, [ 'empty', 'not_empty' ], true ) ) {
			if ( source && in_array( source.type, [ 'select', 'radio', 'swatch', 'checkbox' ], true ) && source.choices.length ) {
				var choiceOptions = source.choices.map( function ( choice ) {
					return el( 'option', { value: choice.slug, text: choice.label + ' (' + choice.slug + ')' } );
				} );
				if ( rule.value && ! source.choices.some( function ( choice ) { return choice.slug === rule.value; } ) ) {
					choiceOptions.unshift( el( 'option', { value: rule.value, text: 'Current value: ' + rule.value } ) );
				}
				valueControl = el( 'select', { class: 'opf-b-input', 'aria-label': 'Condition value' }, choiceOptions );
				valueControl.value = rule.value;
			} else if ( source && 'toggle' === source.type ) {
				valueControl = el( 'select', { class: 'opf-b-input', 'aria-label': 'Condition value' }, [
					el( 'option', { value: '1', text: 'Checked' } ),
					el( 'option', { value: '', text: 'Not checked' } ),
				] );
				valueControl.value = rule.value;
			} else {
				var inputType = source && 'number' === source.type ? 'number' : ( source && 'date' === source.type ? 'date' : 'text' );
				valueControl = el( 'input', { class: 'opf-b-input', type: inputType, value: rule.value || '', 'aria-label': 'Condition value' } );
			}
			valueControl.addEventListener( 'input', function () { rule.value = valueControl.value; } );
			valueControl.addEventListener( 'change', function () { rule.value = valueControl.value; } );
		}

		var remove = el( 'button', { type: 'button', class: 'button button-link-delete', text: 'Remove rule', onclick: function () {
			var index = conditional.rules.indexOf( rule );
			if ( index !== -1 ) conditional.rules.splice( index, 1 );
			rerender();
		} } );
		return el( 'div', { class: 'opf-b-conditional-rule' }, [
			labeledControl( 'Field', fieldSelect ),
			labeledControl( 'Operator', operatorSelect ),
			valueControl ? labeledControl( 'Value', valueControl ) : el( 'span', { class: 'description', text: 'No value needed' } ),
			remove,
		] );
	}

	function conditionalEditor( field ) {
		field.conditionals = Array.isArray( field.conditionals ) ? field.conditionals : [];
		var sourceFields = model.fields.filter( function ( candidate ) { return candidate.id !== field.id; } );
		var groups = field.conditionals.map( function ( conditional, groupIndex ) {
			conditional.rules = Array.isArray( conditional.rules ) ? conditional.rules : [];
			var action = el( 'select', { class: 'opf-b-input', 'aria-label': 'Visibility action' }, [
				el( 'option', { value: 'show', text: 'Show this field if' } ),
				el( 'option', { value: 'hide', text: 'Hide this field if' } ),
			] );
			action.value = conditional.action || 'show';
			action.addEventListener( 'change', function () { conditional.action = action.value; } );
			var logic = el( 'select', { class: 'opf-b-input', 'aria-label': 'How to combine rules' }, [
				el( 'option', { value: 'all', text: 'All rules match' } ),
				el( 'option', { value: 'any', text: 'Any rule matches' } ),
			] );
			logic.value = conditional.logic || 'all';
			logic.addEventListener( 'change', function () { conditional.logic = logic.value; } );
			var addRule = el( 'button', { type: 'button', class: 'button', text: '+ Add rule', onclick: function () {
				conditional.rules.push( { field: sourceFields[ 0 ].id, operator: 'is', value: '' } );
				rerender();
			} } );
			addRule.disabled = ! sourceFields.length;
			var removeGroup = el( 'button', { type: 'button', class: 'button button-link-delete', text: 'Remove condition', onclick: function () {
				field.conditionals.splice( groupIndex, 1 );
				rerender();
			} } );
			var rules = conditional.rules.map( function ( rule ) { return conditionalRuleRow( field, conditional, rule ); } );
			if ( ! rules.length ) {
				rules.push( el( 'p', { class: 'description', text: 'Add at least one rule for this condition to take effect.' } ) );
			}
			return el( 'div', { class: 'opf-b-conditional-group' }, [
				el( 'div', { class: 'opf-b-conditional-settings' }, [ labeledControl( 'Action', action ), labeledControl( 'Rule matching', logic ) ] ),
			el( 'div', { class: 'opf-b-conditional-rules' }, rules ),
			el( 'div', { class: 'opf-b-conditional-actions' }, [ addRule, removeGroup ] ),
			] );
		} );
		var addCondition = el( 'button', { type: 'button', class: 'button', text: '+ Add condition', onclick: function () {
			field.conditionals.push( {
				action: 'show', logic: 'all',
				rules: sourceFields.length ? [ { field: sourceFields[ 0 ].id, operator: 'is', value: '' } ] : [],
			} );
			rerender();
		} } );
		addCondition.disabled = ! sourceFields.length;
		return el( 'div', { class: 'opf-b-conditional-editor' }, [
			el( 'strong', { text: 'Visibility conditions' } ),
			el( 'p', { class: 'description', text: 'Condition groups are combined as alternatives; rules inside each group use the selected matching rule.' } ),
			el( 'div', { class: 'opf-b-conditional-groups' }, groups ),
			addCondition,
		] );
	}

	function fieldCard( field, index ) {
		if ( 'paragraph' === field.type ) {
			field.required = false;
			field.choices = [];
			field.pricing = { type: 'none', amount: 0, formula: '' };
			field.content = field.content || '';
		}
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
			if ( 'paragraph' === field.type ) {
				field.required = false;
				field.choices = [];
				field.pricing = { type: 'none', amount: 0, formula: '' };
			}
			if ( in_array( field.type, [ 'swatch', 'select', 'radio', 'checkbox' ] ) && ! field.choices.length ) {
				field.choices = [ { slug: 'option-1', label: 'Option 1', selected: false, disabled: false, pricing: { type: 'none', amount: 0, formula: '' } } ];
			}
			rerender();
		} );

		var req = el( 'input', { type: 'checkbox', title: 'Required' } );
		req.checked = 'paragraph' !== field.type && !! field.required;
		req.disabled = 'paragraph' === field.type;
		req.addEventListener( 'change', function () {
			field.required = req.checked;
		} );

		var desc = el( 'input', { class: 'opf-b-input opf-b-description', value: field.description || '', placeholder: 'Description (optional)' } );
		desc.addEventListener( 'input', function ( e ) {
			field.description = e.target.value;
		} );

		var duplicate = el( 'button', { type: 'button', class: 'button opf-b-duplicate-field', text: 'Duplicate field', onclick: function () {
			var copy = JSON.parse( JSON.stringify( field ) );
			copy.id = uniqueId( slugify( field.id || 'field' ) + '-copy' );
			model.fields.splice( index + 1, 0, copy );
			rerender();
		} } );

		var remove = el( 'button', { class: 'button button-link-delete', text: 'Delete field', onclick: function () {
			model.fields.splice( index, 1 );
			rerender();
		} } );

		var head = el( 'div', { class: 'opf-b-field-head' }, [ label, typeSel, desc, req, duplicate, remove ] );
		var card = el( 'div', { class: 'opf-b-field' }, [ head ] );
		card.appendChild( conditionalEditor( field ) );
		if ( 'paragraph' === field.type ) {
			var content = el( 'textarea', { class: 'opf-b-input opf-b-paragraph-content', rows: 4, 'aria-label': 'Paragraph content' } );
			content.value = field.content || '';
			content.addEventListener( 'input', function () { field.content = content.value; } );
			card.appendChild( el( 'label', { class: 'opf-b-paragraph-label', text: 'Paragraph content' }, [ content ] ) );
		}

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
		if ( 'date' === field.type ) {
			var dateBounds = el( 'div', { class: 'opf-b-constraints' } );
			[ [ 'allow_past', 'Allow past dates' ], [ 'allow_future', 'Allow future dates' ] ].forEach( function ( setting ) {
				var checkbox = el( 'input', { type: 'checkbox' } );
				checkbox.checked = field[ setting[ 0 ] ] !== false;
				checkbox.addEventListener( 'change', function () { field[ setting[ 0 ] ] = checkbox.checked; } );
				var label = el( 'label', { class: 'opf-b-date-policy' }, [ checkbox, document.createTextNode( setting[ 1 ] ) ] );
				dateBounds.appendChild( label );
			} );
			[ [ 'min_date', 'Minimum date (2026-12-31 or 7d)' ], [ 'max_date', 'Maximum date (2026-12-31 or 1y 2m)' ] ].forEach( function ( setting ) {
				var input = el( 'input', { class: 'opf-b-input', type: 'text', value: field[ setting[ 0 ] ] || '', placeholder: setting[ 1 ] } );
				input.addEventListener( 'input', function () {
					if ( input.value.trim() ) field[ setting[ 0 ] ] = input.value.trim();
					else delete field[ setting[ 0 ] ];
				} );
				dateBounds.appendChild( input );
			} );
			var cutoffInput = el( 'input', { class: 'opf-b-input', type: 'time', value: field.cutoff_time || '', placeholder: 'Disable today after' } );
			cutoffInput.addEventListener( 'input', function () {
				if ( cutoffInput.value ) field.cutoff_time = cutoffInput.value;
				else delete field.cutoff_time;
			} );
			dateBounds.appendChild( cutoffInput );
			var disabledWeekdays = el( 'input', { class: 'opf-b-input', type: 'text', value: Array.isArray( field.disabled_weekdays ) ? field.disabled_weekdays.join( ', ' ) : '', placeholder: 'Disabled weekdays (0=Sun … 6=Sat)' } );
			disabledWeekdays.addEventListener( 'input', function () {
				var days = disabledWeekdays.value.split( ',' ).map( function ( value ) { return value.trim(); } ).filter( Boolean );
				if ( days.length && days.every( function ( day ) { return /^[0-6]$/.test( day ); } ) ) field.disabled_weekdays = days.map( Number );
				else if ( ! days.length ) delete field.disabled_weekdays;
			} );
			var disabledDates = el( 'input', { class: 'opf-b-input', type: 'text', value: Array.isArray( field.disabled_dates ) ? field.disabled_dates.join( ', ' ) : '', placeholder: 'Disabled dates/ranges (YYYY-MM-DD, MM-DD, or start end)' } );
			disabledDates.addEventListener( 'input', function () {
				var rules = disabledDates.value.split( ',' ).map( function ( value ) { return value.trim(); } ).filter( Boolean );
				if ( rules.length ) field.disabled_dates = rules;
				else delete field.disabled_dates;
			} );
			dateBounds.appendChild( disabledWeekdays );
			dateBounds.appendChild( disabledDates );
			card.appendChild( dateBounds );
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
		var placementAtSave = placementSelection();
		function changed( key ) {
			return JSON.stringify( placementAtSave[ key ] ) !== JSON.stringify( initialPlacementSelection[ key ] );
		}
		var changedSubjects = {
			product_cat: changed( 'cats' ),
			product_tag: changed( 'tags' ),
			user_auth: changed( 'auth' ),
			user_role: changed( 'roles' ) || changed( 'excludedRoles' ),
			user_language: changed( 'language' ) || changed( 'languageOperator' ),
		};
		var rules = [];
		if ( changedSubjects.product_cat && placementAtSave.cats.length ) {
			rules.push( { subject: 'product_cat', operator: 'in', terms: placementAtSave.cats } );
		}
		if ( changedSubjects.product_tag && placementAtSave.tags.length ) {
			rules.push( { subject: 'product_tag', operator: 'in', terms: placementAtSave.tags } );
		}
		if ( changedSubjects.user_auth && placementAtSave.auth ) {
			rules.push( { subject: 'user_auth', operator: 'logged_out' === placementAtSave.auth ? 'not_in' : 'in', terms: [ 'logged_in' ] } );
		}
		if ( changedSubjects.user_role ) {
			placementAtSave.roles.forEach( function ( role ) { rules.push( { subject: 'user_role', operator: 'in', terms: [ role ] } ); } );
			placementAtSave.excludedRoles.forEach( function ( role ) { rules.push( { subject: 'user_role', operator: 'not_in', terms: [ role ] } ); } );
		}
		if ( changedSubjects.user_language && placementAtSave.language ) {
			rules.push( { subject: 'user_language', operator: placementAtSave.languageOperator || 'in', terms: [ placementAtSave.language ] } );
		}

		if ( Object.keys( changedSubjects ).some( function ( subject ) { return changedSubjects[ subject ]; } ) ) {
			var groups = ( model.rule_groups || [] ).map( function ( group ) {
				return { rules: ( group.rules || [] ).filter( function ( rule ) {
					if ( changedSubjects[ rule.subject ] && [ 'user_auth', 'user_role', 'user_language' ].indexOf( rule.subject ) !== -1 ) return false;
					return ! ( changedSubjects[ rule.subject ] && 'in' === rule.operator && [ 'product_cat', 'product_tag' ].indexOf( rule.subject ) !== -1 );
				} ) };
			} );
			if ( groups.length ) {
				groups.forEach( function ( group ) { group.rules = group.rules.concat( rules ); } );
				model.rule_groups = groups.some( function ( group ) { return 0 === group.rules.length; } ) ? [] : groups;
			} else {
				model.rule_groups = rules.length ? [ { rules: rules } ] : [];
			}
		}

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
				initialPlacementSelection = placementAtSave;
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

	function placementSelection() {
		function values( selector ) {
			return Array.prototype.slice.call( document.querySelectorAll( selector ) ).map( function ( option ) { return option.value; } );
		}
		function value( selector ) {
			var control = document.querySelector( selector );
			return control ? control.value : '';
		}
		return {
			cats: values( '#opf-placement-cats option:checked' ),
			tags: values( '#opf-placement-tags option:checked' ),
			auth: value( '#opf-placement-auth' ),
			roles: values( '#opf-placement-roles option:checked' ),
			excludedRoles: values( '#opf-placement-excluded-roles option:checked' ),
			language: value( '#opf-placement-language' ),
			languageOperator: value( '#opf-placement-language-operator' ),
		};
	}

	var initialPlacementSelection = null;

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
	initialPlacementSelection = placementSelection();
} )();
