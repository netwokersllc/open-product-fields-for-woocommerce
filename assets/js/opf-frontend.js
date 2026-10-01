/**
 * OPF frontend. Small ES module — no jQuery, no framework, no build step.
 *
 * Responsibilities:
 *  - mirror server-side conditional visibility client-side for instant UX
 *    (the server re-validates everything on add-to-cart),
 *  - keep a values map per group in sync as the customer edits fields,
 *  - toggle hidden fields with the `hidden` attribute + aria-hidden.
 *
 * The server embeds the field metadata as window.OPF_FIELDS:
 *   { "<group_id>": { "<field_id>": { type, conditionals } } }
 */

const REGISTRY = window.OPF_FIELDS || {};

const isVisible = ( field, values ) => {
	if ( ! field.conditionals || ! field.conditionals.length ) {
		return true;
	}
	let hasShow = false;
	let showPass = false;
	let hidePass = false;

	const rulePasses = ( rule ) => {
		const value = values[ rule.field ];
		const actual = Array.isArray( value )
			? value.flat( Infinity ).map( String ).filter( ( item ) => item.trim() !== '' ).join( ', ' )
			: String( value ?? '' );
		const expect = String( rule.value ?? '' );
		switch ( rule.operator ) {
			case 'is':
				return Array.isArray( value )
					? value.flat( Infinity ).includes( expect )
					: actual === expect;
			case 'is_not':
				return ! rulePasses( { ...rule, operator: 'is' } );
			case 'contains':
				return actual.toLowerCase().includes( expect.toLowerCase() );
			case 'not_contains':
				return ! actual.toLowerCase().includes( expect.toLowerCase() );
			case 'greater':
				return actual !== '' && Number( actual ) > Number( expect );
			case 'less':
				return actual !== '' && Number( actual ) < Number( expect );
			case 'empty':
				return actual.trim() === '';
			case 'not_empty':
				return actual.trim() !== '';
			default:
				return false;
		}
	};

	field.conditionals.forEach( ( conditional ) => {
		const results = conditional.rules.map( rulePasses );
		const passed =
			'any' === conditional.logic
				? results.includes( true )
				: ! results.includes( false );
		if ( 'hide' === conditional.action ) {
			hidePass = hidePass || passed;
		} else {
			hasShow = true;
			showPass = showPass || passed;
		}
	} );

	if ( hidePass ) {
		return false;
	}
	return hasShow ? showPass : true;
};

const dateSiteClock = ( input ) => {
	const serverEpoch = Number( input.dataset.opfDateSiteEpoch );
	if ( ! Number.isFinite( serverEpoch ) ) return null;
	if ( ! input.dataset.opfDateClientEpoch ) input.dataset.opfDateClientEpoch = String( Date.now() );
	const now = new Date( serverEpoch * 1000 + Date.now() - Number( input.dataset.opfDateClientEpoch ) );
	const timeZone = input.dataset.opfDateTimezone || 'UTC';
	try {
		const parts = new Intl.DateTimeFormat( 'en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' } ).formatToParts( now );
		const part = ( type ) => ( parts.find( ( item ) => item.type === type ) || {} ).value || '';
		return { date: part( 'year' ) + '-' + part( 'month' ) + '-' + part( 'day' ), time: part( 'hour' ) + ':' + part( 'minute' ) + ':' + part( 'second' ) };
	} catch ( error ) {
		const match = timeZone.match( /^([+-])(\d{2}):(\d{2})$/ );
		if ( ! match ) return null;
		const offset = ( Number( match[2] ) * 60 + Number( match[3] ) ) * ( '-' === match[1] ? -1 : 1 );
		const shifted = new Date( now.getTime() + offset * 60000 );
		return { date: shifted.toISOString().slice( 0, 10 ), time: shifted.toISOString().slice( 11, 19 ) };
	}
};

