/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';

const fetchOptions = async () => {
    try {
        const response = await fetch('https://jsonplaceholder.typicode.com/posts');
        const data = await response.json();

        // Map the fetched data to the options format
        const fetchedOptions = data.slice(0, 3).map((item, index) => ({
            label: __(item.title, 'shipping-workshop'),
            value: `point-${index + 1}`,
        }));

        // Add the 'Other' option
        fetchedOptions.push({
            label: __('Other', 'shipping-workshop'),
            value: 'other',
        });

        return fetchedOptions;
    } catch (error) {
        console.error('Error fetching options:', error);
        return [
            {
                label: __('Point One', 'shipping-workshop'),
                value: 'point-one',
            },
            {
                label: __('Point Two', 'shipping-workshop'),
                value: 'point-two',
            },
            {
                label: __('Point Three', 'shipping-workshop'),
                value: 'point-three',
            },
            {
                label: __('Other', 'shipping-workshop'),
                value: 'other',
            },
        ];
    }
};

const ShippingOptionsUpdater = () => {
    const { shippingAddress } = useSelect((select) => {
        const { getShippingAddress } = select('wc/store');
        return {
            shippingAddress: getShippingAddress(),
        };
    }, []);

    const [options, setOptions] = useState([]);

    useEffect(() => {
        const updateOptions = async () => {
            const fetchedOptions = await fetchOptions();
            setOptions(fetchedOptions);
        };

        if (shippingAddress) {
            updateOptions();
        }
    }, [shippingAddress]);

    return null;
};

// export default ShippingOptionsUpdater;

export let options = [];

// Initialize options when the component mounts
fetchOptions().then(fetchedOptions => {
    options = fetchedOptions;
});
