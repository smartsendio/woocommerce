<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/********************* Primary Class Start**********************************************************/	
class Smartsend_Logistics_PrimaryClass {
	
	public function calculate_shipping($package = array(),$x){
		global $woocommerce;
        	if($woocommerce->customer->shipping_country){
                        $customerCountry = $woocommerce->customer->shipping_country;       
                    }else{
                        $customerCountry = $woocommerce->customer->country;    
                    }
                    
			$x->rate = array();
                        
			$shipping_rates = get_option( $x->table_rate_option );
			if(empty($shipping_rates)) $shipping_rates = $x->table_rates;
			
			$totalPrice = $woocommerce->cart->cart_contents_total;

			$totalPrice = (float) $totalPrice;

			$virtualPrice = 0;
			$shipping_cost = 0;
            $weight = 0 ;
			$discount_total = 0.00;
            $sc = array('all');
			foreach ( $woocommerce->cart->get_cart() as $item ) {
                               
				if ( ! $item['data']->is_virtual() ){
					$shipping_cost += $item['data']->get_price() * $item['quantity'];
					$weight += $item['data']->get_weight()*$item['quantity']; 
					if($item['data']->get_shipping_class()){
						 $sc[] = $item['data']->get_shipping_class();
					}
				} else {
					$virtualPrice += $item['data']->get_price() * $item['quantity'];
				}
			}

			if ( ! empty( $woocommerce->cart->applied_coupons ) ) {
				foreach ( $woocommerce->cart->applied_coupons as $key => $code ) {
					$coupon = new WC_Coupon( $code );

					$couponAmount = (float) $coupon->amount;

					switch ( $coupon->type ) {
						case "fixed_cart" :
							if ( $couponAmount > $totalPrice ) {
								$couponAmount = $totalPrice;
							}
							$discount_total = (float) $discount_total - $couponAmount;
						break;

						case "percent" :
							$percent_discount = (float) round( ( $totalPrice * ( $couponAmount * 0.01 ) ) );
							if ( $percent_discount > $totalPrice ) {
								$percent_discount = $totalPrice;
							}
							$discount_total = (float) $discount_total - $percent_discount;
						break;
					}
				}
			}
			$cheapestexpensive = '';
			if( $x->get_option( 'apply_when' ) == "after"  && !empty($discount_total) ) {
				$shipping_cost = $totalPrice + $discount_total;
			}
			if($x->get_option( 'cheap_expensive' )== 'cheapest'){
				 $cheapestexpensive = 'cheapest';
			}
			if($x->get_option( 'cheap_expensive' )== 'expensive'){
				 $cheapestexpensive = 'expensive';
			}
			$price = (float) $shipping_cost; //Sets the Price that we will calculate the shipping
			$shipping_costs = -1;
			$theFirst = 0;

			if(!empty($shipping_rates)){
                
                //This array will contain the valid shipping methods                
				$shp = array();  
				
				foreach ( $shipping_rates as $rates ) {
					$countries = explode(',', $rates['country']);
                	$countries = array_map("strtoupper", $countries);
                    $countries = array_map("trim", $countries);
                	in_array(strtoupper($customerCountry), $countries);
                                        
					if ( ( (float)$price >= (float)$rates['minO'] ) && ( (float)$price <= (float)$rates['maxO'] )
						&& ( (float)$weight >= (float)$rates['minwO'] ) && ( (float)$weight <= (float)$rates['maxwO'] )
						//&& ($rates['class'] == 'all' || $rates['class'] == $sc)
                        && in_array(strtolower($rates['class']), array_map('strtolower', $sc))
						&& in_array(strtoupper($customerCountry), $countries)
						) {
						// The shipping rate is valid.
						
						if(isset($shp[$rates['methods']]) && $shp[$rates['methods']] != '') {
							//There is already a shipping method with the name in the array of valid shipping methods.
							if ( $cheapestexpensive == 'cheapest' && ( (float) $shp[$rates['methods']]['shippingO'] > (float) $rates['shippingO'] )) {
								//This 
								$shp[$rates['methods']] = $rates;
							} elseif ( $cheapestexpensive == 'expensive' && ( (float) $shp[$rates['methods']]['shippingO'] < (float) $rates['shippingO'] )) {
								$shp[$rates['methods']] = $rates;
							}
						} else {
							//Add the shipping method to the array of valid methods.
							$shp[$rates['methods']] = $rates;
						}
					}
				}
				
				$dformat = get_option( 'woocommerce_pickup_display_dropdown_format', 1 );
				foreach ( $shp as $rates ) {		
					if($rates['method_name']){
						switch ($dformat ) {
							case "0" :
								$mname = ' - '.$rates['method_name'];
								break;
							case "1" :
								$mname = ' ('.$rates['method_name'].')';
								break;
							case "2" :
								$mname = ' - ('.$rates['method_name'].')';
								break;
							case "3" :
								$mname = ' '.$rates['method_name'];
								break;
							case "4" :
								$mname = '-('.$rates['method_name'].')';
								break;                     
						}
                                               
						$rate = array(
								'id'        => $x->id.'_'.$rates['methods'],
								'label'     => $x->title.$mname,
								'cost'      => $rates['shippingO'],
								'calc_tax'  => 'per_order'
						);
						$x->add_rate( $rate );
					}                  
                }
			}
	}
	
	
	function process_table_rates($x) {
			
			// Array that will contain all the shipping methods
			$table_rates = array();
			
			// Load the posted tablerates
			$rates = $_POST[ $x->id . '_tablerate'];
			
			// Go through each rate
			foreach($rates as $rate) {
				// Add to table rates array
					$table_rates[] = array(
                        'class'    		=> (string) $rate[ 'class' ],
                        'methods'  		=> (string) $rate[ 'methods' ],
						'minO'    		=> (float) $rate[ 'minO' ],
						'maxO'    		=> (float) $rate[ 'maxO' ],
                        'minwO'    		=> (float) $rate[ 'minwO' ],
						'maxwO'    		=> (float) $rate[ 'maxwO' ],
						'shippingO' 	=> (float) $rate[ 'shippingO' ],
                        'country' 		=> (string) $rate[ 'country' ],
                        'method_name' 	=> (string) $rate[ 'method_name' ]
					);
			}
			
			// Save rates if any
			update_option( $x->table_rate_option, $table_rates );
			$x->get_table_rates();
			
		}

		
		function save_default_costs( $fields ) {
                   
			$default_minO = woocommerce_clean( $_POST['default_minO'] );
			$default_maxO  = woocommerce_clean( $_POST['default_maxO'] );
			$default_shippingO  = woocommerce_clean( $_POST['default_shippingO'] );

			$fields['minO'] = $default_minO;
			$fields['maxO']  = $default_maxO;
			$fields['shippingO']  = $default_shippingO;

			return $fields;
		}
		
		
					
