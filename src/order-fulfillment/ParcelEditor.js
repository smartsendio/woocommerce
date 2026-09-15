/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The parcels row (section 1.2-D): a summary ("2 parcels · 1.20 kg") with
 * an "Edit" action, and the editor - one block per box with an OPTIONAL
 * weight (placeholder: the computed sum of its units, what the server
 * books with when left empty) and optional L×W×H, and every order unit in
 * exactly one box. A box lists one row per product line with units in it
 * (name + SKU, "× count / of total") and two arrows: ▲ moves one unit of
 * that line to the box above, ▼ one unit to the box below - creating a
 * new box when there is none - so a line can be split across boxes in
 * any proportion. An emptied box stays (it may carry an explicit weight)
 * with a "Remove box" control (never the first box); "Reset to one
 * parcel" clears a stored split.
 */
import { createElement } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { boxLines, computedBoxWeight, emptyBox, formatWeight, moveOneUnit, totalWeight } from './model';
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

	const moveUp = ( id, boxIndex ) => {
		if ( boxIndex === 0 ) {
			return;
		}
		update( boxes, moveOneUnit( units, assignment, id, boxIndex, boxIndex - 1 ) );
	};

	// Below the last box a new one is created for the moved unit.
	const moveDown = ( id, boxIndex ) => {
		const nextBoxes = boxIndex === boxes.length - 1 ? [ ...boxes, emptyBox() ] : boxes;
		update( nextBoxes, moveOneUnit( units, assignment, id, boxIndex, boxIndex + 1 ) );
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
						const lines = boxLines( units, assignment, boxIndex );

						return (
							<div className="smart-send-fulfillment__box" data-ss-box={ boxIndex + 1 } key={ boxIndex }>
								<div className="smart-send-fulfillment__inline-actions">
									<strong>
										{
											/* translators: %d: box number. */
											sprintf( __( 'Box %d', 'smart-send-logistics' ), boxIndex + 1 )
										}
									</strong>
									{ lines.length === 0 && <span className="description">{ __( '(empty)', 'smart-send-logistics' ) }</span> }
									{ /* An emptied box stays until removed explicitly; the first box never goes. */ }
									{ lines.length === 0 && boxIndex > 0 && (
										<Button variant="link" size="small" isDestructive data-ss-action="remove-box" onClick={ () => removeBox( boxIndex ) } disabled={ disabled }>
											{ __( 'Remove box', 'smart-send-logistics' ) }
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

								{ lines.length > 0 && (
									<ul className="smart-send-fulfillment__lines">
										{ lines.map( ( line ) => (
											<li className="smart-send-fulfillment__line" key={ line.id } data-ss-line={ line.id }>
												<span className="smart-send-fulfillment__line-text">
													<span className="smart-send-fulfillment__line-name" title={ line.name } data-ss-value="line.name">{ line.name }</span>
													{ line.sku && (
														<span className="smart-send-fulfillment__line-sku" title={ line.sku } data-ss-value="line.sku">
															{
																/* translators: %s: the product SKU. */
																sprintf( __( 'SKU: %s', 'smart-send-logistics' ), line.sku )
															}
														</span>
													) }
												</span>
												<span className="smart-send-fulfillment__line-quantity">
													<span data-ss-value="line.count">{ '× ' + line.count }</span>
													<span className="description" data-ss-value="line.total">
														{
															/* translators: %d: the line's total number of units on the order. */
															sprintf( __( 'of %d', 'smart-send-logistics' ), line.total )
														}
													</span>
												</span>
												<span className="smart-send-fulfillment__line-move">
													<button
														type="button"
														className="button"
														data-ss-action="move-up"
														aria-label={ __( 'Move one unit to the box above', 'smart-send-logistics' ) }
														title={ __( 'Move one unit to the box above', 'smart-send-logistics' ) }
														onClick={ () => moveUp( line.id, boxIndex ) }
														disabled={ disabled || boxIndex === 0 }
													>
														▲
													</button>
													<button
														type="button"
														className="button"
														data-ss-action="move-down"
														aria-label={ __( 'Move one unit to the box below', 'smart-send-logistics' ) }
														title={ __( 'Move one unit to the box below', 'smart-send-logistics' ) }
														onClick={ () => moveDown( line.id, boxIndex ) }
														disabled={ disabled }
													>
														▼
													</button>
												</span>
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
