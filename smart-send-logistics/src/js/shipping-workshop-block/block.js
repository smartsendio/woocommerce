/**
 * External dependencies
 */
import { useEffect, useState, useCallback } from '@wordpress/element';
import { SelectControl, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';
import { debounce, join } from 'lodash';

/**
 * Internal dependencies
 */
import { options } from './options';
import {countries} from './countries';

export const Block = ( { checkoutExtensionData, extensions } ) => {
	const [ optionslogic, setOptionslogic ] = useState( '' );
	const isCalculating = useSelect( ( select ) => {
		const store = select( 'wc/store/checkout' );
		return store.isCalculating();
	} );
	const { setExtensionData } = checkoutExtensionData;
	/**
	 * Debounce the setExtensionData function to avoid multiple calls to the API when rapidly
	 * changing options.
	 */
	// eslint-disable-next-line react-hooks/exhaustive-deps
	const debouncedSetExtensionData = useCallback(
		debounce( ( namespace, key, value ) => {
			setExtensionData( namespace, key, value );
		}, 1000 ),
		[ setExtensionData ]
	);

	useEffect( () => {
		var carrier = 'postnord';
		var street = setValue( 'shipping-address_1' );
		var city = setValue( 'shipping-city' );
		// var state = document.getElementById( 'shipping-state' ).value;
		var country = setValue( 'components-form-token-input-0' );
		country = countries[country];
		var postcode = setValue( 'shipping-postcode' );
		if ( isCalculating ) {

			if(postcode !== null && street !== null && city !== null && country !== null){
			findClosestAgentByAddress(
				carrier,
				country,
				postcode,
				city,
				street
			);
			}
		} 
	}, [ isCalculating ] );
	const [
		selectedAlternateShippingInstruction,
		setSelectedAlternateShippingInstruction,
	] = useState( 'try-again' );
	const [ otherShippingValue, setOtherShippingValue ] = useState( '' );

	/* Handle changing the select's value */
	useEffect( () => {
		setExtensionData(
			'shipping-workshop',
			'alternateShippingInstruction',
			selectedAlternateShippingInstruction
		);
	
	}, [ setExtensionData, selectedAlternateShippingInstruction ] );



	/* Handle changing the "other" value */
	useEffect( () => {
		
		setExtensionData(
			'shipping-workshop',
			'otherShippingValue',
			otherShippingValue
		);
		
	}, [
		/**
		 * 
		 * 💡 Don't forget to update the dependencies of the `useEffect` when you reference new
		 * functions/variables!
		 */
		otherShippingValue,
		setExtensionData,
	] );

	return (
		<div className="wp-block-shipping-workshop-not-at-home">
			<div className="coountry"></div>
			
			<SelectControl
				label={ __( 'TLS Delievery Point', 'shipping-workshop' ) }
				value={ selectedAlternateShippingInstruction }
				options={ options }
				onChange={ setSelectedAlternateShippingInstruction }
				className='shiiping_smart_ar_hide'
			/>
		</div>
	);
};

// // Function to sanitize input (remove HTML tags)
function sanitizeInput( input ) {
	const sanitizedInput = input.replace( /<[^>]*>?/gm, '' ); // Remove HTML tags
	return sanitizedInput.trim(); // Trim leading and trailing whitespace
}

// Function to find closest agent by address
 function findClosestAgentByAddress(
	carrier,
	country,
	postalCode,
	city,
	street
) {
	// Sanitize inputs
	carrier = sanitizeInput( carrier );
	country = sanitizeInput( country );
	postalCode = sanitizeInput( postalCode );
	city = sanitizeInput( city );
	street = sanitizeInput( street );
     
	getPickupPoints(carrier, country, postalCode, city, street);
	
}

function setValue(id) {
    var element = document.getElementById(id);
    if (element) {
        return element.value;
    }
    return null;
}

const getPickupPoints = async (meta_data,country,postalCode,city,street) => {
    const url = '/wp-json/smart-send-logistics/v1/get-pickup-points-nearby'; // Your endpoint URL

    const data = {
        country: country,
        postCode: postalCode,
        city: city,
        street: street,
        meta_data: meta_data
    };

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });

        if (!response.ok) {
            const errorData = await response.json();
			jQuery('.shiiping_smart_ar_hide').hide();
			jQuery('.shiiping_smart_ar_hide').css('opacity',0);
			
        } else {
            const resultz = await response.json();
			const sortedData = Object.entries(resultz).sort((a, b) => {
                const distanceA = a[0] === "0" ? 0 : parseFloat(a[1].split(':')[0].replace('km', '').replace('m', '')) * (a[1].includes('km') ? 1000 : 1);
                const distanceB = b[0] === "0" ? 0 : parseFloat(b[1].split(':')[0].replace('km', '').replace('m', '')) * (b[1].includes('km') ? 1000 : 1);
                return distanceB - distanceA;
            });
		    var reversedrestoreddata=sortedData.reverse();
			  var output='';
			  jQuery.each(reversedrestoreddata,function(key, value) {
			    output += '<option value="' + value[0] + '">' + value[1] + '</option>';
			   })
              jQuery('.shiiping_smart_ar_hide').find("select").html(output);
			  gettheselectedmethod();
        }
    } catch (error) {
        alert("we are unable right now try again later");
	
	}
};

function gettheselectedmethod(){
	var selected = jQuery(".wc-block-components-shipping-rates-control").find("input[type=radio]:checked").val();
	if(selected.indexOf('smart_send') !== -1){
		jQuery('.shiiping_smart_ar_hide').show();
		jQuery('.shiiping_smart_ar_hide').css('opacity',1);
	}else{
		jQuery('.shiiping_smart_ar_hide').hide();
		jQuery('.shiiping_smart_ar_hide').css('opacity',0);
	}
}