const dateIsBlocked = ( input, isoDate ) => {
	if ( ( input.min && isoDate < input.min ) || ( input.max && isoDate > input.max ) ) return true;
	const date = new Date( isoDate + 'T00:00:00Z' );
	if ( Number.isNaN( date.getTime() ) ) return true;
	const weekdays = JSON.parse( input.dataset.opfDisabledWeekdays || '[]' );
	if ( weekdays.includes( date.getUTCDay() ) ) return true;
	const monthDay = isoDate.slice( 5 );
	const dateRuleBlocks = JSON.parse( input.dataset.opfDisabledDates || '[]' ).some( ( rule ) => {
		const parts = String( rule ).trim().split( /\s+/ );
		if ( 1 === parts.length ) return parts[0] === isoDate || parts[0] === monthDay;
		let [ start, end ] = parts;
		if ( /^\d{2}-\d{2}$/.test( start ) && /^\d{2}-\d{2}$/.test( end ) ) {
			return start <= end ? monthDay >= start && monthDay <= end : monthDay >= start || monthDay <= end;
		}
		if ( /^\d{2}-\d{2}$/.test( start ) ) start = isoDate.slice( 0, 4 ) + '-' + start;
		if ( /^\d{2}-\d{2}$/.test( end ) ) end = isoDate.slice( 0, 4 ) + '-' + end;
		return start <= isoDate && isoDate <= end;
	} );
	if ( dateRuleBlocks ) return true;
	const cutoff = input.dataset.opfDateCutoff;
	if ( cutoff ) {
		const clock = dateSiteClock( input );
		if ( ! clock ) return true;
		if ( isoDate === clock.date && clock.time > cutoff + ':00' ) return true;
	}
	return false;
};

