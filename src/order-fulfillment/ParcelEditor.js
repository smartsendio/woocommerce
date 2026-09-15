/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The parcels row (section 1.2-D): a summary ("2 parcels · 1.20 kg") with
 * an "Edit" action, and the editor - one block per box with an OPTIONAL
 * weight (placeholder: the computed sum of its units, what the server
 * books with when left empty) and optional L×W×H, every order unit in
 * exactly one box (moved between boxes with a select), boxes added and
 * removed (empty boxes are allowed - a box may carry only an explicit
 * weight), and "Reset to one parcel", which clears a stored split.
 */
import { createElement } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { computedBoxWeight, emptyBox, formatWeight, totalWeight } from './model';
import { FieldError } from './ErrorNotice';

const DIMENSIONS = [
	[ 'length', 'L' ],
	[ 'width', 'W' ],
	[ 'height', 'H' ],
];

export default function ParcelEditor( { units, boxes, assignment, editing, onEdit, onDone, onChange, errors, disabled } ) {
	const update = ( nextBoxes, nextAssignment ) => onChange( nextBoxes, nextAssignment );

	const setBoxValue = ( boxIndex, key, value ) => {
		const nextBoxes = boxes.map( ( box, index ) => ( index === boxIndex ? { ...box, [ key ]: value } : box ) );
		update( nextBoxes, assignment );
	};

	const moveUnit = ( unitIndex, boxIndex ) => {
		const nextAssignment = assignment.map( ( current, index ) => ( index === unitIndex ? boxIndex : current ) );
		update( boxes, nextAssignment );
	};

	const addBox = () => update( [ ...boxes, emptyBox() ], assignment );

	const removeBox = ( boxIndex ) => {
		if ( boxes.length === 1 ) {
			return;
		}
		const nextBoxes = boxes.filter( ( box, index ) => index !== boxIndex );
		// Units of the removed box go to the first box; later boxes shift up.
		const nextAssignment = assignment.map( ( current ) => {
			if ( current === boxIndex ) {
				return 0;
			}
			return current > boxIndex ? current - 1 : current;
		} );
		update( nextBoxes, nextAssignment );
	};

	const reset = () => update( [ emptyBox() ], units.map( () => 0 ) );

	const summary = sprintf(
		/* translators: 1: number of parcels, 2: total weight in kg. */
		_n( '%1$d parcel · %2$s kg', '%1$d parcels · %2$s kg', boxes.length, 'smart-send-logistics' ),
		boxes.length,
		formatWeight( totalWeight( units, boxes, assignment ) )
	);

	return (
		<div className="smart-send-fulfillment__row" data-ss-section="parcel_plan">
			<div className="smart-send-fulfillment__inline-actions">
				<strong>{ __( 'Parcels', 'smart-send-logistics' ) }</strong>
				<span data-ss-value="parcel_plan.summary">{ summary }</span>
				{ editing ? (
					<Button variant="secondary" size="small" data-ss-action="parcels-done" onClick={ onDone } disabled={ disabled }>
						{ __( 'Done', 'smart-send-logistics' ) }
					</Button>
				) : (
					<Button variant="link" size="small" data-ss-action="edit-parcels" onClick={ onEdit } disabled={ disabled }>
						{ __( 'Edit', 'smart-send-logistics' ) }
					</Button>
				) }
			</div>
			<FieldError field="parcel_plan" errors={ errors } />

			{ editing && (
				<div className="smart-send-fulfillment__boxes" data-ss-section="parcel_editor">
					{ boxes.map( ( box, boxIndex ) => {
						const computed = computedBoxWeight( units, assignment, boxIndex );
						const unitIndexes = units.map( ( unit, index ) => index ).filter( ( index ) => assignment[ index ] === boxIndex );

						return (
							<div className="smart-send-fulfillment__box" data-ss-box={ boxIndex + 1 } key={ boxIndex }>
								<div className="smart-send-fulfillment__inline-actions">
									<strong>
										{
											/* translators: %d: box number. */
											sprintf( __( 'Box %d', 'smart-send-logistics' ), boxIndex + 1 )
										}
									</strong>
									{ unitIndexes.length === 0 && <span className="description">{ __( '(empty)', 'smart-send-logistics' ) }</span> }
									{ boxes.length > 1 && (
										<Button variant="link" size="small" isDestructive data-ss-action="remove-box" onClick={ () => removeBox( boxIndex ) } disabled={ disabled }>
											{ __( 'Remove', 'smart-send-logistics' ) }
										</Button>
									) }
								</div>

								<div className="smart-send-fulfillment__measures">
									<label>
										<span>{ __( 'Weight (kg)', 'smart-send-logistics' ) }</span>
										<input
											type="text"
											inputMode="decimal"
											data-ss-field={ `parcel_plan.specs[${ boxIndex }].weight` }
											value={ box.weight }
											placeholder={ formatWeight( computed ) }
											onChange={ ( event ) => setBoxValue( boxIndex, 'weight', event.target.value ) }
											disabled={ disabled }
											autoComplete="off"
										/>
									</label>
									{ DIMENSIONS.map( ( [ key, short ] ) => (
										<label key={ key }>
											<span>{ short + ' (cm)' }</span>
											<input
												type="text"
												inputMode="decimal"
												data-ss-field={ `parcel_plan.specs[${ boxIndex }].${ key }` }
												value={ box[ key ] }
												onChange={ ( event ) => setBoxValue( boxIndex, key, event.target.value ) }
												disabled={ disabled }
												autoComplete="off"
											/>
										</label>
									) ) }
								</div>
								{ [ 'weight', 'length', 'width', 'height' ].map( ( key ) => (
									<FieldError key={ key } field={ `parcel_plan.specs[${ boxIndex }].${ key }` } errors={ errors } />
								) ) }
								<FieldError field={ `parcel_plan.specs[${ boxIndex }]` } errors={ errors } />

								{ unitIndexes.length > 0 && (
									<ul className="smart-send-fulfillment__units">
										{ unitIndexes.map( ( unitIndex ) => (
											<li key={ unitIndex } data-ss-unit={ units[ unitIndex ].id }>
												<span>{ units[ unitIndex ].name }</span>
												<select
													data-ss-field={ `parcel_plan.units[${ unitIndex }].box` }
													value={ assignment[ unitIndex ] }
													onChange={ ( event ) => moveUnit( unitIndex, parseInt( event.target.value, 10 ) ) }
													disabled={ disabled }
													autoComplete="off"
												>
													{ boxes.map( ( other, otherIndex ) => (
														<option key={ otherIndex } value={ otherIndex }>
															{
																/* translators: %d: box number. */
																sprintf( __( 'Box %d', 'smart-send-logistics' ), otherIndex + 1 )
															}
														</option>
													) ) }
												</select>
											</li>
										) ) }
									</ul>
								) }
							</div>
						);
					} ) }

					<div className="smart-send-fulfillment__inline-actions">
						<Button variant="secondary" size="small" data-ss-action="add-box" onClick={ addBox } disabled={ disabled }>
							{ __( '+ Add box', 'smart-send-logistics' ) }
						</Button>
						<Button variant="tertiary" size="small" data-ss-action="reset-parcels" onClick={ reset } disabled={ disabled }>
							{ __( 'Reset to one parcel', 'smart-send-logistics' ) }
						</Button>
					</div>
				</div>
			) }
		</div>
	);
}
