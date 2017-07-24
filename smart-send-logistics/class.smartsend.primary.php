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
            } else {
                $customerCountry = $woocommerce->customer->country;    
            }
                    
			$x->rate = array();
                        
			$shipping_rates = get_option( $x->table_rate_option );
			if(empty($shipping_rates)) $shipping_rates = $x->table_rates;
			
			$price 	= (float) $woocommerce->cart->cart_contents_total;
			$weight = (float) $woocommerce->cart->cart_contents_weight;
			$cheapestexpensive = $x->get_option( 'cheap_expensive' , 'cheapest');
			
			$sc = array('all');
			foreach ( $woocommerce->cart->get_cart() as $item ) {
				if($item['data']->get_shipping_class()){
					$sc[] = $item['data']->get_shipping_class();
				}
			}

			if(!empty($shipping_rates)){
                
                //This array will contain the valid shipping methods                
				$shp = array();  
				
				foreach ( $shipping_rates as $rates ) {
					$countries = explode(',', $rates['country']);
                	$countries = array_map("strtoupper", $countries);
                    $countries = array_map("trim", $countries);
                                        
					if ( (float)$price >= (float)$rates['minO']
						&& ( (float)$price <= (float)$rates['maxO'] || (float)$rates['maxO'] == 0)
						&& (float)$weight >= (float)$rates['minwO']
						&& ( (float)$weight <= (float)$rates['maxwO'] || (float)$rates['maxwO'] == 0)
						//&& ($rates['class'] == 'all' || $rates['class'] == $sc)
                        && (!isset($rates['class']) || in_array(strtolower($rates['class']), array_map('strtolower', $sc)))
						&& ( in_array(strtoupper($customerCountry), $countries) || in_array('*', $countries) )
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
				
				$dformat = get_option( 'woocommerce_carrier_display_format', 1 );
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
		if(isset( $_POST[ $x->id . '_tablerate'] ) ) {
			$rates = $_POST[ $x->id . '_tablerate'];
		} else {
			$rates = null;
		}	
		
		// Go through each rate
		if(is_array($rates)) {
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
		}
		
		// Save rates if any
		update_option( $x->table_rate_option, $table_rates );
		$x->load_table_rates();
		
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
				<th scope="row" class="titledesc"><?php _e( 'Price', 'smart-send-logistics' ); ?>:</th>
				<td class="forminp" id="<?php echo $x->id; ?>_table_rates">
					<table class="shippingrows widefat" cellspacing="0">
						<thead>
							<tr>
								<th class="check-column"><input type="checkbox"></th>
                                <th><?php _e( 'Shipping class', 'smart-send-logistics' ); ?> <a class="tips" data-tip="<?php _e( 'Products must belong to this shipping class', 'smart-send-logistics' ); ?>">[?]</a></th>
                                <th><?php _e( 'Methods', 'smart-send-logistics' ); ?> <a class="tips" data-tip="<?php _e( 'Shipping method', 'smart-send-logistics' ); ?>">[?]</a></th>
								<th><?php _e( 'Min price', 'smart-send-logistics' ); ?> <a class="tips" data-tip="<?php _e( 'Minimum total order price for this shipping rate', 'smart-send-logistics' ); ?>">[?]</a></th>
								<th><?php _e( 'Max price', 'smart-send-logistics' ); ?> <a class="tips" data-tip="<?php _e( 'Maximum total order price for this shipping rate', 'smart-send-logistics' ); ?>">[?]</a></th>
								<th><?php _e( 'Min weight', 'smart-send-logistics' ); ?> <a class="tips" data-tip="<?php _e( 'Minimum total order weight for this shipping rate', 'smart-send-logistics' ); ?>">[?]</a></th>
								<th><?php _e( 'Max weight', 'smart-send-logistics' ); ?> <a class="tips" data-tip="<?php _e( 'Maximum total order weight for this shipping rate', 'smart-send-logistics' ); ?>">[?]</a></th>
								<th><?php _e( 'Shipping fee', 'smart-send-logistics' ); ?> <a class="tips" data-tip="<?php _e( 'Shipping price for this shipping method', 'smart-send-logistics' ); ?>">[?]</a></th>
                                <th><?php _e( 'Country', 'smart-send-logistics' ); ?> <a class="tips" data-tip="<?php _e( 'Comma-separated list of valid countries in ISO 3166-1 alpha-2 format. Use * for all countries.', 'smart-send-logistics' ); ?>">[?]</a></th>
								<th><?php _e( 'Method name', 'smart-send-logistics' ); ?> <a class="tips" data-tip="<?php _e( 'Method name to show on frontend', 'smart-send-logistics' ); ?>">[?]</a></th>
							</tr>
						</thead>
						<tfoot>
							<tr>
								<th colspan="4"><a href="#" class="add button" style="margin-left: 24px"><?php _e( '+ Add rate', 'smart-send-logistics' ); ?></a> <a href="#" class="remove button"><?php _e( 'Delete selected rates', 'smart-send-logistics' ); ?></a></th>
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
						if(isset($rate['class']) && esc_attr( $rate['class'] ) == $key) {
							$selected = 'selected="selected"';
						}
						$shipClass .= '<option '.$selected.' value="'.$key.'">'.$m.'</option>';
					}
					echo '<tr class="table_rate">
										<th class="check-column"><input type="checkbox" name="select" /></th>
                                        <td><select name="' . esc_attr($x->id .'_tablerate[' . $i . '][class]') . '">'.$shipClass.'</select></td>
                                        <td><select name="' . esc_attr($x->id .'_tablerate[' . $i . '][methods]' ). '">'.$options.'</select></td>
										<td><input type="number" step="any" min="0" value="' . esc_attr( $rate['minO'] ) . '" name="' . esc_attr( $x->id .'_tablerate[' . $i . '][minO]' ) . '" style="width: 90%; min-width:75px" class="' . esc_attr( $x->id .'field[' . $i . ']' ) . '" placeholder="0.00" size="4" /></td>
										<td><input type="number" step="any" min="0" value="' . esc_attr( $rate['maxO'] ) . '" name="' . esc_attr( $x->id .'_tablerate[' . $i . '][maxO]' ) . '" style="width: 90%; min-width:75px" class="' . esc_attr( $x->id .'field[' . $i . ']' ) . '" placeholder="0.00" size="4" /></td>
                                        <td><input type="number" step="any" min="0" value="' . esc_attr( $rate['minwO'] ) . '" name="' . esc_attr( $x->id .'_tablerate[' . $i . '][minwO]' ) . '" style="width: 90%; min-width:75px" class="' . esc_attr( $x->id .'field[' . $i . ']' ) . '" placeholder="0.00" size="4" /></td>
										<td><input type="number" step="any" min="0" value="' . esc_attr( $rate['maxwO'] ) . '" name="' . esc_attr( $x->id .'_tablerate[' . $i . '][maxwO]' ) . '" style="width: 90%; min-width:75px" class="' . esc_attr( $x->id .'field[' . $i . ']' ) . '" placeholder="0.00" size="4" /></td>
										<td><input type="number" step="any" min="0" value="' . esc_attr( $rate['shippingO'] ) . '" name="' . esc_attr( $x->id .'_tablerate[' . $i . '][shippingO]' ) . '" style="width: 90%; min-width:75px" class="' . esc_attr( $x->id .'field[' . $i . ']' ) . '" placeholder="0.00" size="4" /></td>
                                        <td><input type="text" value="' . esc_attr( $rate['country'] ) . '" name="' . esc_attr( $x->id .'_tablerate[' . $i . '][country]' ) . '" style="width: 90%; min-width:75px" class="' . esc_attr( $x->id .'field[' . $i . ']' ) . '" placeholder="" size="4" /></td>
                                        <td><input type="text" value="' . esc_attr( $rate['method_name'] ) . '" name="' . esc_attr( $x->id .'_tablerate[' . $i . '][method_name]' ) . '" style="width: 90%; min-width:100px" class="' . esc_attr( $x->id .'field[' . $i . ']' ) . '" placeholder="" size="4" /></td>
									</tr>';
				}
			}
			
			// Create 'shipping class' and 'methods' dropdowns that is inserted when a new row is added
			switch ($x->id) {
				case 'smartsend_gls':
					$methods = new Smartsend_Logistics_GLS();
					$methodsData = $methods->get_methods();
					break;
				case 'smartsend_postdanmark':
					$methods = new Smartsend_Logistics_PostDanmark();
					$methodsData = $methods->get_methods();
					break;
				case 'smartsend_bring':
					$methods = new Smartsend_Logistics_Bring();
					$methodsData = $methods->get_methods();
					break;
				case 'smartsend_posten':
					$methods = new Smartsend_Logistics_Posten();
					$methodsData = $methods->get_methods();
					break;
				case 'smartsend_pickuppoints':
					$methods = new Smartsend_Logistics_PickupPoints();
					$methodsData = $methods->get_methods();
					break;
				default:
					throw new Exception( __( 'Unknown carrier','smart-send-logistics') );
			}
			// Shipping method dropdown
			$options = '';
			foreach($methodsData as $key => $m){
				$options .= '<option value="'.$key.'">'.$m.'</option>';
			}
			// Shipping class dropdown
			$shipClass = '';
			$shipclassArr = array();
			$shipclassArr['all'] = 'All Shipping classes';
			if ( WC()->shipping->get_shipping_classes() ) {
				foreach ( WC()->shipping->get_shipping_classes() as $shipping_class ) {
					$shipclassArr[$shipping_class->name] = $shipping_class->name;
				}
			}
			$shipClass = '';
			foreach($shipclassArr as $key => $m){
				$shipClass .= '<option value="'.$key.'">'.$m.'</option>';
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
									<td><input type="number" step="any" min="0" name="<?php echo $x->id; ?>_tablerate[' + size + '][shippingO]" style="width: 90%; min-width:75px" class="<?php echo $x->id; ?>field[' + size + ']" placeholder="0.00" size="4" /></td>\
									<td><input type="text" name="<?php echo $x->id; ?>_tablerate[' + size + '][country]" style="width: 90%" class="<?php echo $x->id; ?>field[' + size + ']" placeholder="" size="4" /></td>\
									<td><input type="text" name="<?php echo $x->id; ?>_tablerate[' + size + '][method_name]" style="width: 90%; min-width:100px" class="<?php echo $x->id; ?>field[' + size + ']" placeholder="" size="4" /></td>\
								</tr>').appendTo('#<?php echo $x->id; ?>_table_rates table tbody');

								return false;
							});

							// Remove row
							jQuery('#<?php echo $x->id; ?>_table_rates').on( 'click', 'a.remove', function(){
								var answer = confirm("<?php _e( 'Delete the selected rates?', 'smart-send-logistics' ); ?>")
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