	  function generate_shipping_table_html($x) {
			global $woocommerce;
			ob_start();
			?>
			<tr valign="top">
				<th scope="row" class="titledesc"><?php _e( 'Price', 'RPTR_CORE_TEXT_DOMAIN' ); ?>:</th>
				<td class="forminp" id="<?php echo $x->id; ?>_table_rates">
					<table class="shippingrows widefat" cellspacing="0">
						<thead>
							<tr>
								<th class="check-column"><input type="checkbox"></th>
                                <th><?php _e( 'Shipping Class', 'RPTR_CORE_TEXT_DOMAIN' ); ?> <a class="tips" data-tip="<?php _e( 'Shipping Class.', 'RPTR_CORE_TEXT_DOMAIN' ); ?>">[?]</a></th>
                                <th><?php _e( 'Methods', 'RPTR_CORE_TEXT_DOMAIN' ); ?> <a class="tips" data-tip="<?php _e( 'Method name to show on frontend.', 'RPTR_CORE_TEXT_DOMAIN' ); ?>">[?]</a></th>
								<th><?php _e( 'Min Price', 'RPTR_CORE_TEXT_DOMAIN' ); ?> <a class="tips" data-tip="<?php _e( 'Min price for this shipping rate.', 'RPTR_CORE_TEXT_DOMAIN' ); ?>">[?]</a></th>
								<th><?php _e( 'Max Price', 'RPTR_CORE_TEXT_DOMAIN' ); ?> <a class="tips" data-tip="<?php _e( 'Max price for this shipping rate.', 'RPTR_CORE_TEXT_DOMAIN' ); ?>">[?]</a></th>
								<th><?php _e( 'Min Weight', 'RPTR_CORE_TEXT_DOMAIN' ); ?> <a class="tips" data-tip="<?php _e( 'Min weight for this shipping rate.', 'RPTR_CORE_TEXT_DOMAIN' ); ?>">[?]</a></th>
								<th><?php _e( 'Max Weight', 'RPTR_CORE_TEXT_DOMAIN' ); ?> <a class="tips" data-tip="<?php _e( 'Max weight for this shipping rate.', 'RPTR_CORE_TEXT_DOMAIN' ); ?>">[?]</a></th>
								<th><?php _e( 'Shipping Fee', 'RPTR_CORE_TEXT_DOMAIN' ); ?> <a class="tips" data-tip="<?php _e( 'Shipping price for this price range.', 'RPTR_CORE_TEXT_DOMAIN' ); ?>">[?]</a></th>
                                <th><?php _e( 'Country', 'RPTR_CORE_TEXT_DOMAIN' ); ?></th>
								<th><?php _e( 'Method Name', 'RPTR_CORE_TEXT_DOMAIN' ); ?></th>
							</tr>
						</thead>
						<tfoot>
							<tr>
								<th colspan="4"><a href="#" class="add button" style="margin-left: 24px"><?php _e( '+ Add Rate', 'RPTR_CORE_TEXT_DOMAIN' ); ?></a> <a href="#" class="remove button"><?php _e( 'Delete selected rates', 'RPTR_CORE_TEXT_DOMAIN' ); ?></a></th>
							</tr>
						</tfoot>
						<tbody class="table_rates">

						<?php
			$i = -1;
                       
                       
			if ( $x->table_rates ) {
				foreach ( $x->table_rates as $class => $rate ) {
                                   
                	$methodsData = array();
                    $options = '';
					$i++;
					if($x->id == 'smartsend_swipbox'){
						$methods = new Smartsend_Logistics_SwipBox();
						$methodsData = $methods->get_methods();
					}
					if($x->id == 'smartsend_gls'){
						$methods = new Smartsend_Logistics_GLS();
						$methodsData = $methods->get_methods();
					}
					if($x->id == 'smartsend_postdanmark'){
						$methods = new Smartsend_Logistics_PostDanmark();
						$methodsData = $methods->get_methods();
					}
					if($x->id == 'smartsend_bring'){
						$methods = new Smartsend_Logistics_Bring();
						$methodsData = $methods->get_methods();
					}
					if($x->id == 'smartsend_posten'){
						$methods = new Smartsend_Logistics_Posten();
						$methodsData = $methods->get_methods();
					}
					if($x->id == 'smartsend_pickuppoints'){
						$methods = new Smartsend_Logistics_PickupPoints();
						$methodsData = $methods->get_methods();
					}
					foreach($methodsData as $key => $m){
						$selected = '';
						if(esc_attr( $rate['methods'] ) == $key) $selected = 'selected="selected"';
						$options .= '<option '.$selected.' value="'.$key.'">'.$m.'</option>';
					}
					$shipClass = '';
                	$shipclassArr = array();
                    $shipclassArr['all'] = 'All Shipping classes';
					if ( WC()->shipping->get_shipping_classes() ) {
						foreach ( WC()->shipping->get_shipping_classes() as $shipping_class ) {
                            $shipclassArr[$shipping_class->name] = $shipping_class->name;
                    	}
					} 
					foreach($shipclassArr as $key => $m){
						$selected = '';
						if(esc_attr( $rate['class'] ) == $key) {
							$selected = 'selected="selected"';
						}
						$shipClass .= '<option '.$selected.' value="'.$key.'">'.$m.'</option>';
					}
					echo '<tr class="table_rate">
										<th class="check-column"><input type="checkbox" name="select" /></th>
                                        <td><select name="' . esc_attr($x->id .'_tablerate[' . $i . '][class]') . '">'.$shipClass.'</select></td>
                                        <td><select name="' . esc_attr($x->id .'_tablerate[' . $i . '][methods]' ). '">'.$options.'</select></td>
										<td><input type="number" step="any" min="0" value="' . esc_attr( $rate['minO'] ) . '" name="' . esc_attr( $x->id .'_tablerate[' . $i . '][minO]' ) . '" style="width: 90%; min-width:75px" class="' . esc_attr( $x->id .'field[' . $i . ']' ) . '" placeholder="'.__( '0.00', 'RPTR_CORE_TEXT_DOMAIN' ).'" size="4" /></td>
										<td><input type="number" step="any" min="0" value="' . esc_attr( $rate['maxO'] ) . '" name="' . esc_attr( $x->id .'_tablerate[' . $i . '][maxO]' ) . '" style="width: 90%; min-width:75px" class="' . esc_attr( $x->id .'field[' . $i . ']' ) . '" placeholder="'.__( '0.00', 'RPTR_CORE_TEXT_DOMAIN' ).'" size="4" /></td>
                                        <td><input type="number" step="any" min="0" value="' . esc_attr( $rate['minwO'] ) . '" name="' . esc_attr( $x->id .'_tablerate[' . $i . '][minwO]' ) . '" style="width: 90%; min-width:75px" class="' . esc_attr( $x->id .'field[' . $i . ']' ) . '" placeholder="'.__( '0.00', 'RPTR_CORE_TEXT_DOMAIN' ).'" size="4" /></td>
										<td><input type="number" step="any" min="0" value="' . esc_attr( $rate['maxwO'] ) . '" name="' . esc_attr( $x->id .'_tablerate[' . $i . '][maxwO]' ) . '" style="width: 90%; min-width:75px" class="' . esc_attr( $x->id .'field[' . $i . ']' ) . '" placeholder="'.__( '0.00', 'RPTR_CORE_TEXT_DOMAIN' ).'" size="4" /></td>
										<td><input type="number" step="any" min="0" value="' . esc_attr( $rate['shippingO'] ) . '" name="' . esc_attr( $x->id .'_tablerate[' . $i . '][shippingO]' ) . '" style="width: 90%; min-width:75px" class="' . esc_attr( $x->id .'field[' . $i . ']' ) . '" placeholder="'.__( '0.00', 'RPTR_CORE_TEXT_DOMAIN' ).'" size="4" /></td>
                                        <td><input type="text" step="any" min="0" value="' . esc_attr( $rate['country'] ) . '" name="' . esc_attr( $x->id .'_tablerate[' . $i . '][country]' ) . '" style="width: 90%; min-width:75px" class="' . esc_attr( $x->id .'field[' . $i . ']' ) . '" placeholder="'.__( '', 'RPTR_CORE_TEXT_DOMAIN' ).'" size="4" /></td>
                                        <td><input type="text" step="any" min="0" value="' . esc_attr( $rate['method_name'] ) . '" name="' . esc_attr( $x->id .'_tablerate[' . $i . '][method_name]' ) . '" style="width: 90%; min-width:100px" class="' . esc_attr( $x->id .'field[' . $i . ']' ) . '" placeholder="'.__( '', 'RPTR_CORE_TEXT_DOMAIN' ).'" size="4" /></td>
									</tr>';
				}
			}
			?>
						</tbody>
					</table>


					<script type="text/javascript">
						jQuery(function() {
							jQuery('#<?php echo $x->id; ?>_table_rates').on( 'click', 'a.add', function(){
								var size = jQuery('#<?php echo $x->id; ?>_table_rates tbody .table_rate').size();
								var previous = size - 1;
								jQuery('<tr class="table_rate">\
									<th class="check-column"><input type="checkbox" name="select" /></th>\
									<td><select name="<?php echo $x->id; ?>_tablerate[' + size + '][class]"><?php echo $shipClass ?></select></td>\
                                    <td><select name="<?php echo $x->id; ?>_tablerate[' + size + '][methods]"><?php echo $options ?></select></td>\n\
                                    <td><input type="number" step="any" min="0" name="<?php echo $x->id; ?>_tablerate[' + size + '][minO]" style="width: 90%; min-width:75px" class="<?php echo $x->id; ?>field[' + size + ']" placeholder="0.00" size="4" /></td>\
									<td><input type="number" step="any" min="0" name="<?php echo $x->id; ?>_tablerate[' + size + '][maxO]" style="width: 90%; min-width:75px" class="<?php echo $x->id; ?>field[' + size + ']" placeholder="0.00" size="4" /></td>\
									<td><input type="number" step="any" min="0" name="<?php echo $x->id; ?>_tablerate[' + size + '][minwO]" style="width: 90%; min-width:75px" class="<?php echo $x->id; ?>field[' + size + ']" placeholder="0.00" size="4" /></td>\
									<td><input type="number" step="any" min="0" name="<?php echo $x->id; ?>_tablerate[' + size + '][maxwO]" style="width: 90%; min-width:75px" class="<?php echo $x->id; ?>field[' + size + ']" placeholder="0.00" size="4" /></td>\
									<td><input type="number" step="any" min="0" name="<?php echo $x->id; ?>_tablerate[' + size + '][shippingO]" style="width: 90%; min-width:75px" class="<?php echo $x->id; ?>field[' + size + ']" placeholder="0.00" size="4" /></td>\<td><input type="text" step="any" min="0" name="<?php echo $x->id; ?>_country[' + size + ']" style="width: 90%" class="<?php echo $x->id; ?>field[' + size + ']" placeholder="" size="4" /></td>\
									<td><input type="text" step="any" min="0" name="<?php echo $x->id; ?>_tablerate[' + size + '][method_name]" style="width: 90%; min-width:100px" class="<?php echo $x->id; ?>field[' + size + ']" placeholder="" size="4" /></td>\
								</tr>').appendTo('#<?php echo $x->id; ?>_table_rates table tbody');

								return false;
							});

							// Remove row
							jQuery('#<?php echo $x->id; ?>_table_rates').on( 'click', 'a.remove', function(){
								var answer = confirm("<?php _e( 'Delete the selected rates?', RPTR_CORE_TEXT_DOMAIN ); ?>")
									if (answer) {
										jQuery('#<?php echo $x->id; ?>_table_rates table tbody tr th.check-column input:checked').each(function(i, el){
										jQuery(el).closest('tr').remove();
									});
								}
								return false;
							});
						});
					</script>
				</td>
			</tr>

        <input type="hidden" id="hdn1" value="yes" />
		<?php
			return ob_get_clean();
		}
		
}

/********************* End Of primaryClass **********************************************************/