const initDatePicker = ( fieldEl, input ) => {
	if ( fieldEl.querySelector( '.opf-date-picker' ) ) return;
	const wrapper = document.createElement( 'div' );
	wrapper.className = 'opf-date-picker';
	const toggle = document.createElement( 'button' );
	toggle.type = 'button';
	toggle.className = 'opf-date-picker__toggle';
	toggle.textContent = 'Choose date';
	toggle.setAttribute( 'aria-haspopup', 'dialog' );
	toggle.setAttribute( 'aria-expanded', 'false' );
	const panel = document.createElement( 'div' );
	panel.className = 'opf-date-picker__panel';
	panel.id = input.id + '-calendar';
	panel.setAttribute( 'role', 'dialog' );
	panel.setAttribute( 'aria-label', 'Choose a date' );
	panel.hidden = true;
	toggle.setAttribute( 'aria-controls', panel.id );
	const header = document.createElement( 'div' );
	header.className = 'opf-date-picker__header';
	const previous = document.createElement( 'button' );
	previous.type = 'button';
	previous.textContent = '‹';
	previous.setAttribute( 'aria-label', 'Previous month' );
	const monthLabel = document.createElement( 'strong' );
	const next = document.createElement( 'button' );
	next.type = 'button';
	next.textContent = '›';
	next.setAttribute( 'aria-label', 'Next month' );
	header.append( previous, monthLabel, next );
	const grid = document.createElement( 'div' );
	grid.className = 'opf-date-picker__grid';
	grid.setAttribute( 'role', 'grid' );
	grid.setAttribute( 'aria-label', 'Calendar dates' );
	const status = document.createElement( 'div' );
	status.className = 'opf-date-picker__status';
	status.setAttribute( 'role', 'status' );
	status.setAttribute( 'aria-live', 'polite' );
	panel.append( header, grid, status );
	wrapper.append( toggle, panel );
	fieldEl.appendChild( wrapper );
	let visibleMonth = ( input.value || new Date().toISOString().slice( 0, 10 ) ).slice( 0, 7 ) + '-01';
	const displayDate = ( isoDate ) => {
		const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( isoDate );
		if ( ! match ) return 'Choose date';
		const values = { yyyy: match[1], yy: match[1].slice( -2 ), mm: match[2], m: String( Number( match[2] ) ), dd: match[3], d: String( Number( match[3] ) ) };
		const format = input.dataset.opfDateFormat || 'mm-dd-yyyy';
		return format.replace( /yyyy|yy|mm|m|dd|d/gi, ( token ) => values[token.toLowerCase()] || token );
	};
	const weekStart = Math.min( 6, Math.max( 0, Number( input.dataset.opfWeekStart || 0 ) ) );
	const render = () => {
		grid.textContent = '';
		const month = new Date( visibleMonth + 'T00:00:00Z' );
		monthLabel.textContent = new Intl.DateTimeFormat( undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' } ).format( month );
		const labels = [ 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ];
		const headingRow = document.createElement( 'div' );
		headingRow.setAttribute( 'role', 'row' );
		labels.slice( weekStart ).concat( labels.slice( 0, weekStart ) ).forEach( ( label ) => {
			const heading = document.createElement( 'span' );
			heading.textContent = label;
			heading.setAttribute( 'role', 'columnheader' );
			headingRow.appendChild( heading );
		} );
		grid.appendChild( headingRow );
		const firstWeekday = new Date( month ).getUTCDay();
		const blanks = ( firstWeekday - weekStart + 7 ) % 7;
		const dayCount = new Date( Date.UTC( month.getUTCFullYear(), month.getUTCMonth() + 1, 0 ) ).getUTCDate();
		let row = document.createElement( 'div' );
		row.setAttribute( 'role', 'row' );
		for ( let blank = 0; blank < blanks; blank++ ) row.appendChild( document.createElement( 'span' ) );
		let tabStopSet = false;
		for ( let day = 1; day <= dayCount; day++ ) {
			const isoDate = visibleMonth.slice( 0, 7 ) + '-' + String( day ).padStart( 2, '0' );
			const choice = document.createElement( 'button' );
			choice.type = 'button';
			choice.textContent = String( day );
			choice.dataset.opfDate = isoDate;
			choice.setAttribute( 'role', 'gridcell' );
			choice.setAttribute( 'aria-label', new Intl.DateTimeFormat( undefined, { dateStyle: 'full', timeZone: 'UTC' } ).format( new Date( isoDate + 'T00:00:00Z' ) ) );
			choice.disabled = dateIsBlocked( input, isoDate );
			choice.tabIndex = ! choice.disabled && ! tabStopSet && ( isoDate === input.value || ! input.value ) ? 0 : -1;
			if ( choice.tabIndex === 0 ) tabStopSet = true;
			if ( isoDate === input.value ) choice.setAttribute( 'aria-pressed', 'true' );
			choice.addEventListener( 'click', () => {
				input.value = isoDate;
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				panel.hidden = true;
				toggle.setAttribute( 'aria-expanded', 'false' );
				toggle.textContent = displayDate( isoDate );
				toggle.focus();
			} );
			row.appendChild( choice );
			if ( ( blanks + day ) % 7 === 0 ) {
				grid.appendChild( row );
				row = document.createElement( 'div' );
				row.setAttribute( 'role', 'row' );
			}
		}
		if ( row.children.length ) grid.appendChild( row );
		status.textContent = grid.querySelector( 'button:not(:disabled)' ) ? '' : 'No selectable dates this month.';
		toggle.textContent = input.value ? displayDate( input.value ) : 'Choose date';
	};
	input.opfRenderDateCalendar = render;
	const changeMonth = ( amount ) => {
		const month = new Date( visibleMonth + 'T00:00:00Z' );
		month.setUTCMonth( month.getUTCMonth() + amount );
		visibleMonth = month.toISOString().slice( 0, 7 ) + '-01';
		render();
	};
	previous.addEventListener( 'click', () => changeMonth( -1 ) );
	next.addEventListener( 'click', () => changeMonth( 1 ) );
	toggle.addEventListener( 'click', () => {
		panel.hidden = ! panel.hidden;
		toggle.setAttribute( 'aria-expanded', String( ! panel.hidden ) );
		render();
		if ( ! panel.hidden ) ( grid.querySelector( '[aria-pressed="true"]:not(:disabled)' ) || grid.querySelector( '[tabindex="0"]' ) || next ).focus();
	} );
	panel.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' === event.key ) {
			panel.hidden = true;
			toggle.setAttribute( 'aria-expanded', 'false' );
			toggle.focus();
			event.preventDefault();
		}
	} );
	grid.addEventListener( 'keydown', ( event ) => {
		const current = event.target.closest( 'button[data-opf-date]' );
		if ( ! current ) return;
		let amount = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[ event.key ];
		if ( 'Home' === event.key ) amount = -(( new Date( current.dataset.opfDate + 'T00:00:00Z' ).getUTCDay() - weekStart + 7 ) % 7 );
		if ( 'End' === event.key ) amount = 6 - (( new Date( current.dataset.opfDate + 'T00:00:00Z' ).getUTCDay() - weekStart + 7 ) % 7 );
		if ( null === amount || undefined === amount ) return;
		const date = new Date( current.dataset.opfDate + 'T00:00:00Z' );
		const direction = amount < 0 ? -1 : 1;
		for ( let attempt = 0; attempt < 42; attempt++ ) {
			const candidate = new Date( date.getTime() );
			candidate.setUTCDate( candidate.getUTCDate() + amount + ( direction * attempt ) );
			const isoDate = candidate.toISOString().slice( 0, 10 );
			if ( dateIsBlocked( input, isoDate ) ) {
				continue;
			}
			if ( isoDate.slice( 0, 7 ) !== visibleMonth.slice( 0, 7 ) ) {
				visibleMonth = isoDate.slice( 0, 7 ) + '-01';
				render();
			}
			const nextDate = grid.querySelector( 'button[data-opf-date="' + isoDate + '"]' );
			if ( nextDate ) {
				grid.querySelectorAll( 'button[data-opf-date]' ).forEach( ( button ) => { button.tabIndex = button === nextDate ? 0 : -1; } );
				nextDate.focus();
			}
			break;
		}
		event.preventDefault();
	} );
	input.addEventListener( 'input', () => {
		const invalid = !! input.value && dateIsBlocked( input, input.value );
		input.setCustomValidity( invalid ? 'This date is unavailable.' : '' );
		render();
	} );
	render();
	if ( input.dataset.opfDateCutoff && window.setInterval && ! input.opfDateCutoffTimer ) {
		input.opfDateCutoffTimer = window.setInterval( () => {
			if ( ! panel.hidden ) render();
			if ( input.value ) input.setCustomValidity( dateIsBlocked( input, input.value ) ? 'This date is unavailable.' : '' );
		}, 15000 );
		window.addEventListener( 'pagehide', () => window.clearInterval( input.opfDateCutoffTimer ), { once: true } );
	}
};

