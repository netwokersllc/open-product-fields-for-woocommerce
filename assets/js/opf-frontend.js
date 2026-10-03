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

const imageQuantityLimitMessage = ( def, quantities ) => {
	const total = Object.values( quantities || {} ).reduce( ( sum, quantity ) => {
		const parsed = Number( quantity );
		return sum + ( Number.isInteger( parsed ) && parsed >= 0 ? parsed : 0 );
	}, 0 );
	if ( null !== def.min_choices && undefined !== def.min_choices && total < Number( def.min_choices ) ) {
		return 'Choose at least ' + def.min_choices + ' items in total.';
	}
	if ( null !== def.max_choices && undefined !== def.max_choices && total > Number( def.max_choices ) ) {
		return 'Choose no more than ' + def.max_choices + ' items in total.';
	}
	return '';
};

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
	monthLabel.id = input.id + '-calendar-month';
	monthLabel.setAttribute( 'aria-live', 'polite' );
	monthLabel.setAttribute( 'aria-atomic', 'true' );
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
		for ( let blank = 0; blank < blanks; blank++ ) {
			const emptyCell = document.createElement( 'span' );
			emptyCell.setAttribute( 'role', 'gridcell' );
			emptyCell.setAttribute( 'aria-hidden', 'true' );
			row.appendChild( emptyCell );
		}
		let tabStopSet = false;
		for ( let day = 1; day <= dayCount; day++ ) {
			const isoDate = visibleMonth.slice( 0, 7 ) + '-' + String( day ).padStart( 2, '0' );
			const choice = document.createElement( 'button' );
			const cell = document.createElement( 'span' );
			choice.type = 'button';
			choice.textContent = String( day );
			choice.dataset.opfDate = isoDate;
			choice.setAttribute( 'aria-label', new Intl.DateTimeFormat( undefined, { dateStyle: 'full', timeZone: 'UTC' } ).format( new Date( isoDate + 'T00:00:00Z' ) ) );
			choice.disabled = dateIsBlocked( input, isoDate );
			choice.tabIndex = ! choice.disabled && ! tabStopSet && ( isoDate === input.value || ! input.value ) ? 0 : -1;
			if ( choice.tabIndex === 0 ) tabStopSet = true;
			cell.setAttribute( 'role', 'gridcell' );
			if ( isoDate === input.value ) cell.setAttribute( 'aria-selected', 'true' );
			choice.addEventListener( 'click', () => {
				input.value = isoDate;
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				panel.hidden = true;
				toggle.setAttribute( 'aria-expanded', 'false' );
				toggle.textContent = displayDate( isoDate );
				toggle.focus();
			} );
			cell.appendChild( choice );
			row.appendChild( cell );
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
	const focusDateInMonth = ( year, monthIndex, day ) => {
		const lastDay = new Date( Date.UTC( year, monthIndex + 1, 0 ) ).getUTCDate();
		const target = Math.min( day, lastDay );
		for ( let distance = 0; distance < lastDay; distance++ ) {
			for ( const direction of distance ? [ -1, 1 ] : [ 1 ] ) {
				const candidateDay = target + distance * direction;
				if ( candidateDay < 1 || candidateDay > lastDay ) continue;
				const isoDate = year + '-' + String( monthIndex + 1 ).padStart( 2, '0' ) + '-' + String( candidateDay ).padStart( 2, '0' );
				const button = grid.querySelector( 'button[data-opf-date="' + isoDate + '"]:not(:disabled)' );
				if ( ! button ) continue;
				grid.querySelectorAll( 'button[data-opf-date]' ).forEach( ( dayButton ) => { dayButton.tabIndex = dayButton === button ? 0 : -1; } );
				button.focus();
				return;
			}
		}
	};
	previous.addEventListener( 'click', () => changeMonth( -1 ) );
	next.addEventListener( 'click', () => changeMonth( 1 ) );
	toggle.addEventListener( 'click', () => {
		panel.hidden = ! panel.hidden;
		toggle.setAttribute( 'aria-expanded', String( ! panel.hidden ) );
		render();
		if ( ! panel.hidden ) ( grid.querySelector( '[aria-selected="true"] button:not(:disabled)' ) || grid.querySelector( '[tabindex="0"]' ) || next ).focus();
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
		if ( 'PageUp' === event.key || 'PageDown' === event.key ) {
			const monthDelta = ( 'PageUp' === event.key ? -1 : 1 ) * ( event.shiftKey ? 12 : 1 );
			const currentDate = new Date( current.dataset.opfDate + 'T00:00:00Z' );
			const targetMonth = new Date( Date.UTC( currentDate.getUTCFullYear(), currentDate.getUTCMonth() + monthDelta, 1 ) );
			changeMonth( monthDelta );
			focusDateInMonth( targetMonth.getUTCFullYear(), targetMonth.getUTCMonth(), currentDate.getUTCDate() );
			event.preventDefault();
			return;
		}
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
			if ( 'upload' === def.type ) return Array.from( instance.querySelectorAll( '[data-opf-upload-token]' ), ( input ) => input.value );
			if ( 'image_quantity' === def.type ) {
				const quantities = {};
				instance.querySelectorAll( '.opf-image-quantity__input' ).forEach( ( input ) => { quantities[ input.dataset.choiceSlug ] = Math.max( 0, parseInt( input.value, 10 ) || 0 ); } );
				return { _opf_type: 'image_quantity', quantities };
			}
			if ( 'products' === def.type ) {
				if ( def.qty_selector ) {
					const quantities = {};
					instance.querySelectorAll( 'input.opf-qty.is-qty' ).forEach( ( input ) => { quantities[ input.dataset.choiceSlug ] = Math.max( 0, parseInt( input.value, 10 ) || 0 ); } );
					return { _opf_type: 'products', quantities };
				}
				if ( def.multiple ) {
					return Array.from( instance.querySelectorAll( '.opf-product-input:checked' ) ).map( ( input ) => input.value );
				}
			}
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
		const readFieldValue = ( fieldEl, def ) => fieldEl.hasAttribute( 'data-opf-section-repeat' )
			? []
			: fieldEl.matches( '[data-opf-repeat="button"], [data-opf-repeat="quantity"]' )
				? Array.from( fieldEl.querySelectorAll( '.opf-field-repeat__rows > [data-opf-repeat-instance]' ) ).map( ( instance ) => readInstanceValue( instance, def ) )
			: readInstanceValue( fieldEl, def );
		const valuesForField = ( fieldEl ) => {
			const instance = fieldEl.closest( '[data-opf-section-repeat] [data-opf-repeat-instance]' );
			if ( ! instance ) return values;
			const scoped = { ...values };
			instance.querySelectorAll( '[data-opf-field]' ).forEach( ( scopedField ) => {
				if ( scopedField.hasAttribute( 'data-opf-section-repeat' ) ) return;
				const scopedId = scopedField.getAttribute( 'data-opf-field' );
				const scopedDef = fieldDefs[ scopedId ] || registry[ scopedId ] || { type: 'text', conditionals: [] };
				scoped[ scopedId ] = readFieldValue( scopedField, scopedDef );
			} );
			return scoped;
		};
		const updateSectionInstanceIds = ( instance, index ) => {
			instance.querySelectorAll( '[id]' ).forEach( ( element ) => {
				const baseId = element.dataset.opfSectionBaseId || element.id.replace( /-section-\d+$/, '' );
				element.dataset.opfSectionBaseId = baseId;
				element.id = baseId + '-section-' + index;
			} );
			instance.querySelectorAll( 'label[for]' ).forEach( ( label ) => {
				const baseId = label.dataset.opfSectionBaseFor || label.htmlFor.replace( /-section-\d+$/, '' );
				label.dataset.opfSectionBaseFor = baseId;
				label.htmlFor = baseId + '-section-' + index;
			} );
		};
		const updateRequiredRepeaters = () => {
			groupEl.querySelectorAll( '[data-opf-repeat].opf-required' ).forEach( ( repeater ) => {
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

		const quantitySyncers = [];
		groupEl.querySelectorAll( '[data-opf-repeat="button"]' ).forEach( ( repeater ) => {
			const rows = repeater.querySelector( '.opf-field-repeat__rows' );
			const first = rows && rows.querySelector( ':scope > [data-opf-repeat-instance]' );
			if ( ! rows || ! first ) return;
			const template = first.cloneNode( true );
			const max = Math.max( 1, Number( repeater.dataset.opfRepeatMax ) || 10000 );
			const repeatDef = registry[ repeater.dataset.opfField ] || {};
			const sectionRepeat = repeater.hasAttribute( 'data-opf-section-repeat' );
			const baseRowLabel = template.querySelector( sectionRepeat ? '.opf-section-repeat__label span' : '.opf-field-label span' );
			const baseLabelText = baseRowLabel ? baseRowLabel.textContent : '';
			const update = () => {
				const instances = Array.from( rows.querySelectorAll( ':scope > [data-opf-repeat-instance]' ) );
				instances.forEach( ( instance, index ) => {
					const rowLabel = instance.querySelector( sectionRepeat ? '.opf-section-repeat__label span' : '.opf-field-label span' );
					if ( rowLabel ) {
						const customLabel = repeatDef.repeat && repeatDef.repeat.label;
						rowLabel.textContent = index > 0 && customLabel ? customLabel.replace( /\{n\}/g, String( index + 1 ) ) : baseLabelText;
					}
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
					if ( sectionRepeat ) updateSectionInstanceIds( instance, index );
					else {
						instance.querySelectorAll( '[id]' ).forEach( ( element ) => {
							element.id = element.id.replace( /-repeat-\d+/, '-repeat-' + index );
						} );
						instance.querySelectorAll( 'label[for]' ).forEach( ( label ) => {
							label.htmlFor = label.htmlFor.replace( /-repeat-\d+/, '-repeat-' + index );
						} );
					}
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
					if ( rows.querySelectorAll( ':scope > [data-opf-repeat-instance]' ).length >= max ) return;
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
					if ( rows.querySelectorAll( ':scope > [data-opf-repeat-instance]' ).length <= 1 ) return;
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
		groupEl.querySelectorAll( '[data-opf-repeat="quantity"]' ).forEach( ( repeater ) => {
			const rows = repeater.querySelector( '.opf-field-repeat__rows' );
			const first = rows && rows.querySelector( ':scope > [data-opf-repeat-instance]' );
			if ( ! rows || ! first ) return;
			const template = first.cloneNode( true );
			const repeatDef = registry[ repeater.dataset.opfField ] || {};
			const sectionRepeat = repeater.hasAttribute( 'data-opf-section-repeat' );
			const baseRowLabel = template.querySelector( sectionRepeat ? '.opf-section-repeat__label span' : '.opf-field-label span' );
			const baseLabelText = baseRowLabel ? baseRowLabel.textContent : '';
			const quantityInput = document.querySelector( 'form.cart input[name="quantity"], form.cart .qty' );
			const resetClone = ( clone ) => {
				clone.querySelectorAll( '.opf-date-picker' ).forEach( ( picker ) => picker.remove() );
				clone.querySelectorAll( 'input' ).forEach( ( input ) => {
					if ( input.type === 'hidden' ) input.value = '0';
					else if ( input.type === 'checkbox' || input.type === 'radio' ) input.checked = false;
					else input.value = '';
				} );
				clone.querySelectorAll( 'textarea' ).forEach( ( input ) => { input.value = ''; } );
				clone.querySelectorAll( 'select' ).forEach( ( input ) => { input.selectedIndex = 0; } );
				clone.querySelectorAll( '.opf-checked' ).forEach( ( element ) => element.classList.remove( 'opf-checked' ) );
			};
			const syncQuantity = () => {
				const target = Math.max( 1, parseInt( quantityInput && quantityInput.value, 10 ) || 1 );
				let instances = Array.from( rows.querySelectorAll( ':scope > [data-opf-repeat-instance]' ) );
				while ( instances.length < target ) {
					const clone = template.cloneNode( true );
					resetClone( clone );
					rows.appendChild( clone );
					const dateInput = clone.querySelector( 'input[type="date"]' );
					if ( dateInput ) initDatePicker( clone, dateInput );
					instances.push( clone );
				}
				while ( instances.length > target ) instances.pop().remove();
				instances.forEach( ( instance, index ) => {
					const rowLabel = instance.querySelector( sectionRepeat ? '.opf-section-repeat__label span' : '.opf-field-label span' );
					const customLabel = repeatDef.repeat && repeatDef.repeat.label;
					if ( rowLabel ) rowLabel.textContent = index > 0 && customLabel ? customLabel.replace( /\{n\}/g, String( index + 1 ) ) : baseLabelText;
					instance.querySelectorAll( '[name]' ).forEach( ( input ) => {
						input.name = input.name.replace( /\[\d+\](\[\])?$/, '[' + index + ']$1' );
					} );
					if ( sectionRepeat ) updateSectionInstanceIds( instance, index );
					else {
						instance.querySelectorAll( '[id]' ).forEach( ( element ) => {
							element.id = element.id.replace( /-repeat-\d+/, '-repeat-' + index );
						} );
						instance.querySelectorAll( 'label[for]' ).forEach( ( label ) => {
							label.htmlFor = label.htmlFor.replace( /-repeat-\d+/, '-repeat-' + index );
						} );
					}
				} );
				const fid = repeater.dataset.opfField;
				values[ fid ] = readFieldValue( repeater, repeatDef );
				updateRequiredRepeaters();
				refresh();
			};
			if ( quantityInput ) {
				quantityInput.addEventListener( 'input', syncQuantity );
				quantityInput.addEventListener( 'change', syncQuantity );
			}
			quantitySyncers.push( syncQuantity );
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
			if ( fieldEl.matches( '[data-opf-repeat="button"], [data-opf-repeat="quantity"]' ) ) {
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
			groupEl.querySelectorAll( '[data-opf-field]' ).forEach( ( fieldEl ) => {
				const fid = fieldEl.getAttribute( 'data-opf-field' );
				const def = fieldDefs[ fid ] || {};
				const visible = isVisible( def, valuesForField( fieldEl ) );
				fieldEl.classList.toggle( 'opf-field--hidden', ! visible );
				fieldEl.classList.toggle( 'opf-hide', ! visible );
				fieldEl.toggleAttribute( 'hidden', ! visible );
				// Hidden required toggles must not block native form validation or submit
				// their hidden false value. Re-enable both controls when shown again.
				if ( 'toggle' === def.type ) {
					fieldEl.querySelectorAll( 'input' ).forEach( ( input ) => { input.disabled = ! visible; } );
				}
				if ( 'image_quantity' === def.type ) {
					const inputs = Array.from( fieldEl.querySelectorAll( '.opf-image-quantity__input' ) );
					const enabledInputs = inputs.filter( ( input ) => ! input.disabled );
					const quantities = {};
					enabledInputs.forEach( ( input ) => { quantities[ input.dataset.choiceSlug ] = input.value; } );
					const message = visible ? imageQuantityLimitMessage( def, quantities ) : '';
					inputs.forEach( ( input ) => input.setCustomValidity( '' ) );
					if ( enabledInputs.length ) enabledInputs[0].setCustomValidity( message );
				}
				if ( 'products' === def.type && def.qty_selector ) {
					const inputs = Array.from( fieldEl.querySelectorAll( 'input.opf-qty.is-qty' ) );
					const enabledInputs = inputs.filter( ( input ) => ! input.disabled );
					const quantities = {};
					enabledInputs.forEach( ( input ) => { quantities[ input.dataset.choiceSlug ] = input.value; } );
					const message = visible ? imageQuantityLimitMessage( def, quantities ) : '';
					inputs.forEach( ( input ) => input.setCustomValidity( '' ) );
					if ( enabledInputs.length ) enabledInputs[0].setCustomValidity( message );
				}

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
			groupEl.querySelectorAll( '.opf-swatch, .opf-card' ).forEach( ( swatch ) => {
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
			if ( fieldDefs[ fid ] && fieldDefs[ fid ].type === 'toggle' && ! fieldEl.matches( '[data-opf-repeat="button"], [data-opf-repeat="quantity"]' ) ) {
				values[ fid ] = input.checked ? '1' : '0';
			} else {
				values[ fid ] = readFieldValue( fieldEl, fieldDefs[ fid ] );
			}
			if ( input.type === 'radio' || input.type === 'checkbox' ) {
				syncChecked();
			}
			if ( fieldDefs[ fid ] && 'products' === fieldDefs[ fid ].type ) {
				refreshProductSwap( fieldEl );
			}
			updateRequiredRepeaters();
			refresh();
		} );

		// Linked-products +/− steppers (qty_selector 'plus_min' display mode).
		groupEl.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( '.opf-qty-minus, .opf-qty-plus' );
			if ( ! button ) {
				return;
			}
			const wrap = button.closest( '.opf-card-qty' );
			const input = wrap && wrap.querySelector( 'input[type="number"]' );
			if ( ! input || input.disabled ) {
				return;
			}
			const step = parseInt( input.step, 10 ) || 1;
			const min = input.min === '' ? -Infinity : parseInt( input.min, 10 );
			const max = input.max === '' ? Infinity : parseInt( input.max, 10 );
			const delta = button.classList.contains( 'opf-qty-plus' ) ? step : -step;
			input.value = Math.min( max, Math.max( min, ( parseInt( input.value, 10 ) || 0 ) + delta ) );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );

		refresh();
		syncChecked();
		quantitySyncers.forEach( ( sync ) => sync() );
	} );
};

// ---------------------------------------------------------------------------
// Linked products: swap the main WooCommerce gallery image with the selected
// child product's image (WAPF "swap main image" parity). The original image
// state is captured on first swap and restored when nothing is selected.
// ---------------------------------------------------------------------------
const gallerySwap = ( () => {
	let saved = null;
	const capture = () => {
		const root = document.querySelector( '.woocommerce-product-gallery' );
		const img = root && root.querySelector( '.wp-post-image' );
		if ( ! img ) {
			return null;
		}
		const link = img.closest( 'a' );
		const thumb = root.querySelector( '.flex-control-thumbs li:first-child img' );
		return {
			root,
			img,
			link,
			thumb,
			src: img.getAttribute( 'src' ),
			srcset: img.getAttribute( 'srcset' ),
			sizes: img.getAttribute( 'sizes' ),
			dataSrc: img.getAttribute( 'data-src' ),
			dataLarge: img.getAttribute( 'data-large_image' ),
			href: link ? link.getAttribute( 'href' ) : null,
			thumbSrc: thumb ? thumb.getAttribute( 'src' ) : null,
		};
	};
	const apply = ( state, url ) => {
		state.img.setAttribute( 'src', url );
		state.img.setAttribute( 'srcset', url );
		state.img.setAttribute( 'sizes', '100vw' );
		state.img.setAttribute( 'data-src', url );
		state.img.setAttribute( 'data-large_image', url );
		if ( state.link ) {
			state.link.setAttribute( 'href', url );
			state.link.setAttribute( 'data-large_image', url );
		}
		if ( state.thumb ) {
			state.thumb.setAttribute( 'src', url );
		}
		if ( window.jQuery ) {
			window.jQuery( state.root ).trigger( 'woocommerce_gallery_init_zoom' );
		}
	};
	return {
		swap( url ) {
			if ( ! saved ) {
				saved = capture();
			}
			if ( saved ) {
				apply( saved, url );
			}
		},
		restore() {
			if ( ! saved ) {
				return;
			}
			const state = saved;
			state.img.setAttribute( 'src', state.src );
			if ( state.srcset ) state.img.setAttribute( 'srcset', state.srcset ); else state.img.removeAttribute( 'srcset' );
			if ( state.sizes ) state.img.setAttribute( 'sizes', state.sizes ); else state.img.removeAttribute( 'sizes' );
			if ( state.dataSrc ) state.img.setAttribute( 'data-src', state.dataSrc ); else state.img.removeAttribute( 'data-src' );
			if ( state.dataLarge ) state.img.setAttribute( 'data-large_image', state.dataLarge ); else state.img.removeAttribute( 'data-large_image' );
			if ( state.link && state.href ) state.link.setAttribute( 'href', state.href );
			if ( state.thumb && state.thumbSrc ) state.thumb.setAttribute( 'src', state.thumbSrc );
			if ( window.jQuery ) {
				window.jQuery( state.root ).trigger( 'woocommerce_gallery_init_zoom' );
			}
			saved = null;
		},
	};
} )();

const refreshProductSwap = ( fieldEl ) => {
	let url = '';
	for ( const el of fieldEl.querySelectorAll( '[data-opf-swap-image]' ) ) {
		const tag = el.tagName.toLowerCase();
		if ( 'option' === tag ) {
			if ( el.selected ) {
				url = el.dataset.opfSwapImage;
				break;
			}
			continue;
		}
		if ( 'number' === el.type ) {
			if ( parseInt( el.value, 10 ) > 0 ) {
				url = el.dataset.opfSwapImage;
				break;
			}
		} else if ( el.checked ) {
			url = el.dataset.opfSwapImage;
			break;
		}
	}
	if ( url ) {
		gallerySwap.swap( url );
	} else {
		gallerySwap.restore();
	}
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
  const symbol = typeof o.symbol === 'string' ? o.symbol : '$';
  const decimals = typeof o.decimals === 'number' ? o.decimals : 2;
  const thousand = typeof o.thousand === 'string' ? o.thousand : ',';
  const decimal = typeof o.decimal === 'string' ? o.decimal : '.';
  const neg = amount < 0 ? '-' : '';
  const fixed = Math.abs(amount).toFixed(decimals);
  const [intPart, fracPart] = fixed.split('.');
  const grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousand);
  const price = `${grouped}${decimals > 0 ? decimal + fracPart.slice(0, decimals) : ''}`;
  const format = o.format || (o.price_format || 'symbolprice').replace('symbol', '%1$s').replace('price', '%2$s');
  return neg + format.replace('%1$s', symbol).replace('%2$s', price);
};

// WAPF resolves [field.X] to the first submitted value's label; submitted
// choice values are slugs, so they are translated through the def-provided
// slug→label map carried on fieldValues.__opf_labels. WAPF's field.X_slug
// suffix picks one submitted value when a field has several; OPF field ids
// may contain underscores, so the full token is tried as a field id first.
const formulaFieldLabel = (token, fieldValues) => {
  const labels = fieldValues && typeof fieldValues === 'object' ? fieldValues.__opf_labels || {} : {};
  const parts = token.split('_');
  let fid = parts[0].toLowerCase();
  let option = parts.length > 1 ? parts[1] : null;
  let value = fieldValues ? fieldValues[fid] : undefined;
  if (value === undefined) {
    const whole = token.toLowerCase();
    if (fieldValues && Object.prototype.hasOwnProperty.call(fieldValues, whole)) {
      fid = whole;
      option = null;
      value = fieldValues[fid];
    } else {
      return '';
    }
  }
  const vals = Array.isArray(value) ? value : [value];
  if (option !== null && vals.length > 1) {
    const hit = vals.find((v) => String(v) === option);
    if (hit === undefined) return '0';
    const hitLabel = labels[fid] ? labels[fid][String(hit)] : undefined;
    return hitLabel !== undefined ? String(hitLabel) : '0';
  }
  const first = vals[0];
  const scalar = first == null ? '' : String(first);
  const resolved = labels[fid] ? labels[fid][scalar] : undefined;
  return resolved !== undefined ? String(resolved) : scalar;
};

const evalFormula = (formula, price, qty, addons, val, fieldValues = {}, todayOverride = null, fieldPrices = {}, formulaOptions = {}) => {
  // Safe mirror of the server-side evaluator (per-unit formulas; the qty
  // factor was stripped at import and is re-applied by the caller).
  // WAPF Extended 3.1.5 parity: [x] aliases [val]; [var_name] variables
  // resolve via formulaOptions.variables (or window.OPF_FORMULA_VARIABLES)
  // with first-rule-wins + recursive evaluation; files() counts the
  // submitted field's comma-joined value list; lookuptable() traverses
  // window.wapf_lookup_tables/OPF_LOOKUP_TABLES/options.lookupTables; and an
  // unregistered name(...) call (e.g. WAPF's map()/reduce() spellings) is
  // evaluated through the same char-clean residual path as WAPF's
  // evaluate_math_string.
  const opts = formulaOptions && typeof formulaOptions === 'object' ? formulaOptions : {};
  const variables = Array.isArray(opts.variables) ? opts.variables : (Array.isArray(window.OPF_FORMULA_VARIABLES) ? window.OPF_FORMULA_VARIABLES : []);
  const ruleFields = Array.isArray(opts.fields) ? opts.fields : (Array.isArray(window.OPF_FORMULA_FIELDS) ? window.OPF_FORMULA_FIELDS : []);
  const lookupTables = Object.assign({}, window.wapf_lookup_tables || {}, window.OPF_LOOKUP_TABLES || {}, opts.lookupTables || {});
  if (String(formula).trim().toLowerCase() === 'true') return 1;
  if (String(formula).trim().toLowerCase() === 'false') return 0;
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
        value = formulaFieldLabel(String(field[1]), fieldValues);
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
    return { weekday: date.getUTCDay(), month, timestamp: date.getTime() };
  };
  const resolved = String(formula).replace(/\[price\.([a-z0-9_-]+)\]/gi, (_, id) => {
    const value = fieldPrices[String(id).toLowerCase()];
    const amount = Array.isArray(value) ? value.reduce((sum, item) => sum + (Number(item) || 0), 0) : Number(value);
    return String(Number.isFinite(amount) ? amount : 0);
  }).replace(/\[field\.([a-z0-9_-]+)\]/gi, (token, id) => formulaFieldLabel(String(id), fieldValues));
  const expr = resolved
    .replace(/\[price\]/gi, ' P ')
    .replace(/\[qty\]/gi, ' Q ')
    .replace(/\[addons\]|\[options_total\]/gi, ' A ')
    .replace(/today\s*\(\s*\)/gi, '__OPF_TODAY__')
    .replace(/\bdatediff\s*\(([^()]*)\)/gi, (_, rawArgs) => {
      const args = rawArgs.split(';');
      if (args.length !== 2) return '0';
      const first = resolveFormulaDate(args[0]);
      const second = resolveFormulaDate(args[1]);
      return first && second ? String(Math.round(Math.abs(second.timestamp - first.timestamp) / 86400000)) : '0';
    })
    .replace(/\b(dow|month)\s*\(([^()]*)\)/gi, (_, fn, rawDate) => {
      const date = resolveFormulaDate(rawDate);
      return date ? String(fn.toLowerCase() === 'dow' ? date.weekday : date.month) : '0';
    })
    .replace(/\[val\]|\[x\]/gi, ' V ');
  const functionNames = new Set(['min', 'max', 'len', 'checked', 'sumqty', 'round', 'abs', 'floor', 'ceil', 'sqrt', 'pow', 'sin', 'cos', 'tan', 'if', 'or', 'and', 'files', 'lookuptable']);
  // WAPF split_formula_variables parity: top-level ';' separates arguments
  // with bracket-depth awareness only (quote-blind — ';' inside quotes
  // still splits) and a trailing separator yields no empty final argument.
  const splitArguments = (input) => {
    const parts = [];
    let start = 0;
    let depth = 0;
    for (let index = 0; index < input.length; index++) {
      const char = input[index];
      if (char === '(') depth++;
      else if (char === ')') depth--;
      else if (depth === 0 && (char === ';' || char === ',')) {
        parts.push(input.slice(start, index).trim());
        start = index + 1;
      }
    }
    const last = input.slice(start).trim();
    if (last !== '' || !parts.length) parts.push(last);
    return parts;
  };
  // One WAPF variable rule (Fields::is_valid_rule parity). Subject 'qty'
  // reads the line quantity; other subjects need a known field definition
  // (or, when no defs were provided, a submitted value as stand-in — the
  // documented OPF fallback matching the PHP port).
  const variableRulePasses = (rule) => {
    const subject = String(rule && rule.field != null ? rule.field : '');
    const condition = String(rule && rule.condition != null ? rule.condition : '');
    const ruleValue = rule && rule.value != null ? String(rule.value) : '';
    let value = null;
    if (subject === 'qty') {
      value = qty;
    } else {
      const defs = ruleFields.length ? ruleFields : Object.keys(fieldValues || {}).map((key) => ({ id: key, type: '' }));
      const def = defs.find((f) => String(f && f.id != null ? f.id : '').toLowerCase() === subject.toLowerCase());
      if (!def) return false;
      if (condition.indexOf('product_var') >= 0) {
        const ids = ruleValue.split(',').map((v) => v.trim());
        const inList = ids.includes(String(opts.productId != null ? opts.productId : (window.OPF_PRODUCT_ID || 0)));
        return condition === 'product_var' ? inList : !inList;
      }
      if (condition.indexOf('patts') >= 0) {
        const attributes = (opts.productAttributes || window.OPF_PRODUCT_ATTRIBUTES || {});
        const has = ruleValue.split(',').some((pair) => {
          const parts = pair.split('|');
          const list = attributes['pa_' + parts[0]];
          return Array.isArray(list) && (parts[1] === '*' || list.includes(parts[1]));
        });
        return condition === 'patts' ? has : !has;
      }
      const key = subject.toLowerCase();
      if (!Object.prototype.hasOwnProperty.call(fieldValues || {}, key)) return false;
      value = fieldValues[key];
      if (value == null) return false;
      if (String(def.type || '') === 'date' && ruleValue !== '') {
        const ruleDate = resolveFormulaDate(ruleValue);
        value = value !== '' && resolveFormulaDate(value) ? resolveFormulaDate(value).timestamp : value;
        return variableDateCondition(condition, value, ruleDate ? ruleDate.timestamp : null);
      }
    }
    switch (condition) {
      case 'check': return String(value) === '1';
      case '!check': return String(value) === '0';
      case '==': return Array.isArray(value) ? value.includes(ruleValue) : String(value) === ruleValue;
      case '!=': return Array.isArray(value) ? !value.includes(ruleValue) : String(value) !== ruleValue;
      case 'empty': return (Array.isArray(value) && value.length === 0) || value === '' || value == null;
      case '!empty': return !((Array.isArray(value) && value.length === 0) || value === '' || value == null);
      case '==contains': return Array.isArray(value) ? value.includes(ruleValue) : String(value).indexOf(ruleValue) !== -1;
      case '!=contains': return Array.isArray(value) ? !value.includes(ruleValue) : String(value).indexOf(ruleValue) === -1;
      case 'lt': return parseFloat(value) < parseFloat(ruleValue);
      case 'gt': return parseFloat(value) > parseFloat(ruleValue);
      case 'gtd': return !!value && value > ruleValue;
      case 'ltd': return !!value && value < ruleValue;
      default: return false;
    }
  };
  const variableDateCondition = (condition, value, ruleTimestamp) => {
    if (ruleTimestamp == null) {
      if (condition === '==') return [ruleTimestamp].includes ? [value].includes(ruleTimestamp) : false;
      if (condition === '!=') return !(Array.isArray(value) ? value.includes(ruleTimestamp) : value === ruleTimestamp);
      return false;
    }
    if (condition === '==') return !!value && value === ruleTimestamp;
    if (condition === '!=') return !(!!value && value === ruleTimestamp);
    if (condition === 'gtd') return !!value && value > ruleTimestamp;
    if (condition === 'ltd') return !!value && value < ruleTimestamp;
    return false;
  };
  // [var_name] tokens → evaluated numeric string; first matching rule wins;
  // nested vars expand recursively (depth-capped where WAPF loops forever).
  const expandVariables = (input, depth) => {
    if (input.indexOf('[var_') === -1) return input;
    if (depth > 16) return null;
    const expanded = String(input).replace(/\[var_.+?]/g, (match) => {
      const name = match.replace(/\[var_|]/g, '');
      const variable = variables.find((candidate) => candidate && String(candidate.name) === name);
      if (!variable) return '0';
      let text = variable.default != null ? String(variable.default) : '';
      for (const rule of Array.isArray(variable.rules) ? variable.rules : []) {
        if (variableRulePasses(rule || {})) { text = rule.variable != null ? String(rule.variable) : ''; break; }
      }
      const nested = expandVariables(text, depth + 1);
      if (nested == null) return '0';
      const result = evalFormula(nested, price, qty, addons, val, fieldValues, todayOverride, fieldPrices, opts);
      return String(Number.isFinite(result) ? result : 0);
    });
    return expanded;
  };
  const exprVars = expandVariables(expr, 0);
  if (exprVars == null) return 0;
  const comparisonParts = (input) => {
    let depth = 0;
    let quote = '';
    for (let index = 0; index < input.length; index++) {
      const char = input[index];
      if (quote) {
        if (char === quote && input[index - 1] !== '\\') quote = '';
        continue;
      }
      if (char === "'" || char === '"') { quote = char; continue; }
      if (char === '(') { depth++; continue; }
      if (char === ')') { depth--; continue; }
      if (depth !== 0) continue;
      const two = input.slice(index, index + 2);
      const operator = ['!=', '<=', '>='].includes(two) ? two : ['=', '<', '>'].includes(char) ? char : '';
      if (operator) return [input.slice(0, index).trim(), operator, input.slice(index + operator.length).trim()];
    }
    return null;
  };
  const comparisonValue = (raw) => {
    const value = raw.trim();
    if (value.length >= 2 && ((value[0] === "'" && value.at(-1) === "'") || (value[0] === '"' && value.at(-1) === '"'))) return value.slice(1, -1);
    if (value.toLowerCase() === 'true') return true;
    if (value.toLowerCase() === 'false') return false;
    if (/^[\d\s().+*\/-]+$/.test(value) && value !== '') return evalFormula(value, price, qty, addons, val, fieldValues, todayOverride);
    return value;
  };
  const conditionPasses = (condition) => {
    const parts = comparisonParts(condition);
    if (!parts) return ['true', '1'].includes(condition.trim().toLowerCase());
    let [left, operator, right] = parts.map((part, index) => index === 1 ? part : comparisonValue(part));
    if (typeof left === 'number' && typeof right === 'number') {
      // Keep numeric comparisons numeric; text comparisons remain exact strings.
    } else if (!Number.isNaN(Number(left)) && !Number.isNaN(Number(right)) && String(left).trim() !== '' && String(right).trim() !== '') {
      left = Number(left);
      right = Number(right);
    }
    switch (operator) {
      case '=': return left === right;
      case '!=': return left !== right;
      case '<': return left < right;
      case '>': return left > right;
      case '<=': return left <= right;
      case '>=': return left >= right;
      default: return false;
    }
  };
  // Literal port of WAPF's browser evalFx residual evaluation: the
  // reference strips every non-math character (letters too — unlike the PHP
  // side which keeps e/E) and evaluates what remains; this is what WAPF's
  // frontend emits for unregistered spellings like map()/reduce().
  const wapfResidualEval = (text) => {
    const parse = (num) => parseFloat(String(num).replace(',', '.'));
    const inner = (raw) => {
      let hadMulDiv = false;
      let hadAddSub = false;
      let n = 0;
      let e = String(raw).replace(/[^\d.+\-*\/()]/gi, '');
      if (e.indexOf('(') !== -1 && e.indexOf(')') !== -1) {
        const paren = /\(([\d.+\-*\/]+)\)/;
        const match = e.match(paren) || [];
        if (match.length > 1) return inner(e.replace(paren, inner(match[1])));
      }
      e = e.replace('(', '').replace(')', '');
      if (e.indexOf('/') !== -1 || e.indexOf('*') !== -1) {
        hadMulDiv = true;
        const ops = ['/', '*'];
        while (ops.length) {
          const op = ops.pop();
          while (op && e.indexOf(op) !== -1) {
            const re = new RegExp('([\\d.]+)\\' + op + '(\\-?[\\d.]+)');
            const m = e.match(re) || [];
            if (!(m.length > 2)) return 0;
            n = op === '*' ? parse(m[1]) * parse(m[2]) : parse(m[1]) / parse(m[2]);
            e = e.replace(re, n).replace('++', '+').replace('--', '+').replace('-+', '-').replace('+-', '-');
          }
        }
      }
      if (e.indexOf('+') !== -1 || e.indexOf('-') !== -1) {
        hadAddSub = true;
        const tokens = (e = e.replace('--', '+')).match(/([\d.]+|[+\-])/g) || [];
        if (tokens.length > 0) {
          n = 0;
          let op = '+';
          for (const token of tokens) {
            if (token === '+' || token === '-') op = token;
            else n = op === '+' ? n + parse(token) : n - parse(token);
          }
        }
      }
      return n = !hadMulDiv && !hadAddSub ? parse(e) : n;
    };
    return inner(text);
  };
  // WAPF lookuptable nearest-axis: JS reference uses a truthy exact-key hit,
  // numeric-sorted keys, strictly-below-first clamping, round-up between
  // keys, and undefined beyond the last (traversal then fails to 0).
  const lookupNearestKey = (value, axis) => {
    if (axis && axis['' + value]) return value;
    const keys = Object.keys(axis || {}).map((key) => parseFloat(key)).sort((a, b) => a - b);
    const numeric = parseFloat(value);
    if (numeric < keys[0]) return keys[0];
    for (let i = 0; i < keys.length; i++) {
      if (numeric > keys[i] && numeric <= keys[i + 1]) return keys[i + 1];
    }
    return keys[keys.length];
  };
  const expandFunctions = (input, depth = 0) => {
    if (depth > 16) return null;
    let output = '';
    let index = 0;
    while (index < input.length) {
      const char = input[index];
      if (!/[a-z_]/i.test(char)) { output += char; index++; continue; }
      let end = index + 1;
      while (end < input.length && /[a-z0-9_]/i.test(input[end])) end++;
      const name = input.slice(index, end).toLowerCase();
      let open = end;
      while (open < input.length && /\s/.test(input[open])) open++;
      if (input[open] !== '(') {
        output += input.slice(index, end);
        index = end;
        continue;
      }
      let close = open + 1;
      let nesting = 1;
      for (; close < input.length; close++) {
        const innerChar = input[close];
        if (innerChar === '(') nesting++;
        else if (innerChar === ')' && --nesting === 0) break;
      }
      if (nesting !== 0) return null;
      const expandedInner = expandFunctions(input.slice(open + 1, close), depth + 1);
      if (expandedInner === null) return null;
      if (!functionNames.has(name)) {
        output += String(wapfResidualEval(input.slice(index, end) + '(' + expandedInner + ')'));
        index = close + 1;
        continue;
      }
      const args = splitArguments(expandedInner);
      const number = (arg) => evalFormula(arg, price, qty, addons, val, fieldValues, todayOverride, fieldPrices, opts);
      let result;
      switch (name) {
        case 'min': result = args.length ? Math.min(...args.map(number)) : 0; break;
        case 'max': result = args.length ? Math.max(...args.map(number)) : 0; break;
        case 'len': {
          let value = String(args[0]);
          // A bare [x]/[val] measures the submitted text (WAPF replaces the
          // token before len runs), not the numeric ' V ' placeholder.
          if (value.trim().toLowerCase() === 'v') value = String(val ?? '');
          if (args[1] === 'true') value = value.replace(/\s/g, '');
          result = value.length;
          break;
        }
        case 'checked': {
          const fieldId = (args[0] || '').replace(/^['"]|['"]$/g, '').trim().toLowerCase();
          const selected = fieldValues[fieldId];
          result = Array.isArray(selected) ? selected.length : 0;
          break;
        }
        case 'sumqty': {
          const fieldId = (args[0] || '').replace(/^['"]|['"]$/g, '').trim().toLowerCase();
          const value = fieldValues[fieldId];
          const quantities = value && value._opf_type === 'image_quantity' ? value.quantities : null;
          result = quantities && typeof quantities === 'object'
            ? Object.values(quantities).reduce((sum, quantity) => {
              const isQuantity = typeof quantity === 'number' || typeof quantity === 'string';
              return sum + (isQuantity && /^\d+$/.test(String(quantity)) ? Number(quantity) : 0);
            }, 0)
            : 0;
          break;
        }
        case 'round': {
          const precision = args.length > 1 && args[1] !== '' ? Math.trunc(number(args[1])) : 0;
          const factor = 10 ** precision;
          const value = number(args[0] || '0');
          result = Math.sign(value) * Math.round(Math.abs(value) * factor) / factor;
          break;
        }
        case 'abs': result = Math.abs(number(args[0] || '0')); break;
        case 'floor': result = Math.floor(number(args[0] || '0')); break;
        case 'ceil': result = Math.ceil(number(args[0] || '0')); break;
        case 'sqrt': result = Math.sqrt(number(args[0] || '0')); break;
        case 'pow': result = args.length === 2 ? number(args[0]) ** number(args[1]) : NaN; break;
        case 'sin': result = Math.sin(number(args[0] || '0')); break;
        case 'cos': result = Math.cos(number(args[0] || '0')); break;
        case 'tan': result = Math.tan(number(args[0] || '0')); break;
        case 'if': result = args.length === 3 ? number(conditionPasses(args[0]) ? args[1] : args[2]) : NaN; break;
        case 'or': result = args.some(conditionPasses) ? 1 : 0; break;
        case 'and': result = args.every(conditionPasses) ? 1 : 0; break;
        // WAPF files(id): the comma-joined upload list of the submitted
        // field value; OPF upload arrays count their non-empty tokens.
        case 'files': {
          const fieldId = (args[0] || '').replace(/^['"]|['"]$/g, '').trim().toLowerCase();
          const submitted = fieldValues ? fieldValues[fieldId] : undefined;
          if (Array.isArray(submitted)) result = submitted.filter((item) => String(item == null ? '' : item).trim() !== '').length;
          else result = String(submitted == null ? '' : submitted).trim() === '' ? 0 : String(submitted).split(',').length;
          break;
        }
        // WAPF lookuptable(table;dim;…): <6-char args are literals, longer
        // args resolve a field id's first submitted label; nearest-axis
        // traversal mirrors views/frontend/lookup-tables.php (fail closed).
        case 'lookuptable': {
          result = 0;
          try {
            const tableName = String(args[0] == null ? '' : args[0]).trim();
            const table = lookupTables[tableName];
            if (!table || typeof table !== 'object') break;
            const tableValues = [];
            let prev = table;
            let failed = false;
            for (let k = 1; k < args.length; k++) {
              const arg = String(args[k]).trim();
              let v;
              if (arg.length < 6) {
                v = arg;
              } else {
                const fid = arg.toLowerCase();
                if (!fieldValues || !Object.prototype.hasOwnProperty.call(fieldValues, fid)) { failed = true; break; }
                v = formulaFieldLabel(fid, fieldValues);
                if (v === '') { failed = true; break; }
              }
              if (prev == null || typeof prev !== 'object') { failed = true; break; }
              const n = lookupNearestKey(v, prev);
              if (n === undefined || n === null) { failed = true; break; }
              tableValues.push(n);
              prev = prev[n];
            }
            if (failed) break;
            let leaf = table;
            for (const key of tableValues) {
              if (leaf == null || typeof leaf !== 'object' || !Object.prototype.hasOwnProperty.call(leaf, key)) { failed = true; break; }
              leaf = leaf[key];
            }
            if (failed) break;
            const numeric = Number(leaf);
            result = Number.isFinite(numeric) ? numeric : 0;
          } catch (lookupError) {
            result = 0;
          }
          break;
        }
      }
      output += Number.isFinite(result) ? String(result) : 'NaN';
      index = close + 1;
    }
    return output;
  };
  const expandedExpr = expandFunctions(exprVars);
  if (expandedExpr === null) return 0;
  const vars = { P: price, Q: qty, A: addons, V: parseFloat(val) || 0 };
  let i = 0;
  const s = expandedExpr;
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
    if (Object.prototype.hasOwnProperty.call(vars, s[i])) return vars[s[i++]];
    const m = /^\d+(?:\.\d+)?/.exec(s.slice(i));
    if (m) { i += m[0].length; return parseFloat(m[0]); }
    i++; // force failure on unknown token
    return NaN;
  };
  skipWs();
  const out = parseExpr();
  return isFinite(out) ? out : 0;
};

// Per-unit contribution of one pricing block (WAPF do_pricing parity):
// normal fields → per_unit ? result : result/qty; quantity-repeat (WAPF
// clone_type=qty) fields → per_unit ? result*qty : result. A formula that
// still references [qty] verbatim (no normalized formula_raw, or formula_raw
// reduces to the stored formula) is a WAPF fx line-space expression: the line
// adds eval(formula) once, so per_unit does not apply.
const verbatimWapfFx = (pricing) => {
  if (pricing.type !== 'formula') return false;
  const formula = String(pricing.formula || '');
  if (!/\[qty\]/i.test(formula)) return false;
  const raw = String(pricing.formula_raw || '').trim();
  if (!raw) return true;
  return formula === raw.split('[options_total]').join('[addons]');
};
const choiceUnitAddon = (pricing, base, qty, addons, val, fieldValues = {}, fieldPrices = {}, formulaBase = base, qtyBased = false) => {
  const t = pricing.type;
  let result = 0;
  if (t === 'fixed') result = parseFloat(pricing.amount) || 0;
  else if (t === 'percent') result = base * ((parseFloat(pricing.amount) || 0) / 100);
  else if (t === 'formula') result = evalFormula(pricing.formula || pricing.formula_raw, formulaBase, qty, addons, val, fieldValues, null, fieldPrices);
  else return 0;
  if (verbatimWapfFx(pricing)) return qtyBased ? result : result / qty;
  const perUnit = pricing.per_unit !== undefined && pricing.per_unit !== null
    ? !!pricing.per_unit
    : !(t === 'fixed' || t === 'formula');
  return qtyBased ? (perUnit ? result * qty : result) : (perUnit ? result : result / qty);
};

const choiceOrFieldAddon = (def, value, base, qty, addons, val, fieldValues = {}, fieldPrices = {}, formulaBase = base, qtyBased = false) => {
  if (def.type === 'toggle' && String(value ?? '') !== '1') return 0;
  if (def.type === 'image_quantity') {
    const quantities = value && value._opf_type === 'image_quantity' ? value.quantities || {} : {};
    return (def.choices || []).reduce((sum, choice) => {
      const count = Math.max(0, parseInt(quantities[choice.slug], 10) || 0);
      if (!count || choice.disabled) return sum;
      // WAPF image-swatch-qty passes the entered count into do_pricing as
      // the value label: nr/nrq/[x] formulas consume it; the pricing type
      // decides whether the count multiplies the charge.
      return sum + choiceUnitAddon(choice.pricing || {}, base, qty, addons, String(count), fieldValues, fieldPrices, formulaBase, qtyBased);
    }, 0);
  }
  if (def.type === 'swatch' || def.type === 'select' || def.type === 'radio' || def.type === 'checkbox') {
    const slugs = Array.isArray(value) ? value : [value];
    let sum = 0;
    (def.choices || []).forEach((c) => {
      if (!slugs.includes(c.slug) || c.disabled) return;
      // WAPF passes the selected choice's label as the pricing value.
      sum += choiceUnitAddon(c.pricing || {}, base, qty, addons, String(c.label ?? ''), fieldValues, fieldPrices, formulaBase, qtyBased);
    });
    return sum;
  }
  if (!String(value || '').trim()) return 0;
  return choiceUnitAddon(def.pricing || {}, base, qty, addons, val, fieldValues, fieldPrices, formulaBase, qtyBased);
};


const writeTotals = () => {
  const totalsEl = document.querySelector('.opf-product-totals, .wapf-product-totals');
  if (!totalsEl) return;
  const config = window.opf_config || {};
  const base = parseFloat(config.product_base_price ?? totalsEl.getAttribute('data-product-price'));
  if (!isFinite(base)) return;
  const formulaBase = Number.isFinite(Number(config.formula_base_price)) ? Number(config.formula_base_price) : base;
  const rate = Number.isFinite(Number(config.currency_rate)) && Number(config.currency_rate) > 0 ? Number(config.currency_rate) : 1;
  const qtyInput = document.querySelector('form.cart input[name="quantity"], form.cart .qty');
  const qty = Math.max(1, parseInt(qtyInput && qtyInput.value, 10) || 1);

  // The server validates every quantity repeater against product quantity
  // before it splits cart lines. Never show a payable total for mismatched or
  // gapped rows while the customer edits the form.
  const quantityRepeaters = [];
  let invalidQuantityRows = false;
  document.querySelectorAll('[data-opf-group]').forEach((groupEl) => {
    const gid = groupEl.getAttribute('data-opf-group');
    groupEl.querySelectorAll('[data-opf-repeat="quantity"][data-opf-field]').forEach((repeater) => {
      const rows = repeater.querySelector(':scope > .opf-field-repeat__rows');
      const instances = rows ? Array.from(rows.querySelectorAll(':scope > [data-opf-repeat-instance]')) : [];
      if (instances.length !== qty) invalidQuantityRows = true;
      instances.forEach((instance, index) => {
        const namedInputs = Array.from(instance.querySelectorAll('[name]'));
        if (namedInputs.some((input) => {
          const match = /\[(\d+)\](?:\[\])?$/.exec(input.name);
          return !match || Number(match[1]) !== index;
        })) invalidQuantityRows = true;
      });
      quantityRepeaters.push({ gid, repeater, instances });
    });
  });
  if (invalidQuantityRows) {
    totalsEl.querySelectorAll('.opf-product-total, .wapf-product-total, .opf-options-total, .wapf-options-total, .opf-grand-total, .wapf-grand-total').forEach((el) => { el.textContent = ''; });
    return;
  }

  const readRepeatControl = (element, def) => {
    if (def.type === 'image_quantity') {
      const quantities = {};
      element.querySelectorAll('.opf-image-quantity__input').forEach((input) => { quantities[input.dataset.choiceSlug] = Math.max(0, parseInt(input.value, 10) || 0); });
      return { _opf_type: 'image_quantity', quantities };
    }
    if (def.type === 'toggle') {
      const checkbox = element.querySelector('input[type="checkbox"]');
      return checkbox && checkbox.checked ? '1' : '0';
    }
    if (def.type === 'checkbox' || (def.type === 'swatch' && def.multiple)) {
      return Array.from(element.querySelectorAll('input[type="checkbox"]:checked')).map((input) => input.value);
    }
    const checked = element.querySelector('input:checked');
    const input = checked || element.querySelector('input:not([type="hidden"]), textarea, select');
    return input ? input.value : '';
  };
  // Match the product-wide server split signature. Repeater labels are
  // presentation only; cart unit identity is group id + field id + row value.
  const unitSigs = quantityRepeaters.length
    ? Array.from({ length: qty }, (_, unitIndex) => JSON.stringify(quantityRepeaters.map(({ gid, repeater, instances }) => {
      const fid = repeater.getAttribute('data-opf-field');
      const instance = instances[unitIndex];
      if (repeater.hasAttribute('data-opf-section-repeat')) {
        const scoped = {};
        if (instance) {
          instance.querySelectorAll('[data-opf-field]').forEach((scopedField) => {
            const scopedId = scopedField.getAttribute('data-opf-field');
            if (!scopedId || scopedField.hasAttribute('data-opf-section-repeat')) return;
            scoped[scopedId] = readRepeatControl(scopedField, (window.OPF_FIELDS || {})[gid]?.[scopedId] || {});
          });
        }
        return [gid + ':' + fid, scoped];
      }
      return [gid + ':' + fid, instance ? readRepeatControl(instance, (window.OPF_FIELDS || {})[gid]?.[fid] || {}) : null];
    })))
    : [];

  let optionsTotal = 0; // line-space display total
  let addonsPU = 0; // running per-unit addon sum — the [addons] context space
  const fieldPrices = {};
  document.querySelectorAll('[data-opf-group]').forEach((groupEl) => {
    const gid = groupEl.getAttribute('data-opf-group');
    const values = {};
    const fields = groupEl.querySelectorAll('[data-opf-field]');
    const readControlValue = (element, def) => {
      if (def.type === 'image_quantity') {
        const quantities = {};
        element.querySelectorAll('.opf-image-quantity__input').forEach((input) => { quantities[input.dataset.choiceSlug] = Math.max(0, parseInt(input.value, 10) || 0); });
        return { _opf_type: 'image_quantity', quantities };
      }
      if (def.type === 'toggle') {
        const checkbox = element.querySelector('input[type="checkbox"]');
        return checkbox && checkbox.checked ? '1' : '0';
      }
      if (def.type === 'checkbox' || (def.type === 'swatch' && def.multiple)) {
        return Array.from(element.querySelectorAll('input[type="checkbox"]:checked')).map((input) => input.value);
      }
      const checked = element.querySelector('input:checked');
      const input = checked || element.querySelector('input:not([type="hidden"]), textarea, select');
      return input ? input.value : '';
    };
    const readFieldControl = (fieldEl, def) => fieldEl.matches('[data-opf-repeat]')
      ? Array.from(fieldEl.querySelectorAll('.opf-field-repeat__rows > [data-opf-repeat-instance]')).map((instance) => readControlValue(instance, def))
      : readControlValue(fieldEl, def);
    const valuesForFormula = (fieldEl, rowIndex = null) => {
      const scoped = { ...values };
      const sectionInstance = fieldEl.closest('[data-opf-section-repeat] [data-opf-repeat-instance]');
      if (sectionInstance) {
        sectionInstance.querySelectorAll('[data-opf-field]').forEach((scopedField) => {
          if (scopedField.hasAttribute('data-opf-section-repeat')) return;
          const scopedId = scopedField.getAttribute('data-opf-field');
          const scopedDef = (window.OPF_FIELDS || {})[gid]?.[scopedId] || {};
          scoped[scopedId] = readFieldControl(scopedField, scopedDef);
        });
      }
      if (rowIndex !== null) {
        fields.forEach((scopedField) => {
          if (!scopedField.matches('[data-opf-repeat]') || scopedField.hasAttribute('data-opf-section-repeat')) return;
          const instances = scopedField.querySelectorAll('.opf-field-repeat__rows > [data-opf-repeat-instance]');
          if (!instances[rowIndex]) return;
          const scopedId = scopedField.getAttribute('data-opf-field');
          const scopedDef = (window.OPF_FIELDS || {})[gid]?.[scopedId] || {};
          const instance = instances[rowIndex];
          scoped[scopedId] = readControlValue(instance, scopedDef);
        });
      }
      return scoped;
    };
      fields.forEach((fieldEl) => {
      const fid = fieldEl.getAttribute('data-opf-field');
      const fieldDef = (window.OPF_FIELDS || {})[gid]?.[fid] || {};
      const repeatRows = fieldEl.matches('[data-opf-repeat]') ? Array.from(fieldEl.querySelectorAll('[data-opf-repeat-instance]')) : null;
      const checked = groupEl.querySelector(`[data-opf-field="${fid}"] input:checked`);
      const anyInput = fieldEl.querySelector('input:not([type=checkbox]):not([type=radio]):not([type=hidden]), textarea, select');
      if (repeatRows) {
        values[fid] = repeatRows.map((row) => {
          const rowChecked = row.querySelector('input:checked');
          if (rowChecked && rowChecked.type === 'checkbox') return Array.from(row.querySelectorAll('input:checked')).map((choice) => choice.value);
          const rowInput = rowChecked || row.querySelector('input:not([type=hidden]), textarea, select');
          return rowInput ? rowInput.value : '';
        });
      } else if (fieldDef.type === 'image_quantity') {
        const quantities = {};
        fieldEl.querySelectorAll('.opf-image-quantity__input').forEach((input) => { quantities[input.dataset.choiceSlug] = Math.max(0, parseInt(input.value, 10) || 0); });
        values[fid] = { _opf_type: 'image_quantity', quantities };
      } else if (fieldDef.type === 'upload') {
        values[fid] = Array.from(fieldEl.querySelectorAll('[data-opf-upload-token]'), (input) => input.value);
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
    const choiceLabels = {};
    fields.forEach((fieldEl) => {
      const fid = fieldEl.getAttribute('data-opf-field');
      const def = (window.OPF_FIELDS || {})[gid]?.[fid];
      if (!def || !Array.isArray(def.choices)) return;
      const map = {};
      def.choices.forEach((choice) => {
        if (choice && choice.slug != null && choice.label != null) map[String(choice.slug)] = String(choice.label);
      });
      if (Object.keys(map).length) choiceLabels[String(fid).toLowerCase()] = map;
    });
    values.__opf_labels = choiceLabels;
    // WAPF clone_type=qty parity: quantity-repeat units merge into cart lines
    // whose quantity is the count of identical units. The clone signature is
    // the WHOLE unit — every quantity-scope field's value at that index —
    // exactly what CartIntegration::split_quantity_repeat_cart_item hashes.
    // unitIndex → { count, firstIndex } — identical-unit group size + leader.
    const unitGroups = unitSigs.map((sig, unitIndex) => {
      let count = 0;
      let firstIndex = -1;
      unitSigs.forEach((other, otherIndex) => {
        if (other === sig) { if (firstIndex < 0) firstIndex = otherIndex; count++; }
      });
      return { count, firstIndex };
    });
    fields.forEach((fieldEl) => {
      const fid = fieldEl.getAttribute('data-opf-field');
      const def = (window.OPF_FIELDS || {})[gid]?.[fid];
      if (!def) return;
      // conditional visibility: hidden fields contribute nothing
      const container = fieldEl;
      if (container.hasAttribute('hidden')) {
        // WAPF resolves [price.ID] from the first matching source, even when
        // that source is hidden. Do not promote a later duplicate's price.
        if (!Object.prototype.hasOwnProperty.call(fieldPrices, fid)) fieldPrices[fid] = 0;
        return;
      }
      const sectionInstance = fieldEl.closest('[data-opf-section-repeat] [data-opf-repeat-instance]');
      const sectionRepeater = sectionInstance && sectionInstance.closest('[data-opf-section-repeat]');
      const sectionRows = sectionRepeater ? Array.from(sectionRepeater.querySelectorAll('.opf-field-repeat__rows > [data-opf-repeat-instance]')) : [];
      const sectionIndex = sectionInstance ? sectionRows.indexOf(sectionInstance) : null;
      const value = sectionInstance ? readFieldControl(fieldEl, def) : values[fid];
      const sectionQtyRepeat = sectionRepeater && sectionRepeater.getAttribute('data-opf-repeat') === 'quantity';
      if (fieldEl.matches('[data-opf-repeat]') && Array.isArray(value)) {
        const isQtyRepeat = fieldEl.getAttribute('data-opf-repeat') === 'quantity' && unitGroups.length;
        // 'quantity' rows collapse to one merged unit per signature; 'button'
        // rows stay separate units on the same cart line.
        const rows = isQtyRepeat
          ? Array.from(value.reduce((map, rowValue, rowIndex) => {
            const group = unitGroups[rowIndex] || { count: 1, firstIndex: rowIndex };
            if (group.firstIndex !== rowIndex) return map;
            map.set(unitSigs[rowIndex], { rowValue, count: group.count, firstIndex: rowIndex });
            return map;
          }, new Map()).values())
          : value.map((rowValue, rowIndex) => ({ rowValue, count: 1, firstIndex: rowIndex }));
        const rowPrices = [];
        let fieldPU = 0;
        rows.forEach((row) => {
          const rowQty = isQtyRepeat ? row.count : qty;
          const clonePrices = Object.fromEntries(Object.entries(fieldPrices).map(([previousId, previousPrice]) => [
            previousId,
            Array.isArray(previousPrice) ? (previousPrice[row.firstIndex] || 0) : previousPrice,
          ]));
          const rowPU = choiceOrFieldAddon(def, row.rowValue, base, rowQty, addonsPU + fieldPU, typeof row.rowValue === 'string' ? row.rowValue : '', valuesForFormula(fieldEl, row.firstIndex), clonePrices, formulaBase, isQtyRepeat);
          rowPrices.push(rowPU);
          fieldPU += rowPU;
          optionsTotal += rowPU * rowQty;
        });
        if (!Object.prototype.hasOwnProperty.call(fieldPrices, fid)) fieldPrices[fid] = rowPrices;
        addonsPU += fieldPU;
      } else {
        // Inner fields of a quantity-mode section repeat belong to the merged
        // clone line: per-unit context is the identical-unit count, and only
        // the group's first instance contributes.
        const sectionGroup = sectionQtyRepeat && sectionIndex !== null && unitGroups[sectionIndex] ? unitGroups[sectionIndex] : null;
        if (sectionGroup && sectionGroup.firstIndex !== sectionIndex) return;
        const rowQty = sectionGroup ? sectionGroup.count : qty;
        const scopedPrices = sectionGroup
          ? Object.fromEntries(Object.entries(fieldPrices).map(([previousId, previousPrice]) => [
            previousId,
            Array.isArray(previousPrice) ? (previousPrice[sectionIndex] || 0) : previousPrice,
          ]))
          : fieldPrices;
        const fieldPU = choiceOrFieldAddon(def, value, base, rowQty, addonsPU, value && typeof value === 'string' ? value : '', valuesForFormula(fieldEl, sectionIndex), scopedPrices, formulaBase, !!sectionQtyRepeat);
        if (sectionGroup) {
          const perRow = Array.isArray(fieldPrices[fid]) ? fieldPrices[fid] : [];
          perRow[sectionIndex] = fieldPU;
          fieldPrices[fid] = perRow;
        } else if (!Object.prototype.hasOwnProperty.call(fieldPrices, fid)) {
          fieldPrices[fid] = fieldPU;
        }
        optionsTotal += fieldPU * rowQty;
        addonsPU += fieldPU;
      }
    });
  });

  const productTotal = base * qty;
  const grand = productTotal + optionsTotal;
  const fmtEl = (el, amount) => {
    if (!el) return;
    el.innerHTML = fmtMoney(amount * rate);
  };
  fmtEl(totalsEl.querySelector('.opf-product-total, .wapf-product-total'), productTotal);
  fmtEl(totalsEl.querySelector('.opf-options-total, .wapf-options-total'), optionsTotal);
  fmtEl(totalsEl.querySelector('.opf-grand-total, .wapf-grand-total'), grand);
};

const initTotals = () => {
  const container = document.querySelector('[data-opf-fields]');
  if (!container) return;
  if (window.jQuery) {
    const originalBase = (window.opf_config || {}).product_base_price;
    const originalFormulaBase = (window.opf_config || {}).formula_base_price;
    window.jQuery('form.variations_form').on('found_variation.opf', (_event, variation) => {
      const totals = document.querySelector('.opf-product-totals, .wapf-product-totals');
      if (!totals) return;
      const config = window.opf_config = window.opf_config || {};
      config.product_base_price = variation.opf_base_price ?? variation.display_price;
      config.formula_base_price = variation.opf_formula_base_price ?? config.product_base_price;
      writeTotals();
    }).on('reset_data.opf', () => {
      const config = window.opf_config = window.opf_config || {};
      config.product_base_price = originalBase;
      config.formula_base_price = originalFormulaBase;
      writeTotals();
    });
  }
  let timer = null;
  container.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(writeTotals, 50);
  });
  container.addEventListener('change', () => {
    clearTimeout(timer);
    timer = setTimeout(writeTotals, 50);
  });
  document.querySelectorAll('form.cart input[name="quantity"], form.cart .qty').forEach((input) => {
    input.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(writeTotals, 50);
    });
    input.addEventListener('change', () => {
      clearTimeout(timer);
      timer = setTimeout(writeTotals, 50);
    });
  });
  writeTotals();
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initTotals);
} else {
  initTotals();
}

// Tooltip-triggered descriptions (description_presentation=tooltip).
// A mouse click fires focusin BEFORE click — without the focusOpenedAt guard
// the click would toggle the just-opened tooltip straight back closed. Escape
// refocuses the trigger, so suppressFocusUntil keeps focusin from reopening it.
(function () {
  let focusOpenedAt = 0;
  let suppressFocusUntil = 0;
  const closeAll = (except) => {
    document.querySelectorAll('.opf-tooltip-trigger[aria-expanded="true"]').forEach((t) => {
      if (except && t === except) return;
      t.setAttribute('aria-expanded', 'false');
    });
  };
  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('.opf-tooltip-trigger');
    if (trigger) {
      closeAll(trigger);
      if (Date.now() - focusOpenedAt < 600) {
        trigger.setAttribute('aria-expanded', 'true'); // click was the focusing gesture
      } else {
        const open = trigger.getAttribute('aria-expanded') === 'true';
        trigger.setAttribute('aria-expanded', open ? 'false' : 'true');
      }
      event.stopPropagation();
      return;
    }
    closeAll();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      const open = document.querySelector('.opf-tooltip-trigger[aria-expanded="true"]');
      if (open) {
        open.setAttribute('aria-expanded', 'false');
        suppressFocusUntil = Date.now() + 600;
        open.focus();
      }
    }
    if (event.key === 'Enter' || event.key === ' ') {
      const trigger = event.target.closest && event.target.closest('.opf-tooltip-trigger');
      if (trigger) {
        const open = trigger.getAttribute('aria-expanded') === 'true';
        closeAll();
        trigger.setAttribute('aria-expanded', open ? 'false' : 'true');
        focusOpenedAt = 0;
        event.preventDefault();
      }
    }
  });
  document.addEventListener('focusin', (event) => {
    const trigger = event.target.closest && event.target.closest('.opf-tooltip-trigger');
    if (trigger && Date.now() >= suppressFocusUntil) {
      closeAll(trigger);
      trigger.setAttribute('aria-expanded', 'true');
      focusOpenedAt = Date.now();
    }
  });
  document.addEventListener('focusout', (event) => {
    const trigger = event.target.closest && event.target.closest('.opf-tooltip-trigger');
    if (trigger && !trigger.contains(event.relatedTarget)) {
      setTimeout(() => trigger.setAttribute('aria-expanded', 'false'), 0);
    }
  });
})();
