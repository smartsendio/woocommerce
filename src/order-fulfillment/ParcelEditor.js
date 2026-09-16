/** @jsxRuntime classic */
/** @jsx createElement */
/**
 * The parcels section (section 1.2-D): collapsed by default to one header
 * line - "Parcels", the summary ("2 parcels · 1.20 kg"), a help icon and
 * an "Edit" link; "Edit" expands the editor under the same header (with
 * a "Done" button in place of Edit, which collapses it again keeping the
 * edited plan - it is submitted with the booking). The editor is one
 * grey card per box with an OPTIONAL
 * weight (placeholder: the computed sum of its units, what the server
 * books with when left empty) and optional L×W×H, and every order unit in
 * exactly one box. A box lists one row per product line with units in it
 * (name + SKU, "× count / of total") and two arrows: ▲ moves one unit of
 * that line to the box above, ▼ one unit to the box below - creating a
 * new box when there is none - so a line can be split across boxes in
 * any proportion. A box is never empty: when its last unit moves out it
 * is removed (its explicit weight/dimensions with it) and the boxes below
 * renumber; ▼ on a lone unit in the last box is disabled, since it would
 * only spawn a box while emptying this one. New boxes come from ▼ alone;
 * "Reset to one parcel" clears a stored split.
 */
import { createElement } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { boxLines, computedBoxWeight, dropBoxIfEmpty, emptyBox, formatWeight, moveOneUnit, totalWeight } from './model';
import { FieldError } from './ErrorNotice';
import DetailRow from './Row';

export const PARCELS_HELP = __( 'Move items between boxes with the arrows. Weight is calculated from the items unless you enter one.', 'smart-send-logistics' );

const DIMENSIONS = [
	[ 'length', 'L' ],
	[ 'width', 'W' ],
	[ 'height', 'H' ],
];

export default function ParcelEditor( { units, boxes, assignment, editing, editable = true, onEdit, onDone, onChange, errors, disabled } ) {
	const update = ( nextBoxes, nextAssignment ) => onChange( nextBoxes, nextAssignment );

	const setBoxValue = ( boxIndex, key, value ) => {
		const nextBoxes = boxes.map( ( box, index ) => ( index === boxIndex ? { ...box, [ key ]: value } : box ) );
		update( nextBoxes, assignment );
	};

	// Move one unit of a line and drop the source box when that emptied it.
	const move = ( id, boxIndex, nextBoxes, target ) => {
		const moved = moveOneUnit( units, assignment, id, boxIndex, target );
		const next = dropBoxIfEmpty( nextBoxes, moved, boxIndex );
		update( next.boxes, next.assignment );
	};

	const moveUp = ( id, boxIndex ) => {
		if ( boxIndex === 0 ) {
			return;
		}
		move( id, boxIndex, boxes, boxIndex - 1 );
	};

	// Below the last box a new one is created for the moved unit.
	const moveDown = ( id, boxIndex ) => {
		const isLast = boxIndex === boxes.length - 1;
		move( id, boxIndex, isLast ? [ ...boxes, emptyBox() ] : boxes, boxIndex + 1 );
	};

	// A lone unit in the last box cannot move down: it would only spawn a
	// box while emptying this one.
	const isStuckBelow = ( line, boxIndex, lines ) => boxIndex === boxes.length - 1 && lines.length === 1 && line.count === 1;

	const reset = () => update( [ emptyBox() ], units.map( () => 0 ) );

	const summary = sprintf(
		/* translators: 1: number of parcels, 2: total weight in kg. */
		_n( '%1$d parcel · %2$s kg', '%1$d parcels · %2$s kg', boxes.length, 'smart-send-logistics' ),
		boxes.length,
		formatWeight( totalWeight( units, boxes, assignment ) )
	);

	return (
		<DetailRow
			className="smart-send-fulfillment__section"
			section="parcel_plan"
			label={ __( 'Parcels', 'smart-send-logistics' ) }
			help={ PARCELS_HELP }
			summary={ <span className="smart-send-fulfillment__summary" data-ss-value="parcel_plan.summary">{ summary }</span> }
			action="edit-parcels"
			editable={ editable }
			editing={ editing }
			onEdit={ onEdit }
			control={ editing && (
				<Button variant="secondary" size="small" className="smart-send-fulfillment__done" data-ss-action="done-parcels" onClick={ onDone } disabled={ disabled }>
					{ __( 'Done', 'smart-send-logistics' ) }
				</Button>
			) }
		>
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
														aria-label={ isStuckBelow( line, boxIndex, lines )
															? __( 'Cannot move down: this is the only unit in the last box and a box must not be empty', 'smart-send-logistics' )
															: __( 'Move one unit to the box below', 'smart-send-logistics' ) }
														title={ isStuckBelow( line, boxIndex, lines )
															? __( 'Cannot move down: this is the only unit in the last box and a box must not be empty', 'smart-send-logistics' )
															: __( 'Move one unit to the box below', 'smart-send-logistics' ) }
														onClick={ () => moveDown( line.id, boxIndex ) }
														disabled={ disabled || isStuckBelow( line, boxIndex, lines ) }
													>
														▼
													</button>
												</span>
											</li>
										) ) }
								</ul>
							</div>
						);
					} ) }

					<button type="button" className="button-link smart-send-fulfillment__reset" data-ss-action="reset-parcels" onClick={ reset } disabled={ disabled }>
						{ __( 'Reset to one parcel', 'smart-send-logistics' ) }
					</button>
				</div>
			) }
		</DetailRow>
	);
}