const init = () => {
	document.querySelectorAll( '[data-opf-group]' ).forEach( ( groupEl ) => {
		const gid = groupEl.getAttribute( 'data-opf-group' );
		const registry = REGISTRY[ gid ] || {};
		const readInstanceValue = ( instance, def ) => {
			if ( def.type === 'toggle' ) {
				const checkbox = instance.querySelector( 'input[type="checkbox"]' );
				return checkbox && checkbox.checked ? '1' : '0';
			}
			if ( [ 'checkbox', 'swatch' ].includes( def.type ) && ( def.type === 'checkbox' || def.multiple ) ) {
				return Array.from( instance.querySelectorAll( 'input[type="checkbox"]:checked' ) ).map( ( input ) => input.value );
			}
			const checked = instance.querySelector( 'input:checked' );
			const input = checked || instance.querySelector( 'input:not([type="hidden"]), textarea, select' );
			return input ? input.value : '';
		};
		const readFieldValue = ( fieldEl, def ) => fieldEl.matches( '[data-opf-repeat="button"]' )
			? Array.from( fieldEl.querySelectorAll( '[data-opf-repeat-instance]' ) ).map( ( instance ) => readInstanceValue( instance, def ) )
			: readInstanceValue( fieldEl, def );
		const updateRequiredRepeaters = () => {
			groupEl.querySelectorAll( '[data-opf-repeat="button"].opf-required' ).forEach( ( repeater ) => {
				const def = registry[ repeater.dataset.opfField ] || {};
				if ( def.type !== 'checkbox' && !( def.type === 'swatch' && def.multiple ) ) return;
				const minimum = Number( def.min_choices || 1 );
				repeater.querySelectorAll( '[data-opf-repeat-instance]' ).forEach( ( instance ) => {
					const inputs = Array.from( instance.querySelectorAll( 'input[type="checkbox"]' ) );
					const invalid = inputs.filter( ( input ) => input.checked ).length < minimum;
					inputs.forEach( ( input, index ) => input.setCustomValidity( invalid && index === 0 ? 'Select the required choices in every repeated row.' : '' ) );
				} );
			} );
		};

		groupEl.querySelectorAll( '[data-opf-repeat="button"]' ).forEach( ( repeater ) => {
			const rows = repeater.querySelector( '.opf-field-repeat__rows' );
			const first = rows && rows.querySelector( '[data-opf-repeat-instance]' );
			if ( ! rows || ! first ) return;
			const template = first.cloneNode( true );
			const max = Math.max( 1, Number( repeater.dataset.opfRepeatMax ) || 10000 );
			const repeatDef = registry[ repeater.dataset.opfField ] || {};
			const update = () => {
				const instances = Array.from( rows.querySelectorAll( '[data-opf-repeat-instance]' ) );
				instances.forEach( ( instance, index ) => {
					let remove = instance.querySelector( ':scope > .opf-field-repeat__remove' );
					if ( ! remove ) {
						remove = document.createElement( 'button' );
						remove.type = 'button';
						remove.className = 'opf-field-repeat__remove';
						remove.textContent = repeatDef.repeat && repeatDef.repeat.del ? repeatDef.repeat.del : 'Remove';
						instance.appendChild( remove );
					}
					remove.setAttribute( 'aria-label', 'Remove row ' + ( index + 1 ) );
					remove.disabled = instances.length <= 1;
					instance.querySelectorAll( '[name]' ).forEach( ( input ) => {
						input.name = input.name.replace( /\[\d+\](\[\])?$/, '[' + index + ']$1' );
					} );
					instance.querySelectorAll( '[id]' ).forEach( ( element ) => {
						element.id = element.id.replace( /-repeat-\d+/, '-repeat-' + index );
					} );
					instance.querySelectorAll( 'label[for]' ).forEach( ( label ) => {
						label.htmlFor = label.htmlFor.replace( /-repeat-\d+/, '-repeat-' + index );
					} );
				} );
				const add = repeater.querySelector( '.opf-field-repeat__add' );
				if ( add ) add.disabled = instances.length >= max;
			};
			const resetClone = ( clone ) => {
				clone.querySelectorAll( '.opf-date-picker' ).forEach( ( picker ) => picker.remove() );
				clone.querySelectorAll( 'input' ).forEach( ( input ) => {
					if ( input.type === 'hidden' ) {
						input.value = '0';
					} else if ( input.type === 'checkbox' || input.type === 'radio' ) {
						input.checked = false;
					} else {
						input.value = '';
					}
				} );
				clone.querySelectorAll( 'textarea' ).forEach( ( input ) => { input.value = ''; } );
				clone.querySelectorAll( 'select' ).forEach( ( input ) => { input.selectedIndex = 0; } );
				clone.querySelectorAll( '.opf-checked' ).forEach( ( element ) => element.classList.remove( 'opf-checked' ) );
				const remove = clone.querySelector( ':scope > .opf-field-repeat__remove' );
				if ( remove ) remove.remove();
			};
			repeater.addEventListener( 'click', ( event ) => {
				if ( event.target.closest( '.opf-field-repeat__add' ) ) {
					if ( rows.querySelectorAll( '[data-opf-repeat-instance]' ).length >= max ) return;
					const clone = template.cloneNode( true );
					resetClone( clone );
					rows.appendChild( clone );
					const dateInput = clone.querySelector( 'input[type="date"]' );
					if ( dateInput ) initDatePicker( clone, dateInput );
					update();
					const def = registry[ repeater.dataset.opfField ] || {};
					values[ repeater.dataset.opfField ] = readFieldValue( repeater, def );
					updateRequiredRepeaters();
					refresh();
				} else if ( event.target.closest( '.opf-field-repeat__remove' ) ) {
					if ( rows.querySelectorAll( '[data-opf-repeat-instance]' ).length <= 1 ) return;
					event.target.closest( '[data-opf-repeat-instance]' ).remove();
					update();
					const def = registry[ repeater.dataset.opfField ] || {};
					values[ repeater.dataset.opfField ] = readFieldValue( repeater, def );
					updateRequiredRepeaters();
					refresh();
				}
			} );
			update();
		} );
		const fields = groupEl.querySelectorAll( '[data-opf-field]' );

		const values = {};
		const fieldDefs = {};

		fields.forEach( ( fieldEl ) => {
			const fid = fieldEl.getAttribute( 'data-opf-field' );
			fieldDefs[ fid ] = registry[ fid ] || { type: 'text', conditionals: [] };

			values[ fid ] = readFieldValue( fieldEl, fieldDefs[ fid ] );
		} );
		updateRequiredRepeaters();
		fields.forEach( ( fieldEl ) => {
			if ( fieldEl.matches( '[data-opf-repeat="button"]' ) ) {
				fieldEl.querySelectorAll( '[data-opf-repeat-instance]' ).forEach( ( instance ) => {
					const input = instance.querySelector( 'input[type="date"]' );
					if ( input ) initDatePicker( instance, input );
				} );
			} else {
				const input = fieldEl.querySelector( 'input[type="date"]' );
				if ( input ) initDatePicker( fieldEl, input );
			}
		} );

		const refresh = () => {
			fields.forEach( ( fieldEl ) => {
				const fid = fieldEl.getAttribute( 'data-opf-field' );
				const def = fieldDefs[ fid ] || {};
				const visible = isVisible( def, values );
				fieldEl.classList.toggle( 'opf-field--hidden', ! visible );
				fieldEl.classList.toggle( 'opf-hide', ! visible );
				fieldEl.toggleAttribute( 'hidden', ! visible );

				// Accordion header: mostrar la elección actual
				const accValue = fieldEl.querySelector( '.acc-value' );
				if ( accValue ) {
					const v = values[ fid ];
					const choices = def.choices || [];
					if ( choices.length ) {
						const slugs = Array.isArray( v ) ? v : [ v ];
						const chosen = choices.find( ( c ) => slugs.includes( c.slug ) );
						if ( chosen ) accValue.textContent = chosen.label;
					} else if ( typeof v === 'string' && v.trim() ) {
						accValue.textContent = v;
					}
				}
			} );
		};

		const syncChecked = () => {
			// Legacy theme integration keys swatch styling off `opf-checked`
			// on the .opf-swatch wrapper, exactly as the legacy JS did.
			groupEl.querySelectorAll( '.opf-swatch' ).forEach( ( swatch ) => {
				const input = swatch.querySelector( 'input' );
				if ( ! input ) {
					return;
				}
				swatch.classList.toggle( 'opf-checked', !! input.checked );
			} );
		};

		groupEl.addEventListener( 'input', ( event ) => {
			const fieldEl = event.target.closest( '[data-opf-field]' );
			if ( ! fieldEl ) {
				return;
			}
			const fid = fieldEl.getAttribute( 'data-opf-field' );
			const input = event.target;
			if ( 'date' === input.type && input.value && dateIsBlocked( input, input.value ) ) {
				input.setCustomValidity( 'This date is unavailable.' );
			} else if ( 'date' === input.type ) {
				input.setCustomValidity( '' );
			}
			if ( input.type === 'checkbox' && input.name.endsWith( '[]' ) && fieldDefs[ fid ] && fieldDefs[ fid ].type === 'swatch' ) {
				const maxChoices = Number( fieldDefs[ fid ].max_choices || 0 );
				const choiceScope = input.closest( '[data-opf-repeat-instance]' ) || fieldEl;
				const checked = choiceScope.querySelectorAll( 'input:checked' ).length;
				if ( input.checked && maxChoices && checked > maxChoices ) {
					input.checked = false;
					input.setCustomValidity( 'Select no more than ' + maxChoices + ' options.' );
				} else {
					groupEl.querySelectorAll( '[data-opf-field="' + fid + '"] input[type="checkbox"]' ).forEach( ( choiceInput ) => choiceInput.setCustomValidity( '' ) );
				}
			}
			if ( fieldDefs[ fid ] && fieldDefs[ fid ].type === 'toggle' && ! fieldEl.matches( '[data-opf-repeat="button"]' ) ) {
				values[ fid ] = input.checked ? '1' : '0';
			} else {
				values[ fid ] = readFieldValue( fieldEl, fieldDefs[ fid ] );
			}
			if ( input.type === 'radio' || input.type === 'checkbox' ) {
				syncChecked();
			}
			updateRequiredRepeaters();
			refresh();
		} );

		refresh();
		syncChecked();
	} );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}

// ---------------------------------------------------------------------------
// Totals block writer (replaces the legacy plugin's own totals JS).
// Reads the server-rendered data-product-price + the per-choice/field
// data-opf-price attributes and writes the three legacy totals spans, using
// the same display_options contract the theme's currency converter expects.

const fmtMoney = (amount) => {
  const o = (window.opf_config || {}).display_options || {};
  const symbol = o.symbol || '$';
  const decimals = typeof o.decimals === 'number' ? o.decimals : 2;
  const thousand = o.thousand || ',';
  const decimal = o.decimal || '.';
  const neg = amount < 0 ? '-' : '';
  const fixed = Math.abs(amount).toFixed(decimals);
  const [intPart, fracPart] = fixed.split('.');
  const grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousand);
  return `${neg}${symbol}${grouped}${decimals > 0 ? decimal + fracPart.slice(0, decimals) : ''}`;
};

const evalFormula = (formula, price, qty, addons, val, fieldValues = {}, todayOverride = null) => {
  // Safe mirror of the server-side evaluator (per-unit formulas; the qty
  // factor was stripped at import and is re-applied by the caller).
  const today = String(todayOverride || window.OPF_TODAY || new Date().toISOString().slice(0, 10));
  const dateFormat = String(window.OPF_DATE_FORMAT || (window.wapf_config || {}).date_format || 'mm-dd-yyyy');
  const resolveFormulaDate = (rawValue) => {
    let value = String(rawValue || '').trim();
    if (value.length >= 2 && ((value[0] === "'" && value[value.length - 1] === "'") || (value[0] === '"' && value[value.length - 1] === '"'))) value = value.slice(1, -1);
    if (value === '__OPF_TODAY__') value = today;
    else if (value.toLowerCase() === '[val]') value = String(val || '').trim();
    else {
      const field = /^\[field\.([a-z0-9_-]+)\]$/i.exec(value);
      if (field) {
        const fieldValue = fieldValues[String(field[1]).toLowerCase()];
        value = fieldValue == null || Array.isArray(fieldValue) ? '' : String(fieldValue);
      }
    }
    let year;
    let month;
    let day;
    const iso = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    if (iso) {
      [, year, month, day] = iso.map((part) => Number(part));
    } else {
      const tokens = dateFormat.toLowerCase().match(/yyyy|yy|mm|m|dd|d|[-\/., ]/g);
      if (!tokens || tokens.length !== 5) return null;
      let pattern = '';
      const parts = {};
      for (const token of tokens) {
        if (['-', '/', '.', ',', ' '].includes(token)) {
          pattern += token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
          continue;
        }
        const key = token[0] === 'y' ? 'year' : (token[0] === 'm' ? 'month' : 'day');
        if (parts[key]) return null;
        parts[key] = Object.keys(parts).length + 1;
        pattern += `(\\d{${token === 'yyyy' ? '4' : (['yy', 'mm', 'dd'].includes(token) ? '2' : '1,2')}})`;
      }
      if (Object.keys(parts).length !== 3) return null;
      const match = new RegExp(`^${pattern}$`).exec(value);
      if (!match) return null;
      year = Number(match[parts.year]);
      month = Number(match[parts.month]);
      day = Number(match[parts.day]);
      if (dateFormat.toLowerCase().includes('yy') && !dateFormat.toLowerCase().includes('yyyy')) year += year < 70 ? 2000 : 1900;
    }
    if (![year, month, day].every(Number.isInteger)) return null;
    const date = new Date(0);
    date.setUTCHours(0, 0, 0, 0);
    date.setUTCFullYear(year, month - 1, day);
    if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return null;
    return { weekday: date.getUTCDay(), month };
  };
  const expr = String(formula)
    .replace(/\[price\]/gi, ' P ')
    .replace(/\[qty\]/gi, ' Q ')
    .replace(/\[addons\]|\[options_total\]/gi, ' A ')
    .replace(/today\s*\(\s*\)/gi, '__OPF_TODAY__')
    .replace(/\b(dow|month)\s*\(([^()]*)\)/gi, (_, fn, rawDate) => {
      const date = resolveFormulaDate(rawDate);
      return date ? String(fn.toLowerCase() === 'dow' ? date.weekday : date.month) : '0';
    })
    .replace(/\[val\]/gi, ' V ');
  const vars = { P: price, Q: qty, A: addons, V: parseFloat(val) || 0 };
  let i = 0;
  const s = expr;
  const skipWs = () => { while (i < s.length && /\s/.test(s[i])) i++; };
  const parseExpr = () => {
    let v = parseTerm();
    while (true) {
      skipWs();
      if (s[i] === '+') { i++; v += parseTerm(); }
      else if (s[i] === '-') { i++; v -= parseTerm(); }
      else break;
    }
    return v;
  };
  const parseTerm = () => {
    let v = parseFactor();
    while (true) {
      skipWs();
      if (s[i] === '*') { i++; v *= parseFactor(); }
      else if (s[i] === '/') { i++; const r = parseFactor(); v = r === 0 ? NaN : v / r; }
      else break;
    }
    return v;
  };
  const parseFactor = () => {
    skipWs();
    if (s[i] === '(') { i++; const v = parseExpr(); skipWs(); if (s[i] === ')') i++; return v; }
    if (s[i] === '-') { i++; return -parseFactor(); }
    const m = /^\d+(?:\.\d+)?/.exec(s.slice(i));
    if (m) { i += m[0].length; return parseFloat(m[0]); }
    i++; // force failure on unknown token
    return NaN;
  };
  skipWs();
  const out = parseExpr();
  return isFinite(out) ? out : 0;
};

const choiceAddonDisplay = (pricing, base, qty, addons, val, fieldValues = {}) => {
  const t = pricing.type;
  if (t === 'fixed') return parseFloat(pricing.amount) || 0;
  if (t === 'percent') return base * ((parseFloat(pricing.amount) || 0) / 100);
  if (t === 'formula') return evalFormula(pricing.formula_raw || pricing.formula, base, qty, addons, val, fieldValues);
  return 0;
};

const choiceOrFieldAddon = (def, value, base, qty, addons, val, fieldValues = {}) => {
  if (def.type === 'swatch' || def.type === 'select' || def.type === 'radio' || def.type === 'checkbox') {
    const slugs = Array.isArray(value) ? value : [value];
    let sum = 0;
    (def.choices || []).forEach((c) => {
      if (!slugs.includes(c.slug) || c.disabled) return;
      const p = c.pricing || {};
      if (p.type === 'fixed') sum += parseFloat(p.amount) || 0;
      else if (p.type === 'percent') sum += base * ((parseFloat(p.amount) || 0) / 100);
      else if (p.type === 'formula') sum += evalFormula(p.formula_raw || p.formula, base, qty, addons, val, fieldValues);
    });
    return sum;
  }
  const p = def.pricing || {};
  if (!String(value || '').trim()) return 0;
  if (p.type === 'fixed') return parseFloat(p.amount) || 0;
  if (p.type === 'percent') return base * ((parseFloat(p.amount) || 0) / 100);
  if (p.type === 'formula') return evalFormula(p.formula_raw || p.formula, base, qty, addons, val, fieldValues);
  return 0;
};


const writeTotals = () => {
  const totalsEl = document.querySelector('.opf-product-totals, .wapf-product-totals');
  if (!totalsEl) return;
  const base = parseFloat(totalsEl.getAttribute('data-product-price'));
  if (!isFinite(base)) return;
  const qtyInput = document.querySelector('form.cart input[name="quantity"], form.cart .qty');
  const qty = Math.max(1, parseInt(qtyInput && qtyInput.value, 10) || 1);

  let optionsTotal = 0;
  document.querySelectorAll('[data-opf-group]').forEach((groupEl) => {
    const gid = groupEl.getAttribute('data-opf-group');
    const values = {};
    const fields = groupEl.querySelectorAll('[data-opf-field]');
    fields.forEach((fieldEl) => {
      const fid = fieldEl.getAttribute('data-opf-field');
      const repeatRows = fieldEl.matches('[data-opf-repeat="button"]') ? Array.from(fieldEl.querySelectorAll('[data-opf-repeat-instance]')) : null;
      const checked = groupEl.querySelector(`[data-opf-field="${fid}"] input:checked`);
      const anyInput = fieldEl.querySelector('input:not([type=checkbox]):not([type=radio]):not([type=hidden]), textarea, select');
      if (repeatRows) {
        values[fid] = repeatRows.map((row) => {
          const rowChecked = row.querySelector('input:checked');
          if (rowChecked && rowChecked.type === 'checkbox') return Array.from(row.querySelectorAll('input:checked')).map((choice) => choice.value);
          const rowInput = rowChecked || row.querySelector('input:not([type=hidden]), textarea, select');
          return rowInput ? rowInput.value : '';
        });
      } else if (checked && checked.type === 'checkbox') {
        values[fid] = Array.from(
          groupEl.querySelectorAll(`[data-opf-field="${fid}"] input:checked`)
        ).map((c) => c.value);
      } else if (checked) {
        values[fid] = checked.value;
      } else if (anyInput) {
        values[fid] = anyInput.value;
      } else {
        values[fid] = '';
      }
    });
    fields.forEach((fieldEl) => {
      const fid = fieldEl.getAttribute('data-opf-field');
      const def = (window.OPF_FIELDS || {})[gid]?.[fid];
      if (!def) return;
      // conditional visibility: hidden fields contribute nothing
      const container = fieldEl;
      if (container.hasAttribute('hidden')) return;
      const value = values[fid];
      const addon = fieldEl.matches('[data-opf-repeat="button"]')
        ? value.reduce((sum, rowValue) => sum + choiceOrFieldAddon(def, rowValue, base, qty, optionsTotal + sum, typeof rowValue === 'string' ? rowValue : '', values), 0)
        : choiceOrFieldAddon(def, value, base, qty, optionsTotal, value && typeof value === 'string' ? value : '', values);
      optionsTotal += addon;
    });
  });

  const productTotal = base * qty;
  const grand = productTotal + optionsTotal;
  const fmtEl = (el, amount) => {
    if (!el) return;
    el.innerHTML = fmtMoney(amount);
  };
  fmtEl(totalsEl.querySelector('.opf-product-total, .wapf-product-total'), productTotal);
  fmtEl(totalsEl.querySelector('.opf-options-total, .wapf-options-total'), optionsTotal);
  fmtEl(totalsEl.querySelector('.opf-grand-total, .wapf-grand-total'), grand);
};

const initTotals = () => {
  const container = document.querySelector('[data-opf-fields]');
  if (!container) return;
  let timer = null;
  container.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(writeTotals, 50);
  });
  container.addEventListener('change', () => {
    clearTimeout(timer);
    timer = setTimeout(writeTotals, 50);
  });
  writeTotals();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initTotals);
} else {
  initTotals();
}
